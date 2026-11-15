<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\ModelMeta;
use App\Models\Photoset;
use App\Models\PhotosetMeta;
use App\Services\Generation\Tools;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Models and photosets live only in the bot tables `models` / `prompts`; old wa_models / wa_photosets rows are moved there. */
class CatalogInBotTablesTest extends TestCase
{
    private const ADMIN = 1337592809;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_11_15_000006_catalog_in_bot_tables.php');
    }

    private function wa(string $table, array $row): int
    {
        return DB::table($table)->insertGetId($row + ['created_at' => now(), 'updated_at' => now()]);
    }

    public function test_old_app_catalog_rows_move_into_the_bot_tables(): void
    {
        $user = $this->user(5, 100);
        $linked = AiModel::slugged('image', 'detail');
        $prompt = Photoset::orderBy('id')->firstOrFail();
        // as before this change: the app's own copies, no meta yet
        ModelMeta::whereKey($linked->id)->delete();
        PhotosetMeta::whereKey($prompt->id)->delete();
        $modelsBefore = DB::table('models')->count();
        $promptsBefore = DB::table('prompts')->count();

        $wa = fn (array $r) => $this->wa('wa_models', $r + ['hint' => null, 'badge' => null, 'is_fast' => false, 'options' => null, 'sort' => 0, 'edited_at' => null]);
        $l = $wa(['bot_model_id' => $linked->id, 'kind' => 'image', 'slug' => 'detail-x', 'name' => 'old copy', 'price' => 1, 'min_plan' => 'pro',
            'hint' => 'Мелкие детали', 'driver' => 'fake', 'provider_model' => 'gpt-image-2', 'options' => '{"quality":"high"}', 'sort' => 7, 'is_active' => true]);
        $a = $wa(['bot_model_id' => null, 'kind' => 'image', 'slug' => 'artsy', 'name' => 'Арт', 'price' => 9, 'min_plan' => 'pro', 'badge' => 'Новая',
            'driver' => 'openai', 'provider_model' => 'gpt-image-1', 'is_active' => true]);
        $off = $wa(['bot_model_id' => null, 'kind' => 'video', 'slug' => 'old-veo', 'name' => 'Old', 'price' => 5, 'min_plan' => 'free',
            'driver' => 'kie', 'provider_model' => 'veo2', 'is_active' => false]);
        $t = $wa(['bot_model_id' => null, 'kind' => 'tool', 'slug' => 'retouch', 'name' => 'Ретушь', 'price' => 10, 'min_plan' => 'free',
            'driver' => 'fake', 'provider_model' => 'x', 'is_active' => false, 'edited_at' => now()]);
        $ps = fn (array $r) => $this->wa('wa_photosets', $r + ['description' => null, 'cover_url' => null, 'seed' => 3, 'palette' => 1, 'trend_hint' => null, 'edited_at' => null, 'kind' => 'photo']);
        $p1 = $ps(['prompt_id' => $prompt->id, 'name' => $prompt->name, 'prompt' => 'old', 'is_trending' => false, 'sort' => 0, 'is_active' => false,
            'description' => 'Описание', 'cover_url' => '/storage/covers/a.png', 'edited_at' => now()]);
        $p2 = $ps(['prompt_id' => null, 'name' => 'Полёт', 'prompt' => 'The camera flies.', 'is_trending' => true, 'sort' => 4, 'is_active' => true, 'kind' => 'video']);

        // old generations point at wa_* ids, which overlap with bot ids
        $g = fn (array $r) => DB::table('wa_generations')->insertGetId($r + ['user_id' => $user->id, 'prompt' => 'x', 'cost' => 1, 'status' => 'done', 'created_at' => now(), 'updated_at' => now()]);
        $g1 = $g(['kind' => 'image', 'ai_model_id' => $l]);
        $g2 = $g(['kind' => 'video', 'ai_model_id' => $a, 'photoset_id' => $p2]);
        $g3 = $g(['kind' => 'video', 'ai_model_id' => $off, 'photoset_id' => $p1]);
        $g4 = $g(['kind' => 'tool', 'tool' => 'retouch', 'ai_model_id' => $t]);

        DB::table('wa_settings')->where('key', 'catalog_moved_at')->delete(); // a database from before this change
        $this->migration()->up();
        $this->migration()->up(); // a second run changes nothing

        // only the app-only model that was in use became a new bot row; the bot row of the linked one is untouched
        $this->assertSame($modelsBefore + 1, DB::table('models')->count());
        $this->assertSame($linked->price, AiModel::find($linked->id)->price);
        $m = AiModel::find($linked->id);
        $this->assertSame(['detail-x', 'Мелкие детали', 'free', 'fake', ['quality' => 'high'], 7], [$m->slug, $m->hint, $m->min_plan, $m->driver, $m->options, $m->sort]);
        // only_sub of the bot row wins: detail is for everybody in the bot, so the old app plan is not applied
        $new = AiModel::slugged('image', 'artsy');
        $row = DB::table('models')->where('id', $new->id)->first();
        $this->assertSame(['photo', 'gpt-image-1', 'Арт', 9, 1], [$row->type, $row->name, $row->text_name, (int) $row->price, (int) $row->only_sub]);
        $this->assertSame(['pro', 'Новая', true], [$new->min_plan, $new->badge, $new->is_active]);
        $this->assertNull(AiModel::slugged('video', 'old-veo'));

        // photosets: the linked prompt got its app fields, the app-only video set is a new prompt
        $this->assertSame($promptsBefore + 1, DB::table('prompts')->count());
        $set = Photoset::find($prompt->id);
        $this->assertSame(['Описание', '/storage/covers/a.png', false], [$set->description, $set->cover_url, $set->is_active]);
        $video = Photoset::where('name', 'Полёт')->firstOrFail();
        $this->assertSame(['video', 4, true, 'The camera flies.'], [$video->kind, $video->sort, $video->is_trending, $video->prompt]);

        // generations point at bot ids now
        $this->assertSame([$linked->id, null], [(int) DB::table('wa_generations')->where('id', $g1)->value('ai_model_id'), DB::table('wa_generations')->where('id', $g1)->value('photoset_id')]);
        $this->assertSame([$new->id, $video->id], array_map('intval', array_values((array) DB::table('wa_generations')->where('id', $g2)->first(['ai_model_id', 'photoset_id']))));
        $this->assertSame([null, $prompt->id], [DB::table('wa_generations')->where('id', $g3)->value('ai_model_id'), (int) DB::table('wa_generations')->where('id', $g3)->value('photoset_id')]);
        $this->assertNull(DB::table('wa_generations')->where('id', $g4)->value('ai_model_id'));

        // the admin's tool settings moved to tool_engines
        $engines = json_decode((string) DB::table('wa_settings')->where('key', 'tool_engines')->value('value'), true);
        $this->assertSame(['active' => false, 'driver' => 'fake', 'provider_model' => 'x', 'options' => null], $engines['retouch']);
    }

    public function test_tools_are_settings_not_models(): void
    {
        $admin = $this->as(self::ADMIN);
        $admin->putJson('/api/admin/tools/retouch', array_merge($this->toolBody('retouch'), ['is_active' => false, 'driver' => 'fake']))->assertOk()
            ->assertJsonPath('data.is_active', false)->assertJsonPath('data.driver', 'fake');
        $this->assertFalse(Tools::isActive('retouch'));
        $this->assertNotContains('retouch', collect($this->as(4)->getJson('/api/bootstrap')->json('tools'))->pluck('id')->all());
        $this->assertSame(0, DB::table('models')->where('type', 'tool')->count());

        $this->user(6, 100);
        $this->as(6)->post('/api/generations', ['kind' => 'tool', 'tool' => 'retouch', 'file' => UploadedFile::fake()->image('a.jpg', 300, 300)], ['Accept' => 'application/json'])->assertStatus(404);
        $this->as(self::ADMIN)->putJson('/api/admin/tools/retouch', array_merge($this->toolBody('retouch'), ['is_active' => true]))->assertOk();
        $this->assertTrue(Tools::isActive('retouch'));
    }

    public function test_a_prompt_opened_by_bot_links_is_not_deleted(): void
    {
        $set = Photoset::orderBy('id')->firstOrFail();
        DB::table('links')->insert(['name' => 'promo1', 'hide' => false, 'prompt_id_id' => $set->id, 'created_at' => now(), 'modified_at' => now()]);
        $this->as(self::ADMIN)->deleteJson("/api/admin/photosets/{$set->id}")->assertStatus(422);
        $this->assertNotNull(Photoset::find($set->id));
        // switching it off hides it in the app, the bot keeps it
        $this->as(self::ADMIN)->patchJson("/api/admin/photosets/{$set->id}", ['is_active' => false])->assertOk();
        $this->assertNotContains($set->id, collect($this->as(4)->getJson('/api/bootstrap')->json('photosets'))->pluck('id')->all());
        $this->assertTrue(DB::table('prompts')->where('id', $set->id)->exists());
    }

    private function toolBody(string $slug): array
    {
        $t = config("neurogpt.tools.$slug");

        return ['name' => $t['name'], 'hint' => $t['hint'], 'cost' => $t['cost'], 'icon' => $t['icon'], 'group' => $t['group'], 'input' => $t['input'], 'prompt' => $t['prompt']];
    }
}
