<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The admin panel's limits of a promo code (bot table `promos`), see App\Services\Payments\Promos. */
class PromoRule extends Model
{
    protected $table = 'wa_promo_rules';

    protected $primaryKey = 'promo_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean', 'starts_at' => 'datetime', 'expires_at' => 'datetime', 'per_user' => 'integer',
            'min_price' => 'integer', 'item_ids' => 'array', 'new_buyers_only' => 'boolean',
        ];
    }
}
