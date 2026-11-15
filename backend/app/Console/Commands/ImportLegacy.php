<?php

namespace App\Console\Commands;

use App\Models\AiModel;
use App\Models\Bot\BotTime;
use App\Models\News;
use App\Models\Photoset;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Billing\Wallet;
use App\Services\Bot\BotSync;
use App\Services\Payments\Shop;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Moves the data of the old web app database (connection "legacy", LEGACY_DB_* in .env) into the bot database.
 * The old database is only read. Repeatable: every copied row is remembered in wa_legacy_map and skipped next time.
 *
 * People: the old users.telegram_id is the bot users.id. A person the bot already knows keeps every bot value;
 * the old app balance is ADDED to the bot `tokens` (--balance=skip leaves it out), a longer paid plan of the old
 * app is carried over, the inviter is set only where the bot has none.
 */
class ImportLegacy extends Command
{
    protected $signature = 'neurogpt:import-legacy
        {--balance=add : add | skip — what to do with the old app balance}
        {--no-mirror : do not add old paid payments to users.paid_money}
        {--dry-run : only count what would be copied}';

    protected $description = 'Copy users, balances, payments, generations, chats and the catalog from the old web app database into the bot database';

    private Connection $old;

    /** @var array<string, array<int, int>> source table => old id => new id */
    private array $map = [];

    private array $stats = [];

    public function handle(Wallet $wallet, BotSync $sync): int
    {
        try {
            $this->old = DB::connection('legacy');
            $this->old->getPdo();
        } catch (Throwable $e) {
            $this->error('No connection to the old database (LEGACY_DB_* in .env): '.$e->getMessage());

            return self::FAILURE;
        }
        if (! $this->old->getSchemaBuilder()->hasColumn('users', 'telegram_id')) {
            $this->error('The "legacy" connection does not look like the old web app database (users.telegram_id is missing).');

            return self::FAILURE;
        }
        if ($this->old->getDatabaseName() === DB::connection()->getDatabaseName() && $this->old->getConfig('host') === DB::connection()->getConfig('host')) {
            $this->error('LEGACY_DB_* points to the same database as DB_*.');

            return self::FAILURE;
        }

        foreach (DB::table('wa_legacy_map')->get() as $row) {
            $this->map[$row->source_table][(int) $row->source_id] = (int) $row->target_id;
        }

        if ($this->option('dry-run')) {
            foreach (['users', 'ai_models', 'photosets', 'news', 'settings', 'payments', 'generations', 'chats', 'chat_messages', 'transactions'] as $t) {
                if ($this->old->getSchemaBuilder()->hasTable($t)) {
                    $total = $this->old->table($t)->count();
                    $done = $t === 'settings' ? 0 : count($this->map[$t] ?? []);
                    $this->line(sprintf('%-14s %6d rows, %6d already copied', $t, $total, $done));
                }
            }

            return self::SUCCESS;
        }

        $this->users($wallet);
        $this->catalog();
        $this->settings();
        $this->payments($sync);
        $this->generations();
        $this->chats();
        $this->transactions();

        $this->newLine();
        $this->table(['table', 'copied now', 'skipped (copied before)'], collect($this->stats)->map(fn ($s, $t) => [$t, $s['new'] ?? 0, $s['old'] ?? 0])->values()->all());
        $this->info('Done. Check it with: php artisan neurogpt:verify-sync');

        return self::SUCCESS;
    }

    private function remember(string $table, int $oldId, string $target, int $newId): void
    {
        DB::table('wa_legacy_map')->insert(['source_table' => $table, 'source_id' => $oldId, 'target_table' => $target, 'target_id' => $newId, 'created_at' => now()]);
        $this->map[$table][$oldId] = $newId;
        $this->stats[$table]['new'] = ($this->stats[$table]['new'] ?? 0) + 1;
    }

    private function seen(string $table, int $oldId): bool
    {
        if (isset($this->map[$table][$oldId])) {
            $this->stats[$table]['old'] = ($this->stats[$table]['old'] ?? 0) + 1;

            return true;
        }

        return false;
    }

