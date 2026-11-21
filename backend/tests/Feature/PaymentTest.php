<?php

namespace Tests\Feature;

use App\Models\Bot\BotPayment;
use App\Models\Bot\ShopItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['neurogpt.yookassa.shop_id' => 'shop', 'neurogpt.yookassa.secret_key' => 'secret', 'neurogpt.stars.rub_per_star' => 2.0]);
        foreach ([100 => 100, 500 => 450] as $tokens => $price) {
            ShopItem::create([
                'active' => true, 'text_name' => "$tokens токенов", 'category' => 'token',
                'item' => "tokens_$tokens", 'amount' => $tokens, 'price' => $price,
            ]);
        }
    }

    private function hook(array $update, string $secret = 'whsecret')
    {
        return $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => $secret])->postJson('/api/telegram/webhook', $update);
    }

    private function fakeTelegram(): void
    {
        Http::fake([
            'api.telegram.org/*/createInvoiceLink' => Http::response(['ok' => true, 'result' => 'https://t.me/$invoice123']),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);
    }

    public function test_subscription_migration_resumes_with_existing_payment_columns(): void
    {
        $this->fakeTelegram();
        $user = $this->user(1);
        $payment = Payment::create([
            'user_id' => $user->id, 'provider' => 'stars', 'purpose' => 'plan', 'plan' => 'club',
            'amount' => 375, 'currency' => 'XTR', 'status' => 'pending', 'idempotence_key' => (string) Str::uuid(),
            'shop_item_id' => 8, 'plan_days' => 30,
        ]);
        Schema::table('wa_payments', fn (Blueprint $table) => $table->dropColumn('plan_name'));

        $migration = require database_path('migrations/2026_11_22_000002_use_shop_subscription_offers.php');
        $migration->up();
        $payment->refresh()->update(['plan_name' => 'Клуб']);
        $migration->up();

        $payment->refresh();
        $this->assertSame([8, 30, 'Клуб'], [$payment->shop_item_id, $payment->plan_days, $payment->plan_name]);
    }

    public function test_stars_topup_from_invoice_to_tokens(): void
    {
        $this->fakeTelegram();
        $user = $this->user(1, 0);

        $offer = ShopItem::tokenOffers()->where('amount', 500)->firstOrFail();
        $this->as(1)->postJson('/api/payments/topup', ['offer_id' => $offer->id, 'expected_price' => 451, 'method' => 'stars'])
            ->assertUnprocessable()->assertJsonPath('code', 'token_offer_changed');
        $res = $this->as(1)->postJson('/api/payments/topup', [
            'offer_id' => $offer->id, 'tokens' => 500, 'expected_price' => 450, 'method' => 'stars',
        ])->assertCreated()
            ->assertJsonPath('data.method', 'stars')->assertJsonPath('data.currency', 'XTR')
            ->assertJsonPath('data.amount', 225)->assertJsonPath('data.confirmation_url', 'https://t.me/$invoice123');
        $payment = Payment::find($res->json('data.id'));
        $payload = 'pay:'.$payment->id.':'.$payment->idempotence_key;
        Http::assertSent(fn ($r) => str_contains($r->url(), 'createInvoiceLink') && $r['currency'] === 'XTR' && $r['prices'][0]['amount'] === 225 && $r['payload'] === $payload);

        // Telegram asks before charging.
        $this->hook(['pre_checkout_query' => ['id' => 'q1', 'from' => ['id' => 1], 'currency' => 'XTR', 'total_amount' => 225, 'invoice_payload' => $payload]])->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'answerPreCheckoutQuery') && $r['ok'] === true);

        $paid = ['message' => ['from' => ['id' => 1], 'chat' => ['id' => 1, 'type' => 'private'], 'successful_payment' => [
            'currency' => 'XTR', 'total_amount' => 225, 'invoice_payload' => $payload, 'telegram_payment_charge_id' => 'ch_1',
        ]]];
        $this->hook($paid)->assertOk();
        $this->hook($paid)->assertOk(); // Telegram may deliver the same update twice
        $this->assertSame(500, $user->refresh()->balance);
        $this->assertSame('succeeded', $payment->refresh()->status);
        $this->assertSame($payment->shop_item_id, (int) BotPayment::findOrFail($payment->bot_payment_id)->item_id);
        $this->as(1)->getJson('/api/payments/'.$payment->id)->assertJsonPath('data.status', 'succeeded')->assertJsonPath('user.balance', 500);

        // A paid invoice cannot be paid again.
        $this->hook(['pre_checkout_query' => ['id' => 'q2', 'from' => ['id' => 1], 'currency' => 'XTR', 'total_amount' => 225, 'invoice_payload' => $payload]]);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'answerPreCheckoutQuery') && $r['pre_checkout_query_id'] === 'q2' && $r['ok'] === false);
    }

    public function test_stars_checks_reject_foreign_and_forged_invoices(): void
    {
        $this->fakeTelegram();
        $this->user(1, 0);
        $this->user(2, 0);
        ShopItem::create(['active' => true, 'text_name' => 'Мастерская', 'category' => 'sub', 'item' => 'studio', 'amount' => 30, 'price' => 1980]);
        $payment = Payment::find($this->as(1)->postJson('/api/payments/plan', ['plan' => 'studio', 'period' => 'month', 'method' => 'stars'])->assertCreated()->json('data.id'));
        $payload = 'pay:'.$payment->id.':'.$payment->idempotence_key;
        $refused = fn (string $id) => Http::assertSent(fn ($r) => str_contains($r->url(), 'answerPreCheckoutQuery') && $r['pre_checkout_query_id'] === $id && $r['ok'] === false);

        $query = fn (string $id, array $over) => $this->hook(['pre_checkout_query' => $over + ['id' => $id, 'from' => ['id' => 1], 'currency' => 'XTR', 'total_amount' => 990, 'invoice_payload' => $payload]]);
        $query('a', ['from' => ['id' => 2]]);
        $refused('a');
        $query('b', ['total_amount' => 1]);
        $refused('b');
        $query('c', ['invoice_payload' => 'pay:'.$payment->id.':00000000-0000-0000-0000-000000000000']);
        $refused('c');

        // Without the webhook secret nobody can announce a payment.
        $this->hook(['message' => ['from' => ['id' => 1], 'chat' => ['id' => 1, 'type' => 'private'], 'successful_payment' => [
            'currency' => 'XTR', 'total_amount' => 990, 'invoice_payload' => $payload, 'telegram_payment_charge_id' => 'x',
        ]]], 'wrong')->assertStatus(403);
        $this->assertSame('free', User::whereKey(1)->first()->activePlan());

        // An underpaid "successful" payment is refunded, not fulfilled.
        $this->hook(['message' => ['from' => ['id' => 1], 'chat' => ['id' => 1, 'type' => 'private'], 'successful_payment' => [
            'currency' => 'XTR', 'total_amount' => 1, 'invoice_payload' => $payload, 'telegram_payment_charge_id' => 'ch_low',
        ]]])->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'refundStarPayment') && $r['telegram_payment_charge_id'] === 'ch_low');
        $this->assertSame('free', User::whereKey(1)->first()->activePlan());
        $this->assertSame(0, User::find(1)->balance);
    }

    public function test_subscription_purchases_use_the_shop_price_and_preserve_the_invoiced_duration(): void
    {
        $this->fakeTelegram();
        $user = $this->user(1, 0, 'existing');
        $until = $user->plan_expires_at->copy()->addDays(45);
        $offer = ShopItem::create([
            'active' => true, 'text_name' => 'Клуб', 'category' => 'sub', 'item' => 'club',
            'amount' => 45, 'price' => 749, 'description' => 'Доступ к моделям', 'tag' => 'Выгодно',
        ]);
        ShopItem::create(['active' => false, 'text_name' => 'Бесплатный', 'category' => 'sub', 'item' => 'free', 'amount' => 0, 'price' => 0]);
        $hidden = ShopItem::create(['active' => false, 'text_name' => 'Архив', 'category' => 'sub', 'item' => 'old', 'amount' => 30, 'price' => 100]);
        ShopItem::create(['active' => true, 'text_name' => '500 токенов', 'category' => 'token', 'item' => 'tokens_500', 'amount' => 500, 'price' => 249]);

        $this->as(1)->getJson('/api/bootstrap')->assertJsonCount(1, 'subscription_offers')
            ->assertJsonPath('subscription_offers.0.id', $offer->id)->assertJsonPath('subscription_offers.0.name', 'Клуб')
            ->assertJsonPath('subscription_offers.0.description', 'Доступ к моделям')->assertJsonPath('subscription_offers.0.days', 45)
            ->assertJsonPath('subscription_offers.0.price', 749)->assertJsonPath('subscription_offers.0.stars', 375);
        $this->as(1)->postJson('/api/payments/plan', ['offer_id' => $hidden->id, 'method' => 'stars'])->assertUnprocessable();
        $this->as(1)->postJson('/api/payments/plan', ['offer_id' => $offer->id, 'expected_days' => 30, 'method' => 'stars'])
            ->assertUnprocessable()->assertJsonPath('code', 'subscription_offer_changed');
        $this->as(1)->postJson('/api/payments/plan', ['offer_id' => $offer->id, 'expected_price' => 700, 'method' => 'stars'])
            ->assertUnprocessable()->assertJsonPath('code', 'subscription_offer_changed');
        $this->assertSame(0, Payment::count());

        $payment = Payment::findOrFail($this->as(1)->postJson('/api/payments/plan', [
            'offer_id' => $offer->id, 'expected_price' => 749, 'expected_days' => 45, 'method' => 'stars',
        ])->assertCreated()->assertJsonPath('data.amount', 375)->assertJsonPath('data.plan_days', 45)
            ->assertJsonPath('data.plan_name', 'Клуб')->json('data.id'));
        $offer->update(['active' => false, 'amount' => 60, 'price' => 1000]);
        $paid = ['message' => ['from' => ['id' => 1], 'chat' => ['id' => 1, 'type' => 'private'], 'successful_payment' => [
            'currency' => 'XTR', 'total_amount' => 375, 'invoice_payload' => 'pay:'.$payment->id.':'.$payment->idempotence_key, 'telegram_payment_charge_id' => 'ch_2',
        ]]];
        $this->hook($paid)->assertOk();
        $this->hook($paid)->assertOk();
        $user->refresh();
        $this->assertSame('club', $user->activePlan());
        $this->assertSame(0, $user->balance);
        $this->assertTrue($until->equalTo($user->plan_expires_at));
        $this->assertSame($offer->id, (int) BotPayment::firstOrFail()->item_id);
        $this->as(1)->getJson('/api/me')->assertJsonPath('plan_name', 'Клуб');
    }

    public function test_yookassa_credits_only_a_verified_full_payment(): void
    {
        $remote = ['id' => 'yk_1', 'status' => 'pending', 'paid' => false, 'amount' => ['value' => '450.00', 'currency' => 'RUB'], 'confirmation' => ['confirmation_url' => 'https://yoomoney.ru/pay/1']];
        Http::fake(['api.yookassa.ru/v3/payments' => Http::response($remote), 'api.yookassa.ru/v3/payments/yk_1' => fn () => Http::response($remote)]);
        $user = $this->user(1, 0);

        $id = $this->as(1)->postJson('/api/payments/topup', ['tokens' => 500])->assertCreated()->assertJsonPath('data.confirmation_url', 'https://yoomoney.ru/pay/1')->json('data.id');
        $this->as(1)->getJson("/api/payments/$id")->assertJsonPath('data.status', 'pending');
        $this->user(2);
        $this->as(2)->getJson("/api/payments/$id")->assertStatus(404);

        // A webhook body that claims success changes nothing: the status is re-read from the API.
        config(['neurogpt.yookassa.verify_ip' => false]);
        $this->postJson('/api/payments/yookassa/webhook', ['event' => 'payment.succeeded', 'object' => ['id' => 'yk_1', 'status' => 'succeeded']])->assertOk();
        $this->assertSame(0, $user->refresh()->balance);

        $remote = ['status' => 'succeeded', 'paid' => true, 'amount' => ['value' => '1.00', 'currency' => 'RUB']] + $remote;
        Http::swap(new Factory);
        Http::fake(['api.yookassa.ru/v3/payments/yk_1' => Http::response($remote)]);
        $this->postJson('/api/payments/yookassa/webhook', ['event' => 'payment.succeeded', 'object' => ['id' => 'yk_1']])->assertOk();
        $this->assertSame(0, $user->refresh()->balance, 'a 1 ruble payment does not buy the 450 ruble pack');
        $this->assertSame('canceled', Payment::find($id)->status);
    }

    public function test_yookassa_success_is_credited_once(): void
    {
        $remote = ['id' => 'yk_2', 'status' => 'succeeded', 'paid' => true, 'amount' => ['value' => '100.00', 'currency' => 'RUB'], 'confirmation' => ['confirmation_url' => 'https://yoomoney.ru/pay/2']];
        Http::fake(['api.yookassa.ru/*' => Http::response($remote)]);
        config(['neurogpt.yookassa.verify_ip' => false]);
        $user = $this->user(1, 0);
        $id = $this->as(1)->postJson('/api/payments/topup', ['tokens' => 100, 'method' => 'card'])->json('data.id');

        $this->postJson('/api/payments/yookassa/webhook', ['event' => 'payment.succeeded', 'object' => ['id' => 'yk_2']])->assertOk();
        $this->postJson('/api/payments/yookassa/webhook', ['event' => 'payment.succeeded', 'object' => ['id' => 'yk_2']])->assertOk();
        $this->as(1)->getJson("/api/payments/$id")->assertJsonPath('data.status', 'succeeded');
        $this->assertSame(100, $user->refresh()->balance);
    }

    public function test_yookassa_webhook_is_closed_to_other_addresses(): void
    {
        $this->postJson('/api/payments/yookassa/webhook', ['event' => 'payment.succeeded', 'object' => ['id' => 'x']])->assertStatus(403);
        // A forged X-Forwarded-For from an untrusted client does not help.
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->withHeaders(['X-Forwarded-For' => '185.71.76.1'])
            ->postJson('/api/payments/yookassa/webhook', ['event' => 'payment.succeeded', 'object' => ['id' => 'x']])->assertStatus(403);
        // Behind our own proxy the real client address is used.
        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])->withHeaders(['X-Forwarded-For' => '185.71.76.1'])
            ->postJson('/api/payments/yookassa/webhook', ['event' => 'payment.succeeded', 'object' => ['id' => 'x']])->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])->withHeaders(['X-Forwarded-For' => '185.71.76.1, 9.9.9.9'])
            ->postJson('/api/payments/yookassa/webhook', ['event' => 'payment.succeeded', 'object' => ['id' => 'x']])->assertStatus(403);
    }

    public function test_unconfigured_method_and_unknown_pack_are_refused(): void
    {
        config(['neurogpt.yookassa.shop_id' => null, 'neurogpt.stars.enabled' => false]);
        $this->user(1, 0);
        $this->as(1)->postJson('/api/payments/topup', ['tokens' => 500, 'method' => 'stars'])->assertStatus(502);
        $this->as(1)->postJson('/api/payments/topup', ['tokens' => 500])->assertStatus(502);
        $this->as(1)->postJson('/api/payments/topup', ['tokens' => 999999])->assertStatus(422);
        $this->as(1)->postJson('/api/payments/topup', ['tokens' => 500, 'method' => 'bitcoin'])->assertStatus(422);
        $this->as(1)->getJson('/api/bootstrap')->assertJsonPath('payments.card', false)->assertJsonPath('payments.stars', false);
        $this->assertSame(0, Payment::where('status', 'pending')->count());
    }

    public function test_start_command_registers_the_referral_and_stale_payments_expire(): void
    {
        $this->fakeTelegram();
        $inviter = $this->user(100);
        $this->hook(['message' => ['from' => ['id' => 300, 'first_name' => 'Аня'], 'chat' => ['id' => 300, 'type' => 'private'], 'text' => '/start friend_100']])->assertOk();
        $this->assertSame($inviter->id, User::whereKey(300)->value('referal_user_from'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && isset($r['reply_markup']));

        $id = $this->as(100)->postJson('/api/payments/topup', ['tokens' => 100, 'method' => 'stars'])->json('data.id');
        Payment::whereKey($id)->update(['created_at' => now()->subDays(2)]);
        $this->artisan('payments:expire')->assertSuccessful();
        $this->assertSame('canceled', Payment::find($id)->status);
    }
}
