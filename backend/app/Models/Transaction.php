<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $table = 'wa_transactions';

    protected $fillable = [
        'user_id', 'type', 'amount', 'balance_after', 'buckets', 'title', 'details', 'generation_id', 'payment_id',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'balance_after' => 'integer', 'buckets' => 'array'];
    }
}
