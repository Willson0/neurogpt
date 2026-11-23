<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Generation;
use App\Services\Generation\KieProvider;
use App\Services\Generation\OpenAiProvider;
use App\Services\Generation\ReplicateProvider;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderJournalTest extends TestCase
{
    public function test_admin_can_find_provider_errors_without_sensitive_payloads(): void
    {
        config([
            'neurogpt.providers.openai.base_url' => 'https://gateway.example.test/v1',
            'neurogpt.providers.openai.api_key' => 'private-key-1234',
            'neurogpt.providers.replicate.api_token' => 'replicate-token',
            'neurogpt.providers.kie.api_key' => 'kie-test-key',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'api.telegram.org/*/getUserProfilePhotos' => Http::response(['ok' => true, 'result' => ['photos' => []]]),
            'gateway.example.test/v1/chat/completions' => Http::response([
                'error' => ['code' => 'private-key-1234', 'message' => 'Invalid API key. Echoed text: private prompt'],
            ], 401, ['X-Request-Id' => 'req-chat-25']),
            'api.replicate.com/v1/predictions' => Http::response(['detail' => 'Too many requests'], 429),
            'api.kie.ai/api/v1/jobs/recordInfo*' => Http::sequence()
                ->push(['code' => 200, 'data' => ['state' => 'waiting']])
                ->push(['code' => 200, 'data' => ['state' => 'fail', 'failCode' => 402, 'failMsg' => 'Not enough credits']]),
        ]);
        $model = new AiModel(['slug' => 'custom-image', 'kind' => 'image']);
        $g = new Generation(['kind' => 'image', 'prompt' => 'private prompt', 'provider_task_id' => 'task-test-33']);
        $g->id = 57;
        foreach ([
            fn () => app(OpenAiProvider::class)->chat($model, [['role' => 'user', 'content' => 'private prompt']]),
            fn () => app(ReplicateProvider::class)->media($g, $model, null),
        ] as $send) {
            try {
                $send();
                $this->fail('Expected provider error');
            } catch (RequestException) {
                // The journal must not change the provider's actual response.
            }
        }
        $kie = app(KieProvider::class);
        $this->assertNull($kie->fetch($g, $model));
        $this->assertDatabaseCount('wa_provider_events', 2);
        try {
            $kie->fetch($g, $model);
            $this->fail('Expected provider error');
        } catch (\App\Exceptions\ProviderUnavailable) {
            // Kie uses an application-level error even when HTTP reports success.
        }
        $this->as(1)->getJson('/api/admin/provider-events')->assertForbidden();
        $admin = $this->as(1337592809);
        $admin->getJson('/api/admin/provider-events?provider=kie&q=task-test-33&outcome=error')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.reason', 'credits')
            ->assertJsonPath('data.0.generation_id', 57)->assertJsonPath('data.0.http_status', 200)
            ->assertJsonPath('data.0.provider_code', '402');
        $admin->getJson('/api/admin/provider-events?q=req-chat-25')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason', 'authorization')->assertJsonPath('data.0.provider_code', null)
            ->assertJsonPath('data.0.host', 'gateway.example.test')
            ->assertDontSee('private-key-1234')->assertDontSee('private prompt');
        $admin->getJson('/api/admin/provider-events?provider=replicate&q=429')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.reason', 'rate_limit');
        $admin->getJson('/api/admin/provider-events?q=%25')->assertOk()->assertJsonCount(0, 'data');

        DB::table('wa_provider_events')->where('provider', 'replicate')->update(['created_at' => now()->subDays(15)]);
        $this->artisan('providers:prune-events')->assertSuccessful();
        $this->assertDatabaseCount('wa_provider_events', 2);
    }
}
