<?php

namespace App\Services\Bot;

use App\Models\AiModel;
use App\Models\Bot\BotModel;
use App\Models\Bot\BotPayment;
use App\Models\Bot\BotPrompt;
use App\Models\Bot\QueryLog;
use App\Models\Bot\ShopItem;
use App\Models\Payment;
use App\Models\Photoset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Everything the app writes into, or reads from, the bot tables besides `users` balances (see Wallet):
 * delivered payments -> `payments` (+ users.paid_money), requests -> `query_logs`,
 * and the bot catalog (`models`, `prompts`) -> the app catalog (wa_models, wa_photosets).
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
            if (! $id || ! BotModel::whereKey($id)->exists()) {
                $id = (int) BotModel::where('type', $type)->orderBy('id')->value('id');
            }
            if (! $id) {
                throw new RuntimeException("The bot table `models` has no model of type \"$type\": a new user cannot be created (models.py requires {$type}_model).");
            }
            $out["{$type}_model_id"] = $id;
        }

        return $out;
    }

    // ---------- payments ----------

    /**
     * A payment the app has delivered becomes a row of the bot table `payments` with was_bought = is_proceed = true,
     * so the bot counts it in its statistics but never delivers it a second time. Pending payments are not written there.
     */
    public function mirrorPayment(Payment $payment): ?BotPayment
    {
        if (! config('neurogpt.bot.mirror_payments') || $payment->bot_payment_id || ! $payment->credited_at) {
            return null;
        }

        $rub = (float) ($payment->rub_amount ?? $payment->amount);
        $row = BotPayment::create([
            'payment_id' => Str::limit((string) ($payment->provider_payment_id ?: 'webapp:'.$payment->idempotence_key), 128, ''),
            'is_autopayment' => false,
            'was_bought' => true,
            'is_proceed' => true,
            'user_id' => $payment->user_id,
            'recipient_id' => $payment->user_id,
            'item_id' => $this->shopItem($payment)->id,
            'total' => (float) $payment->amount,
            'total_valut' => $payment->currency ?: 'RUB',
            'rub_total' => $rub,
        ]);
        User::whereKey($payment->user_id)->increment('paid_money', $rub);
        $payment->forceFill(['bot_payment_id' => $row->id])->saveQuietly();

        return $row;
    }

    /**
     * The `shop` row a purchase belongs to (payments.item_id is required). Looked up by category, item and amount;
     * when the bot has no such row, a hidden one (active = false) is added so it does not appear in the bot menu.
     */
    public function shopItem(Payment $payment): ShopItem
    {
        $cfg = config('neurogpt.bot.shop');
        if ($payment->purpose === 'topup') {
            $attrs = ['category' => $cfg['tokens_category'], 'item' => $cfg['tokens_item'], 'amount' => (int) $payment->tokens];
            $name = "{$payment->tokens} токенов";
        } else {
            $days = $payment->period === 'year' ? 365 : 30;
            $attrs = ['category' => $cfg['sub_category'], 'item' => (string) $payment->plan, 'amount' => $cfg['sub_amount'] === 'months' ? ($days === 365 ? 12 : 1) : $days];
            $name = 'Подписка '.config("neurogpt.plans.{$payment->plan}.name", $payment->plan).($days === 365 ? ', год' : ', месяц');
        }

        return ShopItem::where($attrs)->orderByDesc('active')->orderBy('id')->first()
            ?? ShopItem::create($attrs + [
                'active' => false, 'text_name' => Str::limit($name, 128, ''), 'price' => (int) round((float) ($payment->rub_amount ?? $payment->amount)),
                'description' => 'Создано веб-приложением', 'tag' => 'webapp',
            ]);
    }

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
     * Brings bot models and prompts into the app catalog. New bot rows get an app row linked to them; existing links
     * refresh their local copy (used for sorting and as a fallback). Nothing is written to the bot tables here.
     *
     * @return array{models:int, photosets:int}
     */
    public function pullCatalog(): array
    {
        $created = ['models' => 0, 'photosets' => 0];

        $deleted = (array) config('neurogpt.deleted');
        DB::transaction(function () use (&$created, $deleted) {
            $linked = AiModel::whereNotNull('bot_model_id')->get()->keyBy('bot_model_id');
            foreach (BotModel::whereIn('type', array_keys(BotModel::KINDS))->orderBy('id')->get() as $bot) {
                $kind = BotModel::KINDS[$bot->type];
                $local = ['name' => $bot->text_name ?: $bot->name, 'price' => max(0, (int) $bot->price), 'min_plan' => $bot->minPlan()];
                if (in_array("bot:model:{$bot->id}", $deleted, true)) {
                    continue; // the admin removed it from the app
                }
                if ($row = $linked->get($bot->id)) {
                    $row->setRawAttributes(array_merge($row->getAttributes(), $local));
                    $row->saveQuietly();

                    continue;
                }
                // A config row with the same provider model id (bot `name`) is linked instead of duplicated.
                $row = AiModel::where('kind', $kind)->whereNull('bot_model_id')->where('provider_model', $bot->name)->orderBy('id')->first();
                if ($row && AiModel::where('kind', $kind)->whereNull('bot_model_id')->where('provider_model', $bot->name)->count() === 1) {
                    $row->forceFill(['bot_model_id' => $bot->id] + $local)->saveQuietly();

                    continue;
                }
                $slug = $this->freeSlug($kind, Str::slug($bot->name ?: $bot->text_name, '-') ?: 'm'.$bot->id);
                AiModel::create([
                    'bot_model_id' => $bot->id, 'kind' => $kind, 'slug' => $slug,
                    'hint' => null, 'driver' => $this->driverFor($kind), 'provider_model' => $bot->name,
                    // Video needs provider settings (family, durations) that the bot table does not have: the admin turns it on.
                    'is_active' => $kind !== 'video',
                    'sort' => (int) AiModel::where('kind', $kind)->max('sort') + 1,
                ] + $local);
                $created['models']++;
            }

            $linkedSets = Photoset::whereNotNull('prompt_id')->get()->keyBy('prompt_id');
            foreach (BotPrompt::orderBy('position')->orderBy('id')->get() as $p) {
                if (in_array("bot:prompt:{$p->id}", $deleted, true)) {
                    continue;
                }
                $local = ['name' => $p->name, 'prompt' => $p->prompt, 'is_trending' => (bool) $p->trend, 'sort' => (int) ($p->position ?? 0)];
                if ($row = $linkedSets->get($p->id)) {
                    $row->setRawAttributes(array_merge($row->getAttributes(), $local));
                    $row->saveQuietly();

                    continue;
                }
                $row = new Photoset(['seed' => ($p->id * 37) % 100, 'palette' => $p->id % 5, 'is_active' => true]);
                $row->forceFill(['prompt_id' => $p->id] + $local)->save();
                $created['photosets']++;
            }
        });

        return $created;
    }

    private function driverFor(string $kind): string
    {
        return $kind === 'video' ? (string) config('neurogpt.providers.kie_driver') : (string) config('neurogpt.providers.default');
    }

    private function freeSlug(string $kind, string $base): string
    {
        $base = Str::limit(preg_replace('/[^a-z0-9_-]/', '', strtolower($base)) ?: 'model', 28, '');
        $slug = $base;
        for ($i = 2; AiModel::where('kind', $kind)->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
