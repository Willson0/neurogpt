<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentService;
use App\Services\Telegram\Bot;
use App\Services\Telegram\UserResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, Bot $bot, UserResolver $users, PaymentService $payments)
    {
        // Telegram signs every update with the secret given to setWebhook; anything else is not Telegram.
        $secret = (string) config('neurogpt.telegram.webhook_secret');
        abort_unless($secret !== '' && hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);

        try {
            if (is_array($query = $request->input('pre_checkout_query'))) {
                $this->preCheckout($query, $bot, $payments);
            } elseif (is_array($message = $request->input('message'))) {
                $this->message($message, $bot, $users, $payments);
            }
        } catch (Throwable $e) {
            // A 500 would make Telegram resend the same update for hours; the error is logged instead.
            Log::error('Telegram update failed', ['error' => Bot::scrub($e->getMessage())]);
        }

        return response()->json(['ok' => true]);
    }

    private function preCheckout(array $query, Bot $bot, PaymentService $payments): void
    {
        $payment = $payments->findByStarsPayload($query['invoice_payload'] ?? null);
        $error = $payments->checkStars(
            $payment, (int) ($query['from']['id'] ?? 0), (string) ($query['currency'] ?? ''), (int) ($query['total_amount'] ?? 0),
        );

        $bot->answerPreCheckout((string) ($query['id'] ?? ''), $error === null, $error);
    }

    private function message(array $message, Bot $bot, UserResolver $users, PaymentService $payments): void
    {
        if (empty($message['from']['id']) || ($message['chat']['type'] ?? '') !== 'private' || ! empty($message['from']['is_bot'])) {
            return;
        }
        $chatId = (int) $message['chat']['id'];

        if (is_array($paid = $message['successful_payment'] ?? null)) {
            $this->paid($paid, $message, $chatId, $bot, $payments);

            return;
        }

        $text = trim((string) ($message['text'] ?? ''));
        if (preg_match('/^\/start(?:@\w+)?(?:\s+(\S{1,64}))?/', $text, $m)) {
            $param = $m[1] ?? null;
            $users->upsert($message['from'], $param);   // registers the referral link before the app opens
            // t.me/<bot>?start=<screen> (e.g. start=plans-8): the button opens the app right on that screen
            $bot->sendMessage($chatId, "Привет! Здесь нейросети рисуют картинки, снимают видео и обрабатывают фото.\nНажмите кнопку, чтобы открыть приложение.", $bot->openAppKeyboard('Открыть NeuroGPT', Bot::isScreen($param) ? $param : null));
        } elseif (preg_match('/^\/(paysupport|help)\b/', $text)) {
            $support = config('neurogpt.telegram.support_url');
            $bot->sendMessage($chatId, "Вопросы по оплате и возвратам.\nНапишите, что случилось, и приложите дату платежа".($support ? ": $support" : ' в этот чат, мы ответим здесь.'));
        }
    }

    private function paid(array $paid, array $message, int $chatId, Bot $bot, PaymentService $payments): void
    {
        $payment = $payments->findByStarsPayload($paid['invoice_payload'] ?? null);
        $chargeId = (string) ($paid['telegram_payment_charge_id'] ?? '');

        if (! $payment || $payment->user?->telegram_id !== (int) $message['from']['id'] || $chargeId === '') {
            Log::critical('Stars payment without a matching order', ['payload' => $paid['invoice_payload'] ?? null, 'charge' => $chargeId]);

            return;
        }

        $done = $payments->confirmStars($payment, (string) ($paid['currency'] ?? ''), (int) ($paid['total_amount'] ?? 0), $chargeId, $paid);

        if ($done->status === 'succeeded') {
            $what = $done->purpose === 'plan'
                ? 'Подписка '.$done->subscriptionName().' активна.'.($done->tokens ? ' Начислено '.$done->tokens.' токенов.' : '')
                : 'Начислено '.$done->tokens.' токенов.';
            $bot->sendMessage($chatId, 'Оплата прошла. '.$what, $bot->openAppKeyboard('Открыть NeuroGPT', 'profile'));
        } else {
            // The amount did not match the order: the Stars go back to the user.
            $bot->refundStars((int) $message['from']['id'], $chargeId);
            $bot->sendMessage($chatId, 'Не получилось зачислить оплату, звёзды вернутся на ваш счёт. Попробуйте ещё раз из приложения.');
        }
    }
}