    private function mapped(string $table, $oldId): ?int
    {
        return $oldId === null ? null : ($this->map[$table][(int) $oldId] ?? null);
    }

    // ---------- people ----------

    private function users(Wallet $wallet): void
    {
        $this->info('users…');
        $byOldId = $this->old->table('users')->pluck('telegram_id', 'id')->map(fn ($v) => (int) $v)->all();

        $this->old->table('users')->orderBy('id')->chunk(500, function ($rows) use ($wallet, $byOldId) {
            foreach ($rows as $r) {
                if ($this->seen('users', $r->id)) {
                    continue;
                }
                $tg = (int) $r->telegram_id;
                DB::transaction(function () use ($r, $tg, $wallet, $byOldId) {
                    $user = User::find($tg);
                    if (! $user) {
                        $user = new User;
                        $user->forceFill(User::botDefaults() + BotSync::defaultModelIds());
                        $user->forceFill(['id' => $tg, 'username' => $r->username, 'created_at' => $r->created_at ?? now(), 'last_activity_at' => $r->updated_at ?? now()]);
                        $user->save();
                        $user = User::find($tg);
                    }

                    // inviter: only where the bot has none
                    $inviter = $r->referred_by ? ($byOldId[(int) $r->referred_by] ?? null) : null;
                    if ($inviter && ! $user->referal_user_from && $inviter !== $tg) {
                        $user->forceFill(['referal_user_from' => $inviter]);
                    }
                    // a paid plan that lasts longer than the bot's one
                    $oldUntil = $r->plan_expires_at ? Carbon::parse($r->plan_expires_at) : null;
                    if ($r->plan !== 'free' && $oldUntil?->isFuture() && (! $user->hasActivePlan() || BotTime::from($oldUntil) > (int) $user->end_sub_time)) {
                        $user->setPlan($r->plan, $oldUntil);
                    }
                    $user->save();

                    $profile = UserProfile::firstOrNew(['user_id' => $tg]);
                    foreach (['first_name', 'last_name', 'language_code', 'photo_url', 'last_bonus_on', 'referral_rewarded_at'] as $f) {
                        $profile->{$f} ??= $r->{$f};
                    }
                    $profile->streak = max((int) $profile->streak, (int) $r->streak);
                    $profile->is_blocked = (bool) $profile->is_blocked || (bool) $r->is_blocked;
                    $profile->save();

                    if ($this->option('balance') !== 'skip' && (int) $r->balance > 0) {
                        $wallet->credit($user, (int) $r->balance, 'import', 'Перенос баланса', [], 'Из старой версии приложения (#'.$r->id.')');
                    }
                    $this->remember('users', (int) $r->id, 'users', $tg);
                });
            }
        });
    }

    // ---------- catalog ----------

