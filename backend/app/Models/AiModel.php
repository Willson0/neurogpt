<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A model of the catalog = a row of the bot table `models` (models.py, NeuroModel). The app has no catalog of its own:
 * what the admin panel creates, edits or deletes is that row, so the bot and the app always list the same models.
 *
 * Bot columns: type (text | photo | video; the app also writes "song"), text_name = the name people see, name = the
 * provider's model id, price, only_sub. What the bot table has no column for (link id, hint, badge, the provider that
 * runs it in the app, video lengths, order, on/off in the app) is in wa_model_meta, one row per models.id.
 *
 * In code the model keeps the app vocabulary: kind (image instead of photo), name (= text_name), slug, min_plan, ...
 * Tools of the "Ещё" tab are not models: see App\Services\Generation\Tools.
 */
class AiModel extends Model
{
    protected $table = 'models';

    const UPDATED_AT = 'modified_at';

    /** bot type => app kind */
    public const TYPES = ['text' => 'text', 'photo' => 'image', 'video' => 'video', 'song' => 'song'];

    /** app fields kept in wa_model_meta, with their value when a bot row has no meta yet */
    private const META = [
        'slug' => null, 'hint' => null, 'badge' => null, 'is_fast' => false, 'driver' => null, 'provider_model' => null,
        'options' => null, 'sort' => null, 'is_active' => null, 'edited_at' => null,
    ];

    protected $fillable = [
        'type', 'can_image', 'price', 'text_name', 'tag', 'only_sub', 'params',
        'kind', 'name', 'bot_name', 'min_plan', ...['slug', 'hint', 'badge', 'is_fast', 'driver', 'provider_model', 'options', 'sort', 'is_active', 'edited_at'],
    ];

    protected $attributes = ['can_image' => false, 'price' => 0, 'tag' => '', 'only_sub' => false, 'params' => '{}'];

    protected $with = ['meta'];

    protected function casts(): array
    {
        return ['can_image' => 'boolean', 'price' => 'integer', 'only_sub' => 'boolean', 'params' => 'array'];
    }

    protected static function booted(): void
    {
        // The meta row is saved together with the bot row (also when only meta fields changed).
        static::saved(function (AiModel $m) {
            $meta = $m->relationLoaded('meta') ? $m->getRelation('meta') : null;
            if ($meta && (! $meta->exists || $meta->isDirty())) {
                $meta->model_id = $m->id;
                $meta->save();
            }
        });
        static::deleted(fn (AiModel $m) => ModelMeta::whereKey($m->id)->delete());
    }

    public function meta(): HasOne
    {
        return $this->hasOne(ModelMeta::class, 'model_id');
    }

    public static function typeOf(string $kind): ?string
    {
        return array_search($kind, self::TYPES, true) ?: null;
    }

    public static function onlySubFor(?string $minPlan): bool
    {
        return in_array($minPlan, ['plus', 'pro'], true);
    }

    // ---------- the meta row ----------

    private function metaRow(): ModelMeta
    {
        $meta = $this->relationLoaded('meta') ? $this->getRelation('meta') : ($this->exists ? $this->meta : null);
        if (! $meta) {
            $meta = new ModelMeta;
            $this->setRelation('meta', $meta);
        }

        return $meta;
    }

    private function metaValue(string $key): mixed
    {
        $meta = $this->relationLoaded('meta') ? $this->getRelation('meta') : ($this->exists ? $this->getRelationValue('meta') : null);

        return $meta?->getAttribute($key) ?? (self::META[$key] ?? null);
    }

    private function metaAttribute(string $key): Attribute
    {
        return Attribute::make(
            get: fn () => $this->metaValue($key),
            set: function ($value) use ($key) {
                $this->metaRow()->setAttribute($key, $value);

                return [];
            },
        )->withoutObjectCaching();
    }

    /** A bot model the app has not seen yet is shown, except video: it needs provider settings first. */
    private function defaultActive(): bool
    {
        return ($this->attributes['type'] ?? null) !== 'video';
    }

    // ---------- app names of bot columns ----------

    protected function kind(): Attribute
    {
        return Attribute::make(
            get: fn () => self::TYPES[$this->attributes['type'] ?? ''] ?? ($this->attributes['type'] ?? null),
            set: fn ($value) => ['type' => self::typeOf((string) $value) ?? (string) $value],
        );
    }

