<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AnswerController;
use App\Models\AiModel;
use App\Models\Bot\BotConfig;
use App\Models\Bot\QueryLog;
use App\Models\Bot\ShopItem;
use App\Models\Generation;
use App\Models\Payment;
use App\Models\Photoset;
use App\Models\User;
use App\Services\Generation\Tools;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The 2026-11-17 pass over the bot database (models.py + examples.txt): referral tokens at the first purchase,
 * set prices from the bot's recommended model, tool prices and daily tokens in the bot table `config`,
 * shop counts from description and the AI answer page.
 */
class BotSyncV3Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['neurogpt.stars.rub_per_star' => 2.0]);
        Http::fake([
            'api.telegram.org/*/createInvoiceLink' => Http::response(['ok' => true, 'result' => 'https://t.me/$inv']),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);
        BotConfig::flush();
    }

    private function hook(array $update)
    {
        return $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'whsecret'])->postJson('/api/telegram/webhook', $update);
    }

    /** Buys the pack of 500 tokens with Stars through the Telegram webhook, as the user $id. */
    private function buyWithStars(int $id): Payment
    {
        $payment = Payment::find($this->as($id)->postJson('/api/payments/topup', ['tokens' => 500, 'method' => 'stars'])->assertCreated()->json('data.id'));
        $payload = 'pay:'.$payment->id.':'.$payment->idempotence_key;
        $amount = (int) $payment->total;
        $paid = ['message' => ['from' => ['id' => $id], 'chat' => ['id' => $id, 'type' => 'private'], 'successful_payment' => [
            'currency' => 'XTR', 'total_amount' => $amount, 'invoice_payload' => $payload, 'telegram_payment_charge_id' => 'ch_'.$payment->id,
        ]]];
        $this->hook($paid)->assertOk();
        $this->hook($paid)->assertOk(); // Telegram may deliver the same update twice

        return $payment->refresh();
    }

    // ---------- referral tokens ----------

    public function test_referral_tokens_go_to_the_inviter_at_the_first_purchase_not_at_start(): void
    {
        $inviter = $this->user(100, 0);
        // registered by /start in the bot webhook, then the app is opened: no tokens anywhere at start
        $this->hook(['message' => ['from' => ['id' => 300, 'first_name' => 'Друг'], 'chat' => ['id' => 300, 'type' => 'private'], 'text' => '/start friend_100']])->assertOk();
        $this->as(300)->getJson('/api/me')->assertOk()->assertJsonPath('balance', 0);
        $this->assertSame(100, (int) User::find(300)->referal_user_from);
        $this->assertSame(0, $inviter->refresh()->balance);

        $this->assertSame('succeeded', $this->buyWithStars(300)->status);
        $this->assertSame(50, $inviter->refresh()->balance, '50 referral tokens to the inviter');
        $this->assertSame(500, User::find(300)->balance, 'the buyer gets only the pack');
        $this->assertTrue((bool) User::find(300)->ref_paid);
        $this->assertSame(1, DB::table('wa_transactions')->where(['user_id' => 100, 'type' => 'referral'])->count());

        // the second purchase gives nothing more
        $this->buyWithStars(300);
        $this->assertSame(50, $inviter->refresh()->balance);
        $this->assertSame(1000, User::find(300)->balance);
    }

    public function test_referral_tokens_can_go_to_the_friend_and_are_not_paid_twice(): void
    {
        config(['neurogpt.bonus.referral_tokens_to' => 'friend', 'neurogpt.bonus.referral_tokens' => 70]);
        $inviter = $this->user(100, 0);
        $this->as(301, 'friend_100')->getJson('/api/me')->assertOk()->assertJsonPath('balance', 0);
        $this->buyWithStars(301);
        $this->assertSame(570, User::find(301)->balance);
        $this->assertSame(0, $inviter->refresh()->balance);

        // the bot has already paid the referral of this person (ref_paid = 1): the app does not pay again
        $this->as(302, 'friend_100')->getJson('/api/me')->assertOk();
        DB::table('users')->where('id', 302)->update(['ref_paid' => true]);
        $this->buyWithStars(302);
        $this->assertSame(500, User::find(302)->balance);

        // nobody invited: nothing extra
        $this->user(303, 0);
        $this->buyWithStars(303);
        $this->assertSame(500, User::find(303)->balance);
    }

    // ---------- tool prices in the bot table config ----------

    public function test_tool_prices_live_in_the_bot_config_table(): void
    {
        Artisan::call('neurogpt:sync-bot');
        $this->assertSame('10', DB::table('config')->where(['category' => 'tools', 'name' => 'upscale'])->value('value'));
        $this->assertSame(count(config('neurogpt.tools')), DB::table('config')->where('category', 'tools')->count());
        Artisan::call('neurogpt:sync-bot');
        $this->assertSame(count(config('neurogpt.tools')), DB::table('config')->where('category', 'tools')->count(), 'no duplicates');

        // the bot (or anyone) changes the price in `config`: the app follows
        DB::table('config')->where(['category' => 'tools', 'name' => 'retouch'])->update(['value' => '17']);
        BotConfig::flush();
        $this->user(1, 100);
        $tools = collect($this->as(1)->getJson('/api/bootstrap')->assertOk()->json('tools'))->keyBy('id');
        $this->assertSame(17, $tools['retouch']['cost']);
        $this->as(1)->postJson('/api/generations', ['kind' => 'tool', 'tool' => 'retouch'] + ['file' => \Illuminate\Http\UploadedFile::fake()->image('a.jpg', 64, 64)])
            ->assertCreated()->assertJsonPath('user.balance', 83);

        // the admin panel writes the price there too
        $admin = ['Authorization' => 'tma '.$this->initData(1337592809)];
        $this->withHeaders($admin)->getJson('/api/me')->assertOk();
        $tool = collect($this->withHeaders($admin)->getJson('/api/admin/tools')->assertOk()->json('data'))->firstWhere('slug', 'retouch');
        $this->assertSame(17, $tool['cost']);
        $this->withHeaders($admin)->putJson('/api/admin/tools/retouch', array_merge($tool, ['cost' => 21]))->assertOk()->assertJsonPath('data.cost', 21);
        $this->assertSame('21', DB::table('config')->where(['category' => 'tools', 'name' => 'retouch'])->value('value'));
        $this->assertSame(21, Tools::cost('retouch'));
    }

    public function test_daily_tokens_of_the_bot_config_reach_the_plans(): void
    {
        DB::table('config')->insert([
            ['category' => 'daily_tokens', 'name' => 'pro', 'value' => '200', 'created_at' => now(), 'modified_at' => now()],
            ['category' => 'daily_tokens', 'name' => 'free', 'value' => '20', 'created_at' => now(), 'modified_at' => now()],
        ]);
        $this->user(1, 0);
        $boot = $this->as(1)->getJson('/api/bootstrap')->assertOk();
        $this->assertSame([20, null, 200], [$boot->json('plans.free.daily_tokens'), $boot->json('plans.plus.daily_tokens'), $boot->json('plans.pro.daily_tokens')]);
    }

    // ---------- sets cost what their bot model costs ----------

    public function test_a_set_runs_on_its_recommended_bot_model_and_costs_its_price(): void
    {
        // examples.txt: models.name "gemini-nano-banana-2.1", price 30; the prompt recommends it by that name
        $nano = AiModel::create(['kind' => 'image', 'price' => 30, 'bot_name' => 'gemini-nano-banana-2.1', 'name' => 'Nano Banana 2.1', 'slug' => 'nano21', 'is_active' => true]);
        $set = Photoset::create(['name' => 'Ретро', 'prompt' => 'Old photo style.', 'model' => 'gemini-nano-banana-2.1', 'kind' => 'photo']);
        $free = Photoset::create(['name' => 'Без модели', 'prompt' => 'Any.', 'kind' => 'photo']);

        $this->user(1, 100);
        $sets = collect($this->as(1)->getJson('/api/bootstrap')->assertOk()->json('photosets'))->keyBy('id');
        $this->assertSame('nano21', $sets[$set->id]['model']);
        $this->assertNull($sets[$free->id]['model']);

        $file = fn () => \Illuminate\Http\UploadedFile::fake()->image('me.jpg', 64, 64);
        // the client asks for its own model: the set's recommended one is used and charged
        $res = $this->as(1)->postJson('/api/generations', ['kind' => 'image', 'model' => 'auto', 'photoset_id' => $set->id, 'file' => $file()])->assertCreated();
        $this->assertSame(70, $res->json('user.balance'));
        $this->assertSame($nano->id, Generation::latest('id')->first()->ai_model_id);

        // not enough tokens for the recommended model: the user's own model runs instead, as in the bot
        $this->setBalance(User::find(1), 20);
        $auto = AiModel::slugged('image', 'auto');
        $this->as(1)->postJson('/api/generations', ['kind' => 'image', 'model' => 'auto', 'photoset_id' => $set->id, 'file' => $file()])->assertCreated()
            ->assertJsonPath('user.balance', 20 - $auto->price);
        $this->assertSame($auto->id, Generation::latest('id')->first()->ai_model_id);
    }

    // ---------- shop rows ----------

    public function test_shop_description_counts_reach_the_app(): void
    {
        ShopItem::forceCreate(['id' => 3, 'active' => true, 'text_name' => '500 токенов', 'category' => 'token', 'item' => 'tokens_500', 'amount' => 500, 'price' => 249,
            'description' => '{"photo": 15, "video": 0, "emoji": "💫"}', 'tag' => 'Новичок']);
        ShopItem::forceCreate(['id' => 4, 'active' => true, 'text_name' => '800 токенов', 'category' => 'token', 'item' => 'tokens_800', 'amount' => 800, 'price' => 379,
            'description' => '{"photo": 25, "video": 2, "emoji": "🍒"}', 'tag' => 'Базовый']);
        $this->user(1, 0);
        $packs = $this->as(1)->getJson('/api/bootstrap')->assertOk()->json('packs');
        $this->assertSame([[15, 0, '💫'], [25, 2, '🍒']], array_map(fn ($p) => [$p['photo'], $p['video'], $p['emoji']], $packs));
        $this->getJson('/api/pay/3')->assertOk()->assertJsonPath('data.photo', 15)->assertJsonPath('data.video', 0);
    }

    // ---------- the AI answer page ----------

    public function test_answer_page_shows_only_own_answers_or_signed_links(): void
    {
        $this->user(1215122907, 0);
        $this->user(42, 0);
        AiModel::create(['kind' => 'text', 'price' => 2, 'bot_name' => 'claude-sonnet-5.5', 'name' => 'Claude Sonnet 5.5']);
        // a row as in examples.txt
        $log = QueryLog::create(['user_id' => 1215122907, 'model' => 'claude-sonnet-5.5', 'type' => 'text', 'token_used' => 1172,
            'prompt' => 'здарова', 'answer' => "**Привет!** Формула: \$E=mc^2\$\n```php\necho 1;\n```", 'attachments' => null]);

        $this->as(1215122907)->getJson("/api/answers/{$log->id}")->assertOk()
            ->assertJsonPath('data.model', 'Claude Sonnet 5.5')->assertJsonPath('data.prompt', 'здарова')
            ->assertJsonPath('data.answer', "**Привет!** Формула: \$E=mc^2\$\n```php\necho 1;\n```");
        $this->as(42)->getJson("/api/answers/{$log->id}")->assertStatus(404);
        $this->flushHeaders()->getJson("/api/answers/{$log->id}")->assertStatus(401);
        $this->as(1337592809)->getJson("/api/answers/{$log->id}")->assertOk(); // the admin
        $this->as(1215122907)->getJson('/api/answers/999999')->assertStatus(404);

        // the bot's signed link, no sign-in
        $this->flushHeaders();
        $sig = AnswerController::signature($log->id);
        $this->assertSame(substr(hash_hmac('sha256', "answer:{$log->id}", self::TOKEN), 0, 32), $sig);
        $this->getJson("/api/answers/{$log->id}/shared?sig=$sig")->assertOk()->assertJsonPath('data.id', $log->id);
        $this->getJson("/api/answers/{$log->id}/shared?sig=".str_repeat('0', 32))->assertStatus(404);
        $this->getJson("/api/answers/{$log->id}/shared")->assertStatus(404);
        $this->getJson('/api/answers/'.($log->id + 1).'/shared?sig='.$sig)->assertStatus(404);
    }
}
