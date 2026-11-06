<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'user_id', 'provider', 'provider_payment_id', 'idempotence_key', 'purpose', 'tokens', 'plan',
        'period', 'amount', 'currency', 'status', 'confirmation_url', 'cancel_reason', 'credited_at', 'raw',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'tokens' => 'integer', 'credited_at' => 'datetime', 'raw' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
