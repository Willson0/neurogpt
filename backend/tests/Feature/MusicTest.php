<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Generation;
use App\Models\User;
use App\Services\Generation\FakeProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MusicTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['neurogpt.features.songs' => true, 'neurogpt.providers.kie.api_key' => 'music-test']);
        AiModel::where('kind', 'song')->where('slug', 'like', 'suno-%')->update(['is_active' => true, 'driver' => 'kie']);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*/getUserProfilePhotos' => Http::response(['ok' => true, 'result' => ['photos' => []]])]);
    }

    private function audio(): string
    {
        return app(FakeProvider::class)->media(new Generation(['kind' => 'song']), new AiModel, null)->bytes;
    }

    public function test_suno_variants_can_be_played_downloaded_and_sent_individually(): void
    {
        $this->user(101, 100);
        $audio = $this->audio();
        Http::fake([
            'api.kie.ai/api/v1/jobs/createTask' => Http::response(['code' => 200, 'data' => ['taskId' => 'song-1']]),
            'api.kie.ai/api/v1/jobs/recordInfo*' => Http::response(['code' => 200, 'data' => [
                'state' => 'success', 'response' => ['data' => [
                    ['id' => 'take-1', 'title' => 'Ночной выезд', 'audio_url' => 'https://media.example.test/take-1.wav', 'duration' => 150],
                    ['id' => 'take-2', 'title' => 'Ночной выезд', 'audio_url' => 'https://media.example.test/take-2.wav', 'duration' => 144],
                ]],
            ]]),
            'media.example.test/*' => fn () => Http::response($audio, 200, ['Content-Type' => 'audio/wav']),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
        $result = $this->as(101)->postJson('/api/music', [
            'operation' => 'generate', 'model' => 'suno-v6', 'custom_mode' => true, 'instrumental' => false,
            'title' => 'Ночной выезд', 'style' => 'Synthwave', 'lyrics' => "[Verse]\nТекст песни",
            'duration' => 150, 'variety' => 0, 'style_weight' => 0.75, 'vocal_gender' => 'f',
        ])->assertCreated();
        $id = $result->json('data.0.id');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/createTask') && $request['model'] === 'ai-music-api/generate' && $request['input'] === [
            'custom_mode' => true, 'instrumental' => false, 'title' => 'Ночной выезд', 'style' => 'Synthwave',
            'lyrics' => "[Verse]\nТекст песни", 'vocal_gender' => 'f', 'style_weight' => 0.75, 'variety' => 0,
            'duration' => 150, 'model' => 'V6',
        ]);
        $generation = Generation::findOrFail($id);
        $this->assertSame('done', $generation->status);
        $this->assertSame('take-2', $generation->music_result['tracks'][1]['id']);
        Storage::disk('public')->assertExists($generation->music_result['tracks'][1]['path']);
        $this->assertSame(70, User::findOrFail(101)->balance);
        $result = $this->as(101)->getJson('/api/generations/'.$id)->assertOk()
            ->assertJsonCount(2, 'data.music.tracks')->assertJsonPath('data.music.tracks.1.editable', true);
        $this->as(101)->get('/api/music/'.$id.'/tracks/1/download')->assertDownload('neurogpt-'.$id.'-2.wav');
        $this->get($result->json('data.music.tracks.1.download_url'))->assertDownload('neurogpt-'.$id.'-2.wav')
            ->assertHeader('Access-Control-Allow-Origin', 'https://web.telegram.org');
        $this->getJson('/api/music/download/'.$id.'/1')->assertForbidden();
        $this->as(102)->getJson('/api/music/'.$id.'/tracks/1/download')->assertNotFound();
        $this->as(102)->postJson('/api/music/'.$id.'/tracks/1/send')->assertNotFound();
        $this->as(102)->postJson('/api/music', ['operation' => 'extend', 'source_id' => $id])->assertNotFound();
        $this->as(101)->postJson('/api/music/'.$id.'/tracks/1/send')->assertOk()->assertJsonPath('sent', true);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendDocument'));
    }

    public function test_lyrics_and_graphic_cover_are_priced_separately_and_failure_refunds(): void
    {
        $user = $this->user(101, 100);
        $song = Generation::create([
            'user_id' => $user->id, 'kind' => 'song', 'status' => 'done', 'cost' => 30,
            'result_path' => 'generations/song.wav',
        ]);
        $file = UploadedFile::fake()->image('cover.png');
        $image = file_get_contents($file->getPathname());
        Http::fake([
            'api.kie.ai/api/v1/jobs/createTask' => fn ($request) => Http::response(['code' => 200, 'data' => ['taskId' => $request['model'] === 'ai-music-api/generate-lyrics' ? 'lyrics' : 'cover']]),
            'api.kie.ai/api/v1/jobs/recordInfo?taskId=lyrics' => Http::response(['code' => 200, 'data' => [
                'state' => 'success', 'resultJson' => json_encode(['resultObject' => ['lyricsData' => [
                    ['title' => 'Первый', 'text' => 'Куплет', 'status' => 'SUCCESS'],
                    ['title' => 'Второй', 'text' => 'Припев', 'status' => 'SUCCESS'],
                ]]]),
            ]]),
            'api.kie.ai/api/v1/jobs/recordInfo?taskId=cover' => Http::sequence()
                ->push(['code' => 200, 'data' => ['state' => 'success', 'resultJson' => json_encode(['resultUrls' => ['https://media.example.test/art.png']])]])
                ->push(['code' => 200, 'data' => ['state' => 'fail', 'failMsg' => 'Unavailable']]),
            'media.example.test/art.png' => Http::response($image),
        ]);
        $lyric = $this->as(101)->postJson('/api/music', ['operation' => 'lyrics', 'prompt' => 'Песня о море'])
            ->assertCreated()->json('data.0.id');
        $this->as(101)->getJson('/api/generations/'.$lyric)->assertJsonCount(2, 'data.music.lyrics');
        $cover = $this->as(101)->postJson('/api/music', ['operation' => 'cover', 'source_id' => $song->id, 'prompt' => 'Море ночью'])
            ->assertCreated()->json('data.0.id');
        $this->as(101)->getJson('/api/generations/'.$cover)->assertJsonPath('data.music.parent_id', $song->id);
        Storage::disk('public')->assertExists(Generation::findOrFail($cover)->result_path);
        $failed = $this->as(101)->postJson('/api/music', ['operation' => 'cover', 'source_id' => $song->id, 'prompt' => 'Море ночью'])
            ->assertCreated()->json('data.0.id');
        $this->assertSame('failed', Generation::findOrFail($failed)->status);
        $this->assertSame(80, $user->refresh()->balance);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/createTask') && $r['model'] === 'seedream/4.5-text-to-image' && $r['input']['aspect_ratio'] === '1:1');
    }

    public function test_custom_voice_becomes_a_reusable_profile_after_phrase_verification(): void
    {
        $this->user(101, 100);
        $audio = $this->audio();
        Http::fake([
            'kieai.redpandaai.co/api/file-stream-upload' => Http::response(['code' => 200, 'data' => ['downloadUrl' => 'https://media.example.test/input.wav']]),
            'api.kie.ai/api/v1/jobs/createTask' => fn ($request) => Http::response(['code' => 200, 'data' => ['taskId' => match ($request['model']) {
                'ai-music-api/validation-phrase' => 'phrase-id',
                'ai-music-api/create-voice' => 'verification-job',
                'ai-music-api/generate' => 'song-id',
            }]]),
            'api.kie.ai/api/v1/jobs/recordInfo?taskId=phrase-id' => Http::sequence()
                ->push(['code' => 200, 'data' => ['state' => 'generating', 'resultJson' => '{"data":{"verify_phrase_text":"Read these words"}}']])
                ->push(['code' => 200, 'data' => ['state' => 'success']]),
            'api.kie.ai/api/v1/jobs/recordInfo?taskId=song-id' => Http::response(['code' => 200, 'data' => [
                'state' => 'success', 'response' => ['data' => [['id' => 'audio', 'audio_url' => 'https://media.example.test/audio.wav']]],
            ]]),
            'media.example.test/audio.wav' => Http::response($audio),
        ]);
        $first = $this->as(101)->post('/api/music', [
            'operation' => 'voice_phrase', 'start' => '0', 'end' => '5',
            'file' => UploadedFile::fake()->createWithContent('voice.wav', $audio),
        ])->assertCreated()->json('data.0.id');
        $this->as(101)->getJson('/api/generations/'.$first)->assertJsonPath('data.music.phrase', 'Read these words');

        $voice = $this->as(101)->post('/api/music', [
            'operation' => 'voice_create', 'source_id' => $first, 'title' => 'Мой голос',
            'file' => UploadedFile::fake()->createWithContent('check.wav', $audio),
        ])->assertCreated()->json('data.0.id');
        $this->as(101)->getJson('/api/music/profiles')->assertJsonPath('data.0.id', $voice);
        $this->as(102)->getJson('/api/music/profiles')->assertJsonCount(0, 'data');
        $this->as(101)->postJson('/api/music', [
            'operation' => 'generate', 'profile_id' => $voice, 'custom_mode' => true,
            'title' => 'Новая песня', 'style' => 'Pop',
        ])->assertCreated();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/createTask') && $r['model'] === 'ai-music-api/generate'
            && ($r['input']['persona_id'] ?? '') === 'phrase-id' && $r['input']['persona_model'] === 'voice_persona');
        Storage::disk('local')->assertMissing(Generation::findOrFail($voice)->input_path);
    }

    public function test_incompatible_inputs_are_rejected_before_billing(): void
    {
        $user = $this->user(101, 100);
        $this->as(101)->postJson('/api/music', [
            'operation' => 'generate', 'custom_mode' => true, 'title' => 'Без слов',
            'instrumental' => true, 'lyrics' => 'Текст должен быть исключён',
        ])->assertUnprocessable()->assertJsonValidationErrors('style');
        $this->as(101)->postJson('/api/music', [
            'operation' => 'generate', 'prompt' => 'Тема', 'audio_urls' => ['file:///some/path'],
        ])->assertUnprocessable()->assertJsonValidationErrors('audio_urls.0');
        $this->assertSame(100, $user->refresh()->balance);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.kie.ai'));
    }

    public function test_editing_and_wav_use_the_selected_variant_and_its_model(): void
    {
        $user = $this->user(101, 100);
        $song = Generation::create([
            'user_id' => $user->id, 'kind' => 'song', 'status' => 'done', 'cost' => 50,
            'provider_task_id' => 'original-task',
            'params' => ['music' => ['operation' => 'generate', 'version' => 'V6_WILD']],
            'music_result' => ['tracks' => [
                ['id' => 'first', 'duration' => 80, 'path' => 'generations/first.wav', 'title' => 'First'],
                ['id' => 'second', 'duration' => 90, 'path' => 'generations/second.wav', 'title' => 'Second'],
            ]],
        ]);
        $audio = $this->audio();
        Http::fake([
            'api.kie.ai/api/v1/jobs/createTask' => Http::response(['code' => 200, 'data' => ['taskId' => 'editor-job']]),
            'api.kie.ai/api/v1/jobs/recordInfo*' => Http::response(['code' => 200, 'data' => [
                'state' => 'success', 'resultJson' => '{"resultUrls":["https://media.example.test/processed.wav"]}',
            ]]),
            'media.example.test/processed.wav' => fn () => Http::response($audio),
        ]);

        $this->as(101)->postJson('/api/music', ['operation' => 'extend', 'source_id' => $song->id, 'track' => 1, 'start' => 30])->assertCreated();
        $this->as(101)->postJson('/api/music', ['operation' => 'wav', 'source_id' => $song->id, 'track' => 1])->assertCreated();
        $this->as(101)->postJson('/api/music', [
            'operation' => 'replace', 'source_id' => $song->id, 'track' => 1, 'start' => 10, 'end' => 12,
        ])->assertUnprocessable()->assertJsonValidationErrors('end');

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/createTask') && $r['model'] === 'ai-music-api/extend'
            && $r['input']['audio_id'] === 'second' && $r['input']['model'] === 'V6_WILD');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/createTask') && $r['model'] === 'ai-music-api/convert-to-wav-format'
            && $r['input'] === ['task_id' => 'original-task', 'audio_id' => 'second']);
        $this->assertSame(45, $user->refresh()->balance);
    }
}
