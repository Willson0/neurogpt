<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiModel extends Model
{
    protected $table = 'ai_models';

    protected $fillable = [
        'slug', 'kind', 'name', 'hint', 'price', 'badge', 'is_fast', 'min_plan', 'driver',
        'provider_model', 'sort', 'is_active',
    ];

    protected function casts(): array
    {
        return ['price' => 'integer', 'is_fast' => 'boolean', 'is_active' => 'boolean'];
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true)->orderBy('sort');
    }
}
