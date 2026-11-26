<?php

namespace App\Http\Controllers\Api\Admin;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ProviderBalanceController
{
    public function __invoke(string $provider)
    {
        $kie = $provider === 'kie';
        $config = config('neurogpt.providers.'.($kie ? 'kie' : 'openai'));
        abort_unless($config['api_key'], 503, 'Ключ API для этого провайдера не настроен.');
        $base = rtrim($config['base_url'], '/');
        if (! $kie) {
            abort_unless(parse_url($base, PHP_URL_HOST) === 'api.hydraai.ru', 503, 'Для Hydra AI укажите его адрес в OPENAI_BASE_URL.');
        }

        try {
            $response = Http::withToken($config['api_key'])->acceptJson()->timeout(10)->connectTimeout(5)
                ->withoutRedirecting()->get($base.($kie ? '/api/v1/chat/credit' : '/users/profile'));
        } catch (ConnectionException) {
            abort(502, 'Провайдер не ответил. Попробуйте ещё раз.');
        }
        $code = $kie ? (int) $response->json('code') : $response->status();
        abort_if(in_array($code, [401, 403], true) || in_array($response->status(), [401, 403], true), 502, 'Провайдер отклонил ключ API.');
        $amount = $response->json($kie ? 'data' : 'balance');
        abort_unless($response->successful() && $code === 200 && is_numeric($amount), 502, 'Провайдер не вернул баланс.');

        return response()->json(['amount' => (float) $amount, 'unit' => $kie ? 'credits' : 'RUB']);
    }
}
