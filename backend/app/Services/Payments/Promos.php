<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Promos
{
    public static function table(string $type): string
    {
        return match ($type) {
            'percent' => 'promos',
            'fixed' => 'wa_fixed_promos',
        };
    }

    public static function rows(string $type): Builder
    {
        return DB::table(self::table($type));
    }

    public function quote(string $code, int $price, bool $reserve = false): array
    {
        $code = mb_strtoupper(trim($code));
        $promo = null;
        foreach (['percent', 'fixed'] as $type) {
            $query = self::rows($type)->whereRaw('UPPER(name) = ?', [$code])->orderBy('id');
            if ($reserve) {
                $query->lockForUpdate();
            }
            $promo = $query->first();
            if ($promo) {
                break;
            }
        }
        if (! $promo || $promo->uses < 1 || $promo->discount < 1 || ($type === 'percent' && $promo->discount > 100)) {
            throw ValidationException::withMessages(['promo' => 'Промокод недействителен или закончился']);
        }

        $discount = $type === 'percent' ? intdiv($price * $promo->discount, 100) : $promo->discount;
        $amount = max(1, $price - $discount);
        if ($amount >= $price) {
            throw ValidationException::withMessages(['promo' => 'К этому предложению промокод не применяется']);
        }

        if ($reserve) {
            self::rows($type)->where('id', $promo->id)->decrement('uses', 1, [
                $type === 'percent' ? 'modified_at' : 'updated_at' => now(),
            ]);
        }

        return ['amount' => $amount, 'promo_id' => $promo->id, 'promo_source' => $type, 'promo_code' => $code];
    }

    /** The caller locks the payment so duplicate cancellation events can only release once. */
    public function release(Payment $payment): void
    {
        if (! $payment->promo_id || $payment->promo_released || $payment->credited_at) {
            return;
        }
        self::rows($payment->promo_source)->where('id', $payment->promo_id)->increment('uses', 1, [
            $payment->promo_source === 'percent' ? 'modified_at' : 'updated_at' => now(),
        ]);
        $payment->update(['promo_released' => true]);
    }
}
