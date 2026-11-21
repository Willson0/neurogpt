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
            'plan_name' => $p->plan_name,
            'plan_days' => $p->plan_days,
            'shop_item_id' => $p->shop_item_id,
            'period' => $p->period,
            'amount' => (float) $p->amount,
            'currency' => $p->currency,
            // card: the YooKassa page; stars: the invoice link for WebApp.openInvoice
            'confirmation_url' => $p->status === 'pending' ? $p->confirmation_url : null,
        ];
    }
}
