<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One paid purchase with a promo code (payment_id = payments.id). */
class PromoUse extends Model
{
    protected $table = 'wa_promo_uses';

    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['discount_rub' => 'decimal:2'];
    }
}
