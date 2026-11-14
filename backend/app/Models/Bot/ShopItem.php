<?php

namespace App\Models\Bot;

use Illuminate\Database\Eloquent\Model;

/** Bot table `shop`: what can be bought. category + item + amount describe a purchase. */
class ShopItem extends Model
{
    protected $table = 'shop';

    const UPDATED_AT = 'modified_at';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'amount' => 'integer', 'price' => 'integer'];
    }
}
