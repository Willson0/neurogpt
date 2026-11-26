<?php

namespace App\Services\Admin;

use App\Exceptions\ContentFiltered;
use App\Models\AiModel;
use App\Models\Generation;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Diagnostics from AI providers. Store identifiers and categories only, never headers, inputs or response bodies. */
class ProviderJournal
{
    public const PROVIDERS = ['kie', 'openai', 'replicate'];

    public static function request(
        string $provider,
        string $operation,
        Closure $send,
        ?AiModel $model = null,
        ?Generation $generation = null,
    ): Response {
        $start = hrtime(true);
        try {
            $response = $send();
        } catch (Throwable $e) {
            self::write($provider, $operation, 'error', $model, $generation, [
                'reason' => self::reason(null, $e->getMessage()),
                'duration_ms' => self::elapsed($start),
            ]);
            throw $e;
        }

        try {
            self::recordResponse($provider, $operation, $response, $model, $generation, self::elapsed($start));
        } catch (Throwable $e) {
            self::reportUnavailable($e);
        }

        return $response;
    }

    public static function failure(string $provider, string $operation, ?AiModel $model, ?Generation $generation, Throwable $error): void
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            return;
        }

        self::write($provider, $operation, 'error', $model, $generation, [
            'reason' => $error instanceof ContentFiltered ? 'moderation' : self::reason(null, $error->getMessage()),
        ]);
    }

    private static function recordResponse(string $provider, string $operation, Response $response, ?AiModel $model, ?Generation $generation, int $elapsed): void
    {
        $status = $response->status();
        $body = [];
        if ($operation !== 'download') {
            $body = $response->json();
            $body = is_array($body) ? $body : [];
        }

        $state = $body['data']['state'] ?? $body['status'] ?? null;
        $failedTask = in_array($state, ['fail', 'failed', 'canceled'], true);
        $apiCode = $provider === 'kie'
            ? ($failedTask ? ($body['data']['failCode'] ?? $body['code'] ?? null) : ($body['code'] ?? null))
            : ($body['error']['code'] ?? $body['error']['type'] ?? null);
        $message = $body['data']['failMsg'] ?? $body['error']['message'] ?? $body['msg'] ?? $body['detail'] ?? $body['error'] ?? '';
        $failed = $status >= 400 || $failedTask || ! empty($body['error'])
            || ($provider === 'kie' && is_numeric($apiCode) && (int) $apiCode !== 200);
        if ($operation === 'poll' && ! $failed && ! in_array($state, ['success', 'succeeded'], true)) {
            return;
        }

        $task = $body['data']['taskId'] ?? ($provider === 'replicate' ? ($body['id'] ?? null) : null);
        self::write($provider, $operation, $failed ? 'error' : 'ok', $model, $generation, [
            'http_status' => $status,
            'provider_code' => self::identifier($apiCode, 48),
            'task_id' => self::identifier($task ?? $generation?->provider_task_id),
            'request_id' => self::identifier($response->header('X-Request-Id')),
            'duration_ms' => $elapsed,
            'reason' => $failed ? self::reason($status >= 400 ? $status : (is_numeric($apiCode) ? (int) $apiCode : null), is_string($message) ? $message : '') : null,
        ]);
    }

    private static function write(string $provider, string $operation, string $outcome, ?AiModel $model, ?Generation $generation, array $fields): void
    {
        try {
            if ($provider === 'kie' && str_starts_with($operation, 'upload')) {
                $url = config($operation === 'upload_media' ? 'neurogpt.providers.kie.stream_upload_url' : 'neurogpt.providers.kie.upload_url');
            } else {
                $url = config("neurogpt.providers.$provider.base_url");
            }

            DB::table('wa_provider_events')->insert($fields + [
                'provider' => $provider,
                'host' => self::identifier(parse_url((string) $url, PHP_URL_HOST), 255),
                'operation' => $operation,
                'outcome' => $outcome,
                'model' => self::identifier($model?->slug ?? $generation?->model?->slug, 64),
                'feature' => self::identifier($generation?->params['music']['operation'] ?? $generation?->kind, 32),
                'generation_id' => $generation?->id,
                'task_id' => self::identifier($generation?->provider_task_id),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            self::reportUnavailable($e);
        }
    }

    private static function elapsed(int $start): int
    {
        return max(0, (int) ((hrtime(true) - $start) / 1_000_000));
    }

    private static function identifier(mixed $value, int $limit = 128): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $value = (string) $value;
        if (strlen($value) > $limit || ! preg_match('/^[A-Za-z0-9_:.-]+$/D', $value)) {
            return null;
        }

        // An upstream error can echo a credential in a field that normally contains an identifier.
        foreach (['openai.api_key', 'kie.api_key', 'replicate.api_token'] as $key) {
            $secret = (string) config('neurogpt.providers.'.$key);
            if ($secret !== '' && str_contains($value, $secret)) {
                return null;
            }
        }

        return $value;
    }

    public static function isCopyright(string $message): bool
    {
        return (bool) preg_match('/copyright|copy.right|rights.holder|intellectual property|dmca|protected (song|audio|track)|matches (an? )?existing (work|song|track)|авторск/ui', $message);
    }

    private static function reason(?int $code, string $message): string
    {
        return match (true) {
            self::isCopyright($message) => 'copyright',
            $code === 402 || preg_match('/credits|insufficient.quota|billing|payment.required/i', $message) => 'credits',
            in_array($code, [401, 403], true) || preg_match('/api.key|api.token|unauthori[sz]ed|authentication/i', $message) => 'authorization',
            $code === 429 || preg_match('/rate.limit|too.many.requests/i', $message) => 'rate_limit',
            preg_match('/moderation|content.policy|nsfw|safety|content.filtered|sensitive/i', $message) => 'moderation',
            $code === 413 => 'size',
            $code === 404 => 'not_found',
            $code === 408 || preg_match('/timed? ?out|timeout/i', $message) => 'timeout',
            preg_match('/curl|connect|network|dns|ssl/i', $message) => 'connection',
            in_array($code, [400, 415, 422], true) || preg_match('/invalid (input|parameter)|missing|required field|unsupported/i', $message) => 'input',
            preg_match('/empty|invalid (image|audio)|no file|incomplete|unexpected|no image/i', $message) => 'result',
            $code >= 500 => 'upstream',
            default => 'unknown',
        };
    }

    private static function reportUnavailable(Throwable $error): void
    {
        Log::warning('Provider journal unavailable', ['exception' => $error::class]);
    }
}
