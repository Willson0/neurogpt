<?php

namespace App\Services\Generation;

use App\Models\AiModel;
use App\Models\Generation;
use App\Services\Admin\ProviderJournal;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Replicate predictions for video, songs and tools. `provider_model` holds the model
 * version id; the input fields follow the usual prompt / image / aspect_ratio names.
 */
class ReplicateProvider implements Provider
{
    private function http()
    {
        $cfg = config('neurogpt.providers.replicate');
        if (! $cfg['api_token']) {
            throw new RuntimeException('REPLICATE_API_TOKEN is not set');
        }

        return Http::withToken($cfg['api_token'])->baseUrl(rtrim($cfg['base_url'], '/'))->timeout(60)->acceptJson();
    }

    public function chat(AiModel $model, array $messages, ?int $timeout = null): string
    {
        throw new RuntimeException('The replicate driver does not serve text');
    }

    public function media(Generation $generation, AiModel $model, ?string $inputPath): MediaResult
    {
        $input = array_filter([
            'prompt' => $generation->providerPrompt(),
            'aspect_ratio' => $generation->params['format'] ?? null,
            'duration' => $generation->params['duration'] ?? null,
        ]);
        if ($model->kind === 'song') {
            // Music models differ in field names: REPLICATE_SONG_PROMPT_FIELD / REPLICATE_SONG_DURATION_FIELD adjust them.
            $input = array_filter([
                config('neurogpt.providers.replicate.song_prompt_field') => $generation->providerPrompt(),
                config('neurogpt.providers.replicate.song_duration_field') => $generation->params['duration'] ?? null,
            ]);
        }
        if ($inputPath) {
            $mime = mime_content_type($inputPath) ?: 'image/jpeg';
            $input['image'] = 'data:'.$mime.';base64,'.base64_encode(file_get_contents($inputPath));
        }

        $created = ProviderJournal::request('replicate', 'submit',
            fn () => $this->http()->post('/predictions', ['version' => $model->provider_model, 'input' => $input]),
            $model, $generation)->throw()->json();

        $deadline = time() + (int) config('neurogpt.providers.replicate.timeout');
        $prediction = $created;
        while (! in_array($prediction['status'] ?? '', ['succeeded', 'failed', 'canceled'], true)) {
            if (time() > $deadline) {
                throw new RuntimeException('Generation timed out');
            }
            sleep(3);
            $prediction = ProviderJournal::request('replicate', 'poll',
                fn () => $this->http()->get('/predictions/'.$created['id']), $model, $generation)->throw()->json();
        }

        if ($prediction['status'] !== 'succeeded') {
            throw new RuntimeException($prediction['error'] ?? 'Generation failed');
        }

        $url = is_array($prediction['output']) ? ($prediction['output'][0] ?? null) : $prediction['output'];
        if (! is_string($url)) {
            throw new RuntimeException('The provider returned no file');
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: ($model->kind === 'video' ? 'mp4' : ($model->kind === 'song' ? 'mp3' : 'png'));

        $response = ProviderJournal::request('replicate', 'download', fn () => Http::timeout(120)->get($url), $model, $generation);

        return new MediaResult($response->throw()->body(), $ext);
    }
}
