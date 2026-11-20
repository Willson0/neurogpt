<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Bot
{
    /** The bot token is part of every API URL, so it must never reach a log line. */
    public static function scrub(string $text): string
    {
        $token = (string) config('neurogpt.telegram.bot_token');
        $text = $token !== '' ? str_replace($token, '<bot-token>', $text) : $text;

        return (string) preg_replace('#/bot\d+:[\w-]+#', '/bot<bot-token>', $text);
    }

    private function call(string $method, array $payload = [], array $files = []): ?array
    {
        $token = config('neurogpt.telegram.bot_token');
        if (! $token) {
            return null;
        }

        $req = Http::timeout($files ? 120 : 20)->connectTimeout(10);
        foreach ($files as $field => [$name, $contents]) {
            $req = $req->attach($field, $contents, $name);
        }
        // Multipart bodies carry plain strings only: nested values travel as JSON.
        if ($files) {
            $payload = array_map(fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v, $payload);
        }

        try {
            $res = $req->post("https://api.telegram.org/bot{$token}/{$method}", $payload);
        } catch (ConnectionException $e) {
            Log::warning('Telegram API unreachable', ['method' => $method, 'error' => self::scrub($e->getMessage())]);

            return null;
        }
        if (! $res->ok()) {
            Log::warning('Telegram API error', ['method' => $method, 'status' => $res->status(), 'body' => self::scrub($res->body())]);

            return null;
        }

        $result = $res->json('result');

        return is_array($result) ? $result : ['ok' => (bool) $result, 'value' => $result];
    }

    public function sendMessage(int $chatId, string $text, array $extra = []): ?array
    {
        return $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text] + $extra);
    }

    public function sendPhoto(int $chatId, string $contents, string $filename, ?string $caption = null): ?array
    {
        return $this->call('sendPhoto', array_filter(['chat_id' => $chatId, 'caption' => $caption]), ['photo' => [$filename, $contents]]);
    }

    public function sendVideo(int $chatId, string $contents, string $filename, ?string $caption = null): ?array
    {
        return $this->call('sendVideo', array_filter(['chat_id' => $chatId, 'caption' => $caption, 'supports_streaming' => 'true']), ['video' => [$filename, $contents]]);
    }

    public function sendAudio(int $chatId, string $contents, string $filename, ?string $caption = null): ?array
    {
        return $this->call('sendAudio', array_filter(['chat_id' => $chatId, 'caption' => $caption]), ['audio' => [$filename, $contents]]);
    }

    public function sendDocument(int $chatId, string $contents, string $filename, ?string $caption = null): ?array
    {
        return $this->call('sendDocument', array_filter(['chat_id' => $chatId, 'caption' => $caption]), ['document' => [$filename, $contents]]);
    }

    public function openAppKeyboard(string $label = 'Открыть', ?string $screen = null): array
    {
        return ['reply_markup' => ['inline_keyboard' => [[[
            'text' => $label,
            'web_app' => ['url' => self::webAppUrl($screen)],
        ]]]]];
    }

    /**
     * Mini App URL for a web_app button that opens the app on a given screen: https://<app>/?startapp=<screen>.
     * The screen syntax is described in frontend/src/services/deeplink.js and the README.
     */
    public static function webAppUrl(?string $screen = null): string
    {
        $url = rtrim((string) config('neurogpt.telegram.webapp_url'), '/').'/';
        if (! self::isScreen($screen)) {
            return $url;
        }

        return $url.'?startapp='.$screen;
    }

    /** A value the app understands as a screen: the startapp alphabet, and not a referral code. */
    public static function isScreen(?string $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) && ! preg_match('/^(?:friend|ref)_\d+$/', $value);
    }

    /** Direct link that opens the Mini App inside Telegram, or null when the bot name is not set. */
    public static function directLink(?string $screen = null): ?string
    {
        $bot = config('neurogpt.telegram.bot_username');
        if (! $bot) {
            return null;
        }
        $app = (string) config('neurogpt.telegram.webapp_short_name');
        $base = $app !== '' ? "https://t.me/{$bot}/{$app}" : "https://t.me/{$bot}";

        return self::isScreen($screen) ? "{$base}?startapp={$screen}" : ($app !== '' ? $base : "{$base}?startapp");
    }

    /** Menu button of the bot (next to the text field): opens the Mini App. */
    public function setMenuButton(string $text = 'Открыть'): ?array
    {
        return $this->call('setChatMenuButton', ['menu_button' => [
            'type' => 'web_app', 'text' => $text, 'web_app' => ['url' => self::webAppUrl()],
        ]]);
    }

    public function setWebhook(string $url, string $secret): ?array
    {
        // pre_checkout_query is required for Telegram Stars payments.
        return $this->call('setWebhook', [
            'url' => $url, 'secret_token' => $secret, 'allowed_updates' => ['message', 'pre_checkout_query'],
        ]);
    }

    public function setCommands(): ?array
    {
        return $this->call('setMyCommands', ['commands' => [
            ['command' => 'start', 'description' => 'Открыть NeuroGPT'],
            ['command' => 'paysupport', 'description' => 'Вопросы по оплате'],
        ]]);
    }

    // ---- Telegram Stars ----

    /** Invoice link for a payment in Stars (currency XTR, no provider token). */
    public function createStarsInvoice(string $title, string $description, string $payload, int $stars): ?string
    {
        $res = $this->call('createInvoiceLink', [
            'title' => mb_substr($title, 0, 32),
            'description' => mb_substr($description, 0, 255),
            'payload' => $payload,
            'provider_token' => '',
            'currency' => 'XTR',
            'prices' => [['label' => mb_substr($title, 0, 32), 'amount' => $stars]],
        ]);
        $link = $res['value'] ?? null;

        return is_string($link) && str_starts_with($link, 'https://') ? $link : null;
    }

    public function answerPreCheckout(string $queryId, bool $ok, ?string $error = null): ?array
    {
        return $this->call('answerPreCheckoutQuery', array_filter([
            'pre_checkout_query_id' => $queryId, 'ok' => $ok, 'error_message' => $ok ? null : ($error ?? 'Платёж не найден'),
        ], fn ($v) => $v !== null));
    }

    public function refundStars(int $telegramUserId, string $chargeId): ?array
    {
        return $this->call('refundStarPayment', ['user_id' => $telegramUserId, 'telegram_payment_charge_id' => $chargeId]);
    }

    /** Smallest-but-not-tiny profile photo of a user as raw image bytes, or null. */
    public function profilePhoto(int $userId): ?string
    {
        $photos = $this->call('getUserProfilePhotos', ['user_id' => $userId, 'limit' => 1]);
        $sizes = $photos['photos'][0] ?? [];
        if (! $sizes) {
            return null;
        }
        $pick = collect($sizes)->first(fn ($s) => ($s['width'] ?? 0) >= 160) ?? end($sizes);

        return $this->download($pick['file_id']);
    }

    /** The bytes of a file the bot received or sent (a Telegram file_id), or null. Works only with the same bot token. */
    public function download(string $fileId): ?string
    {
        $file = $this->call('getFile', ['file_id' => $fileId]);
        $token = config('neurogpt.telegram.bot_token');
        if (empty($file['file_path']) || ! $token) {
            return null;
        }

        try {
            $res = Http::timeout(30)->get("https://api.telegram.org/file/bot{$token}/{$file['file_path']}");
        } catch (ConnectionException) {
            return null;
        }

        return $res->ok() ? $res->body() : null;
    }
}
