<?php

namespace App\Services\Generation;

use App\Models\AiModel;
use App\Models\Generation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** OpenAI-compatible API: OpenAI itself, OpenRouter, or any gateway that speaks the same dialect. */
class OpenAiProvider implements Provider
{
    private function http()
    {
        $cfg = config('neurogpt.providers.openai');
        if (! $cfg['api_key']) {
            throw new RuntimeException('OPENAI_API_KEY is not set');
        }

        return Http::withToken($cfg['api_key'])->baseUrl(rtrim($cfg['base_url'], '/'))->timeout($cfg['timeout'])->acceptJson();
    }

    public function chat(AiModel $model, array $messages): string
    {
        $res = $this->http()->post('/chat/completions', [
            'model' => $model->provider_model,
            'messages' => $messages,
        ])->throw()->json();

        $text = $res['choices'][0]['message']['content'] ?? null;
        if (! is_string($text) || $text === '') {
            throw new RuntimeException('Empty answer from the model');
        }

        return $text;
    }

    public function media(Generation $generation, AiModel $model, ?string $inputPath): MediaResult
    {
        if ($model->kind !== 'image') {
            throw new RuntimeException('The openai driver generates images only');
        }

        $format = $generation->params['format'] ?? '1:1';
        // Gateways such as HydraAI size images by aspect ratio ("2:3"), OpenAI by pixels.
        $size = config('neurogpt.providers.openai.image_size') === 'ratio'
            ? match ($format) { '9:16' => '2:3', '16:9' => '3:2', default => '1:1' }
            : match ($format) { '9:16' => '1024x1536', '16:9' => '1536x1024', default => '1024x1024' };
        $prompt = $generation->prompt;
        if (! empty($generation->params['style']) && $generation->params['style'] !== 'любой') {
            $prompt .= ', стиль: '.$generation->params['style'];
        }
        if (! empty($generation->params['negative'])) {
            $prompt .= '. Без: '.$generation->params['negative'];
        }

        if ($inputPath && config('neurogpt.providers.openai.image_edit') === 'image_url') {
            // Gateways such as HydraAI take the source picture as a data URI in a JSON field.
            $mime = mime_content_type($inputPath) ?: 'image/png';
            $res = $this->http()->post('/images/generations', [
                'model' => $model->provider_model,
                'prompt' => $prompt,
                'size' => $size,
                'n' => 1,
                'response_format' => 'b64_json',
                'image_url' => 'data:'.$mime.';base64,'.base64_encode(file_get_contents($inputPath)),
            ])->throw()->json();
        } elseif ($inputPath) {
            // Photoset: the user's photo is the base of the edit.
            $res = $this->http()->asMultipart()
                ->attach('image', file_get_contents($inputPath), basename($inputPath))
                ->post('/images/edits', [
                    ['name' => 'model', 'contents' => $model->provider_model],
                    ['name' => 'prompt', 'contents' => $prompt],
                    ['name' => 'size', 'contents' => $size],
                ])->throw()->json();
        } else {
            $res = $this->http()->post('/images/generations', [
                'model' => $model->provider_model,
                'prompt' => $prompt,
                'size' => $size,
                'n' => 1,
            ])->throw()->json();
        }

        $item = $res['data'][0] ?? [];
        $b64 = $item['b64_json'] ?? null;
        $url = $item['url'] ?? null;

        // Some gateways put a data URI or a plain link into b64_json.
        if (is_string($b64) && preg_match('#^https?://#i', $b64)) {
            [$url, $b64] = [$b64, null];
        }
        if (is_string($b64) && str_starts_with($b64, 'data:')) {
            $b64 = substr($b64, (int) strpos($b64, ',') + 1);
        }
        if (is_string($url) && str_starts_with($url, 'data:')) {
            [$b64, $url] = [substr($url, (int) strpos($url, ',') + 1), null];
        }

        $bytes = null;
        if (! empty($b64)) {
            $bytes = base64_decode(preg_replace('/\s+/', '', $b64), true) ?: null;
        } elseif (! empty($url)) {
            $bytes = Http::timeout(60)->get($url)->throw()->body();
        }

        if ($bytes !== null) {
            if (! @getimagesizefromstring($bytes)) {
                Log::warning('Provider returned data that is not an image', ['head' => substr(bin2hex(substr($bytes, 0, 16)), 0, 32), 'size' => strlen($bytes), 'keys' => array_keys($item)]);
                throw new RuntimeException('The provider returned invalid image data');
            }

            return ImageNormalizer::normalize($bytes);
        }

        throw new RuntimeException('The provider returned no image');
    }
}
