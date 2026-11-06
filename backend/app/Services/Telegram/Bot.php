<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Bot
{
    private function call(string $method, array $payload = [], array $files = []): ?array
    {
        $token = config('neurogpt.telegram.bot_token');
        if (! $token) {
            return null;
        }

        $req = Http::timeout(60);
        foreach ($files as $field => [$name, $contents]) {
            $req = $req->attach($field, $contents, $name);
        }

        try {
            $res = $req->post("https://api.telegram.org/bot{$token}/{$method}", $payload);
        } catch (ConnectionException $e) {
            Log::warning('Telegram API unreachable', ['method' => $method, 'error' => $e->getMessage()]);

            return null;
        }
        if (! $res->ok()) {
            Log::warning('Telegram API error', ['method' => $method, 'status' => $res->status(), 'body' => $res->body()]);

            return null;
        }

        return $res->json('result');
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
        return $this->call('sendVideo', array_filter(['chat_id' => $chatId, 'caption' => $caption]), ['video' => [$filename, $contents]]);
    }

    public function sendDocument(int $chatId, string $contents, string $filename, ?string $caption = null): ?array
    {
        return $this->call('sendDocument', array_filter(['chat_id' => $chatId, 'caption' => $caption]), ['document' => [$filename, $contents]]);
    }

    public function openAppKeyboard(string $label = 'Открыть'): array
    {
        return ['reply_markup' => ['inline_keyboard' => [[[
            'text' => $label,
            'web_app' => ['url' => config('neurogpt.telegram.webapp_url')],
        ]]]]];
    }

    public function setWebhook(string $url, string $secret): ?array
    {
        return $this->call('setWebhook', ['url' => $url, 'secret_token' => $secret, 'allowed_updates' => ['message']]);
    }
}
