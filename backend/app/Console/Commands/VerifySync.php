<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Checks that the app and the bot see the same data:
 *  1. the bot tables have every column of models.py the app relies on;
 *  2. links from the app tables into the bot tables point to existing rows (models, prompts, payments, query_logs, users);
 *  3. every balance change in the ledger adds up;
 *  4. after neurogpt:import-legacy: row counts per table and a field-by-field comparison of sampled rows.
 * Exit code 1 when something is wrong, so it can run in CI or after a deploy.
 */
class VerifySync extends Command
{
    protected $signature = 'neurogpt:verify-sync {--sample=200 : rows compared per table with the old database} {--no-legacy : skip the comparison with the old database} {--balance=add : the --balance used for neurogpt:import-legacy}';

    protected $description = 'Verify the app data against the bot tables (and against the old web app database after an import)';

    /** Columns of models.py (as Tortoise names them) the app reads or writes. */
    public const BOT_COLUMNS = [
        'users' => ['id', 'username', 'email', 'paid_money', 'is_premium', 'blocked_us', 'tokens', 'sub_tokens', 'temp_tokens',
            'temp_tokens_until', 'text_model_id', 'text_role_id', 'photo_model_id', 'video_model_id', 'save_context',
            'sub', 'start_sub_time', 'end_sub_time', 'autocont_sub', 'autocont_try', 'is_trial_sub', 'auto_continue',
            'last_payment_method_id', 'was_subed', 'link', 'referal_user_from', 'ref_paid', 'ref_balance', 'last_notif', 'last_activity_at', 'created_at'],
        'models' => ['id', 'type', 'can_image', 'price', 'name', 'text_name', 'tag', 'only_sub', 'params', 'created_at', 'modified_at'],
        'prompts' => ['id', 'position', 'name', 'prompt', 'photo_example', 'model', 'trend'],
        'roles' => ['id', 'name', 'prompt'],
        'links' => ['id', 'name', 'hide', 'prompt_id_id', 'created_at', 'modified_at'],
        'shop' => ['id', 'active', 'text_name', 'category', 'item', 'amount', 'price', 'description', 'tag', 'created_at', 'modified_at'],
        'payments' => ['id', 'payment_id', 'is_autopayment', 'was_bought', 'is_proceed', 'user_id', 'recipient_id', 'item_id', 'total',
            'total_valut', 'rub_total', 'created_at', 'modified_at'],
        'query_logs' => ['id', 'user_id', 'model', 'type', 'token_used', 'prompt', 'answer', 'attachments', 'was_image_deleted', 'created_at', 'modified_at'],
        'payment_methods' => ['id', 'payment_method_id', 'first6', 'last4', 'card_type', 'created_at'],
        'op' => ['id', 'channel_id', 'check_sub', 'link', 'created_at', 'modified_at'],
        'promos' => ['id', 'name', 'discount', 'uses', 'created_at', 'modified_at'],
        'logs_table' => ['id', 'user_id', 'action', 'params', 'event_type', 'created_at', 'modified_at'],
        'veo3_prompts' => ['id', 'user_id', 'hash', 'status', 'model', 'task_id', 'prompts', 'tokens_used', 'created_at', 'modified_at'],
        'config' => ['id', 'category', 'name', 'value', 'created_at', 'modified_at'],
        'admins' => ['id', 'username'],
    ];

    private int $errors = 0;

    private int $warnings = 0;

    public function handle(): int
    {
        $this->schema();
        $this->links();
        $this->ledger();
        if (! $this->option('no-legacy')) {
            $this->legacy();
        }

        $this->newLine();
        if ($this->errors) {
            $this->error("Errors: {$this->errors}, warnings: {$this->warnings}");

            return self::FAILURE;
        }
        $this->info("OK. Warnings: {$this->warnings}");

        return self::SUCCESS;
    }

    private function bad(string $msg): void
    {
        $this->errors++;
        $this->line("  <fg=red>✗</> $msg");
    }

    private function warn2(string $msg): void
    {
        $this->warnings++;
        $this->line("  <fg=yellow>!</> $msg");
    }

    private function ok(string $msg): void
    {
        $this->line("  <fg=green>✓</> $msg");
    }

