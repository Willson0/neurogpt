<?php

namespace App\Models;

use App\Models\Bot\BotPrompt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A photoset or a video set of the app (wa_photosets, `kind` = photo | video). When prompt_id points to a row of the bot table `prompts`, that row is
 * the source of truth for the name, the prompt text, the "trend" flag and the position; edits in the admin
 * panel are written back to it. Description, cover and colors exist only in the app.
 */
class Photoset extends Model
{
    protected $table = 'wa_photosets';

    protected $fillable = [
        'prompt_id', 'kind', 'name', 'description', 'prompt', 'cover_url', 'seed', 'palette', 'is_trending',
        'trend_hint', 'sort', 'is_active', 'edited_at',
    ];

    protected $with = ['botPrompt'];

    protected function casts(): array
    {
        return ['is_trending' => 'boolean', 'is_active' => 'boolean', 'prompt_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saved(function (Photoset $p) {
            $bot = $p->relationLoaded('botPrompt') ? $p->getRelation('botPrompt') : null;
            if ($bot && $bot->isDirty()) {
                $bot->save();
            }
        });
    }

    public function botPrompt(): BelongsTo
    {
        return $this->belongsTo(BotPrompt::class, 'prompt_id');
    }

    private function linked(): ?BotPrompt
    {
        return $this->prompt_id ? $this->getRelationValue('botPrompt') : null;
    }

    public function getNameAttribute($value): ?string
    {
        return ($bot = $this->linked()) ? $bot->name : $value;
    }

    public function setNameAttribute($value): void
    {
        $this->attributes['name'] = $value;
        if ($bot = $this->linked()) {
            $bot->name = Str::limit((string) $value, 64, '');
        }
    }

    public function getPromptAttribute($value): ?string
    {
        return ($bot = $this->linked()) ? $bot->prompt : $value;
    }

    public function setPromptAttribute($value): void
    {
        $this->attributes['prompt'] = $value;
        if ($bot = $this->linked()) {
            $bot->prompt = (string) $value;
        }
    }

    public function getIsTrendingAttribute($value): bool
    {
        return ($bot = $this->linked()) ? (bool) $bot->trend : (bool) $value;
    }

    public function setIsTrendingAttribute($value): void
    {
        $this->attributes['is_trending'] = (bool) $value;
        if ($bot = $this->linked()) {
            $bot->trend = (bool) $value;
        }
    }

    public function getSortAttribute($value): int
    {
        return ($bot = $this->linked()) && $bot->position !== null ? (int) $bot->position : (int) $value;
    }

    public function setSortAttribute($value): void
    {
        $this->attributes['sort'] = (int) $value;
        if ($bot = $this->linked()) {
            $bot->position = (int) $value;
        }
    }

    /** The app's own cover, otherwise the bot's example when it is a link (a Telegram file_id is copied by neurogpt:sync-bot). */
    public function getCoverUrlAttribute($value): ?string
    {
        if ($value) {
            return $value;
        }
        $example = $this->linked()?->photo_example;

        return $example && preg_match('#^https://#', $example) ? $example : null;
    }

    public function isVideo(): bool
    {
        return $this->kind === 'video';
    }

    /** The generation kind a set makes: a picture for photo sets, a video for video sets. */
    public function generationKind(): string
    {
        return $this->isVideo() ? 'video' : 'image';
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true)->orderBy('sort');
    }
}
