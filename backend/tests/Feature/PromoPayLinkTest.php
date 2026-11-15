<?php

namespace Tests\Feature;

use App\Models\Bot\Promo;
use App\Models\Bot\ShopItem;
use App\Models\Payment;
use App\Models\PromoUse;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Promo codes (bot table promos + panel limits), the payment link of a shop row, the Stars rate, old payments moved into `payments`. */
class PromoPayLinkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['neurogpt.yookassa.shop_id' => 'shop', 'neurogpt.yookassa.secret_key' => 'secret', 'neurogpt.yookassa.verify_ip' => false,
            'neurogpt.stars.rub_per_star' => 2.0, 'neurogpt.telegram.webapp_url' => 'https://app.test', 'neurogpt.telegram.webapp_short_name' => 'app']);
        $row = fn (int $id, bool $active, string $name, string $cat, string $item, int $amount, int $price) => ShopItem::forceCreate([
            'id' => $id, 'active' => $active, 'text_name' => $name, 'category' => $cat, 'item' => $item, 'amount' => $amount, 'price' => $price,
        ]);
        $row(3, true, '500 токенов', 'token', 'tokens_500', 500, 249);
        $row(5, true, '1500 токенов', 'token', 'tokens_1500', 1500, 649);
        $row(8, true, 'Neuro PRO', 'sub', 'pro', 30, 749);
        $row(9, false, 'Скрытый', 'token', 'tokens_9000', 9000, 10);
    }

    private function admin(): static
    {
        return $this->withHeaders(['Authorization' => 'tma '.$this->initData(1337592809), 'Accept' => 'application/json']);
    }

    private function fakeYooKassa(string $id, string $value, array $state = []): void
    {
        $remote = ['id' => $id, 'status' => 'pending', 'paid' => false, 'amount' => ['value' => $value, 'currency' => 'RUB'],
            'confirmation' => ['confirmation_url' => "https://yoomoney.ru/pay/$id"]];
        Http::swap(new Factory);
        Http::fake([
            'api.yookassa.ru/v3/payments' => Http::response($remote),
            "api.yookassa.ru/v3/payments/$id" => Http::response(array_merge($remote, $state)),
            'api.telegram.org/*/createInvoiceLink' => Http::response(['ok' => true, 'result' => 'https://t.me/$inv']),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);
    }

    private function promo(array $over = []): int
    {
        return $this->admin()->postJson('/api/admin/promos', $over + [
            'code' => 'spring20', 'discount' => 20, 'uses' => 2, 'active' => true, 'per_user' => 1, 'new_buyers_only' => false,
        ])->assertCreated()->json('data.id');
    }

    public function test_admin_creates_a_promo_in_the_bot_table_with_extra_limits(): void
    {
        $id = $this->promo(['expires_at' => now()->addDays(3)->toIso8601String(), 'item_ids' => [3, 5], 'min_price' => 200, 'note' => 'блогер']);

        $bot = DB::table('promos')->find($id);
        $this->assertSame(['SPRING20', 20, 2], [$bot->name, (int) $bot->discount, (int) $bot->uses]);
        $this->assertNotNull($bot->created_at);
        $this->admin()->getJson('/api/admin/promos')->assertOk()
            ->assertJsonPath('data.0.code', 'SPRING20')->assertJsonPath('data.0.item_ids', [3, 5])->assertJsonPath('data.0.state', 'active')
            ->assertJsonPath('data.0.min_price', 200)->assertJsonPath('data.0.used', 0);

        // the same code twice (any case) is refused, a bad discount too
        $this->admin()->postJson('/api/admin/promos', ['code' => 'Spring20', 'discount' => 5, 'active' => true, 'new_buyers_only' => false])->assertStatus(422);
        $this->admin()->postJson('/api/admin/promos', ['code' => 'FREE', 'discount' => 100, 'active' => true, 'new_buyers_only' => false])->assertStatus(422);

        // no limit = -1 in the bot table, switching off is a panel rule
        $this->admin()->patchJson("/api/admin/promos/$id", ['code' => 'spring20', 'discount' => 25, 'uses' => null, 'active' => false, 'new_buyers_only' => false])
            ->assertOk()->assertJsonPath('data.uses', null)->assertJsonPath('data.state', 'off');
        $this->assertSame([-1, 25], [(int) DB::table('promos')->where('id', $id)->value('uses'), (int) DB::table('promos')->where('id', $id)->value('discount')]);

        $this->as(5)->getJson('/api/me')->assertOk();
        $this->as(5)->postJson('/api/admin/promos', ['code' => 'X', 'discount' => 5, 'active' => true, 'new_buyers_only' => false])->assertForbidden();

        $this->admin()->deleteJson("/api/admin/promos/$id")->assertOk();
        $this->assertSame(0, DB::table('promos')->count());
    }

    public function test_promo_check_explains_every_refusal(): void
    {
        $this->user(200, 0);
        $check = fn (string $code, ?int $item = null) => $this->as(200)->postJson('/api/promos/check', array_filter(['code' => $code, 'item_id' => $item]));

        $check('nope')->assertStatus(422)->assertJsonPath('code', 'promo_not_found');
        $this->promo(['item_ids' => [3, 8], 'min_price' => 300]);
        $check('Spring20', 5)->assertStatus(422)->assertJsonPath('code', 'promo_not_for_item')->assertJsonPath('message', 'Промокод не действует на этот товар');
        $check('spring20', 3)->assertStatus(422)->assertJsonPath('code', 'promo_min_price');
        $check(' spring20 ', 8)->assertOk()->assertJsonPath('data.discount', 20)->assertJsonPath('data.new_price', 599)->assertJsonPath('data.stars', 300);
        $check('spring20')->assertOk()->assertJsonPath('data.item_ids', [3, 8]);

        $this->promo(['code' => 'OLD', 'expires_at' => now()->subMinute()->toIso8601String()]);
        $check('old', 3)->assertStatus(422)->assertJsonPath('code', 'promo_expired');
        $this->promo(['code' => 'SOON', 'starts_at' => now()->addDay()->toIso8601String()]);
        $check('soon', 3)->assertStatus(422)->assertJsonPath('code', 'promo_not_started');
        $this->promo(['code' => 'EMPTY', 'uses' => 0]);
        $check('empty', 3)->assertStatus(422)->assertJsonPath('code', 'promo_used_up');
        $this->promo(['code' => 'OFF', 'active' => false]);
        $check('off', 3)->assertStatus(422)->assertJsonPath('code', 'promo_inactive');
        $this->promo(['code' => 'SUBS', 'category' => 'sub']);
        $check('subs', 3)->assertStatus(422)->assertJsonPath('code', 'promo_not_for_item');
        $check('subs', 8)->assertOk();

        // a code made in the bot (no panel rules) works by its discount and uses only
        Promo::create(['name' => 'BOT10', 'discount' => 10, 'uses' => -1]);
        $check('bot10', 3)->assertOk()->assertJsonPath('data.new_price', 224);

        // first purchase only
        $this->promo(['code' => 'FIRST', 'new_buyers_only' => true]);
        $check('first', 3)->assertOk();
        Payment::create(['payment_id' => 'x', 'is_autopayment' => false, 'was_bought' => true, 'is_proceed' => true, 'total' => 1, 'total_valut' => 'RUB',
            'rub_total' => 1, 'item_id' => 3, 'user_id' => 200, 'recipient_id' => 200]);
        $check('first', 3)->assertStatus(422)->assertJsonPath('code', 'promo_new_buyers');
    }

    public function test_paying_with_a_promo_charges_the_discounted_price_and_counts_one_use(): void
    {
        $inviter = $this->user(100, 0);
        $this->as(200, 'friend_100')->getJson('/api/me')->assertOk();
        User::findOrFail(200)->forceFill(['tokens' => 0])->save();
        $promoId = $this->promo();
        $this->fakeYooKassa('yk_p1', '519.00', ['status' => 'succeeded', 'paid' => true]);

        $res = $this->as(200)->postJson('/api/payments/item', ['item_id' => 5, 'promo' => 'spring20'])->assertCreated()
            ->assertJsonPath('data.amount', 519)->assertJsonPath('data.price', 649)->assertJsonPath('data.promo', 'SPRING20')->assertJsonPath('data.discount', 20);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.yookassa.ru/v3/payments' && $r['amount']['value'] === '519.00'
            && str_contains($r['description'], 'SPRING20') && $r['metadata']['promo'] === 'SPRING20');

        // the bot row says what was really charged; the use is not counted before the money arrives
        $row = DB::table('payments')->find($res->json('data.id'));
        $this->assertSame([519.0, 519.0, 5, 0], [(float) $row->total, (float) $row->rub_total, (int) $row->item_id, (int) $row->was_bought]);
        $this->assertSame(2, (int) DB::table('promos')->where('id', $promoId)->value('uses'));

        $this->postJson('/api/payments/yookassa/webhook', ['event' => 'payment.succeeded', 'object' => ['id' => 'yk_p1']])->assertOk();
        $this->postJson('/api/payments/yookassa/webhook', ['event' => 'payment.succeeded', 'object' => ['id' => 'yk_p1']])->assertOk();

        $this->assertSame(1500, User::find(200)->balance);
        $this->assertSame(1, (int) DB::table('promos')->where('id', $promoId)->value('uses'), 'one use taken from the bot counter');
        $this->assertSame(1, PromoUse::count());
        $this->assertEquals(130, PromoUse::first()->discount_rub);
        $this->assertEquals(519, DB::table('users')->where('id', 200)->value('paid_money'));
        $this->assertSame(103, (int) $inviter->refresh()->ref_balance, '20 % of the 519 rubles paid');

        // per_user = 1: the same person cannot use it again
        $this->as(200)->postJson('/api/payments/item', ['item_id' => 5, 'promo' => 'spring20'])->assertStatus(422)->assertJsonPath('code', 'promo_user_limit');
        // a code for other items is refused at payment, not only at "Применить"
        $this->promo(['code' => 'PROONLY', 'item_ids' => [8]]);
        $this->as(200)->postJson('/api/payments/item', ['item_id' => 3, 'promo' => 'proonly'])->assertStatus(422)->assertJsonPath('code', 'promo_not_for_item');

        $this->admin()->getJson('/api/admin/promos')->assertJsonPath('data.1.used', 1)->assertJsonPath('data.1.revenue', 519);
        $this->admin()->getJson('/api/admin/payments?user=SPRING20')->assertOk()->assertJsonPath('data.0.promo', 'SPRING20')
            ->assertJsonPath('summary.with_promo', 1);
    }

    public function test_stars_price_is_counted_from_the_discounted_rubles(): void
    {
        $this->user(300, 0);
        $this->promo(['code' => 'HALF', 'discount' => 50, 'uses' => null, 'per_user' => null]);
        $this->fakeYooKassa('unused', '1');

        // 749 ₽ -50 % = 375 ₽ (rounded), at 2 ₽ per star = 188 ⭐
        $p = $this->as(300)->postJson('/api/payments/item', ['item_id' => 8, 'method' => 'stars', 'promo' => 'half'])->assertCreated()
            ->assertJsonPath('data.amount', 188)->assertJsonPath('data.currency', 'XTR')->json('data');
        $payment = Payment::find($p['id']);
        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'whsecret'])->postJson('/api/telegram/webhook', ['update_id' => 3, 'message' => [
            'message_id' => 1, 'chat' => ['id' => 300, 'type' => 'private'], 'from' => ['id' => 300],
            'successful_payment' => ['currency' => 'XTR', 'total_amount' => 188, 'invoice_payload' => 'pay:'.$payment->id.':'.$payment->idempotence_key, 'telegram_payment_charge_id' => 'ch_h'],
        ]])->assertOk();
        $this->assertSame('pro', User::find(300)->activePlan());
        $this->assertSame(-1, (int) DB::table('promos')->where('name', 'HALF')->value('uses'), 'unlimited stays unlimited');
        $this->assertSame(1, PromoUse::count());
    }

    public function test_the_payment_link_shows_the_item_without_sign_in(): void
    {
        $this->getJson('/api/pay/5')->assertOk()
            ->assertJsonPath('data.item_id', 5)->assertJsonPath('data.name', '1500 токенов')->assertJsonPath('data.kind', 'tokens')
            ->assertJsonPath('data.price', 649)->assertJsonPath('data.stars', 325)->assertJsonPath('data.available', true)
            ->assertJsonPath('data.pay_link', 'https://app.test/pay/5')
            ->assertJsonPath('data.telegram_link', 'https://t.me/neuroai_gptbot/app?startapp=pay-5');
        $this->getJson('/api/pay/8')->assertOk()->assertJsonPath('data.kind', 'plan')->assertJsonPath('data.days', 30);
        $this->getJson('/api/pay/9')->assertOk()->assertJsonPath('data.available', false);
        $this->getJson('/api/pay/404')->assertNotFound();

        $this->admin()->getJson('/api/admin/shop')->assertOk()->assertJsonPath('data.0.pay_link', fn ($v) => str_starts_with($v, 'https://app.test/pay/'));
        $this->artisan('telegram:links')->expectsOutputToContain('https://app.test/pay/5')->assertSuccessful();
    }

    public function test_stars_rate_from_the_panel_changes_the_prices(): void
    {
        $this->admin()->putJson('/api/admin/settings', ['values' => ['stars' => ['enabled' => true, 'rub_per_star' => 1.5]]])->assertOk()
            ->assertJsonPath('data.stars.rub_per_star', 1.5);
        $boot = $this->as(400)->getJson('/api/bootstrap')->assertOk()->assertJsonPath('stars_rate', 1.5);
        $this->assertSame(433, collect($boot->json('packs'))->firstWhere('item_id', 5)['stars'], '649 / 1.5 rounded up');
        $this->admin()->putJson('/api/admin/settings', ['values' => ['stars' => ['enabled' => true, 'rub_per_star' => 0]]])->assertStatus(422);
    }

    public function test_admin_list_shows_payments_made_by_the_bot(): void
    {
        $this->user(500, 0);
        Payment::create(['payment_id' => 'bot-yk-1', 'is_autopayment' => false, 'was_bought' => true, 'is_proceed' => true, 'total' => 249,
            'total_valut' => 'RUB', 'rub_total' => 249, 'item_id' => 3, 'user_id' => 500, 'recipient_id' => 500]);
        Payment::create(['payment_id' => 'bot-yk-2', 'is_autopayment' => false, 'was_bought' => false, 'is_proceed' => false, 'total' => 749,
            'total_valut' => 'RUB', 'rub_total' => 749, 'item_id' => 8, 'user_id' => 500, 'recipient_id' => 500]);

        $r = $this->admin()->getJson('/api/admin/payments')->assertOk()
            ->assertJsonPath('summary.total', 2)->assertJsonPath('summary.succeeded', 1)->assertJsonPath('summary.pending', 1)
            ->assertJsonPath('summary.tokens', 500)->assertJsonPath('summary.rub', 249);
        $this->assertSame([true, 'plan', 'Neuro PRO'], [$r->json('data.0.from_bot'), $r->json('data.0.purpose'), $r->json('data.0.item.name')]);
        $this->admin()->getJson('/api/admin/payments?purpose=topup')->assertJsonPath('summary.total', 1);
        $this->admin()->getJson('/api/admin/payments?status=pending')->assertJsonPath('data.0.provider_payment_id', 'bot-yk-2');

        // the app never closes or re-checks a pending payment of the bot
        $this->travel(2)->days();
        $this->artisan('payments:expire')->assertSuccessful();
        $this->assertSame(0, (int) DB::table('payments')->where('payment_id', 'bot-yk-2')->value('is_proceed'));
    }

    public function test_old_app_payments_are_moved_into_the_bot_table(): void
    {
        $this->user(600, 0);
        $now = now();
        $old = fn (array $a) => DB::table('wa_payments')->insertGetId($a + ['user_id' => 600, 'provider' => 'yookassa', 'purpose' => 'topup',
            'tokens' => 500, 'amount' => 249, 'rub_amount' => 249, 'currency' => 'RUB', 'created_at' => $now, 'updated_at' => $now]);
        // already mirrored before (paid), pending, and a paid one from a config pack that was never mirrored
        $mirrored = DB::table('payments')->insertGetId(['payment_id' => 'yk-old-1', 'is_autopayment' => false, 'was_bought' => true, 'is_proceed' => true,
            'total' => 249, 'total_valut' => 'RUB', 'rub_total' => 249, 'item_id' => 3, 'user_id' => 600, 'recipient_id' => 600, 'created_at' => $now, 'modified_at' => $now]);
        $a = $old(['provider_payment_id' => 'yk-old-1', 'idempotence_key' => 'k-1', 'status' => 'succeeded', 'credited_at' => $now, 'bot_payment_id' => $mirrored,
            'shop_item_id' => 3, 'referrer_id' => 100, 'ref_amount' => 49]);
        $b = $old(['provider_payment_id' => 'yk-old-2', 'idempotence_key' => 'k-2', 'status' => 'pending', 'shop_item_id' => 3, 'confirmation_url' => 'https://yoomoney.ru/x']);
        $c = $old(['provider_payment_id' => null, 'idempotence_key' => 'k-3', 'status' => 'succeeded', 'credited_at' => $now, 'tokens' => 777, 'amount' => 700, 'rub_amount' => 700]);
        DB::table('wa_transactions')->insert(['user_id' => 600, 'type' => 'topup', 'amount' => 777, 'balance_after' => 777, 'title' => 'Пополнение',
            'payment_id' => $c, 'created_at' => $now, 'updated_at' => $now]);

        $migration = require database_path('migrations/2026_11_15_000005_payments_in_bot_table.php');
        $migration->up();
        $migration->up(); // safe to run twice

        $this->assertSame(3, DB::table('payments')->count());
        $this->assertSame(3, DB::table('wa_payment_details')->count());
        $this->assertSame(49, (int) DB::table('wa_payment_details')->where('payment_id', $mirrored)->value('ref_amount'));
        $pending = Payment::where('payment_id', 'yk-old-2')->firstOrFail();
        $this->assertSame(['pending', 'yookassa', 'https://yoomoney.ru/x'], [$pending->status, $pending->provider, $pending->confirmation_url]);
        $paid = Payment::where('payment_id', 'k-3')->firstOrFail();
        $this->assertSame(['succeeded', 777], [$paid->status, $paid->tokens]);
        $this->assertSame(['token', 777, false], [$paid->item->category, (int) $paid->item->amount, (bool) $paid->item->active], 'hidden shop row for a config pack');
        $this->assertEquals(700, DB::table('users')->where('id', 600)->value('paid_money'), 'only the never mirrored payment adds to paid_money');
        $this->assertSame($paid->id, (int) DB::table('wa_transactions')->where('user_id', 600)->where('type', 'topup')->value('payment_id'));
        $this->assertSame(3, DB::table('wa_payments')->count(), 'the old table stays as an archive');

        // the moved pending payment is still finished by the YooKassa webhook
        $this->fakeYooKassa('yk-old-2', '249.00', ['status' => 'succeeded', 'paid' => true]);
        $this->postJson('/api/payments/yookassa/webhook', ['event' => 'payment.succeeded', 'object' => ['id' => 'yk-old-2']])->assertOk();
        $this->assertSame('succeeded', $pending->refresh()->status);
        $this->assertSame(500, User::find(600)->balance);
        $this->artisan('neurogpt:verify-sync')->expectsOutputToContain('payment states match the bot flags');
        unset($a, $b);
    }
}