    private function catalog(): void
    {
        $this->info('catalog…');
        $app = ['hint', 'badge', 'is_fast', 'driver', 'provider_model', 'options', 'sort', 'is_active'];
        foreach ($this->old->table('ai_models')->orderBy('id')->get() as $r) {
            if ($this->seen('ai_models', $r->id)) {
                continue;
            }
            $row = AiModel::where('kind', $r->kind)->where('slug', $r->slug)->first();
            $values = collect((array) $r)->only($app)->all();
            $values['options'] = $r->options ? json_decode($r->options, true) : null;
            if (! $row) {
                $row = AiModel::create($values + ['kind' => $r->kind, 'slug' => $r->slug, 'name' => $r->name, 'price' => $r->price,
                    'min_plan' => $r->min_plan, 'edited_at' => $r->edited_at ?? null]);
            } elseif ($r->edited_at ?? null) {
                // The admin had tuned this model. Linked to the bot: price, name and plan stay the bot's.
                $row->forceFill($values + ($row->bot_model_id ? [] : ['name' => $r->name, 'price' => $r->price, 'min_plan' => $r->min_plan]) + ['edited_at' => $r->edited_at]);
                $row->bot_model_id ? $row->saveQuietly() : $row->save();
            }
            $this->remember('ai_models', (int) $r->id, 'wa_models', $row->id);
        }

        foreach ($this->old->table('photosets')->orderBy('id')->get() as $r) {
            if ($this->seen('photosets', $r->id)) {
                continue;
            }
            $row = Photoset::where('name', $r->name)->whereNull('prompt_id')->first();
            $values = collect((array) $r)->only(['description', 'prompt', 'seed', 'palette', 'is_trending', 'trend_hint', 'sort', 'is_active', 'cover_url'])->all();
            if (! $row) {
                $row = Photoset::create($values + ['name' => $r->name, 'edited_at' => $r->edited_at ?? null]);
            } elseif (($r->edited_at ?? null) || ($r->cover_url && ! $row->getRawOriginal('cover_url'))) {
                $row->forceFill($values + ['edited_at' => $r->edited_at ?? now()])->save();
            }
            $this->remember('photosets', (int) $r->id, 'wa_photosets', $row->id);
        }

        foreach ($this->old->table('news')->orderBy('id')->get() as $r) {
            if ($this->seen('news', $r->id)) {
                continue;
            }
            $values = collect((array) $r)->only(['subtitle', 'sticker', 'style', 'action', 'sort', 'is_active', 'ends_at'])->all();
            $row = News::where('title', $r->title)->first();
            if (! $row) {
                $row = News::create($values + ['title' => $r->title, 'edited_at' => $r->edited_at ?? null]);
            } elseif ($r->edited_at ?? null) {
                $row->forceFill($values + ['edited_at' => $r->edited_at])->save();
            }
            $this->remember('news', (int) $r->id, 'wa_news', $row->id);
        }
    }

    /** Admin settings: a key already saved in the new database is kept. */
    private function settings(): void
    {
        if (! $this->old->getSchemaBuilder()->hasTable('settings')) {
            return;
        }
        foreach ($this->old->table('settings')->get() as $r) {
            if (DB::table('wa_settings')->where('key', $r->key)->exists()) {
                $this->stats['settings']['old'] = ($this->stats['settings']['old'] ?? 0) + 1;

                continue;
            }
            DB::table('wa_settings')->insert(['key' => $r->key, 'value' => $r->value, 'created_at' => $r->created_at, 'updated_at' => $r->updated_at]);
            $this->stats['settings']['new'] = ($this->stats['settings']['new'] ?? 0) + 1;
        }
    }

    // ---------- history ----------

    /** Old payments go into the bot table `payments` (all of them, like new ones) with their details in wa_payment_details. */
    private function payments(BotSync $sync): void
    {
        $this->info('payments…');
        $rate = (float) config('neurogpt.stars.rub_per_star');
        $shop = app(Shop::class);
        $this->old->table('payments')->orderBy('id')->chunk(500, function ($rows) use ($rate, $shop) {
            foreach ($rows as $r) {
                if ($this->seen('payments', $r->id) || ! ($user = $this->mapped('users', $r->user_id))) {
                    continue;
                }
                $rub = $r->currency === 'XTR' ? round((float) $r->amount * $rate, 2) : (float) $r->amount;
                $attrs = ['purpose' => $r->purpose, 'tokens' => (int) $r->tokens, 'plan' => $r->plan, 'period' => $r->period,
                    'days' => $r->purpose === 'plan' ? ($r->period === 'year' ? 365 : 30) : null, 'price' => $rub];
                $paid = $r->credited_at !== null;
                $id = DB::transaction(function () use ($r, $user, $rub, $attrs, $paid, $shop) {
                    $id = DB::table('payments')->insertGetId([
                        'payment_id' => Str::limit((string) ($r->provider_payment_id ?: $r->idempotence_key), 128, ''),
                        'is_autopayment' => false, 'was_bought' => $paid, 'is_proceed' => $r->status !== 'pending',
                        'total' => (float) $r->amount, 'total_valut' => $r->currency ?: 'RUB', 'rub_total' => $rub,
                        'item_id' => $shop->rowFor($attrs)->id, 'user_id' => $user, 'recipient_id' => $user,
                        'created_at' => $r->created_at ?? now(), 'modified_at' => $r->updated_at ?? now(),
                    ]);
                    DB::table('wa_payment_details')->insert($attrs + [
                        'payment_id' => $id, 'provider' => $r->provider ?: 'yookassa', 'idempotence_key' => $r->idempotence_key,
                        'status' => $r->status, 'confirmation_url' => $r->confirmation_url, 'cancel_reason' => $r->cancel_reason,
                        'credited_at' => $r->credited_at, 'raw' => $r->raw, 'created_at' => $r->created_at, 'updated_at' => $r->updated_at,
                    ]);
                    if ($paid && ! $this->option('no-mirror')) {
                        DB::table('users')->where('id', $user)->increment('paid_money', $rub);
                    }

                    return $id;
                });
                $this->remember('payments', (int) $r->id, 'payments', $id);
            }
        });
    }

