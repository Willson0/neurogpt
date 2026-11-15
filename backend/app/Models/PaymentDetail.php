<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What the bot table `payments` has no column for, one row per payments.id. See Payment. */
class PaymentDetail extends Model
{
    protected $table = 'wa_payment_details';

    protected $primaryKey = 'payment_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tokens' => 'integer', 'days' => 'integer', 'price' => 'decimal:2', 'discount' => 'integer', 'promo_id' => 'integer',
            'credited_at' => 'datetime', 'raw' => 'array', 'referrer_id' => 'integer', 'ref_amount' => 'integer',
            'ref_reverted' => 'integer', 'refunded_amount' => 'decimal:2', 'refunded_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
