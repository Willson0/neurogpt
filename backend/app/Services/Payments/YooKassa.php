<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Thin client for the YooKassa REST API. Credentials come from .env only. */
class YooKassa
{
    private function http(?string $idempotenceKey = null): PendingRequest
    {
        $shop = config('neurogpt.yookassa.shop_id');
        $secret = config('neurogpt.yookassa.secret_key');
        if (! $shop || ! $secret) {
            throw new RuntimeException('YooKassa is not configured: set YOOKASSA_SHOP_ID and YOOKASSA_SECRET_KEY');
        }

        $req = Http::withBasicAuth($shop, $secret)->baseUrl(config('neurogpt.yookassa.api_url'))->timeout(30)->acceptJson();

        return $idempotenceKey ? $req->withHeaders(['Idempotence-Key' => $idempotenceKey]) : $req;
    }

    public function createPayment(string $idempotenceKey, float $amount, string $description, string $returnUrl, array $metadata, ?array $receipt = null): array
    {
        $body = [
            'amount' => ['value' => number_format($amount, 2, '.', ''), 'currency' => 'RUB'],
            'capture' => true,
            'confirmation' => ['type' => 'redirect', 'return_url' => $returnUrl],
            'description' => mb_substr($description, 0, 128),
            'metadata' => $metadata,
        ];
        if ($receipt) {
            $body['receipt'] = $receipt;
        }

        return $this->http($idempotenceKey)->post('/payments', $body)->throw()->json();
    }

    public function getPayment(string $id): array
    {
        return $this->http()->get('/payments/'.rawurlencode($id))->throw()->json();
    }
}
