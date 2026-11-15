<?php

namespace Tests\Feature;

use App\Jobs\PollGeneration;
use App\Models\AiModel;
use App\Models\Generation;
use App\Models\User;
use App\Services\Generation\GenerationRunner;
use App\Services\Generation\KieProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KieTest extends TestCase
{
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['neurogpt.providers.kie.api_key' => 'kie-test', 'neurogpt.providers.openai.api_key' => null]);
        AiModel::ofKind('video')->get()->each(fn (AiModel $m) => $m->update(['driver' => 'kie']));
        $engines = [];
        foreach (['upscale', 'remove_bg', 'animate'] as $slug) {
            $t = config("neurogpt.tools.$slug");
            $engines[$slug] = ['driver' => 'kie', 'provider_model' => $t['provider_model'], 'active' => true,
                'options' => array_intersect_key($t, array_flip(['family', 'tier', 'resolution', 'duration', 'factor']))];
        }
        config(['neurogpt.tool_engines' => $engines]);
    }

    private function fakeKie(array $record): void
    {
        Http::fake([
            'api.kie.ai/api/v1/jobs/createTask' => function ($request) {
                $this->sent[] = $request->data();

                return Http::response(['code' => 200, 'msg' => 'success', 'data' => ['taskId' => 'task_1']]);
            },
            'api.kie.ai/api/v1/jobs/recordInfo*' => Http::response(['code' => 200, 'data' => $record]),
            'kieai.redpandaai.co/*' => Http::response(['success' => true, 'code' => 200, 'data' => ['downloadUrl' => 'https://files.kie.test/in.jpg']]),
            'cdn.kie.test/*' => Http::response('FAKE-MP4-BYTES'),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
    }

    public function test_video_is_submitted_polled_and_stored(): void
    {
        $this->fakeKie(['state' => 'success', 'resultJson' => json_encode(['resultUrls' => ['https://cdn.kie.test/out.mp4']])]);
        $this->user(1, 100);

        $res = $this->as(1)->postJson('/api/generations', ['kind' => 'video', 'prompt' => 'волны на закате', 'model' => 'veo-lite', 'format' => '9:16'])->assertCreated();
        $g = Generation::find($res->json('data.0.id'));

        // The sync test queue runs the submit job and the poll job right away.
        $this->assertSame('done', $g->status);
        $this->assertSame('task_1', $g->provider_task_id);
        $this->assertStringEndsWith('.mp4', $g->result_path);
        $this->assertSame('FAKE-MP4-BYTES', Storage::disk('public')->get($g->result_path));
        $this->assertSame(60, User::first()->balance);

        $this->assertSame('veo-3-1', $this->sent[0]['model']);
        $this->assertSame([
            'prompt' => 'волны на закате', 'model' => 'veo3_lite', 'aspect_ratio' => '9:16', 'resolution' => '720p',
            'duration' => 8, 'enable_translation' => true, 'generation_type' => 'TEXT_2_VIDEO',
        ], $this->sent[0]['input']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/sendVideo'));
    }

    public function test_request_bodies_match_each_model_family(): void
    {
        $this->fakeKie(['state' => 'success', 'resultJson' => json_encode(['resultUrls' => ['https://cdn.kie.test/out.mp4']])]);
        $this->user(1, 1000, 'pro');

        $this->as(1)->postJson('/api/generations', ['kind' => 'video', 'prompt' => 'a cat', 'model' => 'kling', 'duration' => 10, 'format' => '1:1'])->assertCreated();
        $this->assertSame('kling-3.0/video', $this->sent[0]['model']);
        $this->assertSame(['prompt' => 'a cat', 'sound' => true, 'duration' => '10', 'aspect_ratio' => '1:1', 'mode' => 'std', 'multi_shots' => false], $this->sent[0]['input']);

        $this->as(1)->postJson('/api/generations', ['kind' => 'video', 'prompt' => 'a cat', 'model' => 'seedance-mini'])->assertCreated();
        $this->assertSame('bytedance/seedance-2-mini', $this->sent[1]['model']);
        $this->assertSame(['prompt' => 'a cat', 'resolution' => '480p', 'aspect_ratio' => '16:9', 'duration' => 5, 'generate_audio' => true], $this->sent[1]['input']);

        // A photo in video mode becomes the first frame.
        $this->as(1)->post('/api/generations', ['kind' => 'video', 'prompt' => 'a cat', 'model' => 'veo', 'file' => UploadedFile::fake()->image('a.jpg', 640, 640)])->assertCreated();
        $this->assertSame('veo3', $this->sent[2]['input']['model']);
        $this->assertSame('1080p', $this->sent[2]['input']['resolution']);
        $this->assertSame('FIRST_AND_LAST_FRAMES_2_VIDEO', $this->sent[2]['input']['generation_type']);
        $this->assertSame(['https://files.kie.test/in.jpg'], $this->sent[2]['input']['image_urls']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'file-base64-upload') && str_starts_with($r['base64Data'], 'data:image/jpeg;base64,') && $r->hasHeader('Authorization', 'Bearer kie-test'));
    }

    public function test_tools_use_dedicated_kie_models(): void
    {
        $png = (function () {
            $im = imagecreatetruecolor(8, 8);
            imagesavealpha($im, true);
            imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
            ob_start();
            imagepng($im);

            return ob_get_clean();
        })();
        Http::fake([
            'api.kie.ai/api/v1/jobs/createTask' => function ($request) {
                $this->sent[] = $request->data();

                return Http::response(['code' => 200, 'data' => ['taskId' => 'task_2']]);
            },
            'api.kie.ai/api/v1/jobs/recordInfo*' => Http::response(['code' => 200, 'data' => ['state' => 'success', 'resultJson' => json_encode(['resultUrls' => ['https://cdn.kie.test/out.png']])]]),
            'kieai.redpandaai.co/*' => Http::response(['code' => 200, 'data' => ['downloadUrl' => 'https://files.kie.test/in.jpg']]),
            'cdn.kie.test/*' => Http::response($png),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
        $this->user(1, 100);
        $photo = fn () => UploadedFile::fake()->image('me.jpg', 600, 800);

        $id = $this->as(1)->post('/api/generations', ['kind' => 'tool', 'tool' => 'remove_bg', 'file' => $photo()])->assertCreated()->json('data.0.id');
        $this->assertSame(['model' => 'recraft/remove-background', 'input' => ['image' => 'https://files.kie.test/in.jpg']], $this->sent[0]);
        $this->assertStringEndsWith('.png', Generation::find($id)->result_path, 'transparency is kept');

        $this->as(1)->post('/api/generations', ['kind' => 'tool', 'tool' => 'upscale', 'file' => $photo()])->assertCreated();
        $this->assertSame(['model' => 'topaz/image-upscale', 'input' => ['image_url' => 'https://files.kie.test/in.jpg', 'upscale_factor' => '2']], $this->sent[1]);

        // The transparent PNG made above can be fed into the next tool without a new upload.
        $this->as(1)->postJson('/api/generations', ['kind' => 'tool', 'tool' => 'upscale', 'source_generation_id' => $id])->assertCreated();
        $this->assertSame(100 - 3 - 10 - 10, User::first()->balance);
    }

    public function test_provider_failure_and_content_filter_refund_the_tokens(): void
    {
        $this->fakeKie(['state' => 'fail', 'failCode' => '501', 'failMsg' => 'Your prompt was flagged as violating content policies']);
        $this->user(1, 100);
        $id = $this->as(1)->postJson('/api/generations', ['kind' => 'video', 'prompt' => 'x y z', 'model' => 'veo-lite'])->assertCreated()->json('data.0.id');
        $this->assertSame('failed', Generation::find($id)->status);
        $this->assertSame('content_filtered', Generation::find($id)->error);
        $this->assertSame(100, User::first()->balance);

        Http::swap(new Factory);
        Http::fake(['api.kie.ai/api/v1/jobs/createTask' => Http::response(['code' => 402, 'msg' => 'Insufficient credits'])]);
        $id = $this->as(1)->postJson('/api/generations', ['kind' => 'video', 'prompt' => 'x y z', 'model' => 'veo-lite'])->assertCreated()->json('data.0.id');
        $this->assertSame('unavailable', Generation::find($id)->error);
        $this->assertSame(100, User::first()->balance);
    }

    public function test_pending_task_keeps_polling_and_times_out_with_a_refund(): void
    {
        $this->fakeKie(['state' => 'generating']);
        $user = $this->user(1, 60);
        $model = AiModel::slugged('video', 'veo-lite');
        $g = Generation::create(['user_id' => $user->id, 'kind' => 'video', 'ai_model_id' => $model->id, 'prompt' => 'x', 'cost' => 40, 'status' => 'processing', 'started_at' => now(), 'provider_task_id' => 'task_1', 'params' => ['duration' => 8]]);
        $this->setBalance($user, 20);

        Bus::fake([PollGeneration::class]);
        (new PollGeneration($g->id))->handle(app(GenerationRunner::class));
        Bus::assertDispatched(PollGeneration::class, fn ($job) => $job->attempt === 2);
        $this->assertSame('processing', $g->refresh()->status);

        // Transient API errors do not fail the task either.
        Http::swap(new Factory);
        Http::fake(['api.kie.ai/*' => Http::response(['code' => 429, 'msg' => 'Too many requests'])]);
        $this->assertFalse(app(GenerationRunner::class)->poll($g->id));

        $g->update(['started_at' => now()->subSeconds(config('neurogpt.limits.generation_timeout') + 5)]);
        $this->assertTrue(app(GenerationRunner::class)->poll($g->id));
        $this->assertSame('failed', $g->refresh()->status);
        $this->assertSame(60, $user->refresh()->balance);
    }

    public function test_reaper_refunds_stuck_generations(): void
    {
        $user = $this->user(1, 0);
        $model = AiModel::ofKind('image')->first();
        $g = Generation::create(['user_id' => $user->id, 'kind' => 'image', 'ai_model_id' => $model->id, 'prompt' => 'x', 'cost' => 8, 'status' => 'processing', 'started_at' => now()->subHours(2)]);
        $fresh = Generation::create(['user_id' => $user->id, 'kind' => 'image', 'ai_model_id' => $model->id, 'prompt' => 'x', 'cost' => 8, 'status' => 'processing', 'started_at' => now()]);

        $this->artisan('generations:reap')->assertSuccessful();
        $this->assertSame('failed', $g->refresh()->status);
        $this->assertSame('processing', $fresh->refresh()->status);
        $this->assertSame(8, $user->refresh()->balance);
    }

    public function test_result_links_are_found_in_every_documented_shape(): void
    {
        $this->assertSame('https://a/1.mp4', KieProvider::resultUrl(['resultUrls' => ['https://a/1.mp4']]));
        $this->assertSame('https://a/2.mp4', KieProvider::resultUrl(['data' => ['result_urls' => ['https://a/2.mp4'], 'origin_urls' => ['https://a/o.mp4']]]));
        $this->assertSame('https://a/o.mp4', KieProvider::resultUrl(['originUrls' => ['https://a/o.mp4']]));
        $this->assertNull(KieProvider::resultUrl(['resultUrls' => ['http://insecure/1.mp4', 'file:///etc/passwd']]));
    }
}
