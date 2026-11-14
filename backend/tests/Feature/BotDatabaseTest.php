<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Bot\BotModel;
use App\Models\Bot\BotPayment;
use App\Models\Bot\BotPrompt;
use App\Models\Bot\QueryLog;
use App\Models\Bot\ShopItem;
use App\Models\Bot\TextRole;
use App\Models\Generation;
use App\Models\Payment;
use App\Models\Photoset;
use App\Models\User;
use App\Services\Billing\Wallet;
use App\Services\Bot\BotSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** The app on the bot's tables (models.py): users, models, prompts, shop, payments, query_logs. */
class BotDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['neurogpt.stars.rub_per_star' => 2.0]);
        Http::fake([
            'api.telegram.org/*/createInvoiceLink' => Http::response(['ok' => true, 'result' => 'https://t.me/$invoice123']),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
    }

    /** A user the bot created before the app existed: the app must not reset anything of the bot's. */
    private function botUser(int $id, array $columns = []): User
    {
        $user = new User;
        $user->forceFill(User::botDefaults() + BotSync::defaultModelIds() + ['id' => $id, 'username' => 'bot'.$id, 'tokens' => 50]);
        $user->forceFill($columns)->save();

        return User::findOrFail($id);
    }

    public function test_a_new_person_gets_a_complete_bot_row(): void
    {
        $this->as(4242)->getJson('/api/me')->assertOk()->assertJsonPath('balance', 50)->assertJsonPath('telegram_id', 4242);

        $row = (array) DB::table('users')->where('id', 4242)->first();
        // every NOT NULL column of models.py is filled, the balance is the signup bonus in `tokens`
        $this->assertSame(50, (int) $row['tokens']);
        $this->assertSame('free', $row['sub']);
        $this->assertSame('free', $row['autocont_sub']);
        $this->assertEquals(1, $row['save_context']);
        $this->assertEquals(1, $row['auto_continue']);
        $this->assertSame('nik4242', $row['username']);
        foreach (['text_model_id' => 'text', 'photo_model_id' => 'photo', 'video_model_id' => 'video'] as $col => $type) {
            $this->assertSame($type, BotModel::find($row[$col])->type);
        }
        $this->assertSame('Ник', User::find(4242)->first_name); // the profile lives in wa_user_profiles
    }

    public function test_an_existing_bot_user_keeps_his_counters_and_plan(): void
    {
        $until = now()->addDays(5)->getTimestamp();
        $this->botUser(777, ['tokens' => 30, 'sub_tokens' => 200, 'temp_tokens' => 15, 'temp_tokens_until' => now()->addHour()->getTimestamp(),
            'sub' => 'plus', 'end_sub_time' => $until, 'paid_money' => 990.0]);

        $this->as(777)->getJson('/api/me')->assertOk()
            ->assertJsonPath('balance', 245)->assertJsonPath('plan', 'plus')
            ->assertJsonPath('balance_parts', ['temp_tokens' => 15, 'sub_tokens' => 200, 'tokens' => 30]);

        $row = DB::table('users')->where('id', 777)->first();
        $this->assertSame(30, (int) $row->tokens);
        $this->assertSame(990.0, (float) $row->paid_money);
        $this->assertSame($until, (int) $row->end_sub_time);
        $this->assertSame(0, DB::table('wa_transactions')->where('user_id', 777)->count(), 'no signup bonus for a bot user');
    }

    public function test_expired_temp_and_subscription_tokens_do_not_count(): void
    {
        $this->botUser(778, ['tokens' => 10, 'sub_tokens' => 500, 'sub' => 'pro', 'end_sub_time' => now()->subDay()->getTimestamp(),
            'temp_tokens' => 99, 'temp_tokens_until' => now()->subMinute()->getTimestamp()]);

        $this->as(778)->getJson('/api/me')->assertJsonPath('balance', 10)->assertJsonPath('plan', 'free');
    }

    public function test_spending_takes_temp_then_subscription_then_tokens_and_refund_returns_them(): void
    {
        $this->botUser(779, ['tokens' => 100, 'sub_tokens' => 5, 'sub' => 'plus', 'end_sub_time' => now()->addDays(3)->getTimestamp(),
            'temp_tokens' => 3, 'temp_tokens_until' => now()->addHour()->getTimestamp()]);

        // detail image = 12 tokens: 3 temp + 5 sub + 4 tokens
        $id = $this->as(779)->postJson('/api/generations', ['kind' => 'image', 'prompt' => 'кот', 'model' => 'detail'])
            ->assertCreated()->assertJsonPath('user.balance', 96)->json('data.0.id');
        $row = DB::table('users')->where('id', 779)->first();
        $this->assertSame([0, 0, 96], [(int) $row->temp_tokens, (int) $row->sub_tokens, (int) $row->tokens]);

        // it ran (sync queue); a cancel is not possible any more, so fail it by hand and check the refund
        $g = Generation::find($id);
        $this->assertSame('done', $g->status);
        $spend = Wallet::spendOf($id);
        $buckets = $spend->buckets;
        ksort($buckets); // MySQL JSON does not keep key order
        $this->assertSame(['sub_tokens' => -5, 'temp_tokens' => -3, 'tokens' => -4], $buckets);

        app(Wallet::class)->refund(User::find(779), 12, 'Возврат', ['generation_id' => $id], null, $spend);
        $row = DB::table('users')->where('id', 779)->first();
        $this->assertSame([3, 5, 100], [(int) $row->temp_tokens, (int) $row->sub_tokens, (int) $row->tokens]);
    }

    public function test_a_finished_generation_is_logged_for_the_bot(): void
    {
        $this->user(780, 100);
        $this->as(780)->postJson('/api/generations', ['kind' => 'image', 'prompt' => 'закат'])->assertCreated();

        $log = QueryLog::where('user_id', 780)->firstOrFail();
        $this->assertSame('image', $log->type);
        $this->assertSame('закат', $log->prompt);
        $this->assertSame(8, $log->token_used);
        $this->assertStringContainsString('/storage/generations/', (string) $log->answer);
        $this->assertNull($log->attachments);
        $this->assertSame($log->id, (int) Generation::where('user_id', 780)->value('query_log_id'));
    }

    public function test_chat_uses_the_bot_role_and_the_bot_text_model(): void
    {
        $role = TextRole::create(['name' => 'Пират', 'prompt' => 'Отвечай как пират']);
        $smart = AiModel::where('kind', 'text')->where('slug', 'smart')->firstOrFail();
        $this->botUser(781, ['text_role_id' => $role->id, 'text_model_id' => $smart->bot_model_id, 'tokens' => 20]);

        $this->as(781)->postJson('/api/chats/messages', ['message' => 'привет'])->assertOk()
            ->assertJsonPath('message.cost', 3)->assertJsonPath('user.balance', 17);
        $this->assertSame('text', QueryLog::where('user_id', 781)->value('type'));
    }

    public function test_paid_payment_is_mirrored_into_the_bot_tables_once(): void
    {
        $this->user(782, 0);
        $payment = Payment::find($this->as(782)->postJson('/api/payments/plan', ['plan' => 'plus', 'period' => 'month', 'method' => 'stars'])->json('data.id'));
        $msg = ['message_id' => 9, 'chat' => ['id' => 782, 'type' => 'private'], 'from' => ['id' => 782],
            'successful_payment' => ['currency' => 'XTR', 'total_amount' => (int) $payment->amount,
                'invoice_payload' => 'pay:'.$payment->id.':'.$payment->idempotence_key, 'telegram_payment_charge_id' => 'ch_1']];
        $hook = fn () => $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'whsecret'])->postJson('/api/telegram/webhook', ['update_id' => 1, 'message' => $msg]);
        $hook()->assertOk();
        $hook()->assertOk();

        $user = DB::table('users')->where('id', 782)->first();
        $this->assertSame('plus', $user->sub);
        $this->assertGreaterThan(now()->addDays(27)->getTimestamp(), (int) $user->end_sub_time);
        $this->assertSame(1000, (int) $user->sub_tokens, 'plan tokens go to sub_tokens');
        $this->assertSame(780.0, (float) $user->paid_money);

        $this->assertSame(1, BotPayment::count());
        $bp = BotPayment::first();
        $this->assertTrue($bp->was_bought && $bp->is_proceed);
        $this->assertSame('XTR', $bp->total_valut);
        $this->assertSame(780.0, $bp->rub_total);
        $this->assertSame([782, 782], [(int) $bp->user_id, (int) $bp->recipient_id]);
        $item = ShopItem::find($bp->item_id);
        $this->assertSame(['sub', 'plus', 30, false], [$item->category, $item->item, $item->amount, $item->active]);
        $this->assertSame($bp->id, (int) $payment->refresh()->bot_payment_id);
    }

    public function test_an_existing_shop_item_is_reused(): void
    {
        $item = ShopItem::create(['active' => true, 'text_name' => '500 токенов', 'category' => 'token', 'item' => 'tokens_500', 'amount' => 500, 'price' => 450]);
        $user = $this->user(783, 0);
        $p = Payment::create(['user_id' => 783, 'provider' => 'yookassa', 'provider_payment_id' => 'yk-1', 'idempotence_key' => 'k1',
            'purpose' => 'topup', 'tokens' => 500, 'amount' => 450, 'rub_amount' => 450, 'status' => 'succeeded', 'credited_at' => now()]);
        app(BotSync::class)->mirrorPayment($p);

        $this->assertSame($item->id, (int) BotPayment::firstOrFail()->item_id);
        $this->assertSame('yk-1', BotPayment::firstOrFail()->payment_id);
        $this->assertSame(1, ShopItem::count());
    }

    public function test_bot_catalog_is_pulled_and_admin_edits_go_back_to_the_bot(): void
    {
        $bot = BotModel::create(['type' => 'photo', 'can_image' => true, 'price' => 25, 'name' => 'flux-pro', 'text_name' => 'Flux Pro',
            'tag' => '', 'only_sub' => true, 'params' => (object) []]);
        $prompt = BotPrompt::create(['name' => 'Барби', 'prompt' => 'Make a barbie doll', 'trend' => true, 'position' => 1]);
        app(BotSync::class)->pullCatalog();

        $m = AiModel::where('bot_model_id', $bot->id)->firstOrFail();
        // only_sub = any subscription: the app shows it as plus until an admin narrows it to pro
        $this->assertSame(['image', 'Flux Pro', 25, 'plus', true], [$m->kind, $m->name, $m->price, $m->min_plan, $m->is_active]);
        $boot = $this->as(784)->getJson('/api/bootstrap')->assertOk();
        $this->assertSame(25, collect($boot->json('models.image'))->firstWhere('name', 'Flux Pro')['price']);
        $set = collect($boot->json('photosets'))->firstWhere('name', 'Барби');
        $this->assertTrue($set['trending']);

        // the bot owner changes the price in the bot: the app shows it at once
        $bot->update(['price' => 30]);
        $this->assertSame(30, AiModel::find($m->id)->price);

        // an admin edit is written to the bot row
        $this->withHeaders(['Authorization' => 'tma '.$this->initData(1337592809), 'Accept' => 'application/json'])
            ->patchJson("/api/admin/models/{$m->id}", ['price' => 40, 'min_plan' => 'free', 'name' => 'Flux'])->assertOk();
        $bot->refresh();
        $this->assertSame([40, 'Flux', false], [$bot->price, $bot->text_name, $bot->only_sub]);
        // pro is kept on the app side, the bot gets only_sub = true; a later catalog pull does not lose it
        $this->withHeaders(['Authorization' => 'tma '.$this->initData(1337592809), 'Accept' => 'application/json'])
            ->patchJson("/api/admin/models/{$m->id}", ['min_plan' => 'pro'])->assertOk();
        app(BotSync::class)->pullCatalog();
        $this->assertTrue($bot->refresh()->only_sub);
        $this->assertSame('pro', AiModel::find($m->id)->min_plan);

        $ps = Photoset::where('prompt_id', $prompt->id)->firstOrFail();
        $this->withHeaders(['Authorization' => 'tma '.$this->initData(1337592809), 'Accept' => 'application/json'])
            ->patchJson("/api/admin/photosets/{$ps->id}", ['prompt' => 'Make a barbie', 'is_trending' => false])->assertOk();
        $prompt->refresh();
        $this->assertSame(['Make a barbie', false], [$prompt->prompt, $prompt->trend]);

        // pulling again does not duplicate anything
        app(BotSync::class)->pullCatalog();
        $this->assertSame(1, AiModel::where('bot_model_id', $bot->id)->count());
        $this->assertSame(1, Photoset::where('prompt_id', $prompt->id)->count());
    }

    public function test_seeder_never_overwrites_bot_values(): void
    {
        $auto = AiModel::where('kind', 'image')->where('slug', 'auto')->firstOrFail();
        BotModel::whereKey($auto->bot_model_id)->update(['price' => 99]);
        $this->seed();
        $this->assertSame(99, (int) BotModel::find($auto->bot_model_id)->price);
        $this->assertSame(99, AiModel::find($auto->id)->price);
    }

    public function test_referral_from_bot_column_and_app_reward_mark(): void
    {
        $this->user(800);
        $this->as(801, 'friend_800')->getJson('/api/me')->assertOk();
        $this->assertSame(800, (int) DB::table('users')->where('id', 801)->value('referal_user_from'));
        $this->assertEquals(0, DB::table('users')->where('id', 801)->value('ref_paid'), 'ref_paid stays the bot\'s business');
    }

    public function test_destructive_commands_are_refused_outside_tests(): void
    {
        $this->app['env'] = 'production';
        DB::prohibitDestructiveCommands(true);
        $this->artisan('migrate:fresh', ['--force' => true])->assertFailed();
        DB::prohibitDestructiveCommands(false);
        $this->assertTrue(Schema::hasTable('users'));
    }

    public function test_removing_a_bot_model_from_the_app_keeps_it_in_the_bot(): void
    {
        $bot = BotModel::create(['type' => 'photo', 'can_image' => true, 'price' => 5, 'name' => 'sdxl', 'text_name' => 'SDXL', 'tag' => '', 'params' => (object) []]);
        app(BotSync::class)->pullCatalog();
        $m = AiModel::where('bot_model_id', $bot->id)->firstOrFail();

        $this->withHeaders(['Authorization' => 'tma '.$this->initData(1337592809), 'Accept' => 'application/json'])
            ->deleteJson("/api/admin/models/{$m->id}")->assertOk();
        app(BotSync::class)->pullCatalog();

        $this->assertTrue(BotModel::whereKey($bot->id)->exists());
        $this->assertFalse(AiModel::where('bot_model_id', $bot->id)->exists());
    }
}
