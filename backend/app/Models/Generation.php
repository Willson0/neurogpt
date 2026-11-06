<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    protected $fillable = [
        'user_id', 'kind', 'ai_model_id', 'photoset_id', 'tool', 'prompt', 'params', 'input_path',
        'cost', 'status', 'result_path', 'result_text', 'error', 'is_favorite', 'rating',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'cost' => 'integer',
            'is_favorite' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function photoset(): BelongsTo
    {
        return $this->belongsTo(Photoset::class);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, ['done', 'failed', 'cancelled'], true);
    }
}
