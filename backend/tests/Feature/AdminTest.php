<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Chat;
use App\Models\Generation;
use App\Models\News;
use App\Models\Photoset;
use App\Models\User;
use App\Services\Admin\Settings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminTest extends TestCase
{
    private const ADMIN = 1337592809;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    }

    private function admin(): static
    {
        return $this->as(self::ADMIN);
    }

    public function test_every_admin_route_is_closed_to_other_users(): void
    {
        $this->user(1);
        $routes = collect(app('router')->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/admin'));
        $this->assertGreaterThan(25, $routes->count());

        foreach ($routes as $route) {
            $uri = '/'.preg_replace('/\{[A-Za-z]+\}/', '1', $route->uri());
            $method = $route->methods()[0];
            $this->as(1)->json($method, $uri)->assertStatus(403);
            $this->flushHeaders()->json($method, $uri, [], ['Accept' => 'application/json'])->assertStatus(401);
        }

        $this->as(1)->getJson('/api/me')->assertJsonPath('is_admin', false);
        $this->admin()->getJson('/api/me')->assertJsonPath('is_admin', true);
        $this->admin()->getJson('/api/admin/stats')->assertOk()->assertJsonPath('users.total', 2);
    }

    public function test_model_is_edited_created_deleted_and_survives_the_seeder(): void
    {
        $model = AiModel::where('kind', 'image')->where('slug', 'detail')->firstOrFail();
        $this->admin()->patchJson("/api/admin/models/{$model->id}", ['name' => 'Супер', 'price' => 77, 'min_plan' => 'pro', 'badge' => 'Новая'])
            ->assertOk()->assertJsonPath('data.price', 77)->assertJsonPath('data.edited', true);

        $video = AiModel::where('kind', 'video')->where('slug', 'kling')->firstOrFail();
        $this->admin()->patchJson("/api/admin/models/{$video->id}", ['options' => ['durations' => ['4' => 1, '12' => 2.5], 'formats' => ['16:9'], 'family' => 'kling']])
            ->assertOk()->assertJsonPath('data.options.durations.12', 2.5);
        $this->admin()->patchJson("/api/admin/models/{$video->id}", ['options' => ['durations' => ['0' => 1]]])->assertStatus(422);

        $this->admin()->postJson('/api/admin/models', ['kind' => 'video', 'slug' => 'mine', 'name' => 'Моя', 'price' => 9, 'driver' => 'fake'])->assertCreated();
        $this->admin()->postJson('/api/admin/models', ['kind' => 'video', 'slug' => 'mine', 'name' => 'Моя', 'price' => 9])->assertStatus(422);

        $quick = AiModel::where('kind', 'image')->where('slug', 'quick')->firstOrFail();
        $this->admin()->deleteJson("/api/admin/models/{$quick->id}")->assertOk();

        $this->seed(DatabaseSeeder::class);
        $this->assertSame(77, $model->refresh()->price);
        $this->assertSame('Супер', $model->name);
        $this->assertTrue(AiModel::where('kind', 'video')->where('slug', 'mine')->value('is_active') == true);
        $this->assertFalse(AiModel::where('kind', 'image')->where('slug', 'quick')->exists());

        // the app sees the new price and the user is charged with it
        $boot = $this->admin()->getJson('/api/bootstrap')->assertOk();
        $this->assertSame(77, collect($boot->json('models.image'))->firstWhere('id', 'detail')['price']);
        $this->assertSame([4, 12], array_column(collect($boot->json('models.video'))->firstWhere('id', 'kling')['durations'], 'seconds'));
        $this->user(5, 100, 'pro');
        $this->as(5)->postJson('/api/generations', ['kind' => 'image', 'prompt' => 'кот', 'model' => 'detail'])->assertCreated()->assertJsonPath('user.balance', 23);
    }

    public function test_settings_change_prices_bonuses_and_reload_from_the_database(): void
    {
        $this->admin()->putJson('/api/admin/settings', ['values' => [
            'packs' => [['tokens' => 300, 'price' => 250, 'badge' => 'Хит'], ['tokens' => 50, 'price' => 60]],
            'plans' => ['plus' => ['name' => 'Плюс', 'month' => 900, 'year_month' => 600, 'tokens' => 1200], 'pro' => ['name' => 'Pro', 'month' => 1980, 'year_month' => 1380, 'tokens' => 3000]],
            'bonus' => ['signup' => 70, 'daily' => 9],
            'stars' => ['rub_per_star' => 2.5],
            'limits' => ['active_generations' => 2],
            'telegram.support_url' => 'https://t.me/help_me',
        ]])->assertOk()->assertJsonPath('data.bonus.referral', 50)->assertJsonPath('data.bonus.signup', 70);

        $boot = $this->as(7)->getJson('/api/bootstrap')->assertOk()
            ->assertJsonPath('user.balance', 70)->assertJsonPath('packs.0.tokens', 50)->assertJsonPath('packs.1.stars', 100)
            ->assertJsonPath('plans.plus.name', 'Плюс')->assertJsonPath('support_url', 'https://t.me/help_me');
        $this->assertSame(0, $boot->json('plans.free.month'));
        $this->as(7)->postJson('/api/payments/topup', ['tokens' => 100, 'method' => 'stars'])->assertStatus(422);

        // a fresh process reads the same values back from the table
        config(['neurogpt.bonus.signup' => 50, 'neurogpt.packs' => []]);
        Settings::apply();
        $this->assertSame(70, config('neurogpt.bonus.signup'));
        $this->assertSame(5, (int) config('neurogpt.bonus.referral') / 10);
        $this->assertCount(2, config('neurogpt.packs'));
        $this->assertSame(1200, (int) config('neurogpt.limits.generation_timeout'));

        foreach ([
            ['packs' => []], ['bonus' => ['signup' => -1]], ['bonus' => ['hack' => 1]], ['admin_ids' => [1]],
            ['telegram.bot_token' => 'x'], ['stars' => ['rub_per_star' => 0]], ['plans' => ['plus' => ['name' => 'x']]],
        ] as $bad) {
            $this->admin()->putJson('/api/admin/settings', ['values' => $bad])->assertStatus(422);
        }
        $this->assertSame([1337592809], config('neurogpt.admin_ids'));
    }

    public function test_theme_is_validated_and_public(): void
    {
        $this->admin()->putJson('/api/admin/settings', ['values' => ['theme' => ['dark' => ['--color-accent' => '#3399ff'], 'light' => []]]])
            ->assertOk()->assertJsonPath('data.theme.dark.--color-accent', '#3399ff');
        $this->flushHeaders()->getJson('/api/config')->assertOk()->assertJsonPath('theme.dark.--color-accent', '#3399ff')->assertJsonPath('bot_username', 'neuroai_gptbot');
        $this->as(3)->getJson('/api/bootstrap')->assertJsonPath('theme.dark.--color-accent', '#3399ff');

        foreach ([['dark' => ['--color-accent' => 'red;} body{display:none']], ['dark' => ['--evil' => '#000000']], ['dark' => ['--color-accent' => 'url(x)']]] as $bad) {
            $this->admin()->putJson('/api/admin/settings', ['values' => ['theme' => $bad]])->assertStatus(422);
        }
    }

    public function test_tool_text_price_and_switch_are_edited(): void
    {
        $tool = $this->admin()->getJson('/api/admin/tools')->assertOk()->json('data.0');
        $this->assertSame('upscale', $tool['slug']);
        $this->admin()->putJson('/api/admin/tools/retouch', [
            'name' => 'Ретушь+', 'hint' => 'Новая', 'cost' => 21, 'group' => 'fix', 'input' => 'none', 'prompt' => 'Retouch gently.', 'is_active' => true,
        ])->assertOk()->assertJsonPath('data.cost', 21);
        $this->admin()->postJson('/api/admin/tools', [
            'slug' => 'cartoon', 'name' => 'Мульт', 'cost' => 4, 'group' => 'create', 'input' => 'choice', 'prompt' => 'Make it {option}.',
            'choices' => [['id' => 'a', 'name' => 'А', 'prompt' => 'style a']],
        ])->assertCreated();
        $this->admin()->deleteJson('/api/admin/tools/sticker')->assertOk();
        $this->seed(DatabaseSeeder::class);

        $tools = collect($this->as(2)->getJson('/api/bootstrap')->json('tools'))->keyBy('id');
        $this->assertSame(21, $tools['retouch']['cost']);
        $this->assertSame('Ретушь+', $tools['retouch']['name']);
        $this->assertSame('А', $tools['cartoon']['options'][0]['name']);
        $this->assertFalse($tools->has('sticker'));

        $this->user(2, 30);
        $file = UploadedFile::fake()->image('me.jpg', 300, 300);
        $this->as(2)->post('/api/generations', ['kind' => 'tool', 'tool' => 'retouch', 'file' => $file])->assertCreated()->assertJsonPath('user.balance', 9);
        $this->as(2)->post('/api/generations', ['kind' => 'tool', 'tool' => 'cartoon', 'option' => 'a', 'file' => $file])->assertCreated()->assertJsonPath('user.balance', 5);
        $this->as(2)->post('/api/generations', ['kind' => 'tool', 'tool' => 'sticker', 'file' => $file])->assertStatus(422);
    }

    public function test_photosets_news_and_cover_upload(): void
    {
        $url = $this->admin()->post('/api/admin/upload', ['file' => UploadedFile::fake()->image('c.png', 200, 200)])->assertCreated()->json('url');
        Storage::disk('public')->assertExists(substr($url, 9));

        $id = $this->admin()->postJson('/api/admin/photosets', ['name' => 'Мой сет', 'prompt' => 'Draw.', 'cover_url' => $url, 'is_trending' => true])->assertCreated()->json('data.id');
        $this->admin()->patchJson("/api/admin/photosets/$id", ['description' => 'Описание', 'cover_url' => 'javascript:alert(1)'])->assertStatus(422);
        $this->admin()->patchJson("/api/admin/photosets/$id", ['description' => 'Описание'])->assertOk();
        $first = Photoset::orderBy('id')->first();
        $this->admin()->deleteJson("/api/admin/photosets/{$first->id}")->assertOk();

        $nid = $this->admin()->postJson('/api/admin/news', ['title' => 'Акция', 'subtitle' => 'Скидки', 'style' => 'gold', 'action' => 'topup'])->assertCreated()->json('data.id');
        $this->admin()->patchJson("/api/admin/news/$nid", ['is_active' => false])->assertOk();

        $this->seed(DatabaseSeeder::class);
        $this->assertTrue(Photoset::find($id)->is_active);
        $this->assertNull(Photoset::find($first->id));
        $this->assertFalse(News::find($nid)->is_active);
        $this->assertSame('Мой сет', collect($this->as(4)->getJson('/api/bootstrap')->json('photosets'))->last()['name']);
    }

    public function test_admin_sees_all_generations_with_prompts_and_filters(): void
    {
        $this->user(1, 100);
        $this->user(2, 100);
        $this->as(1)->postJson('/api/generations', ['kind' => 'image', 'prompt' => 'рыжий кот'])->assertCreated();
        $this->as(2)->post('/api/generations', ['kind' => 'image', 'prompt' => 'собака на луне', 'file' => UploadedFile::fake()->image('me.jpg', 300, 300)])->assertCreated();
        $this->as(2)->postJson('/api/generations', ['kind' => 'video', 'prompt' => 'море', 'model' => 'seedance-mini'])->assertCreated();
        $old = Generation::where('prompt', 'рыжий кот')->firstOrFail();
        $old->forceFill(['created_at' => now()->subDays(40)])->save();

        $all = $this->admin()->getJson('/api/admin/generations')->assertOk()->assertJsonPath('summary.total', 3);
        $this->assertSame('море', $all->json('data.0.prompt'));
        $this->assertSame('nik2', $all->json('data.0.user.username'));
        $this->assertNotNull($all->json('data.1.result_url'));
        $this->assertFalse($all->json('data.1.has_input')); // uploads are removed once the job is done

        $this->admin()->getJson('/api/admin/generations?kind=video')->assertJsonPath('summary.total', 1);
        $this->admin()->getJson('/api/admin/generations?q='.urlencode('кот'))->assertJsonPath('summary.total', 1)->assertJsonPath('data.0.user.telegram_id', 1);
        $this->admin()->getJson('/api/admin/generations?user=nik2')->assertJsonPath('summary.total', 2);
        $this->admin()->getJson('/api/admin/generations?user=1')->assertJsonPath('summary.total', 1);
        $from = urlencode(now()->subDays(7)->toIso8601String());
        $this->admin()->getJson("/api/admin/generations?from=$from")->assertJsonPath('summary.total', 2);
        $to = urlencode(now()->subDays(30)->toIso8601String());
        $this->admin()->getJson("/api/admin/generations?to=$to")->assertJsonPath('summary.total', 1)->assertJsonPath('data.0.prompt', 'рыжий кот');

        $withInput = Generation::where('prompt', 'собака на луне')->firstOrFail();
        $this->admin()->get("/api/admin/generations/{$withInput->id}/input")->assertStatus(404);
        Storage::disk('local')->put($withInput->input_path, UploadedFile::fake()->image('x.jpg', 80, 80)->getContent());
        $this->admin()->get("/api/admin/generations/{$withInput->id}/input")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->as(1)->get("/api/admin/generations/{$withInput->id}/input")->assertStatus(403);

        $this->admin()->deleteJson("/api/admin/generations/{$withInput->id}")->assertOk();
        Storage::disk('public')->assertMissing($withInput->result_path);
        Storage::disk('local')->assertMissing($withInput->input_path);
        $this->assertNull(Generation::find($withInput->id));

        $chat = Chat::create(['user_id' => 2, 'title' => 'x']);
        $chat->messages()->create(['role' => 'user', 'content' => 'Привет, как дела?', 'cost' => 0]);
        $chat->messages()->create(['role' => 'assistant', 'content' => 'Отлично', 'cost' => 1]);
        $this->admin()->getJson('/api/admin/messages')->assertOk()->assertJsonPath('summary.total', 2)->assertJsonPath('data.0.content', 'Отлично')->assertJsonPath('data.0.user.telegram_id', 2);
        $this->admin()->getJson('/api/admin/messages?q='.urlencode('Привет'))->assertJsonPath('summary.total', 1);
    }

    public function test_users_balance_plan_block_and_payments(): void
    {
        $user = $this->user(9, 10);
        $this->admin()->getJson('/api/admin/users?q=9')->assertOk()->assertJsonPath('summary.total', 1)->assertJsonPath('data.0.balance', 10);
        $this->admin()->postJson("/api/admin/users/{$user->id}/balance", ['amount' => 500, 'comment' => 'Подарок'])->assertOk()->assertJsonPath('data.balance', 510);
        $this->admin()->postJson("/api/admin/users/{$user->id}/balance", ['amount' => -10])->assertOk()->assertJsonPath('data.balance', 500);
        $this->admin()->postJson("/api/admin/users/{$user->id}/balance", ['amount' => -9999])->assertStatus(422);
        $this->assertSame(500, $user->refresh()->balance);
        $this->assertSame(2, $user->transactions()->whereIn('title', ['Начисление от администрации', 'Списание администрацией'])->count());

        $this->admin()->patchJson("/api/admin/users/{$user->id}", ['plan' => 'pro', 'plan_days' => 10])->assertOk()->assertJsonPath('data.plan', 'pro');
        $this->admin()->patchJson("/api/admin/users/{$user->id}", ['is_blocked' => true])->assertOk();
        $this->as(9)->getJson('/api/me')->assertStatus(403);

        $me = User::whereKey(self::ADMIN)->firstOrFail();
        $this->admin()->patchJson("/api/admin/users/{$me->id}", ['is_blocked' => true])->assertStatus(422);
        $this->admin()->getJson('/api/admin/payments')->assertOk()->assertJsonCount(0, 'data');
        $this->admin()->getJson('/api/admin/users?filter=blocked')->assertJsonPath('summary.total', 1);
    }

    public function test_main_admin_adds_and_removes_admins(): void
    {
        $this->user(42);
        $this->as(42)->getJson('/api/admin/stats')->assertStatus(403);

        $this->admin()->postJson('/api/admin/admins', ['user' => '@nik42'])->assertCreated()->assertJsonCount(2, 'data')->assertJsonPath('data.1.username', 'nik42');
        $this->admin()->postJson('/api/admin/admins', ['user' => '42'])->assertStatus(422);
        $this->admin()->postJson('/api/admin/admins', ['user' => '@nobody'])->assertStatus(422);
        $this->admin()->postJson('/api/admin/admins', ['user' => '777'])->assertCreated()->assertJsonPath('data.2.opened_app', false);

        $this->as(42)->getJson('/api/me')->assertJsonPath('is_admin', true)->assertJsonPath('is_owner', false);
        $this->as(42)->getJson('/api/admin/stats')->assertOk();
        $this->as(42)->getJson('/api/admin/admins')->assertOk()->assertJsonPath('can_manage', false)->assertJsonPath('data.0.is_owner', true);
        // an added admin cannot promote, demote, or change the list through settings
        $this->as(42)->postJson('/api/admin/admins', ['user' => '555'])->assertStatus(403);
        $this->as(42)->deleteJson('/api/admin/admins/777')->assertStatus(403);
        $this->as(42)->deleteJson('/api/admin/admins/'.self::ADMIN)->assertStatus(403);
        $this->as(42)->putJson('/api/admin/settings', ['values' => ['admins' => [555]]])->assertStatus(422);
        $this->admin()->putJson('/api/admin/settings', ['values' => ['admins' => [555]]])->assertStatus(422);
        $this->assertArrayNotHasKey('admins', $this->admin()->getJson('/api/admin/settings')->json('data'));

        // the list survives a restart, the main admin cannot be removed
        config(['neurogpt.admins' => []]);
        Settings::apply();
        $this->assertSame([42, 777], config('neurogpt.admins'));
        $this->admin()->deleteJson('/api/admin/admins/'.self::ADMIN)->assertStatus(404);
        $this->admin()->deleteJson('/api/admin/admins/42')->assertOk()->assertJsonCount(2, 'data');
        $this->as(42)->getJson('/api/admin/stats')->assertStatus(403);
        $this->as(42)->getJson('/api/me')->assertJsonPath('is_admin', false);
    }

    public function test_photoset_example_is_generated_free_and_becomes_the_cover(): void
    {
        $admin = $this->user(self::ADMIN, 0);
        $set = Photoset::orderBy('id')->firstOrFail();
        $this->assertNull($set->cover_url);

        // without a photo, free of charge even with an empty balance
        $id = $this->admin()->postJson("/api/admin/photosets/{$set->id}/example")->assertCreated()->assertJsonPath('data.cost', 0)->json('data.id');
        $g = Generation::findOrFail($id);
        $this->assertSame('done', $g->status);
        $this->assertSame(0, $admin->refresh()->balance);
        $this->assertSame(0, $admin->transactions()->where('generation_id', $id)->count());
        $this->as(self::ADMIN)->post("/api/admin/photosets/{$set->id}/example", ['file' => UploadedFile::fake()->image('me.jpg', 300, 300)])->assertCreated();

        $url = $this->admin()->postJson("/api/admin/photosets/{$set->id}/cover", ['generation_id' => $id])->assertOk()->json('data.cover_url');
        $this->assertStringStartsWith('/storage/covers/', $url);
        Storage::disk('public')->assertExists(substr($url, 9));
        $this->admin()->deleteJson("/api/admin/generations/$id")->assertOk();
        Storage::disk('public')->assertExists(substr($url, 9));

        // someone else's picture or another photoset's example cannot be used
        $this->user(2, 100);
        $other = $this->as(2)->post('/api/generations', ['kind' => 'image', 'photoset_id' => $set->id, 'file' => UploadedFile::fake()->image('me.jpg', 300, 300)])->assertCreated()->json('data.0.id');
        $this->admin()->postJson("/api/admin/photosets/{$set->id}/cover", ['generation_id' => $other])->assertStatus(422);
        $this->as(2)->postJson("/api/admin/photosets/{$set->id}/example")->assertStatus(403);

        $this->seed(DatabaseSeeder::class);
        $this->assertSame($url, $set->refresh()->cover_url);
        $this->assertSame($url, collect($this->as(2)->getJson('/api/bootstrap')->json('photosets'))->firstWhere('id', $set->id)['cover_url']);
    }

    public function test_yookassa_is_configured_by_the_main_admin_and_the_secret_stays_on_the_server(): void
    {
        config(['neurogpt.yookassa.shop_id' => null, 'neurogpt.yookassa.secret_key' => null]);
        $this->user(42);
        $this->admin()->postJson('/api/admin/admins', ['user' => '42'])->assertCreated();
        $body = ['shop_id' => '123456', 'secret_key' => 'live_SUPERSECRETKEY1234', 'return_url' => 'https://app.example.com', 'verify_ip' => true,
            'receipt' => ['enabled' => true, 'email' => 'shop@example.com', 'tax_system_code' => 2, 'vat_code' => 1]];

        $this->admin()->getJson('/api/admin/yookassa')->assertOk()->assertJsonPath('data.has_secret', false)->assertJsonPath('data.methods.card', false);
        $this->as(42)->putJson('/api/admin/yookassa', $body)->assertStatus(403);
        $this->as(42)->postJson('/api/admin/yookassa/check')->assertStatus(403);
        $this->admin()->putJson('/api/admin/yookassa', ['receipt' => ['enabled' => true, 'email' => null]] + $body)->assertStatus(422);

        $res = $this->admin()->putJson('/api/admin/yookassa', $body)->assertOk()
            ->assertJsonPath('data.shop_id', '123456')->assertJsonPath('data.has_secret', true)->assertJsonPath('data.secret_hint', '••••1234')
            ->assertJsonPath('data.secret_source', 'admin')->assertJsonPath('data.methods.card', true)->assertJsonPath('data.receipt.tax_system_code', 2);
        $this->assertStringNotContainsString('SUPERSECRET', $res->getContent());
        $this->assertStringNotContainsString('SUPERSECRET', $this->as(42)->getJson('/api/admin/yookassa')->assertOk()->assertJsonPath('can_manage', false)->getContent());
        $this->assertStringNotContainsString('SUPERSECRET', $this->admin()->getJson('/api/admin/settings')->getContent());
        $this->assertStringNotContainsString('SUPERSECRET', (string) \DB::table('wa_settings')->where('key', 'yookassa')->value('value'));
        $this->admin()->putJson('/api/admin/settings', ['values' => ['yookassa' => ['shop_id' => '1']]])->assertStatus(422);

        // an empty secret keeps the saved one; a fresh process reads everything back
        $this->admin()->putJson('/api/admin/yookassa', ['secret_key' => '', 'shop_id' => '654321'] + $body)->assertOk()->assertJsonPath('data.secret_hint', '••••1234');
        config(['neurogpt.yookassa.shop_id' => null, 'neurogpt.yookassa.secret_key' => null]);
        Settings::apply();
        $this->assertSame('654321', config('neurogpt.yookassa.shop_id'));
        $this->assertSame('live_SUPERSECRETKEY1234', config('neurogpt.yookassa.secret_key'));
        $this->assertNotEmpty(config('neurogpt.yookassa.allowed_ips'));

        // payments really go out with these credentials and a receipt
        Http::fake([
            'api.yookassa.ru/v3/me' => Http::response(['account_id' => '654321', 'test' => false, 'status' => 'enabled']),
            'api.yookassa.ru/v3/payments' => Http::response(['id' => 'yk_1', 'status' => 'pending', 'confirmation' => ['confirmation_url' => 'https://yoomoney.ru/pay/1']]),
            'api.yookassa.ru/v3/payments/yk_1' => Http::response(['id' => 'yk_1', 'status' => 'succeeded', 'paid' => true, 'amount' => ['value' => '450.00', 'currency' => 'RUB'],
                'income_amount' => ['value' => '434.25', 'currency' => 'RUB'], 'payment_method' => ['type' => 'bank_card', 'title' => 'Bank card *4444']]),
        ]);
        $this->admin()->postJson('/api/admin/yookassa/check')->assertOk()->assertJsonPath('ok', true)->assertJsonPath('account_id', '654321');
        $this->user(2, 0);
        $pid = $this->as(2)->postJson('/api/payments/topup', ['tokens' => 500, 'method' => 'card'])->assertCreated()->json('data.id');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/payments') && $r->hasHeader('Authorization', 'Basic '.base64_encode('654321:live_SUPERSECRETKEY1234')) && $r['receipt']['customer']['email'] === 'shop@example.com');

        // payment details and a manual status check
        $this->admin()->getJson('/api/admin/payments')->assertOk()->assertJsonPath('summary.total', 1)->assertJsonPath('summary.pending', 1)->assertJsonPath('data.0.provider_payment_id', 'yk_1');
        $this->as(2)->postJson("/api/admin/payments/$pid/sync")->assertStatus(403);
        $this->admin()->postJson("/api/admin/payments/$pid/sync")->assertOk()->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.method_title', 'Bank card *4444')->assertJsonPath('data.income', 434.25);
        $this->assertSame(500, User::find(2)->balance);
        $this->admin()->getJson('/api/admin/payments?status=succeeded&provider=yookassa&user=nik2')->assertJsonPath('summary.succeeded', 1)
            ->assertJsonPath('summary.by_currency.RUB', 450)->assertJsonPath('summary.tokens', 500)->assertJsonPath('summary.payers', 1);
        $this->admin()->getJson('/api/admin/payments?user=yk_1')->assertJsonPath('summary.total', 1);
        $this->admin()->getJson('/api/admin/payments?provider=stars')->assertJsonPath('summary.total', 0);
    }

    public function test_browser_login_widget_works_for_the_admin_only(): void
    {
        $sign = function (int $id, int $age = 0, string $token = self::TOKEN): string {
            $data = ['id' => $id, 'first_name' => 'Ник', 'username' => 'boss', 'auth_date' => time() - $age];
            ksort($data);
            $check = implode("\n", array_map(fn ($k, $v) => "$k=$v", array_keys($data), $data));
            $data['hash'] = hash_hmac('sha256', $check, hash('sha256', $token, true));

            return 'tgl '.base64_encode(json_encode($data));
        };
        $get = fn (string $auth, string $uri = '/api/admin/stats') => $this->flushHeaders()->getJson($uri, ['Authorization' => $auth]);

        $get($sign(self::ADMIN))->assertOk();
        $get($sign(self::ADMIN), '/api/me')->assertOk()->assertJsonPath('is_admin', true)->assertJsonPath('username', 'boss');
        $get($sign(555), '/api/me')->assertStatus(401);
        $this->admin()->postJson('/api/admin/admins', ['user' => '555'])->assertCreated();
        $get($sign(555))->assertOk();
        $get($sign(self::ADMIN, 0, 'other:token'))->assertStatus(401);
        $get($sign(self::ADMIN, 40 * 86400))->assertStatus(401);
        $get('tgl '.base64_encode(json_encode(['id' => self::ADMIN, 'auth_date' => time(), 'hash' => str_repeat('0', 64)])))->assertStatus(401);
        $get('tgl garbage')->assertStatus(401);
    }
}
