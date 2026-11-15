<?php

namespace App\Models\Bot;

use App\Models\PromoRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Bot table `promos`: name = the code people type, discount = percent off, uses = uses left (-1 = no limit, 0 = used up).
 * Extra limits from the admin panel (dates, per person, items, minimum price) are in wa_promo_rules (rules()).
 */
class Promo extends Model
{
    protected $table = 'promos';

    const UPDATED_AT = 'modified_at';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['discount' => 'integer', 'uses' => 'integer'];
    }

    public function rules(): HasOne
    {
        return $this->hasOne(PromoRule::class, 'promo_id');
    }
}
