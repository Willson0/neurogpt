<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Payments live in the bot table `payments` (models.py, PaymentModel) from the moment they are created, not only after
 * they were paid. Its row is the payment: id, payment_id (YooKassa id / UUID), user, item, total, currency, rub_total and
 * the state (is_proceed = 0 while waiting, was_bought = 1 when paid, is_proceed = 1 & was_bought = 0 when canceled).
 *
 * The bot table has no columns for what only the web app needs (Stars invoice key, confirmation link, the 20 % referral
 * commission, refunds, the promo code). That goes to wa_payment_details, one row per payments.id, nothing else.
 * Bot tables are not altered: no column is added to `payments` or `promos`.
 *
 * Promo codes: the bot table `promos` (name = code, discount = percent, uses = uses left, -1 = unlimited) plus
 * wa_promo_rules for the extra limits set in the admin panel, and wa_promo_uses for who used which code.
 *
 * Old wa_payments rows are copied into `payments` (the ones not there yet) and their details carried over.
 * wa_payments itself is kept untouched as an archive and is no longer written.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wa_payment_details')) {
            Schema::create('wa_payment_details', function (Blueprint $t) {
                $t->unsignedBigInteger('payment_id')->primary(); // payments.id (bot table, no FK into it)
                $t->string('provider', 16); // yookassa | stars
                $t->string('idempotence_key', 64)->unique(); // YooKassa Idempotence-Key, the secret part of the Stars payload
                $t->string('status', 16)->default('pending'); // pending | succeeded | canceled | refunded (same as the bot flags, plus refunded)
                $t->string('purpose', 16); // topup | plan
                $t->unsignedInteger('tokens')->default(0);
                $t->string('plan', 16)->nullable();
                $t->string('period', 8)->nullable();
                $t->unsignedInteger('days')->nullable();
                $t->decimal('price', 10, 2); // rubles before the promo discount
                $t->unsignedBigInteger('promo_id')->nullable(); // promos.id
                $t->string('promo_code', 255)->nullable();
                $t->unsignedTinyInteger('discount')->default(0); // percent
                $t->text('confirmation_url')->nullable();
                $t->string('cancel_reason')->nullable();
                $t->timestamp('credited_at')->nullable();
                $t->unsignedBigInteger('referrer_id')->nullable(); // users.id
                $t->integer('ref_amount')->default(0);
                $t->integer('ref_reverted')->default(0);
                $t->decimal('refunded_amount', 10, 2)->default(0);
                $t->timestamp('refunded_at')->nullable();
                $t->json('raw')->nullable();
                $t->timestamps();
                $t->index(['status', 'created_at']);
                $t->index('referrer_id');
                $t->index('promo_id');
            });
        }

        if (! Schema::hasTable('wa_promo_rules')) {
            Schema::create('wa_promo_rules', function (Blueprint $t) {
                $t->unsignedBigInteger('promo_id')->primary(); // promos.id
                $t->boolean('active')->default(true);
                $t->timestamp('starts_at')->nullable();
                $t->timestamp('expires_at')->nullable();
                $t->unsignedInteger('per_user')->nullable(); // uses per person, null = no limit
                $t->unsignedInteger('min_price')->nullable(); // rubles
                $t->json('item_ids')->nullable(); // shop ids, null = every item
                $t->string('category', 128)->nullable(); // only this shop category (token / sub), null = any
                $t->boolean('new_buyers_only')->default(false); // only people who have never paid
                $t->string('note', 255)->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('wa_promo_uses')) {
            Schema::create('wa_promo_uses', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('promo_id');
                $t->bigInteger('user_id');
                $t->unsignedBigInteger('payment_id')->unique(); // payments.id
                $t->decimal('discount_rub', 10, 2)->default(0);
                $t->timestamp('created_at')->nullable();
                $t->index(['promo_id', 'user_id']);
            });
        }

        // wa_transactions.payment_id now holds payments.id (bot) instead of wa_payments.id.
        if (Schema::hasTable('wa_transactions') && $this->hasForeign('wa_transactions', 'payment_id')) {
            Schema::table('wa_transactions', function (Blueprint $t) {
                $t->dropForeign(['payment_id']);
            });
        }

        if (Schema::hasTable('wa_payments')) {
            $this->copyOldPayments();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_promo_uses');
        Schema::dropIfExists('wa_promo_rules');
        Schema::dropIfExists('wa_payment_details');
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

    /** Every wa_payments row gets its `payments` row (if it had none) and its details. Safe to run again. */
    private function copyOldPayments(): void
    {
        $cfg = config('neurogpt.bot.shop');
        $now = now();
        // which ledger entries belong to which old payment, read before any of them is changed (old and new ids overlap)
        $ledger = DB::table('wa_transactions')->whereNotNull('payment_id')->get(['id', 'payment_id'])
            ->groupBy('payment_id')->map(fn ($g) => $g->pluck('id')->all());
        $relink = [];

        DB::table('wa_payments')->orderBy('id')->chunkById(200, function ($rows) use ($cfg, $now, $ledger, &$relink) {
            foreach ($rows as $w) {
                $botId = $w->bot_payment_id ? (int) $w->bot_payment_id : null;
                if (DB::table('wa_payment_details')->where('idempotence_key', $w->idempotence_key)->exists()
                    || ($botId && DB::table('wa_payment_details')->where('payment_id', $botId)->exists())) {
                    continue; // moved on an earlier run
                }
                if (! DB::table('users')->where('id', $w->user_id)->exists()) {
                    continue;
                }

                if (! $botId || ! DB::table('payments')->where('id', $botId)->exists()) {
                    $itemId = $this->itemFor($w, $cfg, $now);
                    $succeeded = $w->credited_at !== null;
                    $botId = DB::table('payments')->insertGetId([
                        'payment_id' => Str::limit((string) ($w->provider_payment_id ?: $w->idempotence_key), 128, ''),
                        'is_autopayment' => false,
                        'was_bought' => $succeeded,
                        'is_proceed' => $w->status !== 'pending',
                        'total' => (float) $w->amount,
                        'total_valut' => $w->currency ?: 'RUB',
                        'rub_total' => (float) ($w->rub_amount ?? $w->amount),
                        'created_at' => $w->created_at ?? $now,
                        'modified_at' => $w->updated_at ?? $now,
                        'item_id' => $itemId,
                        'recipient_id' => $w->user_id,
                        'user_id' => $w->user_id,
                    ]);
                    if ($succeeded) {
                        // the old code raised paid_money when it mirrored a paid payment; this one was never mirrored
                        DB::table('users')->where('id', $w->user_id)->increment('paid_money', (float) ($w->rub_amount ?? $w->amount) - (float) $w->refunded_amount);
                    }
                }

                DB::table('wa_payment_details')->insert([
                    'payment_id' => $botId,
                    'provider' => $w->provider ?: 'yookassa',
                    'idempotence_key' => $w->idempotence_key,
                    'status' => $w->status,
                    'purpose' => $w->purpose,
                    'tokens' => (int) $w->tokens,
                    'plan' => $w->plan,
                    'period' => $w->period,
                    'days' => $w->days,
                    'price' => (float) ($w->rub_amount ?? $w->amount),
                    'confirmation_url' => $w->confirmation_url,
                    'cancel_reason' => $w->cancel_reason,
                    'credited_at' => $w->credited_at,
                    'referrer_id' => $w->referrer_id,
                    'ref_amount' => (int) $w->ref_amount,
                    'ref_reverted' => (int) $w->ref_reverted,
                    'refunded_amount' => (float) $w->refunded_amount,
                    'refunded_at' => $w->refunded_at,
                    'raw' => $w->raw,
                    'created_at' => $w->created_at,
                    'updated_at' => $w->updated_at,
                ]);
                DB::table('wa_legacy_map')->where('target_table', 'wa_payments')->where('target_id', $w->id)
                    ->update(['target_table' => 'payments', 'target_id' => $botId]);
                if ($ids = $ledger->get($w->id)) {
                    $relink[$botId] = $ids;
                }
            }
        });

        foreach ($relink as $botId => $ids) {
            DB::table('wa_transactions')->whereIn('id', $ids)->update(['payment_id' => $botId]);
        }
    }

    /** The shop row of an old payment: its own, the same pack/plan, or a hidden row (active = 0) so the bot menu does not change. */
    private function itemFor(object $w, array $cfg, $now): int
    {
        if ($w->shop_item_id && DB::table('shop')->where('id', $w->shop_item_id)->exists()) {
            return (int) $w->shop_item_id;
        }
        if ($w->purpose === 'topup') {
            $attrs = ['category' => $cfg['tokens_category'], 'amount' => (int) $w->tokens];
            $found = DB::table('shop')->where($attrs)->orderByDesc('active')->orderBy('id')->value('id');
            $attrs['item'] = $cfg['tokens_item'];
            $name = "{$w->tokens} токенов";
        } else {
            $days = $w->days ?: ($w->period === 'year' ? 365 : 30);
            $attrs = ['category' => $cfg['sub_category'], 'item' => (string) $w->plan, 'amount' => $cfg['sub_amount'] === 'months' ? max(1, (int) round($days / 30)) : $days];
            $found = DB::table('shop')->where($attrs)->orderByDesc('active')->orderBy('id')->value('id');
            $name = 'Подписка '.$w->plan;
        }

        return (int) ($found ?: DB::table('shop')->insertGetId($attrs + [
            'active' => false, 'text_name' => $name, 'price' => (int) round((float) ($w->rub_amount ?? $w->amount)),
            'description' => 'Создано веб-приложением', 'tag' => 'webapp', 'created_at' => $now, 'modified_at' => $now,
        ]));
    }
};
