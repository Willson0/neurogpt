<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The catalog lives only in the bot tables: models = `models`, photosets and video sets = `prompts` (models.py).
 * wa_models and wa_photosets are no longer read or written. The app keeps only what those bot tables have no column
 * for, in wa_model_meta (one row per models.id) and wa_photoset_meta (one row per prompts.id).
 *
 *  - a wa_models / wa_photosets row linked to a bot row: its app fields go to the meta table;
 *  - a row that existed only in the app (turned on, or edited by the admin): a new row is added to `models` / `prompts`
 *    with its meta. Rows the seeder had switched off and nobody touched are not carried over;
 *  - tools (wa_models.kind = tool): the admin's settings go to the `tool_engines` setting;
 *  - wa_generations.ai_model_id / photoset_id now hold models.id / prompts.id (the foreign keys into wa_* are dropped).
 *
 * Bot tables are not altered, rows are only added. wa_models and wa_photosets stay untouched as an archive.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wa_model_meta')) {
            Schema::create('wa_model_meta', function (Blueprint $t) {
                $t->unsignedBigInteger('model_id')->primary(); // models.id (bot table, no FK into it)
                $t->string('slug', 32)->nullable(); // id in links and in the app (auto, quick, veo3-fast ...)
                $t->string('hint')->nullable();
                $t->string('badge', 32)->nullable();
                $t->boolean('is_fast')->default(false);
                $t->string('min_plan', 16)->nullable(); // plus | pro for an only_sub model (the bot only knows "any subscription")
                $t->string('driver', 32)->nullable(); // provider in the app; null = the default for the kind
                $t->string('provider_model')->nullable(); // null = models.name
                $t->json('options')->nullable(); // video lengths, formats, provider settings
                $t->unsignedSmallInteger('sort')->nullable();
                $t->boolean('is_active')->nullable(); // shown in the app; null = yes (video: no)
                $t->unsignedBigInteger('source_id')->nullable()->unique(); // wa_models.id it came from
                $t->timestamp('edited_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('wa_photoset_meta')) {
            Schema::create('wa_photoset_meta', function (Blueprint $t) {
                $t->unsignedInteger('prompt_id')->primary(); // prompts.id
                $t->string('kind', 8)->default('photo'); // photo | video
                $t->text('description')->nullable();
                $t->string('cover_url')->nullable();
                $t->unsignedInteger('seed')->default(1);
                $t->unsignedTinyInteger('palette')->default(0);
                $t->string('trend_hint')->nullable();
                $t->boolean('is_active')->nullable();
                $t->unsignedBigInteger('source_id')->nullable()->unique(); // wa_photosets.id it came from
                $t->timestamp('edited_at')->nullable();
                $t->timestamps();
            });
        }
        foreach (['ai_model_id', 'photoset_id'] as $col) {
            if (Schema::hasTable('wa_generations') && $this->hasForeign('wa_generations', $col)) {
                Schema::table('wa_generations', fn (Blueprint $t) => $t->dropForeign([$col]));
            }
        }

        // Done once: after the move generations hold bot ids, so a second remap would point them at wrong rows.
        if (Schema::hasTable('wa_models') && Schema::hasTable('wa_photosets') && ! DB::table('wa_settings')->where('key', 'catalog_moved_at')->exists()) {
            DB::transaction(function () {
                $models = $this->moveModels();
                $sets = $this->movePhotosets();
                $this->relinkGenerations($models, $sets);
                DB::table('wa_settings')->insert(['key' => 'catalog_moved_at', 'value' => json_encode(now()->toIso8601String()), 'created_at' => now(), 'updated_at' => now()]);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_photoset_meta');
        Schema::dropIfExists('wa_model_meta');
    }

    private function hasForeign(string $table, string $column): bool
    {
        try {
            foreach (Schema::getForeignKeys($table) as $fk) {
                if (in_array($column, $fk['columns'], true)) {
                    return true;
                }
            }
        } catch (Throwable) {
        }

        return false;
    }

    /** @return array<int, int|null> wa_models.id => models.id (null: not carried over) */
    private function moveModels(): array
    {
        $types = ['text' => 'text', 'image' => 'photo', 'video' => 'video', 'song' => 'song'];
        $map = [];
        $now = now();
        $engines = json_decode((string) DB::table('wa_settings')->where('key', 'tool_engines')->value('value'), true) ?: [];

        foreach (DB::table('wa_models')->orderBy('id')->get() as $w) {
            if ($w->kind === 'tool') {
                if ($w->edited_at && ! isset($engines[$w->slug])) {
                    $engines[$w->slug] = ['active' => (bool) $w->is_active, 'driver' => $w->driver, 'provider_model' => $w->provider_model,
                        'options' => $w->options ? json_decode($w->options, true) : null];
                }

                continue;
            }
            if (! isset($types[$w->kind])) {
                continue;
            }
            if ($done = DB::table('wa_model_meta')->where('source_id', $w->id)->value('model_id')) {
                $map[$w->id] = (int) $done; // moved on an earlier run

                continue;
            }
            $botId = $w->bot_model_id && DB::table('models')->where('id', $w->bot_model_id)->exists() ? (int) $w->bot_model_id : null;
            if (! $botId) {
                if (! $w->is_active && ! $w->edited_at) {
                    $map[$w->id] = null; // switched off by the seeder, never used by the admin

                    continue;
                }
                $botId = DB::table('models')->insertGetId([
                    'type' => $types[$w->kind], 'can_image' => $w->kind !== 'text', 'price' => (int) $w->price,
                    'name' => Str::limit((string) ($w->provider_model ?: $w->slug), 100, ''),
                    'text_name' => Str::limit((string) $w->name, 100, ''), 'tag' => '',
                    'only_sub' => in_array($w->min_plan, ['plus', 'pro'], true), 'params' => '{}',
                    'created_at' => $w->created_at ?? $now, 'modified_at' => $now,
                ]);
            }
            if (! DB::table('wa_model_meta')->where('model_id', $botId)->exists()) {
                DB::table('wa_model_meta')->insert([
                    'model_id' => $botId, 'slug' => Str::limit($w->slug, 32, ''), 'hint' => $w->hint, 'badge' => $w->badge ? Str::limit($w->badge, 32, '') : null,
                    'is_fast' => (bool) $w->is_fast, 'min_plan' => $w->min_plan, 'driver' => $w->driver,
                    // the bot row keeps its own provider id in `name`; the app's is kept only when it differs
                    'provider_model' => $w->provider_model !== DB::table('models')->where('id', $botId)->value('name') ? $w->provider_model : null,
                    'options' => $w->options, 'sort' => (int) $w->sort, 'is_active' => (bool) $w->is_active, 'source_id' => $w->id,
                    'edited_at' => $w->edited_at, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $map[$w->id] = $botId;
        }

        if ($engines) {
            DB::table('wa_settings')->updateOrInsert(['key' => 'tool_engines'], [
                'value' => json_encode($engines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $this->relinkLegacy('ai_models', 'wa_models', 'models', $map);

        return $map;
    }

    /** @return array<int, int|null> wa_photosets.id => prompts.id */
    private function movePhotosets(): array
    {
        $map = [];
        $now = now();
        $videoModel = DB::table('models')->where('type', 'video')->orderBy('id')->value('name');

        foreach (DB::table('wa_photosets')->orderBy('id')->get() as $w) {
            if ($done = DB::table('wa_photoset_meta')->where('source_id', $w->id)->value('prompt_id')) {
                $map[$w->id] = (int) $done;

                continue;
            }
            $kind = ($w->kind ?? 'photo') === 'video' ? 'video' : 'photo';
            $promptId = $w->prompt_id && DB::table('prompts')->where('id', $w->prompt_id)->exists() ? (int) $w->prompt_id : null;
            if (! $promptId) {
                if (! $w->is_active && ! $w->edited_at) {
                    $map[$w->id] = null;

                    continue;
                }
                $promptId = DB::table('prompts')->insertGetId([
                    'position' => (int) $w->sort, 'name' => Str::limit((string) $w->name, 64, ''), 'prompt' => (string) $w->prompt,
                    'photo_example' => null, 'model' => $kind === 'video' && $videoModel ? Str::limit($videoModel, 50, '') : null,
                    'trend' => (bool) $w->is_trending,
                ]);
            }
            if (! DB::table('wa_photoset_meta')->where('prompt_id', $promptId)->exists()) {
                DB::table('wa_photoset_meta')->insert([
                    'prompt_id' => $promptId, 'kind' => $kind, 'description' => $w->description, 'cover_url' => $w->cover_url,
                    'seed' => (int) $w->seed, 'palette' => (int) $w->palette, 'trend_hint' => $w->trend_hint,
                    'is_active' => (bool) $w->is_active, 'source_id' => $w->id, 'edited_at' => $w->edited_at,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $map[$w->id] = $promptId;
        }
        $this->relinkLegacy('photosets', 'wa_photosets', 'prompts', $map);

        return $map;
    }

    private function relinkLegacy(string $source, string $from, string $to, array $map): void
    {
        if (! Schema::hasTable('wa_legacy_map')) {
            return;
        }
        foreach (DB::table('wa_legacy_map')->where('source_table', $source)->where('target_table', $from)->get() as $row) {
            if ($target = $map[$row->target_id] ?? null) {
                DB::table('wa_legacy_map')->where('id', $row->id)->update(['target_table' => $to, 'target_id' => $target]);
            }
        }
    }

    /** Old and new ids overlap, so every generation is read first and then updated by its own id. */
    private function relinkGenerations(array $models, array $sets): void
    {
        $rows = DB::table('wa_generations')->where(fn ($q) => $q->whereNotNull('ai_model_id')->orWhereNotNull('photoset_id'))
            ->get(['id', 'ai_model_id', 'photoset_id', 'kind']);
        $groups = [];
        foreach ($rows as $g) {
            $oldModel = $g->ai_model_id !== null ? (int) $g->ai_model_id : null;
            $oldSet = $g->photoset_id !== null ? (int) $g->photoset_id : null;
            $model = $g->kind === 'tool' || ! $oldModel ? null : ($models[$oldModel] ?? null);
            $set = $oldSet ? ($sets[$oldSet] ?? null) : null;
            if ($model === $oldModel && $set === $oldSet) {
                continue;
            }
            $groups[($model ?? 'null').':'.($set ?? 'null')][] = $g->id;
        }
        foreach ($groups as $key => $ids) {
            [$model, $set] = explode(':', $key);
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table('wa_generations')->whereIn('id', $chunk)->update([
                    'ai_model_id' => $model === 'null' ? null : (int) $model, 'photoset_id' => $set === 'null' ? null : (int) $set,
                ]);
            }
        }
    }
};
