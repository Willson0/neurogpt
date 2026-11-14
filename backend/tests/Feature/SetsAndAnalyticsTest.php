<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Bot\BotModel;
use App\Models\Bot\BotPrompt;
use App\Models\Event;
use App\Models\Generation;
use App\Models\Photoset;
use App\Services\Bot\BotSync;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Video sets, the admin analytics with button presses, and syncing models with the bot table `models`. */
class SetsAndAnalyticsTest extends TestCase
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

    public function test_video_sets_are_kept_apart_and_make_videos(): void
    {
        $id = $this->admin()->postJson('/api/admin/photosets', ['kind' => 'video', 'name' => 'Полёт', 'prompt' => 'The camera flies around the person.'])
            ->assertCreated()->assertJsonPath('data.kind', 'video')->json('data.id');
        $this->admin()->postJson('/api/admin/photosets', ['kind' => 'gif', 'name' => 'x', 'prompt' => 'y'])->assertStatus(422);
        // without kind a set stays a photo set, as before
        $photo = $this->admin()->postJson('/api/admin/photosets', ['name' => 'Портрет', 'prompt' => 'Portrait.'])->assertCreated()->assertJsonPath('data.kind', 'photo')->json('data.id');

        $kinds = collect($this->admin()->getJson('/api/admin/photosets?kind=video')->assertOk()->json('data'))->pluck('kind')->unique()->all();
        $this->assertSame(['video'], $kinds);

        $boot = collect($this->as(5)->getJson('/api/bootstrap')->assertOk()->json('photosets'));
        $this->assertSame('video', $boot->firstWhere('id', $id)['kind']);
        $this->assertSame('photo', $boot->firstWhere('id', $photo)['kind']);

        // a video set runs as a video, the photo is optional (it becomes the first frame)
        $this->user(5, 1000);
        $video = AiModel::active()->where('kind', 'video')->orderBy('sort')->firstOrFail();
        $g = $this->as(5)->postJson('/api/generations', ['kind' => 'video', 'photoset_id' => $id, 'model' => $video->slug])->assertCreated()->json('data.0');
        $this->assertSame('video', $g['kind']);
        $row = Generation::findOrFail($g['id']);
        $this->assertSame($id, $row->photoset_id);
        $this->assertSame('Видеосет «Полёт»', $row->title());
        $this->assertSame((int) ceil($video->price * ($video->durations()[$row->params['duration']] ?? 1)), $row->cost);

        $this->as(5)->postJson('/api/generations', ['kind' => 'image', 'photoset_id' => $id])->assertStatus(422)->assertJsonPath('errors.kind.0', 'Видеосет создаёт видео');
        $this->as(5)->postJson('/api/generations', ['kind' => 'video', 'photoset_id' => $photo])->assertStatus(422);
        // a photo set still needs the user's photo
        $this->as(5)->postJson('/api/generations', ['kind' => 'image', 'photoset_id' => $photo])->assertStatus(422)->assertJsonPath('errors.file.0', 'Сначала выберите фото');
        $this->as(5)->post('/api/generations', ['kind' => 'image', 'photoset_id' => $photo, 'file' => UploadedFile::fake()->image('me.jpg', 300, 300)], ['Accept' => 'application/json'])->assertCreated();

        // the admin example of a video set is a free video
        $this->user(self::ADMIN, 0);
        $this->admin()->postJson("/api/admin/photosets/$id/example")->assertCreated()->assertJsonPath('data.kind', 'video')->assertJsonPath('data.cost', 0);
    }

    public function test_video_cover_can_be_uploaded(): void
    {
        $file = UploadedFile::fake()->createWithContent('clip.mp4', "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 200));
        $url = $this->admin()->post('/api/admin/upload', ['file' => $file], ['Accept' => 'application/json'])->assertCreated()->json('url');
        $this->assertStringEndsWith('.mp4', $url);
    }

    public function test_bot_prompt_with_a_video_model_becomes_a_video_set(): void
    {
        BotModel::create(['type' => 'video', 'price' => 50, 'name' => 'veo3', 'text_name' => 'Veo 3', 'tag' => '', 'params' => (object) []]);
        $p = BotPrompt::create(['name' => 'Танец', 'prompt' => 'The person dances.', 'model' => 'veo3', 'trend' => false, 'position' => 3]);
        $q = BotPrompt::create(['name' => 'Аниме', 'prompt' => 'Anime style.', 'trend' => false, 'position' => 4]);
        app(BotSync::class)->pullCatalog();

        $this->assertSame('video', Photoset::where('prompt_id', $p->id)->value('kind'));
        $this->assertSame('photo', Photoset::where('prompt_id', $q->id)->value('kind'));
    }

    public function test_button_presses_are_stored_and_counted(): void
    {
        $this->flushHeaders()->postJson('/api/events', ['events' => [['type' => 'click', 'name' => 'x']]])->assertStatus(401);
        $this->as(7)->postJson('/api/events', ['events' => [['type' => 'tap', 'name' => 'x']]])->assertStatus(422);
        $this->as(7)->postJson('/api/events', ['events' => array_fill(0, 51, ['type' => 'click', 'name' => 'x'])])->assertStatus(422);

        $this->as(7)->postJson('/api/events', ['events' => [
            ['type' => 'view', 'name' => 'home', 'screen' => 'home'],
            ['type' => 'click', 'name' => 'Пополнить', 'screen' => 'home', 'ago' => 30],
            ['type' => 'click', 'name' => "  Пополнить\n ", 'screen' => 'home'],
            ['type' => 'click', 'name' => '   ', 'screen' => 'home'],
            ['type' => 'click', 'name' => str_repeat('я', 150), 'screen' => 'topup'],
        ]])->assertOk()->assertJsonPath('saved', 4);
        $this->as(8)->postJson('/api/events', ['events' => [['type' => 'click', 'name' => 'Пополнить', 'screen' => 'home']]])->assertOk();

        $this->assertSame(3, Event::where('name', 'Пополнить')->count());
        $this->assertSame(64, mb_strlen(Event::where('screen', 'topup')->value('name')));
        $this->assertTrue(Event::where('user_id', 7)->orderBy('created_at')->first()->created_at->lt(now()->subSeconds(20)));

        // the bot's own log of button presses is read, never written
        DB::table('logs_table')->insert([
            ['user_id' => 7, 'action' => 'menu_photo', 'params' => null, 'event_type' => 'button', 'created_at' => now(), 'modified_at' => now()],
            ['user_id' => 8, 'action' => 'menu_photo', 'params' => null, 'event_type' => 'button', 'created_at' => now(), 'modified_at' => now()],
        ]);
        $this->user(9, 1000);
        $this->as(9)->postJson('/api/generations', ['kind' => 'image', 'prompt' => 'кот'])->assertCreated();

        $a = $this->admin()->getJson('/api/admin/analytics')->assertOk();
        $top = collect($a->json('clicks.top'))->firstWhere('name', 'Пополнить');
        $this->assertSame(['n' => 3, 'users' => 2, 'screen' => 'home'], ['n' => $top['n'], 'users' => $top['users'], 'screen' => $top['screen']]);
        $this->assertSame(4, $a->json('clicks.total'));
        $this->assertSame('home', $a->json('clicks.screens.0.name'));
        $this->assertSame(['name' => 'menu_photo', 'n' => 2, 'users' => 2], $a->json('bot.actions.0'));
        $this->assertSame(1, $a->json('generations.total'));
        $this->assertGreaterThan(0, $a->json('generations.tokens'));
        $this->assertGreaterThan(0, $a->json('tokens.spent'));
        $this->assertSame('image', $a->json('generations.by_kind.0.key'));
        $this->assertNotEmpty($a->json('generations.models'));
        $this->assertGreaterThanOrEqual(7, count($a->json('series')));
        $today = collect($a->json('series'))->firstWhere('day', now()->toDateString());
        $this->assertSame(1, $today['generations']);
        $this->assertSame(4, $today['clicks']);

        // a period without data is empty, not broken
        $empty = $this->admin()->getJson('/api/admin/analytics?from=2020-01-01T00:00:00Z&to=2020-01-31T23:59:59Z')->assertOk();
        $this->assertSame(0, $empty->json('generations.total'));
        $this->assertCount(31, $empty->json('series'));
        $this->as(7)->getJson('/api/admin/analytics')->assertStatus(403);
    }

    public function test_models_sync_with_the_bot_table_both_ways(): void
    {
        $bot = BotModel::create(['type' => 'text', 'price' => 3, 'name' => 'gpt-5-mini', 'text_name' => 'GPT-5 mini', 'tag' => '', 'params' => (object) []]);
        $r = $this->admin()->postJson('/api/admin/models/sync')->assertOk();
        $this->assertSame(1, $r->json('data.models'));
        $m = AiModel::where('bot_model_id', $bot->id)->firstOrFail();
        $this->assertSame(['text', 'GPT-5 mini', 3], [$m->kind, $m->name, $m->price]);

        // a change made in the bot shows up, a second sync creates nothing new
        DB::table('models')->where('id', $bot->id)->update(['price' => 4]);
        $this->assertSame(0, $this->admin()->postJson('/api/admin/models/sync')->json('data.models'));
        $this->assertSame(4, AiModel::find($m->id)->price);

        // a model created in the panel can be added to the bot table as a new row
        $id = $this->admin()->postJson('/api/admin/models', ['kind' => 'image', 'slug' => 'nano', 'name' => 'Nano', 'price' => 12, 'min_plan' => 'plus',
            'provider_model' => 'nano-banana', 'driver' => 'fake', 'create_in_bot' => true])->assertCreated()->json('data.bot_model_id');
        $row = BotModel::findOrFail($id);
        $this->assertSame(['photo', 'nano-banana', 'Nano', 12, ['plus', 'pro']], [$row->type, $row->name, $row->text_name, $row->price, $row->allowed_subs]);
        $this->admin()->postJson('/api/admin/models', ['kind' => 'song', 'slug' => 's2', 'name' => 'S', 'price' => 1, 'provider_model' => 'x', 'create_in_bot' => true])->assertStatus(422);
        $this->admin()->postJson('/api/admin/models', ['kind' => 'image', 'slug' => 'n2', 'name' => 'S', 'price' => 1, 'create_in_bot' => true])->assertStatus(422);

        // a bot row deleted by the bot owner is shown as missing
        DB::table('models')->where('id', $bot->id)->delete();
        $this->assertSame(1, $this->admin()->postJson('/api/admin/models/sync')->json('data.missing'));
        $listed = collect($this->admin()->getJson('/api/admin/models')->json('data'))->firstWhere('id', $m->id);
        $this->assertTrue($listed['bot_missing']);
    }
}
