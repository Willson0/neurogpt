<?php

namespace App\Services\Generation;

use App\Models\AiModel;
use App\Models\Generation;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Replicate predictions for video and tools. `provider_model` holds the model
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

    public function chat(AiModel $model, array $messages): string
    {
        throw new RuntimeException('The replicate driver does not serve text');
    }

    public function media(Generation $generation, AiModel $model, ?string $inputPath): MediaResult
    {
        $input = array_filter([
            'prompt' => $generation->prompt,
            'aspect_ratio' => $generation->params['format'] ?? null,
            'duration' => $generation->params['duration'] ?? null,
        ]);
        if ($inputPath) {
            $mime = mime_content_type($inputPath) ?: 'image/jpeg';
            $input['image'] = 'data:'.$mime.';base64,'.base64_encode(file_get_contents($inputPath));
        }

        $created = $this->http()->post('/predictions', ['version' => $model->provider_model, 'input' => $input])->throw()->json();

        $deadline = time() + (int) config('neurogpt.providers.replicate.timeout');
        $prediction = $created;
        while (! in_array($prediction['status'] ?? '', ['succeeded', 'failed', 'canceled'], true)) {
            if (time() > $deadline) {
                throw new RuntimeException('Generation timed out');
            }
            sleep(3);
            $prediction = $this->http()->get('/predictions/'.$created['id'])->throw()->json();
        }

        if ($prediction['status'] !== 'succeeded') {
            throw new RuntimeException($prediction['error'] ?? 'Generation failed');
        }

        $url = is_array($prediction['output']) ? ($prediction['output'][0] ?? null) : $prediction['output'];
        if (! is_string($url)) {
            throw new RuntimeException('The provider returned no file');
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: ($model->kind === 'video' ? 'mp4' : 'png');

        return new MediaResult(Http::timeout(120)->get($url)->throw()->body(), $ext);
    }
}
