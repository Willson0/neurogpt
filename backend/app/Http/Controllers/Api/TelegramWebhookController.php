<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Telegram\Bot;
use App\Services\Telegram\UserResolver;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, Bot $bot, UserResolver $users)
    {
        $secret = config('neurogpt.telegram.webhook_secret');
        abort_unless($secret && hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);

        $message = $request->input('message');
        if (! is_array($message) || empty($message['from']['id']) || ($message['chat']['type'] ?? '') !== 'private') {
            return response()->json(['ok' => true]);
        }

        $text = (string) ($message['text'] ?? '');
        if (str_starts_with($text, '/start')) {
            $param = trim(substr($text, 6)) ?: null;
            $users->upsert($message['from'], $param);   // registers the referral link before the app opens
            $bot->sendMessage($message['chat']['id'], "Привет! Здесь можно создавать картинки, видео и общаться с нейросетями.\nНажмите кнопку, чтобы открыть приложение.", $bot->openAppKeyboard('Открыть NeuroGPT'));
        }

        return response()->json(['ok' => true]);
    }
}
