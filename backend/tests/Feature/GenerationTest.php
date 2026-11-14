<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Generation;
use App\Models\Photoset;
use App\Models\User;
use App\Services\Generation\GenerationRunner;
use App\Services\Generation\MediaResult;
use App\Services\Generation\PromptBuilder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    }

    public function test_image_is_generated_charged_and_delivered(): void
    {
        $this->user(1, 50);
        $res = $this->as(1)->postJson('/api/generations', ['kind' => 'image', 'prompt' => 'кот', 'style' => 'anime', 'count' => 2])
            ->assertCreated()->assertJsonPath('user.balance', 34)->assertJsonCount(2, 'data');

        $g = Generation::find($res->json('data.0.id'));
        $this->assertSame('done', $g->status);
        Storage::disk('public')->assertExists($g->result_path);
        $this->as(1)->getJson('/api/generations/'.$g->id)->assertJsonPath('data.status', 'done')->assertJsonPath('data.title', 'Картинка');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/sendDocument') || str_contains($r->url(), '/sendPhoto'));
    }

    public function test_validation_and_limits(): void
    {
        $this->user(1, 10);
        $this->as(1)->postJson('/api/generations', ['kind' => 'image', 'prompt' => ''])->assertStatus(422);
        $this->as(1)->postJson('/api/generations', ['kind' => 'image', 'prompt' => 'x', 'model' => 'photo'])->assertStatus(403)->assertJsonPath('code', 'model_locked');
        $this->as(1)->postJson('/api/generations', ['kind' => 'image', 'prompt' => 'x', 'model' => '../etc'])->assertStatus(422);
        $this->as(1)->postJson('/api/generations', ['kind' => 'image', 'prompt' => 'x', 'model' => 'nope'])->assertStatus(404);
        $this->as(1)->postJson('/api/generations', ['kind' => 'image', 'prompt' => 'x', 'count' => 4])->assertStatus(402)->assertJsonPath('code', 'insufficient_balance');
        $this->as(1)->postJson('/api/generations', ['kind' => 'song', 'prompt' => 'x'])->assertStatus(422)->assertJsonPath('code', 'coming_soon');
        $this->assertSame(10, User::first()->balance);
        $this->assertSame(0, Generation::count());
    }

    public function test_another_users_generation_is_invisible(): void
    {
        $this->user(1, 50);
        $id = $this->as(1)->postJson('/api/generations', ['kind' => 'image', 'prompt' => 'кот'])->json('data.0.id');
        $this->user(2, 50);
        $this->as(2)->getJson("/api/generations/$id")->assertStatus(404);
        $this->as(2)->patchJson("/api/generations/$id", ['is_favorite' => true])->assertStatus(404);
        $this->as(2)->postJson("/api/generations/$id/cancel")->assertStatus(404);
        $this->as(2)->postJson('/api/generations', ['kind' => 'tool', 'tool' => 'retouch', 'source_generation_id' => $id])->assertStatus(422);
        $this->as(2)->getJson('/api/generations')->assertJsonCount(0, 'data');
    }

    public function test_video_price_follows_model_and_duration(): void
    {
        $this->user(1, 500, 'plus');
        $this->as(1)->postJson('/api/generations', ['kind' => 'video', 'prompt' => 'волны', 'model' => 'kling', 'duration' => 10, 'format' => '1:1'])
            ->assertCreated()->assertJsonPath('data.0.cost', 220)->assertJsonPath('data.0.params.duration', 10)->assertJsonPath('user.balance', 280);
        // Veo makes 8-second clips only and has no square format.
        $this->as(1)->postJson('/api/generations', ['kind' => 'video', 'prompt' => 'волны', 'model' => 'veo-lite', 'duration' => 10])->assertStatus(422);
        $this->as(1)->postJson('/api/generations', ['kind' => 'video', 'prompt' => 'волны', 'model' => 'veo-lite', 'format' => '1:1'])
            ->assertCreated()->assertJsonPath('data.0.params.format', '16:9')->assertJsonPath('data.0.cost', 40);
        $this->as(1)->postJson('/api/generations', ['kind' => 'video', 'prompt' => 'волны', 'model' => 'veo'])->assertStatus(403);
        // No model named: the first one of the catalog.
        $this->as(1)->postJson('/api/generations', ['kind' => 'video', 'prompt' => 'волны'])->assertCreated()->assertJsonPath('data.0.cost', 20);
    }

    public function test_tools_need_a_photo_and_their_inputs(): void
    {
        $this->user(1, 100);
        $photo = fn () => UploadedFile::fake()->image('me.jpg', 600, 800);

        $this->as(1)->post('/api/generations', ['kind' => 'tool', 'tool' => 'retouch'])->assertStatus(422);
        $this->as(1)->post('/api/generations', ['kind' => 'tool', 'tool' => 'replace_bg', 'file' => $photo()])->assertStatus(422);
        $this->as(1)->post('/api/generations', ['kind' => 'tool', 'tool' => 'stylize', 'option' => 'nope', 'file' => $photo()])->assertStatus(422);
        $this->as(1)->post('/api/generations', ['kind' => 'tool', 'tool' => 'hack', 'file' => $photo()])->assertStatus(422);
        $this->as(1)->post('/api/generations', ['kind' => 'tool', 'tool' => 'retouch', 'file' => UploadedFile::fake()->create('x.php', 10, 'text/x-php')])->assertStatus(422);
        $this->assertSame(100, User::first()->balance);

        $res = $this->as(1)->post('/api/generations', ['kind' => 'tool', 'tool' => 'stylize', 'option' => 'anime', 'file' => $photo()])
            ->assertCreated()->assertJsonPath('data.0.cost', 10)->assertJsonPath('data.0.title', 'Стилизация');
        $g = Generation::find($res->json('data.0.id'));
        $this->assertSame('done', $g->status);
        $this->assertSame([], Storage::disk('local')->allFiles('inputs'), 'the upload is removed after the job');

        // A finished picture can be the source of the next tool.
        $this->as(1)->postJson('/api/generations', ['kind' => 'tool', 'tool' => 'upscale', 'source_generation_id' => $g->id])->assertStatus(422); // svg from the fake driver is not a photo
    }

    public function test_prompts_are_built_from_presets(): void
    {
        $user = $this->user(1);
        $builder = app(PromptBuilder::class);
        $make = fn (array $a) => tap(new Generation($a + ['user_id' => $user->id, 'cost' => 1]), fn ($g) => $g->setRelation('photoset', isset($a['photoset_id']) ? Photoset::find($a['photoset_id']) : null));

        $p = $builder->for($make(['kind' => 'image', 'prompt' => 'кот', 'params' => ['style' => 'anime', 'negative' => 'текст']]));
        $this->assertStringContainsString('кот', $p);
        $this->assertStringContainsString('anime illustration', $p);
        $this->assertStringContainsString('текст', $p);

        $p = $builder->for($make(['kind' => 'tool', 'tool' => 'replace_bg', 'prompt' => 'пляж']));
        $this->assertStringContainsString('Replace only the background of this photo with: пляж', $p);

        $p = $builder->for($make(['kind' => 'tool', 'tool' => 'stylize', 'params' => ['option' => 'clay']]));
        $this->assertStringContainsString('claymation', $p);
        $this->assertStringNotContainsString('{', $p);

        $p = $builder->for($make(['kind' => 'tool', 'tool' => 'sticker', 'prompt' => '']));
        $this->assertStringNotContainsString('{input}', $p);
        $this->assertStringNotContainsString('Additional wish', $p);

        $set = Photoset::where('name', 'Аниме-аватар')->first();
        $p = $builder->for($make(['kind' => 'image', 'photoset_id' => $set->id, 'prompt' => 'в очках']));
        $this->assertStringContainsString('Keep the face fully recognizable', $p);
        $this->assertStringContainsString('в очках', $p);

        foreach (config('neurogpt.tools') as $id => $tool) {
            $this->assertNotEmpty($tool['prompt'], "tool $id has a prompt");
            $this->assertNotNull(AiModel::where('kind', 'tool')->where('slug', $id)->first(), "tool $id is seeded");
        }
    }

    public function test_failure_refunds_once_and_a_late_result_is_ignored(): void
    {
        $user = $this->user(1, 50);
        $model = AiModel::where('kind', 'image')->where('slug', 'auto')->first();
        $g = Generation::create(['user_id' => $user->id, 'kind' => 'image', 'ai_model_id' => $model->id, 'prompt' => 'x', 'cost' => 8, 'status' => 'processing', 'started_at' => now()]);
        $this->setBalance($user, 42);

        $runner = app(GenerationRunner::class);
        $runner->fail($g, new \RuntimeException('boom'));
        $runner->fail($g, new \RuntimeException('boom again'));
        $this->assertSame(50, $user->refresh()->balance);

        // The worker that was presumed dead finishes afterwards: the refunded job must not turn into a free result.
        $runner->complete($g, new MediaResult('data', 'jpg'));
        $this->assertSame('failed', $g->refresh()->status);
        $this->assertNull($g->result_path);
        $this->assertSame([], Storage::disk('public')->allFiles('generations'));
    }

    public function test_too_many_active_generations_are_refused(): void
    {
        config(['neurogpt.limits.active_generations' => 2]);
        $user = $this->user(1, 500);
        $model = AiModel::where('kind', 'image')->first();
        foreach ([1, 2] as $i) {
            Generation::create(['user_id' => $user->id, 'kind' => 'image', 'ai_model_id' => $model->id, 'prompt' => 'x', 'cost' => 8, 'status' => 'processing']);
        }
        $this->as(1)->postJson('/api/generations', ['kind' => 'image', 'prompt' => 'кот'])->assertStatus(429)->assertJsonPath('code', 'too_many_active');
        $this->assertSame(500, $user->refresh()->balance);
    }

    public function test_referral_bonus_after_first_generation_with_a_cap(): void
    {
        config(['neurogpt.limits.referral_rewards' => 1]);
        $inviter = $this->user(100, 0);
        foreach ([201, 202] as $id) {
            $this->as($id, 'friend_100')->postJson('/api/generations', ['kind' => 'image', 'prompt' => 'кот'])->assertCreated();
            $this->as($id)->postJson('/api/generations', ['kind' => 'image', 'prompt' => 'кот'])->assertCreated();
        }
        // Each friend: 50 welcome + 50 gift - 2 x 8. The inviter is paid for the first friend only.
        $this->assertSame(84, User::find(201)->balance);
        $this->assertSame(84, User::find(202)->balance);
        $this->assertSame(50, $inviter->refresh()->balance);
    }

    public function test_improve_prompt_is_free_and_capped(): void
    {
        config(['neurogpt.limits.improve_per_day' => 2]);
        $this->user(1, 5);
        $out = $this->as(1)->postJson('/api/prompt/improve', ['prompt' => 'кот-космонавт'])->assertOk()->json('prompt');
        $this->assertStringStartsWith('кот-космонавт', $out);
        $this->assertGreaterThan(mb_strlen('кот-космонавт'), mb_strlen($out));
        $this->as(1)->postJson('/api/prompt/improve', ['prompt' => 'кот', 'kind' => 'video'])->assertOk();
        $this->as(1)->postJson('/api/prompt/improve', ['prompt' => 'кот'])->assertStatus(429);
        $this->assertSame(5, User::first()->balance);
    }
}