    private function generations(): void
    {
        $this->info('generations…');
        $this->old->table('generations')->orderBy('id')->chunk(500, function ($rows) {
            $insert = [];
            foreach ($rows as $r) {
                if ($this->seen('generations', $r->id) || ! ($user = $this->mapped('users', $r->user_id))) {
                    continue;
                }
                $id = DB::table('wa_generations')->insertGetId([
                    'user_id' => $user, 'kind' => $r->kind, 'ai_model_id' => $this->mapped('ai_models', $r->ai_model_id),
                    'photoset_id' => $this->mapped('photosets', $r->photoset_id), 'tool' => $r->tool, 'prompt' => $r->prompt,
                    'params' => $r->params, 'input_path' => $r->input_path, 'provider_task_id' => $r->provider_task_id ?? null,
                    'cost' => $r->cost, 'status' => $r->status, 'result_path' => $r->result_path, 'result_text' => $r->result_text,
                    'error' => $r->error, 'is_favorite' => $r->is_favorite, 'rating' => $r->rating,
                    'started_at' => $r->started_at, 'finished_at' => $r->finished_at, 'created_at' => $r->created_at, 'updated_at' => $r->updated_at,
                ]);
                $this->remember('generations', (int) $r->id, 'wa_generations', $id);
            }
        });
    }

    private function chats(): void
    {
        $this->info('chats…');
        foreach ($this->old->table('chats')->orderBy('id')->cursor() as $r) {
            if ($this->seen('chats', $r->id) || ! ($user = $this->mapped('users', $r->user_id))) {
                continue;
            }
            $id = DB::table('wa_chats')->insertGetId(['user_id' => $user, 'title' => $r->title, 'created_at' => $r->created_at, 'updated_at' => $r->updated_at]);
            $this->remember('chats', (int) $r->id, 'wa_chats', $id);
        }
        foreach ($this->old->table('chat_messages')->orderBy('id')->cursor() as $r) {
            if ($this->seen('chat_messages', $r->id) || ! ($chat = $this->mapped('chats', $r->chat_id))) {
                continue;
            }
            $id = DB::table('wa_chat_messages')->insertGetId(['chat_id' => $chat, 'role' => $r->role, 'content' => $r->content, 'cost' => $r->cost,
                'created_at' => $r->created_at, 'updated_at' => $r->updated_at]);
            $this->remember('chat_messages', (int) $r->id, 'wa_chat_messages', $id);
        }
    }

    private function transactions(): void
    {
        $this->info('transactions…');
        $this->old->table('transactions')->orderBy('id')->chunk(1000, function ($rows) {
            foreach ($rows as $r) {
                if ($this->seen('transactions', $r->id) || ! ($user = $this->mapped('users', $r->user_id))) {
                    continue;
                }
                $id = DB::table('wa_transactions')->insertGetId([
                    'user_id' => $user, 'type' => $r->type, 'amount' => $r->amount, 'balance_after' => $r->balance_after, 'buckets' => null,
                    'title' => $r->title, 'details' => $r->details,
                    'generation_id' => $this->mapped('generations', $r->generation_id), 'payment_id' => $this->mapped('payments', $r->payment_id),
                    'created_at' => $r->created_at, 'updated_at' => $r->updated_at,
                ]);
                $this->remember('transactions', (int) $r->id, 'wa_transactions', $id);
            }
        });
    }
}