    /** The name people see (text_name). The provider's id the bot uses is bot_name (column `name`). */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => ($this->attributes['text_name'] ?? '') !== '' ? $this->attributes['text_name'] : $value,
            set: fn ($value) => ['text_name' => Str::limit((string) $value, 100, '')],
        );
    }

    protected function botName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->attributes['name'] ?? null,
            set: fn ($value) => ['name' => Str::limit((string) $value, 100, '')],
        );
    }

    /**
     * Who may use it. The bot only knows only_sub ("any subscription"); plus vs pro is kept in meta.min_plan:
     * an only_sub model needs at least plus.
     */
    protected function minPlan(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! ($this->attributes['only_sub'] ?? false)) {
                    return 'free';
                }
                $local = $this->metaValue('min_plan');

                return in_array($local, ['plus', 'pro'], true) ? $local : 'plus';
            },
            set: function ($value) {
                $this->metaRow()->setAttribute('min_plan', $value ?: 'free');

                return ['only_sub' => self::onlySubFor((string) $value)];
            },
        );
    }

    protected function slug(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->metaValue('slug') ?: ($this->id ? 'm'.$this->id : null),
            set: function ($value) {
                $this->metaRow()->setAttribute('slug', $value);

                return [];
            },
        );
    }

    /** The provider's model id in the app: meta override, otherwise the one the bot uses. */
    protected function providerModel(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->metaValue('provider_model') ?: ($this->attributes['name'] ?? null),
            set: function ($value) {
                $this->metaRow()->setAttribute('provider_model', $value ?: null);

                return [];
            },
        );
    }

    protected function driver(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->metaValue('driver') ?: self::defaultDriver((string) $this->kind),
            set: function ($value) {
                $this->metaRow()->setAttribute('driver', $value);

                return [];
            },
        );
    }

    protected function isActive(): Attribute
    {
        return Attribute::make(
            get: fn () => (bool) ($this->metaValue('is_active') ?? $this->defaultActive()),
            set: function ($value) {
                $this->metaRow()->setAttribute('is_active', (bool) $value);

                return [];
            },
        );
    }

    protected function sort(): Attribute
    {
        return Attribute::make(
            get: fn () => (int) ($this->metaValue('sort') ?? 0),
            set: function ($value) {
                $this->metaRow()->setAttribute('sort', (int) $value);

                return [];
            },
        );
    }

    protected function hint(): Attribute
    {
        return $this->metaAttribute('hint');
    }

    protected function badge(): Attribute
    {
        return $this->metaAttribute('badge');
    }

    protected function isFast(): Attribute
    {
        return Attribute::make(get: fn () => (bool) $this->metaValue('is_fast'), set: function ($value) {
            $this->metaRow()->setAttribute('is_fast', (bool) $value);

            return [];
        });
    }

    protected function options(): Attribute
    {
        return $this->metaAttribute('options');
    }

    protected function editedAt(): Attribute
    {
        return $this->metaAttribute('edited_at');
    }

    public static function defaultDriver(string $kind): string
    {
        return match ($kind) {
            'video' => (string) config('neurogpt.providers.kie_driver'),
            'song' => config('neurogpt.providers.song_model') ? 'replicate' : 'unavailable',
            default => (string) config('neurogpt.providers.default'),
        };
    }

    // ---------- queries ----------

    public function scopeOfKind(Builder $q, string $kind): Builder
    {
        return $q->where('models.type', self::typeOf($kind) ?? $kind);
    }

    public function scopeOfKinds(Builder $q, array $kinds): Builder
    {
        return $q->whereIn('models.type', array_map(fn ($k) => self::typeOf($k) ?? $k, $kinds));
    }

    /** Shown in the app, in the admin's order. A bot row without meta is on, video off until the admin sets it up. */
    public function scopeActive(Builder $q): Builder
    {
        return $q->leftJoin('wa_model_meta as mm', 'mm.model_id', '=', 'models.id')->select('models.*')
            ->whereRaw("coalesce(mm.is_active, case when models.type = 'video' then 0 else 1 end) = 1")
            ->orderByRaw('coalesce(mm.sort, 65000)')->orderBy('models.id');
    }

    /** Every catalog model of these kinds in the admin's order (kind, sort, id). */
    public static function catalog(array $kinds): Collection
    {
        return self::ofKinds($kinds)->get()->sortBy(fn (AiModel $m) => [array_search($m->kind, $kinds, true), $m->metaValue('sort') ?? 65000, $m->id])->values();
    }

    /** An active model of this kind by its link id. Lists are short, so it is matched in PHP (the slug lives in meta). */
    public static function findSlug(string $kind, ?string $slug): ?self
    {
        return $slug ? self::active()->ofKind($kind)->get()->first(fn (AiModel $m) => $m->slug === $slug) : null;
    }

    /** A model of this kind by its link id, switched on or off. */
    public static function slugged(string $kind, string $slug): ?self
    {
        return self::ofKind($kind)->get()->first(fn (AiModel $m) => $m->slug === $slug);
    }

    /** A link id that no other model of this kind has. */
    public static function freeSlug(string $kind, string $base, ?int $except = null): string
    {
        $base = Str::limit(preg_replace('/[^a-z0-9_-]/', '', strtolower($base)) ?: 'model', 28, '');
        $taken = self::ofKind($kind)->get()->reject(fn (AiModel $m) => $m->id === $except)->map(fn (AiModel $m) => $m->slug)->all();
        $slug = $base;
        for ($i = 2; in_array($slug, $taken, true); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    // ---------- options ----------

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
