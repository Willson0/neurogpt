<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $u = $this->resource;

        return [
            'id' => $u->id,
            'telegram_id' => $u->telegram_id,
            'name' => $u->displayName(),
            'username' => $u->username,
            'photo_url' => $u->photo_url,
            'balance' => $u->balance,
            'plan' => $u->activePlan(),
            'plan_expires_at' => $u->activePlan() === 'free' ? null : $u->plan_expires_at?->toIso8601String(),
            'streak' => $u->streak,
            'daily_bonus_available' => ! $u->last_bonus_on || ! $u->last_bonus_on->isToday(),
            'stats' => [
                'created' => $u->generations()->where('status', 'done')->count(),
                'spent' => (int) abs($u->transactions()->where('type', 'spend')->sum('amount')),
            ],
        ];
    }
}
