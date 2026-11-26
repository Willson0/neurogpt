<?php

namespace App\Services\Generation;

use App\Models\AiModel;
use App\Models\Generation;
use App\Services\Admin\ProviderJournal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** OpenAI-compatible API: OpenAI itself, OpenRouter, or any gateway that speaks the same dialect. */
class OpenAiProvider implements Provider
{
    private function http(?int $timeout = null)
    {
        $cfg = config('neurogpt.providers.openai');
        if (! $cfg['api_key']) {
            throw new RuntimeException('OPENAI_API_KEY is not set');
        }

        return Http::withToken($cfg['api_key'])->baseUrl(rtrim($cfg['base_url'], '/'))->timeout($timeout ?? $cfg['timeout'])->connectTimeout(15)->acceptJson();
    }

    public function chat(AiModel $model, array $messages, ?int $timeout = null, ?Generation $generation = null): string
    {
        $res = ProviderJournal::request('openai', 'chat', fn () => $this->http($timeout)->post('/chat/completions', [
            'model' => $model->provider_model,
            'messages' => $messages,
        ]), $model, $generation)->throw()->json();

        $text = $res['choices'][0]['message']['content'] ?? null;
        if (! is_string($text) || trim($text) === '') {
            $error = new RuntimeException('Empty answer from the model');
            ProviderJournal::failure('openai', 'chat_result', $model, $generation, $error);
            throw $error;
        }

        return $text;
    }

    public function music(Generation $generation, string $operation): MediaResult
    {
        $input = $generation->params['music']['input'];
        $lyrics = $operation === 'lyrics';
        $model = new AiModel([
            'kind' => $lyrics ? 'text' : 'image',
            'provider_model' => config('neurogpt.providers.openai.'.($lyrics ? 'text_model' : 'image_model')),
        ]);
        if ($lyrics) {
            $text = $this->chat($model, [
                ['role' => 'system', 'content' => 'Write original song lyrics in the language requested, or the language of the brief. Return only lyrics with section markers such as [Verse] and [Chorus]. Keep the result below 5000 characters.'],
                ['role' => 'user', 'content' => $input['prompt']],
            ], null, $generation);

            return new MediaResult('', '', ['lyrics' => [['title' => 'Текст песни', 'text' => mb_substr(trim($text), 0, 5000)]]]);
        }

        $generation->finalPrompt = $input['prompt'];
        $image = $this->media($generation, $model, null);

        return new MediaResult($image->bytes, $image->extension, ['cover' => true]);
    }

    public function media(Generation $generation, AiModel $model, ?string $inputPath): MediaResult
    {
        if (! in_array($model->kind, ['image', 'tool'], true)) {
            throw new RuntimeException('The openai driver generates images only');
        }

        $format = $generation->params['format'] ?? '1:1';
        // Gateways such as HydraAI size images by aspect ratio ("2:3"), OpenAI by pixels.
        $size = config('neurogpt.providers.openai.image_size') === 'ratio'
            ? match ($format) { '9:16' => '2:3', '16:9' => '3:2', default => '1:1' }
            : match ($format) { '9:16' => '1024x1536', '16:9' => '1536x1024', default => '1024x1024' };
        $prompt = $generation->providerPrompt();

        if ($inputPath && config('neurogpt.providers.openai.image_edit') === 'image_url') {
            // Gateways such as HydraAI take the source picture as a data URI in a JSON field.
            $mime = mime_content_type($inputPath) ?: 'image/png';
            $res = ProviderJournal::request('openai', 'image_edit', fn () => $this->http()->post('/images/generations', [
                'model' => $model->provider_model,
                'prompt' => $prompt,
                'size' => $size,
                'n' => 1,
                'response_format' => 'b64_json',
                'image_url' => 'data:'.$mime.';base64,'.base64_encode(file_get_contents($inputPath)),
            ]), $model, $generation)->throw()->json();
        } elseif ($inputPath) {
            // Photoset: the user's photo is the base of the edit.
            $res = ProviderJournal::request('openai', 'image_edit', fn () => $this->http()->asMultipart()
                ->attach('image', file_get_contents($inputPath), basename($inputPath))
                ->post('/images/edits', [
                    ['name' => 'model', 'contents' => $model->provider_model],
                    ['name' => 'prompt', 'contents' => $prompt],
                    ['name' => 'size', 'contents' => $size],
                ]), $model, $generation)->throw()->json();
        } else {
            $res = ProviderJournal::request('openai', 'image', fn () => $this->http()->post('/images/generations', [
                'model' => $model->provider_model,
                'prompt' => $prompt,
                'size' => $size,
                'n' => 1,
            ]), $model, $generation)->throw()->json();
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
            if (! preg_match('#^https://#i', $url)) {
                throw new RuntimeException('The provider returned an unsafe link');
            }
            $bytes = ProviderJournal::request('openai', 'download',
                fn () => Http::timeout(60)->get($url), $model, $generation)->throw()->body();
        }

        if ($bytes !== null) {
            if (! @getimagesizefromstring($bytes)) {
                Log::warning('Provider returned data that is not an image', ['head' => substr(bin2hex(substr($bytes, 0, 16)), 0, 32), 'size' => strlen($bytes), 'keys' => array_keys($item)]);
                throw new RuntimeException('The provider returned invalid image data');
            }

            return ImageNormalizer::normalize($bytes, 2048, $generation->kind === 'tool' && config("neurogpt.tools.{$generation->tool}.output") === 'png');
        }

        throw new RuntimeException('The provider returned no image');
    }
}
