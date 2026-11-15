<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $p = $this->resource;

        return [
            'id' => $p->id,
            'status' => $p->status,
            'method' => $p->provider === 'stars' ? 'stars' : 'card',
            'purpose' => $p->purpose,
            'tokens' => $p->tokens,
            'plan' => $p->plan,
            'period' => $p->period,
            'amount' => (float) $p->total,
            'currency' => $p->currency,
            'item_id' => $p->item_id,
            // rubles before and after a promo code
            'price' => (float) ($p->details?->price ?? $p->rub_total),
            'rub_amount' => (float) $p->rub_total,
            'promo' => $p->details?->promo_code,
            'discount' => (int) ($p->details?->discount ?? 0),
            // card: the YooKassa page; stars: the invoice link for WebApp.openInvoice
            'confirmation_url' => $p->status === 'pending' ? $p->confirmation_url : null,
        ];
    }
}
