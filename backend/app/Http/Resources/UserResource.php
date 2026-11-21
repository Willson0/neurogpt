<?php

namespace App\Http\Resources;

use App\Http\Middleware\AdminOnly;
use App\Services\Payments\SubscriptionCatalog;
use App\Services\Payments\RenewSubscriptions;
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
            // The bot's three counters behind the balance: bought/bonus, subscription, temporary.
            'balance_parts' => $u->balanceParts(),
            'plan' => $u->activePlan(),
            'plan_name' => SubscriptionCatalog::name($u->activePlan()),
            'plan_expires_at' => $u->activePlan() === 'free' ? null : $u->plan_expires_at?->toIso8601String(),
            'auto_renewal' => $u->auto_continue && $u->last_payment_method_id
                && RenewSubscriptions::mandate($u->id)->first()?->renew_opt_in === true,
            'streak' => $u->streak,
            'is_admin' => AdminOnly::allows($u),
            'is_owner' => AdminOnly::isOwner($u),
            'daily_bonus_available' => ! $u->last_bonus_on || ! $u->last_bonus_on->isToday(),
            'invite_link' => $u->inviteLink(),
            'invited' => $u->newQuery()->where('referal_user_from', $u->id)->count(),
            'stats' => [
                'created' => $u->generations()->where('status', 'done')->count(),
                'spent' => (int) abs($u->transactions()->where('type', 'spend')->sum('amount')),
            ],
        ];
    }
}
