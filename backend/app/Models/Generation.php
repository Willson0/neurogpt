<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    protected $fillable = [
        'user_id', 'kind', 'ai_model_id', 'photoset_id', 'tool', 'prompt', 'params', 'input_path', 'provider_task_id',
        'cost', 'status', 'result_path', 'result_text', 'error', 'is_favorite', 'rating',
        'started_at', 'finished_at',
    ];

    /** The full instruction for the provider, built by PromptBuilder right before the job runs. */
    public ?string $finalPrompt = null;

    public function providerPrompt(): string
    {
        return $this->finalPrompt ?? (string) $this->prompt;
    }

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

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'processing'], true);
    }

    /** What the user sees as the name of this generation. */
    public function title(): string
    {
        return match (true) {
            $this->photoset_id !== null => 'Фотосет «'.($this->photoset?->name ?? '…').'»',
            $this->kind === 'tool' => (string) (config("neurogpt.tools.{$this->tool}.name") ?? 'Инструмент'),
            $this->kind === 'video' => 'Видео',
            $this->kind === 'song' => 'Песня',
            default => 'Картинка',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this->status, ['done', 'failed', 'cancelled'], true);
    }
}
