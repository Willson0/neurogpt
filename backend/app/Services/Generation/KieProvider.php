<?php

namespace App\Services\Generation;

use App\Exceptions\ContentFiltered;
use App\Exceptions\ProviderUnavailable;
use App\Models\AiModel;
use App\Models\Generation;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * kie.ai jobs API (https://docs.kie.ai): video models, image-to-video, upscale and background removal.
 * Every model goes through the same two calls, POST /api/v1/jobs/createTask and
 * GET /api/v1/jobs/recordInfo; only the `input` object differs, picked by the `family` option.
 * Source pictures are uploaded to kie's file storage first, because the API takes links, not files.
 */
class KieProvider implements AsyncProvider, Provider
{
    private const MAX_RESULT_BYTES = 80 * 1024 * 1024;

    public function __construct(private readonly KieMusic $music) {}

    private function http(int $timeout = 60): PendingRequest
    {
        $key = config('neurogpt.providers.kie.api_key');
        if (! $key) {
            throw new ProviderUnavailable('KIE_API_KEY is not set');
        }

        return Http::withToken($key)->timeout($timeout)->connectTimeout(15)->acceptJson();
    }

    private function url(string $path): string
    {
        return rtrim(config('neurogpt.providers.kie.base_url'), '/').$path;
    }

    public function chat(AiModel $model, array $messages, ?int $timeout = null): string
    {
        throw new RuntimeException('The kie driver does not serve text');
    }

    /** Blocking variant for a synchronous queue: submit, then poll until the task ends. */
    public function media(Generation $generation, AiModel $model, ?string $inputPath): MediaResult
    {
        $generation->provider_task_id = $this->submit($generation, $model, $inputPath);
        $deadline = time() + (int) config('neurogpt.limits.generation_timeout');

        while (time() < $deadline) {
            sleep(5);
            if ($result = $this->fetch($generation, $model)) {
                return $result;
            }
        }

        throw new RuntimeException('Generation timed out');
    }

    public function submit(Generation $generation, AiModel $model, ?string $inputPath): string
    {
        $payload = isset($generation->params['music'])
            ? $this->music->request($generation, $inputPath ? $this->uploadAudio($inputPath) : null)
            : ['model' => $model->provider_model, 'input' => $this->input($generation, $model, $inputPath ? $this->upload($inputPath) : null)];
        return $this->taskId($this->create($payload));
    }

    private function taskId(array $data): string
    {
        $taskId = $data['taskId'] ?? null;
        if ((! is_string($taskId) && ! is_int($taskId)) || (string) $taskId === '') {
            throw new RuntimeException('kie.ai returned no task ID');
        }

        return (string) $taskId;
    }

    public function musicMetadata(Generation $generation): string|MediaResult
    {
        $result = $this->create($this->music->request($generation, null));
        $music = $generation->params['music'];
        if ($music['operation'] === 'boost_style') {
            return ! empty($result['result']) ? $this->music->style($result['result']) : $this->taskId($result);
        }

        return $this->music->profile((string) ($result['persona_id'] ?? ''), 'style_persona', $music['name']);
    }

    private function create(array $payload): array
    {
        $res = $this->http()->post($this->url('/api/v1/jobs/createTask'), $payload);
        $body = $res->json() ?? [];
        $code = (int) ($body['code'] ?? $res->status());
        if ($code !== 200) {
            $this->refuse($code, (string) ($body['msg'] ?? 'createTask failed'));
        }

        return $body['data'] ?? [];
    }

    public function fetch(Generation $generation, AiModel $model): ?MediaResult
    {
        $music = $generation->params['music'] ?? null;
        $taskId = $music ? $this->music->recordId($generation) : $generation->provider_task_id;
        try {
            $res = $this->http(30)->get($this->url('/api/v1/jobs/recordInfo'), ['taskId' => $taskId]);
        } catch (\Illuminate\Http\Client\ConnectionException $exception) {
            if ($music) {
                return null;
            }
            throw $exception;
        }
        $body = $res->json() ?? [];
        $code = (int) ($body['code'] ?? $res->status());

        // Rate limit, maintenance or a hiccup on their side: the task itself is fine, ask again later.
        if ($code !== 200) {
            if (in_array($code, [401, 404, 422], true)) {
                $this->refuse($code, (string) ($body['msg'] ?? 'recordInfo failed'));
            }

            return null;
        }

        $data = $body['data'] ?? [];
        $state = $data['state'] ?? '';

        if ($state === 'fail' && ($music['operation'] ?? null) !== 'voice_retry') {
            $this->refuse((int) ($data['failCode'] ?? 501), (string) ($data['failMsg'] ?? 'Generation failed'));
        }
        $result = $data['resultJson'] ?? null;
        $result = is_string($result) ? json_decode($result, true) : $result;
        if ($music) {
            return $this->music->finished($generation, $data, is_array($result) ? $result : []);
        }
        if ($state !== 'success') {
            return null;
        }

        $url = self::resultUrl(is_array($result) ? $result : []) ?? self::resultUrl(is_array($data['response'] ?? null) ? $data['response'] : []);
        if (! $url) {
            throw new RuntimeException('kie.ai returned no file');
        }

        return $this->download($url, $generation, $model);
    }

    /** The request body differs per model family; see the model pages under docs.kie.ai/market. */
    private function input(Generation $g, AiModel $model, ?string $imageUrl): array
    {
        $opt = $model->options ?? [];
        $prompt = Str::limit($g->providerPrompt(), 2400, '');
        $format = $g->params['format'] ?? '16:9';
        $duration = (int) ($g->params['duration'] ?? $opt['duration'] ?? array_key_first($model->durations()) ?? 5);
        $needsImage = fn () => $imageUrl ?? throw new RuntimeException('This model needs a source image');

        return match ($opt['family'] ?? '') {
            'veo' => array_filter([
                'prompt' => $prompt,
                'model' => $opt['tier'] ?? 'veo3_fast',
                'aspect_ratio' => $imageUrl && $g->kind === 'tool' ? 'Auto' : (in_array($format, ['16:9', '9:16'], true) ? $format : '16:9'),
                'resolution' => $opt['resolution'] ?? '720p',
                'duration' => in_array($duration, [4, 6, 8], true) ? $duration : 8,
                'enable_translation' => true,
                'generation_type' => $imageUrl ? 'FIRST_AND_LAST_FRAMES_2_VIDEO' : 'TEXT_2_VIDEO',
                'image_urls' => $imageUrl ? [$imageUrl] : null,
            ], fn ($v) => $v !== null),
            'kling' => array_filter([
                'prompt' => $prompt,
                'sound' => true,
                'duration' => (string) max(3, min(15, $duration)),
                'aspect_ratio' => in_array($format, ['16:9', '9:16', '1:1'], true) ? $format : '16:9',
                'mode' => $opt['mode'] ?? 'std',
                'multi_shots' => false,
                'image_urls' => $imageUrl ? [$imageUrl] : null,
            ], fn ($v) => $v !== null),
            'seedance' => array_filter([
                'prompt' => $prompt,
                'resolution' => $opt['resolution'] ?? '480p',
                'aspect_ratio' => $imageUrl ? 'adaptive' : $format,
                'duration' => max(4, min(15, $duration)),
                'generate_audio' => true,
                'first_frame_url' => $imageUrl,
            ], fn ($v) => $v !== null),
            'wan' => array_filter([
                'prompt' => $prompt,
                'resolution' => $opt['resolution'] ?? '720P',
                'aspect_ratio' => $imageUrl ? 'adaptive' : $format,
                'duration' => max(2, min(30, $duration)),
                'audio' => true,
                'first_frame_url' => $imageUrl,
            ], fn ($v) => $v !== null),
            'topaz' => ['image_url' => $needsImage(), 'upscale_factor' => (string) ($opt['factor'] ?? 2)],
            'recraft-bg' => ['image' => $needsImage()],
            default => throw new RuntimeException('Unknown kie model family for '.$model->slug),
        };
    }

    /** Uploads the source picture to kie's temporary storage (kept for 24 hours) and returns its link. */
    private function upload(string $path): string
    {
        $mime = mime_content_type($path) ?: 'image/jpeg';
        $ext = match ($mime) { 'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg' };

        $res = $this->http(120)->post(config('neurogpt.providers.kie.upload_url'), [
            'base64Data' => 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path)),
            'uploadPath' => 'neurogpt/inputs',
            'fileName' => Str::uuid().'.'.$ext,
        ]);
        $body = $res->json() ?? [];
        $url = $body['data']['downloadUrl'] ?? null;

        if ((int) ($body['code'] ?? $res->status()) !== 200 || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            $this->refuse((int) ($body['code'] ?? $res->status()), 'file upload: '.($body['msg'] ?? 'no link'));
        }

        return $url;
    }

    private function uploadAudio(string $path): string
    {
        $stream = fopen($path, 'rb');
        if (! $stream) {
            throw new RuntimeException('Cannot read music upload');
        }
        try {
            $response = $this->http(120)->attach('file', $stream, basename($path))
                ->post(config('neurogpt.providers.kie.stream_upload_url'), ['uploadPath' => 'neurogpt/music']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $url = $response->json('data.downloadUrl');
        if ((int) ($response->json('code') ?? $response->status()) !== 200 || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            $this->refuse((int) ($response->json('code') ?? $response->status()), 'audio upload failed');
        }

        return $url;
    }

    private function download(string $url, Generation $g, AiModel $model): MediaResult
    {
        if (! str_starts_with($url, 'https://')) {
            throw new RuntimeException('kie.ai returned an unsafe link');
        }

        $res = Http::timeout(300)->connectTimeout(15)->get($url)->throw();
        $bytes = $res->body();
        if ($bytes === '' || strlen($bytes) > self::MAX_RESULT_BYTES) {
            throw new RuntimeException('The result file is empty or too large');
        }

        $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $video = $model->kind === 'video' || config("neurogpt.tools.{$g->tool}.output") === 'video';

        if ($video) {
            return new MediaResult($bytes, in_array($ext, ['mp4', 'mov', 'webm'], true) ? $ext : 'mp4');
        }
        if (! @getimagesizefromstring($bytes)) {
            throw new RuntimeException('kie.ai returned data that is not an image');
        }

        return ImageNormalizer::normalize($bytes, 4096, config("neurogpt.tools.{$g->tool}.output") === 'png');
    }

    /** First https link found under resultUrls / result_urls (at any depth), then originUrls, then any *url* key. */
    public static function resultUrl(array $result): ?string
    {
        $links = [];
        $walk = function (array $node, string $path) use (&$walk, &$links) {
            foreach ($node as $key => $value) {
                $here = is_string($key) ? $path.'/'.$key : $path;
                if (is_array($value)) {
                    $walk($value, $here);
                } elseif (is_string($value) && str_starts_with($value, 'https://')) {
                    $links[] = [$here, $value];
                }
            }
        };
        $walk($result, '');

        foreach (['/result_?urls?/i', '/origin_?urls?/i', '/url/i'] as $pattern) {
            foreach ($links as [$path, $link]) {
                if (preg_match($pattern, $path)) {
                    return $link;
                }
            }
        }

        return null;
    }

    /** @return never */
    private function refuse(int $code, string $message): void
    {
        if (preg_match('/polic|sensitive|nsfw|violat|prohibit|moderat|safety|flagged|inappropriate|celebrit|minor/i', $message)) {
            throw new ContentFiltered($message);
        }
        if ($code === 402 || preg_match('/insufficient|credits/i', $message)) {
            Log::critical('kie.ai: not enough credits, top up the account', ['message' => $message]);
            throw new ProviderUnavailable('kie.ai account has no credits');
        }
        if (in_array($code, [401, 455, 505], true)) {
            Log::critical('kie.ai refused the request', ['code' => $code, 'message' => $message]);
            throw new ProviderUnavailable("kie.ai is unavailable ($code)");
        }

        throw new RuntimeException("kie.ai error $code: ".Str::limit($message, 300));
    }
}
