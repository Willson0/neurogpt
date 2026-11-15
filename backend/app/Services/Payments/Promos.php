<?php

namespace App\Services\Payments;

use App\Models\Bot\Promo;
use App\Models\Bot\ShopItem;
use App\Models\Payment;
use App\Models\PromoUse;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Promo codes typed on the payment screen.
 *
 * The code itself is a row of the bot table `promos` (name, discount in percent, uses left: -1 = unlimited), so the bot
 * and the app share the same codes and the same counter. The admin panel adds limits the bot table has no column for
 * (wa_promo_rules): on/off, start and end date, uses per person, only some shop items or one category, a minimum price,
 * new buyers only. A use is counted when the payment is paid, not when the screen is opened.
 */
class Promos
{
    /** Why a code does not apply, as the app shows it. */
    public const ERRORS = [
        'promo_not_found' => 'Такого промокода нет',
        'promo_inactive' => 'Промокод выключен',
        'promo_not_started' => 'Промокод ещё не действует',
        'promo_expired' => 'Срок действия промокода закончился',
        'promo_used_up' => 'Промокод закончился',
        'promo_user_limit' => 'Вы уже использовали этот промокод',
        'promo_not_for_item' => 'Промокод не действует на этот товар',
        'promo_min_price' => 'Сумма покупки меньше, чем нужно для промокода',
        'promo_new_buyers' => 'Промокод только для первой покупки',
    ];

    public static function normalize(?string $code): string
    {
        return mb_strtoupper(trim((string) $code));
    }

    public function find(?string $code): ?Promo
    {
        $code = self::normalize($code);
        if ($code === '' || mb_strlen($code) > 255) {
            return null;
        }

        // Codes are compared without regard to case: "summer" and "SUMMER" are the same code.
        return Promo::with('rules')->whereRaw('upper(name) = ?', [$code])->orderBy('id')->first();
    }

    /**
     * Null when the code can be used by this person for this item, otherwise the error code (a key of ERRORS).
     * $item null = the code is checked without an item (only the general limits).
     */
    public function refusal(?Promo $promo, User $user, ?ShopItem $item): ?string
    {
        if (! $promo || (int) $promo->discount <= 0) {
            return 'promo_not_found';
        }
        $r = $promo->rules;
        if ($r && ! $r->active) {
            return 'promo_inactive';
        }
        if ($r?->starts_at && $r->starts_at->isFuture()) {
            return 'promo_not_started';
        }
        if ($r?->expires_at && $r->expires_at->isPast()) {
            return 'promo_expired';
        }
        if ((int) $promo->uses === 0) {
            return 'promo_used_up';
        }
        if ($r?->per_user && $this->usedBy($promo, $user) >= $r->per_user) {
            return 'promo_user_limit';
        }
        if ($r?->new_buyers_only && Payment::where('user_id', $user->id)->where('was_bought', true)->exists()) {
            return 'promo_new_buyers';
        }
        if ($item) {
            if (($r?->item_ids && ! in_array((int) $item->id, array_map('intval', $r->item_ids), true))
                || ($r?->category && $r->category !== $item->category)) {
                return 'promo_not_for_item';
            }
            if ($r?->min_price && (int) $item->price < $r->min_price) {
                return 'promo_min_price';
            }
        }

        return null;
    }

    public function usedBy(Promo $promo, User $user): int
    {
        return PromoUse::where('promo_id', $promo->id)->where('user_id', $user->id)->count();
    }

    /** Percent off, 1..99: a purchase is never free (YooKassa takes at least 1 ruble, Stars at least 1 star). */
    public static function percent(Promo $promo): int
    {
        return max(0, min(99, (int) $promo->discount));
    }

    /** The ruble price after the discount, whole rubles rounded to the nearest, at least 1. */
    public static function apply(float $rub, int $percent): float
    {
        return $percent > 0 ? max(1.0, round($rub * (100 - $percent) / 100)) : $rub;
    }

    /** What the app shows next to the code: the discount and, for one item, the new price. */
    public function describe(Promo $promo, ?ShopItem $item): array
    {
        $percent = self::percent($promo);
        $r = $promo->rules;
        $out = [
            'code' => $promo->name,
            'discount' => $percent,
            'item_ids' => $r?->item_ids ? array_map('intval', $r->item_ids) : null,
            'category' => $r?->category,
            'min_price' => $r?->min_price,
            'expires_at' => $r?->expires_at?->toIso8601String(),
        ];
        if ($item) {
            $rub = self::apply((float) $item->price, $percent);
            $out += ['item_id' => (int) $item->id, 'price' => (float) $item->price, 'new_price' => $rub, 'stars' => PaymentService::stars($rub)];
        }

        return $out;
    }

    /**
     * Counts one use after the payment is paid (inside the payment's transaction): one row in wa_promo_uses per payment
     * and promos.uses - 1 in the bot table when it is limited. A code that ran out while the person was paying still
     * gives the discount: the money is already taken.
     */
    public function consume(Payment $payment): void
    {
        $d = $payment->details;
        if (! $d?->promo_id || PromoUse::where('payment_id', $payment->id)->exists()) {
            return;
        }
        PromoUse::create([
            'promo_id' => $d->promo_id, 'user_id' => $payment->user_id, 'payment_id' => $payment->id,
            'discount_rub' => max(0, (float) $d->price - (float) $payment->rub_total), 'created_at' => now(),
        ]);
        // uses = uses - 1 in SQL, only while above zero (-1 = unlimited stays -1)
        Promo::whereKey($d->promo_id)->where('uses', '>', 0)->update(['uses' => DB::raw('uses - 1'), 'modified_at' => now()]);
    }
}
