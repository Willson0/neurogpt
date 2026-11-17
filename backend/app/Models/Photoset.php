<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A photoset or a video set = a row of the bot table `prompts` (models.py, Prompt): name, prompt, position (= sort),
 * trend (= is_trending), photo_example, model (the recommended bot model). What the admin panel creates, edits or
 * deletes is that row. The app's own fields (photo or video set, description, cover, colors, trend label, on/off)
 * are in wa_photoset_meta, one row per prompts.id.
 */
class Photoset extends Model
{
    protected $table = 'prompts';

    public $timestamps = false;

    private const META = ['kind', 'description', 'cover_url', 'seed', 'palette', 'trend_hint', 'is_active', 'edited_at'];

    protected $fillable = [
        'name', 'prompt', 'position', 'photo_example', 'model', 'trend', 'sort', 'is_trending',
        'kind', 'description', 'cover_url', 'seed', 'palette', 'trend_hint', 'is_active', 'edited_at',
    ];

    protected $attributes = ['trend' => false];

    protected $with = ['meta'];

    protected function casts(): array
    {
        return ['trend' => 'boolean', 'position' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saved(function (Photoset $p) {
            $meta = $p->relationLoaded('meta') ? $p->getRelation('meta') : null;
            if ($meta && (! $meta->exists || $meta->isDirty())) {
                $meta->prompt_id = $p->id;
                $meta->save();
            }
        });
        static::deleted(fn (Photoset $p) => PhotosetMeta::whereKey($p->id)->delete());
    }

    public function meta(): HasOne
    {
        return $this->hasOne(PhotosetMeta::class, 'prompt_id');
    }

    private function metaRow(): PhotosetMeta
    {
        $meta = $this->relationLoaded('meta') ? $this->getRelation('meta') : ($this->exists ? $this->meta : null);
        if (! $meta) {
            $meta = new PhotosetMeta;
            $this->setRelation('meta', $meta);
        }

        return $meta;
    }

    private function metaValue(string $key): mixed
    {
        $meta = $this->relationLoaded('meta') ? $this->getRelation('meta') : ($this->exists ? $this->getRelationValue('meta') : null);

        return $meta?->getAttribute($key);
    }

    private function metaAttribute(string $key, mixed $default = null): Attribute
    {
        return Attribute::make(
            get: fn () => $this->metaValue($key) ?? $default,
            set: function ($value) use ($key) {
                $this->metaRow()->setAttribute($key, $value);

                return [];
            },
        )->withoutObjectCaching();
    }

    protected function name(): Attribute
    {
        return Attribute::make(set: fn ($value) => ['name' => Str::limit((string) $value, 64, '')]);
    }

    protected function sort(): Attribute
    {
        return Attribute::make(
            get: fn () => (int) ($this->attributes['position'] ?? 0),
            set: fn ($value) => ['position' => (int) $value],
        );
    }

    protected function isTrending(): Attribute
    {
        return Attribute::make(
            get: fn () => (bool) ($this->attributes['trend'] ?? false),
            set: fn ($value) => ['trend' => (bool) $value],
        );
    }

    protected function kind(): Attribute
    {
        return $this->metaAttribute('kind', 'photo');
    }

    protected function description(): Attribute
    {
        return $this->metaAttribute('description');
    }

    protected function seed(): Attribute
    {
        return $this->metaAttribute('seed', 1);
    }

    protected function palette(): Attribute
    {
        return $this->metaAttribute('palette', 0);
    }

    protected function trendHint(): Attribute
    {
        return $this->metaAttribute('trend_hint');
    }

    protected function isActive(): Attribute
    {
        return Attribute::make(
            get: fn () => (bool) ($this->metaValue('is_active') ?? true),
            set: function ($value) {
                $this->metaRow()->setAttribute('is_active', (bool) $value);

                return [];
            },
        );
    }

    protected function editedAt(): Attribute
    {
        return $this->metaAttribute('edited_at');
    }

    /** The app's own cover, otherwise the bot's example when it is a link (a Telegram file_id is copied by neurogpt:sync-bot). */
    protected function coverUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($own = $this->metaValue('cover_url')) {
                    return $own;
                }
                $example = $this->attributes['photo_example'] ?? null;

                return $example && preg_match('#^https://#', $example) ? $example : null;
            },
            set: function ($value) {
                $this->metaRow()->setAttribute('cover_url', $value ?: null);

                return [];
            },
        );
    }

    /** The cover the admin set in the app (not the bot's example). */
    public function ownCover(): ?string
    {
        return $this->metaValue('cover_url');
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

    /**
     * The bot's recommended model of the set: prompts.model holds the model id the bot uses (models.name), e.g.
     * "gemini-nano-banana-2.1"; a display name (models.text_name) is accepted too. Only an active model of the kind
     * this set makes counts. Null means the user's own model is used (prompts.model is null), as in the bot.
     *
     * @param  Collection<int, AiModel>|null  $active  active models, to avoid a query per set
     */
    public function recommendedModel(?Collection $active = null): ?AiModel
    {
        $wanted = mb_strtolower(trim((string) ($this->attributes['model'] ?? '')));
        if ($wanted === '') {
            return null;
        }
        $kind = $this->generationKind();
        $list = ($active ?? AiModel::active()->ofKind($kind)->get())->filter(fn (AiModel $m) => $m->kind === $kind);

        return $list->first(fn (AiModel $m) => mb_strtolower((string) $m->bot_name) === $wanted)
            ?? $list->first(fn (AiModel $m) => mb_strtolower((string) $m->name) === $wanted);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->leftJoin('wa_photoset_meta as pm', 'pm.prompt_id', '=', 'prompts.id')->select('prompts.*')
            ->whereRaw('coalesce(pm.is_active, 1) = 1')
            ->orderByRaw('coalesce(prompts.position, 0)')->orderBy('prompts.id');
    }

    /** photo | video. A bot prompt without meta is a photo set until neurogpt:sync-bot looks at its model. */
    public function scopeOfKind(Builder $q, string $kind): Builder
    {
        return $kind === 'photo'
            ? $q->whereNotExists(fn ($x) => $x->from('wa_photoset_meta as pk')->whereColumn('pk.prompt_id', 'prompts.id')->where('pk.kind', 'video'))
            : $q->whereExists(fn ($x) => $x->from('wa_photoset_meta as pk')->whereColumn('pk.prompt_id', 'prompts.id')->where('pk.kind', $kind));
    }
}
