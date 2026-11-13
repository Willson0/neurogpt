<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiModel extends Model
{
    protected $table = 'ai_models';

    protected $fillable = [
        'slug', 'kind', 'name', 'hint', 'price', 'badge', 'is_fast', 'min_plan', 'driver',
        'provider_model', 'options', 'sort', 'is_active', 'edited_at',
    ];

    protected function casts(): array
    {
        return ['price' => 'integer', 'is_fast' => 'boolean', 'is_active' => 'boolean', 'options' => 'array'];
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true)->orderBy('sort');
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /** @return array<int, int|float> seconds => price multiplier */
    public function durations(): array
    {
        return $this->option('durations') ?: ($this->kind === 'video' ? [5 => 1, 10 => 2] : []);
    }

    /** @return list<string> */
    public function formats(): array
    {
        return $this->option('formats') ?: ['1:1', '9:16', '16:9'];
    }
}
