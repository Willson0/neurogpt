<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class News extends Model
{
    protected $table = 'news';

    protected $fillable = ['title', 'subtitle', 'sticker', 'style', 'action', 'sort', 'is_active', 'ends_at'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'ends_at' => 'datetime'];
    }

    public function scopeVisible($q)
    {
        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderBy('sort');
    }
}
