<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Photoset extends Model
{
    protected $fillable = [
        'name', 'description', 'prompt', 'cover_url', 'seed', 'palette', 'is_trending',
        'trend_hint', 'sort', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_trending' => 'boolean', 'is_active' => 'boolean'];
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true)->orderBy('sort');
    }
}
