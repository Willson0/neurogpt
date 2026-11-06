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
            'purpose' => $p->purpose,
            'tokens' => $p->tokens,
            'plan' => $p->plan,
            'period' => $p->period,
            'amount' => (float) $p->amount,
            'confirmation_url' => $p->status === 'pending' ? $p->confirmation_url : null,
        ];
    }
}
