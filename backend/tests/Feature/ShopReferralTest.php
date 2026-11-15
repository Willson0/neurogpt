<?php

namespace Tests\Feature;

use App\Models\Bot\ShopItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Purchases by the id of a bot `shop` row, the 20 % referral commission and YooKassa refunds. */
class ShopReferralTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['neurogpt.yookassa.shop_id' => 'shop', 'neurogpt.yookassa.secret_key' => 'secret', 'neurogpt.yookassa.verify_ip' => false,
            'neurogpt.stars.rub_per_star' => 2.0]);

        // The bot's shop as on the screenshot.
        $row = fn (int $id, bool $active, string $name, string $cat, string $item, int $amount, int $price, ?string $desc, ?string $tag) => ShopItem::forceCreate([
            'id' => $id, 'active' => $active, 'text_name' => $name, 'category' => $cat, 'item' => $item, 'amount' => $amount,
            'price' => $price, 'description' => $desc, 'tag' => $tag,
        ]);
        $row(1, false, 'Бесплатный', 'sub', 'free', 0, 0, null, null);
        $row(3, true, '300 токенов', 'token', 'tokens_300', 500, 249, '{"photo": 15, "video": 0, "emoji": "💫"}', 'Новичок');
        $row(4, true, '800 токенов', 'token', 'tokens_800', 800, 379, '{"photo": 25, "video": 2, "emoji": "🍒"}', 'Базовый');
        $row(5, true, '1500 токенов', 'token', 'tokens_1500', 1500, 649, null, 'Популярно');
        $row(8, true, '💎 Neuro PRO', 'sub', 'pro', 30, 749, 'Норм темка', 'Выгодно');
        $row(9, false, 'Скрытый', 'token', 'tokens_9000', 9000, 10, null, null);
    }

    /** YooKassa answers: creating the payment and reading it back. */
    private function fakeYooKassa(string $id, string $value, array $state = []): void
    {
        $remote = ['id' => $id, 'status' => 'pending', 'paid' => false, 'amount' => ['value' => $value, 'currency' => 'RUB'],
            'confirmation' => ['confirmation_url' => "https://yoomoney.ru/pay/$id"]];
        Http::swap(new Factory);
        Http::fake([
            'api.yookassa.ru/v3/payments' => Http::response($remote),
            "api.yookassa.ru/v3/payments/$id" => Http::response(array_merge($remote, $state)),
        ]);
    }

    private function hook(string $event, array $object)
    {
        return $this->postJson('/api/payments/yookassa/webhook', ['type' => 'notification', 'event' => $event, 'object' => $object]);
    }

    /** A buyer invited by 100. */
    private function invited(): array
    {
        $inviter = $this->user(100, 0);
        $this->as(200, 'friend_100')->getJson('/api/me')->assertOk();
        $buyer = User::findOrFail(200);
        $buyer->forceFill(['tokens' => 0])->save();

        return [$inviter, $buyer];
    }

    public function test_the_app_shows_the_shop_and_pays_by_item_id_with_the_server_price(): void
    {
        $this->user(1, 0);
        $boot = $this->as(1)->getJson('/api/bootstrap')->assertOk();
        $this->assertSame([3, 4, 5], array_column($boot->json('packs'), 'item_id'), 'only active rows with a price, cheapest first');
        $this->assertSame(['item_id' => 3, 'tokens' => 500, 'price' => 249, 'name' => '300 токенов', 'badge' => 'Новичок', 'stars' => 125, 'category' => 'token', 'photo' => 15, 'video' => 0, 'emoji' => '💫'],
            $boot->json('packs.0'));
        $this->assertSame([true, 8, 749, null], [$boot->json('plans.pro.available'), $boot->json('plans.pro.item_month'), $boot->json('plans.pro.month'), $boot->json('plans.pro.item_year')]);
        $this->assertFalse($boot->json('plans.plus.available'), 'the shop sells no Plus');

        $this->fakeYooKassa('yk_10', '249.00');
        // the client sends only the id: a price in the request is ignored
        $res = $this->as(1)->postJson('/api/payments/item', ['item_id' => 3, 'price' => 1, 'amount' => 1])->assertCreated()
            ->assertJsonPath('data.amount', 249)->assertJsonPath('data.tokens', 500)->assertJsonPath('data.confirmation_url', 'https://yoomoney.ru/pay/yk_10');
        Http::assertSent(fn ($r) => $r->url() === 'https://api.yookassa.ru/v3/payments' && $r['amount']['value'] === '249.00'
            && $r['metadata']['item_id'] === '3' && $r['description'] === '300 токенов');
        $this->assertSame(3, Payment::find($res->json('data.id'))->shop_item_id);

        // hidden, free and unknown rows cannot be bought
        foreach ([1, 9, 777] as $id) {
            $this->as(1)->postJson('/api/payments/item', ['item_id' => $id])->assertStatus(422)->assertJsonPath('code', 'unknown_item');
        }
        // the old endpoints follow the shop too
        Http::fake(['api.telegram.org/*/createInvoiceLink' => Http::response(['ok' => true, 'result' => 'https://t.me/$inv'])]);
        $this->as(1)->postJson('/api/payments/topup', ['tokens' => 800, 'method' => 'stars'])->assertStatus(201)->assertJsonPath('data.amount', 190);
        $this->as(1)->postJson('/api/payments/topup', ['tokens' => 100])->assertStatus(422);
        $this->as(1)->postJson('/api/payments/plan', ['plan' => 'pro', 'period' => 'year'])->assertStatus(422);
    }

    public function test_paid_purchase_gives_the_inviter_20_percent_once(): void
    {
        [$inviter, $buyer] = $this->invited();
        $this->fakeYooKassa('yk_20', '249.00', ['status' => 'succeeded', 'paid' => true]);
        $id = $this->as(200)->postJson('/api/payments/item', ['item_id' => 3])->assertCreated()->json('data.id');

        // a forged body changes nothing until the API confirms (here it does), and repeats change nothing either
        foreach (range(1, 3) as $i) {
            $this->hook('payment.succeeded', ['id' => 'yk_20', 'status' => 'succeeded'])->assertOk();
        }
        $this->as(200)->getJson("/api/payments/$id")->assertJsonPath('data.status', 'succeeded');

        $buyer->refresh();
        $this->assertSame(500, $buyer->balance);
        $this->assertTrue($buyer->ref_paid);
        $this->assertSame(49, (int) $inviter->refresh()->ref_balance, '20 % of 249 ₽, rounded down');
        $this->assertSame(0, $inviter->balance, 'the commission is rubles on ref_balance, not tokens');
        $this->assertSame([100, 49], [Payment::find($id)->details->referrer_id, Payment::find($id)->details->ref_amount]);
        $bot = Payment::sole();
        $this->assertSame([3, 249.0, true], [(int) $bot->item_id, $bot->rub_total, $bot->was_bought]);
        $this->assertEquals(249, DB::table('users')->where('id', 200)->value('paid_money'));

        // a second purchase pays again
        $this->fakeYooKassa('yk_21', '649.00', ['status' => 'succeeded', 'paid' => true]);
        $this->as(200)->postJson('/api/payments/item', ['item_id' => 5])->assertCreated();
        $this->hook('payment.succeeded', ['id' => 'yk_21'])->assertOk();
        $this->assertSame(49 + 129, (int) $inviter->refresh()->ref_balance);
    }

    public function test_refund_takes_the_commission_back_once_and_in_proportion(): void
    {
        [$inviter, $buyer] = $this->invited();
        $inviter->forceFill(['ref_balance' => 10])->save();
        $this->fakeYooKassa('yk_30', '649.00', ['status' => 'succeeded', 'paid' => true]);
        $id = $this->as(200)->postJson('/api/payments/item', ['item_id' => 5])->json('data.id');
        $this->hook('payment.succeeded', ['id' => 'yk_30'])->assertOk();
        $this->assertSame(10 + 129, (int) $inviter->refresh()->ref_balance);

        // half of the price returned: half of the commission (64 of 129) goes back; repeated notifications do nothing
        $this->fakeYooKassa('yk_30', '649.00', ['status' => 'succeeded', 'paid' => true, 'refunded_amount' => ['value' => '324.50', 'currency' => 'RUB']]);
        $this->hook('refund.succeeded', ['id' => 'rf_1', 'payment_id' => 'yk_30', 'status' => 'succeeded'])->assertOk();
        $this->hook('refund.succeeded', ['id' => 'rf_1', 'payment_id' => 'yk_30', 'status' => 'succeeded'])->assertOk();
        $this->assertSame(10 + 129 - 64, (int) $inviter->refresh()->ref_balance);
        $this->assertSame('succeeded', Payment::find($id)->status);

        // the rest returned: the whole commission is gone, the payment is refunded, paid_money is back to 0
        $this->fakeYooKassa('yk_30', '649.00', ['status' => 'succeeded', 'paid' => true, 'refunded_amount' => ['value' => '649.00', 'currency' => 'RUB']]);
        $this->hook('refund.succeeded', ['id' => 'rf_2', 'payment_id' => 'yk_30'])->assertOk();
        $this->hook('refund.succeeded', ['id' => 'rf_2', 'payment_id' => 'yk_30'])->assertOk();
        $this->hook('payment.succeeded', ['id' => 'yk_30'])->assertOk(); // a late repeat of the success does not pay again
        $this->assertSame(10, (int) $inviter->refresh()->ref_balance);
        $p = Payment::find($id);
        $this->assertSame(['refunded', 129, 129, '649.00'], [$p->status, $p->details->ref_amount, $p->details->ref_reverted, $p->details->refunded_amount]);
        $this->assertEquals(0, DB::table('users')->where('id', 200)->value('paid_money'));
        $this->assertSame(1500, $buyer->refresh()->balance, 'the buyer keeps the tokens: support decides');

        // a refund notification the API does not confirm changes nothing
        $this->fakeYooKassa('yk_30', '649.00', ['status' => 'succeeded', 'paid' => true, 'refunded_amount' => ['value' => '649.00', 'currency' => 'RUB']]);
        $this->hook('refund.succeeded', ['id' => 'rf_x', 'payment_id' => 'unknown'])->assertOk();
        $this->assertSame(10, (int) $inviter->refresh()->ref_balance);
    }

    public function test_api_error_asks_yookassa_to_retry(): void
    {
        [$inviter] = $this->invited();
        $this->fakeYooKassa('yk_40', '249.00');
        $this->as(200)->postJson('/api/payments/item', ['item_id' => 3])->assertCreated();
        Http::swap(new Factory);
        Http::fake(['api.yookassa.ru/*' => Http::response(['type' => 'error'], 500)]);
        $this->hook('payment.succeeded', ['id' => 'yk_40'])->assertStatus(500);
        $this->assertSame(0, (int) $inviter->refresh()->ref_balance);
        $this->hook('payment.waiting_for_capture', ['id' => 'nope'])->assertOk();
        $this->hook('deal.closed', ['id' => 'yk_40'])->assertOk();
    }

    public function test_canceled_payment_pays_nothing_and_no_self_or_missing_referrer(): void
    {
        [$inviter] = $this->invited();
        $this->fakeYooKassa('yk_50', '249.00', ['status' => 'canceled', 'cancellation_details' => ['reason' => 'expired_on_confirmation']]);
        $id = $this->as(200)->postJson('/api/payments/item', ['item_id' => 3])->json('data.id');
        $this->hook('payment.canceled', ['id' => 'yk_50'])->assertOk();
        $this->assertSame(['canceled', 'expired_on_confirmation'], [Payment::find($id)->status, Payment::find($id)->details->cancel_reason]);
        $this->assertSame(0, (int) $inviter->refresh()->ref_balance);

        // someone without an inviter, and someone whose inviter is gone
        $this->user(300, 0);
        DB::table('users')->where('id', 300)->update(['referal_user_from' => 300]);
        $this->fakeYooKassa('yk_51', '249.00', ['status' => 'succeeded', 'paid' => true]);
        $id = $this->as(300)->postJson('/api/payments/item', ['item_id' => 3])->json('data.id');
        $this->hook('payment.succeeded', ['id' => 'yk_51'])->assertOk();
        $this->assertSame([null, 0], [Payment::find($id)->details->referrer_id, Payment::find($id)->details->ref_amount]);
        $this->assertSame(500, User::find(300)->balance);
    }

    public function test_subscription_item_and_stars_also_pay_the_inviter(): void
    {
        [$inviter, $buyer] = $this->invited();
        Http::fake(['api.telegram.org/*/createInvoiceLink' => Http::response(['ok' => true, 'result' => 'https://t.me/$inv']), 'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        $payment = Payment::find($this->as(200)->postJson('/api/payments/item', ['item_id' => 8, 'method' => 'stars'])->assertCreated()
            ->assertJsonPath('data.amount', 375)->assertJsonPath('data.plan', 'pro')->json('data.id'));
        $this->assertSame([30, 'month', 3000], [$payment->details->days, $payment->period, $payment->tokens]);

        $paid = ['message' => ['from' => ['id' => 200], 'chat' => ['id' => 200, 'type' => 'private'], 'successful_payment' => [
            'currency' => 'XTR', 'total_amount' => 375, 'invoice_payload' => 'pay:'.$payment->id.':'.$payment->idempotence_key, 'telegram_payment_charge_id' => 'ch_9',
        ]]];
        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'whsecret'])->postJson('/api/telegram/webhook', $paid)->assertOk();
        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'whsecret'])->postJson('/api/telegram/webhook', $paid)->assertOk();

        $buyer->refresh();
        $this->assertSame('pro', $buyer->activePlan());
        $this->assertTrue($buyer->plan_expires_at->between(now()->addDays(29), now()->addDays(31)));
        $this->assertSame(3000, (int) $buyer->sub_tokens);
        $this->assertSame(149, (int) $inviter->refresh()->ref_balance, '20 % of the 749 ₽ price');
        $this->assertSame(8, (int) Payment::sole()->item_id);

        // a cheaper plan than the active one is refused
        ShopItem::forceCreate(['id' => 11, 'active' => true, 'text_name' => 'Plus', 'category' => 'sub', 'item' => 'plus', 'amount' => 30, 'price' => 300]);
        $this->as(200)->postJson('/api/payments/item', ['item_id' => 11])->assertStatus(422)->assertJsonPath('code', 'plan_downgrade');
    }

    public function test_new_bot_columns_and_bot_admins(): void
    {
        $this->user(500, 0);
        $this->assertSame(0, (int) DB::table('users')->where('id', 500)->value('ref_balance'));
        $this->as(500)->getJson('/api/me')->assertOk()->assertJsonPath('ref_balance', 0)->assertJsonPath('is_admin', false);
        $this->as(500)->getJson('/api/admin/payments')->assertStatus(403);

        // a row in the bot table `admins` opens the panel, without owner rights
        DB::table('admins')->insert(['id' => 500, 'username' => 'helper']);
        $this->as(500)->getJson('/api/me')->assertJsonPath('is_admin', true)->assertJsonPath('is_owner', false);
        $this->as(500)->getJson('/api/admin/payments')->assertOk();

        $this->artisan('neurogpt:verify-sync', ['--no-legacy' => true])->expectsOutputToContain('`users`')->run();
    }
}