    private function schema(): void
    {
        $this->info('1. Bot tables (models.py)');
        foreach (self::BOT_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $this->bad("table `$table` is missing");

                continue;
            }
            $missing = array_diff($columns, Schema::getColumnListing($table));
            $missing ? $this->bad("`$table`: no column ".implode(', ', $missing)) : $this->ok("`$table`: ".count($columns).' columns');
        }
    }

    private function links(): void
    {
        $this->info('2. App rows that point into bot tables');
        $orphans = fn (string $table, string $col, string $target, string $targetCol = 'id') => DB::table($table)
            ->whereNotNull($col)->whereNotExists(fn ($q) => $q->from($target)->whereColumn("$target.$targetCol", "$table.$col"))->count();

        foreach ([
            ['wa_model_meta', 'model_id', 'models', 'app settings of models deleted from the bot table (harmless, ignored)'],
            ['wa_photoset_meta', 'prompt_id', 'prompts', 'app settings of prompts deleted from the bot table (harmless, ignored)'],
        ] as [$t, $c, $target, $label]) {
            ($n = $orphans($t, $c, $target)) ? $this->warn2("$n $label") : $this->ok("$t.$c → $target");
        }
        $noMeta = DB::table('models')->whereNotExists(fn ($q) => $q->from('wa_model_meta')->whereColumn('wa_model_meta.model_id', 'models.id'))->count()
            + DB::table('prompts')->whereNotExists(fn ($q) => $q->from('wa_photoset_meta')->whereColumn('wa_photoset_meta.prompt_id', 'prompts.id'))->count();
        $noMeta ? $this->warn2("$noMeta bot models / prompts have no app settings yet (run neurogpt:sync-bot)") : $this->ok('every bot model and prompt has its app settings');
        foreach ([
            ['wa_user_profiles', 'user_id', 'profiles of people missing in users'],
            ['wa_generations', 'user_id', 'generations of people missing in users'],
            ['wa_transactions', 'user_id', 'ledger rows of people missing in users'],
        ] as [$t, $c, $label]) {
            ($n = $orphans($t, $c, 'users')) ? $this->warn2("$n $label (deleted by the bot?)") : $this->ok("$t.$c → users");
        }


        // payments: one bot row per app payment, with the same state
        $lost = $orphans('wa_payment_details', 'payment_id', 'payments');
        $lost ? $this->bad("$lost app payment details have no row in the bot table payments") : $this->ok('every app payment is a row of the bot table payments');
        $bad = DB::table('wa_payment_details as d')->join('payments as p', 'p.id', '=', 'd.payment_id')
            ->where(fn ($q) => $q
                ->where(fn ($x) => $x->whereIn('d.status', ['succeeded', 'refunded'])->where('p.was_bought', false))
                ->orWhere(fn ($x) => $x->where('d.status', 'pending')->where(fn ($y) => $y->where('p.is_proceed', true)->orWhere('p.was_bought', true)))
                ->orWhere(fn ($x) => $x->where('d.status', 'canceled')->where(fn ($y) => $y->where('p.is_proceed', false)->orWhere('p.was_bought', true))))
            ->count();
        $bad ? $this->bad("$bad app payments have another state in the bot table (was_bought / is_proceed)") : $this->ok('payment states match the bot flags');
        $usesLost = $orphans('wa_promo_uses', 'payment_id', 'payments');
        $usesLost ? $this->warn2("$usesLost promo uses point to deleted payments") : $this->ok('promo uses → payments');

        if (config('neurogpt.bot.query_logs')) {
            $since = DB::table('wa_legacy_map')->max('created_at');
            $q = DB::table('wa_generations')->where('status', 'done')->whereNull('query_log_id');
            if ($since) {
                $q->where('created_at', '>', $since); // imported history was never logged
            }
            ($n = $q->count()) ? $this->warn2("$n finished generations are not in query_logs") : $this->ok('finished generations are in query_logs');
        }
    }

    private function ledger(): void
    {
        $this->info('3. Ledger');
        $bad = 0;
        DB::table('wa_transactions')->whereNotNull('buckets')->orderBy('id')->chunk(2000, function ($rows) use (&$bad) {
            foreach ($rows as $r) {
                $buckets = json_decode((string) $r->buckets, true) ?: [];
                if (array_sum($buckets) !== (int) $r->amount || array_diff(array_keys($buckets), ['tokens', 'sub_tokens', 'temp_tokens'])) {
                    $bad++;
                }
            }
        });
        $bad ? $this->bad("$bad ledger rows where the counters do not add up to the amount") : $this->ok('every ledger row adds up');

        $negative = DB::table('users')->where(fn ($q) => $q->where('tokens', '<', 0)->orWhere('sub_tokens', '<', 0)->orWhere('temp_tokens', '<', 0))->count();
        $negative ? $this->warn2("$negative users have a negative counter (written by the bot?)") : $this->ok('no negative counters');
    }

    private function legacy(): void
    {
        $this->info('4. Old web app database');
        if (! DB::table('wa_legacy_map')->exists()) {
            $this->line('  nothing was imported (skip)');

            return;
        }
        try {
            $old = DB::connection('legacy');
            $old->getPdo();
        } catch (Throwable $e) {
            $this->warn2('no connection to the old database (LEGACY_DB_*): '.$e->getMessage());

            return;
        }

        $tables = [
            'users' => 'users', 'ai_models' => 'models', 'photosets' => 'prompts', 'news' => 'wa_news', 'payments' => 'payments',
            'generations' => 'wa_generations', 'chats' => 'wa_chats', 'chat_messages' => 'wa_chat_messages', 'transactions' => 'wa_transactions',
        ];
        foreach ($tables as $src => $dst) {
            $total = $old->table($src)->count();
            $mapped = DB::table('wa_legacy_map')->where('source_table', $src)->count();
            $missingTargets = DB::table('wa_legacy_map as m')->where('source_table', $src)
                ->whereNotExists(fn ($q) => $q->from($dst)->whereColumn("$dst.id", 'm.target_id'))->count();
            if ($mapped !== $total) {
                $this->bad("$src: ".$total.' rows in the old database, '.$mapped.' copied (run neurogpt:import-legacy)');
            } elseif ($missingTargets) {
                $this->bad("$src: $missingTargets copied rows are gone from $dst");
            } else {
                $this->ok("$src: $total = $mapped");
            }
        }

        $sample = max(1, (int) $this->option('sample'));
        $check = function (string $src, string $dst, array $fields, ?callable $extra = null) use ($old, $sample) {
            $pairs = DB::table('wa_legacy_map')->where('source_table', $src)->inRandomOrder()->limit($sample)->pluck('target_id', 'source_id');
            $oldRows = $old->table($src)->whereIn('id', $pairs->keys())->get()->keyBy('id');
            $newRows = DB::table($dst)->whereIn('id', $pairs->values())->get()->keyBy('id');
            $diff = 0;
            foreach ($pairs as $oldId => $newId) {
                $o = $oldRows->get($oldId);
                $n = $newRows->get($newId);
                if (! $o || ! $n) {
                    $diff++;

                    continue;
                }
                foreach ($fields as $of => $nf) {
                    if ($this->norm($o->{$of}) !== $this->norm($n->{$nf})) {
                        $diff++;
                        $this->line("    $src #$oldId.$of: «".$this->norm($o->{$of})."» ≠ $dst #$newId.$nf: «".$this->norm($n->{$nf}).'»');

                        continue 2;
                    }
                }
                if ($extra && ($why = $extra($o, $n))) {
                    $diff++;
                    $this->line("    $src #$oldId: $why");
                }
            }
            $diff ? $this->bad("$src: $diff of {$pairs->count()} sampled rows differ") : $this->ok("$src: {$pairs->count()} sampled rows match field by field");
        };

        $check('users', 'users', ['telegram_id' => 'id'], function ($o, $n) {
            $p = DB::table('wa_user_profiles')->where('user_id', $n->id)->first();
            if (! $p || ($o->first_name !== null && $p->first_name !== $o->first_name)) {
                return 'profile (name) was not carried over';
            }
            if ((int) $o->balance > 0 && $this->option('balance') !== 'skip') {
                $moved = (int) DB::table('wa_transactions')->where('user_id', $n->id)->where('type', 'import')->sum('amount');
                if ($moved !== (int) $o->balance) {
                    return "old balance {$o->balance}, carried over $moved";
                }
            }

            return null;
        });
        $check('payments', 'payments', ['amount' => 'total', 'currency' => 'total_valut'], function ($o, $n) {
            $d = DB::table('wa_payment_details')->where('payment_id', $n->id)->first();
            if (! $d) {
                return 'no wa_payment_details row';
            }
            foreach (['provider', 'idempotence_key', 'purpose', 'tokens', 'plan', 'status', 'credited_at'] as $f) {
                if ($this->norm($o->{$f}) !== $this->norm($d->{$f})) {
                    return "$f: «".$this->norm($o->{$f}).'» ≠ «'.$this->norm($d->{$f}).'»';
                }
            }

            return ((bool) $o->credited_at) !== (bool) $n->was_bought ? 'was_bought does not match the old status' : null;
        });
        $check('generations', 'wa_generations', ['kind' => 'kind', 'tool' => 'tool', 'prompt' => 'prompt', 'cost' => 'cost', 'status' => 'status',
            'result_path' => 'result_path', 'is_favorite' => 'is_favorite', 'created_at' => 'created_at']);
        $check('chat_messages', 'wa_chat_messages', ['role' => 'role', 'content' => 'content', 'cost' => 'cost', 'created_at' => 'created_at']);
        $check('transactions', 'wa_transactions', ['type' => 'type', 'amount' => 'amount', 'balance_after' => 'balance_after', 'title' => 'title', 'details' => 'details', 'created_at' => 'created_at']);

        // owners: the person of every copied row is the same Telegram id
        foreach (['payments' => 'payments', 'generations' => 'wa_generations', 'transactions' => 'wa_transactions'] as $src => $dst) {
            $oldOwner = $old->table($src)->join('users', 'users.id', '=', "$src.user_id")->pluck('users.telegram_id', "$src.id");
            $wrong = 0;
            DB::table('wa_legacy_map as m')->join($dst, "$dst.id", '=', 'm.target_id')->where('m.source_table', $src)
                ->select('m.source_id', "$dst.user_id")->orderBy('m.id')->chunk(5000, function ($rows) use ($oldOwner, &$wrong) {
                    foreach ($rows as $r) {
                        if ((int) ($oldOwner[$r->source_id] ?? -1) !== (int) $r->user_id) {
                            $wrong++;
                        }
                    }
                });
            $wrong ? $this->bad("$src: $wrong rows belong to another person after the copy") : $this->ok("$src: every row belongs to the same Telegram id");
        }
    }

    private function norm(mixed $v): string
    {
        if ($v === null) {
            return '∅';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_numeric($v)) {
            return rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
        }
        if (preg_match('/^\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d/', (string) $v)) {
            return substr(str_replace('T', ' ', (string) $v), 0, 19);
        }

        return (string) $v;
    }
}
