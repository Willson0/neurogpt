<?php

namespace App\Services\Bot;

use App\Models\AiModel;
use App\Models\Bot\QueryLog;
use App\Models\ModelMeta;
use App\Models\Photoset;
use App\Models\PhotosetMeta;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Everything the app writes into, or reads from, the bot tables besides `users` balances (see Wallet):
 * requests -> `query_logs`,
 * and app meta for new rows of the bot catalog (`models`, `prompts`).
 */
class BotSync
{
    // ---------- new people ----------

    /**
     * models.py requires text_model, photo_model and video_model for every user. A new person gets the ids from
     * BOT_DEFAULT_*_MODEL_ID, otherwise the first bot model of that type.
     *
     * @return array{text_model_id:int, photo_model_id:int, video_model_id:int}
     */
    public static function defaultModelIds(): array
    {
        $out = [];
        foreach (['text', 'photo', 'video'] as $type) {
            $id = (int) config("neurogpt.bot.default_models.$type");
            if (! $id || ! AiModel::whereKey($id)->exists()) {
                $id = (int) AiModel::where('type', $type)->orderBy('id')->value('id');
            }
            if (! $id) {
                throw new RuntimeException("The bot table `models` has no model of type \"$type\": a new user cannot be created (models.py requires {$type}_model).");
            }
            $out["{$type}_model_id"] = $id;
        }

        return $out;
    }

    // ---------- payments ----------
    // Payments are written straight into the bot table `payments` by PaymentService (see App\Models\Payment).

    // ---------- statistics ----------

    /** One request in the bot table `query_logs`. Never breaks the caller. */
    public function logQuery(int $userId, string $model, string $type, int $tokens, string $prompt, ?string $answer = null): ?int
    {
        if (! config('neurogpt.bot.query_logs')) {
            return null;
        }
        try {
            return QueryLog::create([
                'user_id' => $userId, 'model' => Str::limit($model, 150, ''), 'type' => Str::limit($type, 50, ''),
                'token_used' => $tokens, 'prompt' => $prompt, 'answer' => $answer, 'attachments' => null, 'was_image_deleted' => false,
            ])->id;
        } catch (Throwable $e) {
            Log::warning('query_logs write failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    // ---------- catalog ----------

    /**
     * The catalog is the bot tables themselves (`models`, `prompts`), so there is nothing to copy. A row the bot added
     * gets its app meta here: a free link id, the default provider, video switched off until the admin sets it up,
     * and a bot prompt whose recommended model is a video model becomes a video set. Bot rows are not written.
     *
     * @return array{models:int, photosets:int}
     */
    public function pullCatalog(): array
    {
        $created = ['models' => 0, 'photosets' => 0];

        DB::transaction(function () use (&$created) {
            $known = ModelMeta::pluck('model_id')->flip();
            foreach (AiModel::ofKinds(array_values(AiModel::TYPES))->orderBy('id')->get() as $m) {
                if ($known->has($m->id)) {
                    continue;
                }
                $kind = $m->kind;
                ModelMeta::create([
                    'model_id' => $m->id, 'slug' => AiModel::freeSlug($kind, Str::slug($m->bot_name ?: $m->name, '-') ?: 'm'.$m->id, $m->id),
                    'driver' => AiModel::defaultDriver($kind), 'is_active' => $kind !== 'video',
                    'sort' => (int) ModelMeta::whereIn('model_id', AiModel::ofKind($kind)->select('id'))->max('sort') + 1,
                ]);
                $created['models']++;
            }

            $knownSets = PhotosetMeta::pluck('prompt_id')->flip();
            $videoModels = AiModel::ofKind('video')->pluck('name')->map(fn ($n) => strtolower((string) $n))->all();
            foreach (Photoset::orderBy('id')->get() as $p) {
                if ($knownSets->has($p->id)) {
                    continue;
                }
                PhotosetMeta::create([
                    'prompt_id' => $p->id, 'kind' => $p->model && in_array(strtolower($p->model), $videoModels, true) ? 'video' : 'photo',
                    'seed' => ($p->id * 37) % 100, 'palette' => $p->id % 5, 'is_active' => true,
                ]);
                $created['photosets']++;
            }
        });

        return $created;
    }
}
