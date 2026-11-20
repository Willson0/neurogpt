<?php

namespace App\Models;

use App\Models\Bot\BotModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A model of the app catalog (wa_models): how it is shown and which provider runs it.
 *
 * When bot_model_id points to a row of the bot table `models`, that row is the source of truth for
 * name (text_name), price and who may use it (allowed_subs): they are read from it, and a change made in the
 * admin panel is written back to it, so the bot and the app always charge the same. Tools and songs have no
 * bot counterpart and keep their own values.
 */
class AiModel extends Model
{
    protected $table = 'wa_models';

    protected $fillable = [
        'bot_model_id', 'slug', 'kind', 'name', 'hint', 'price', 'badge', 'is_fast', 'min_plan', 'driver',
        'provider_model', 'options', 'sort', 'is_active', 'edited_at',
    ];

    protected $with = ['bot'];

    protected function casts(): array
    {
        return ['price' => 'integer', 'is_fast' => 'boolean', 'is_active' => 'boolean', 'options' => 'array', 'bot_model_id' => 'integer'];
    }

    protected static function booted(): void
    {
        // Values changed through the linked fields go to the bot row in the same save.
        static::saved(function (AiModel $m) {
            $bot = $m->relationLoaded('bot') ? $m->getRelation('bot') : null;
            if ($bot && $bot->isDirty()) {
                $bot->save();
            }
        });
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(BotModel::class, 'bot_model_id');
    }

    private function linked(): ?BotModel
    {
        if (! $this->bot_model_id) {
            return null;
        }

        return $this->getRelationValue('bot');
    }

    public function getNameAttribute($value): ?string
    {
        return ($bot = $this->linked()) ? ($bot->text_name ?: $bot->name) : $value;
    }

    public function setNameAttribute($value): void
    {
        $this->attributes['name'] = $value;
        if ($bot = $this->linked()) {
            $bot->text_name = Str::limit((string) $value, 100, '');
        }
    }

    public function getPriceAttribute($value): int
    {
        return ($bot = $this->linked()) ? (int) $bot->price : (int) $value;
    }

    public function setPriceAttribute($value): void
    {
        $this->attributes['price'] = (int) $value;
        if ($bot = $this->linked()) {
            $bot->price = (int) $value;
        }
    }

    public function getMinPlanAttribute($value): string
    {
        return ($bot = $this->linked()) ? $bot->minPlan() : (string) ($value ?: 'free');
    }

    public function setMinPlanAttribute($value): void
    {
        $this->attributes['min_plan'] = $value ?: 'free';
        if ($bot = $this->linked()) {
            $bot->allowed_subs = BotModel::subsFor((string) $value);
        }
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
