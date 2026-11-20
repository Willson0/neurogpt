<?php

namespace App\Models\Bot;

use Illuminate\Database\Eloquent\Model;

/** Bot table `payments`. The app writes a row only for a payment it has already delivered (was_bought = is_proceed = true). */
class BotPayment extends Model
{
    protected $table = 'payments';

    const UPDATED_AT = 'modified_at';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_autopayment' => 'boolean', 'was_bought' => 'boolean', 'is_proceed' => 'boolean',
            'total' => 'float', 'rub_total' => 'float',
        ];
    }
}
