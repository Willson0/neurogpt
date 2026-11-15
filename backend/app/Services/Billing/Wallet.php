<?php

namespace App\Services\Billing;

use App\Exceptions\InsufficientBalance;
use App\Models\Bot\BotTime;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Every balance change goes through here: a locked row of the bot table `users`, a ledger entry, one DB transaction.
 *
 * The bot keeps three counters. Spending takes temp_tokens first (while temp_tokens_until has not passed), then
 * sub_tokens (while the subscription is active), then tokens. Purchases and bonuses go to tokens, subscription
 * tokens to sub_tokens. A refund puts tokens back where they were taken from (into `tokens` once the
 * temporary or subscription ones have expired). Only the changed counters are written, as relative SQL updates.
 */
class Wallet
{
    public const BUCKETS = ['temp_tokens', 'sub_tokens', 'tokens'];

    public function credit(User $user, int $amount, string $type, string $title, array $link = [], ?string $details = null, string $bucket = 'tokens'): Transaction
    {
        return $this->apply($user, $type, $title, $link, $details, fn (User $u) => [$bucket => abs($amount)]);
    }

    /** @throws InsufficientBalance */
    public function debit(User $user, int $amount, string $title, array $link = [], ?string $details = null): Transaction
    {
        $amount = abs($amount);

        return $this->apply($user, 'spend', $title, $link, $details, function (User $u) use ($amount) {
            $parts = $u->balanceParts();
            if (array_sum($parts) < $amount) {
                throw new InsufficientBalance($amount, array_sum($parts));
            }
            $left = $amount;
            $take = [];
            foreach (self::BUCKETS as $bucket) {
                $n = min($left, $parts[$bucket]);
                if ($n > 0) {
                    $take[$bucket] = -$n;
                    $left -= $n;
                }
            }

            return $take;
        });
    }

    /**
     * Returns what a spend took, to the same counters. $spend is the "spend" entry being undone;
     * without it (or when it is unknown) everything goes to `tokens`.
     */
    public function refund(User $user, int $amount, string $title, array $link = [], ?string $details = null, ?Transaction $spend = null): Transaction
    {
        return $this->apply($user, 'refund', $title, $link, $details, function (User $u) use ($amount, $spend) {
            $back = [];
            $left = abs($amount);
            foreach (self::BUCKETS as $bucket) {
                $n = min($left, abs((int) (($spend?->buckets ?? [])[$bucket] ?? 0)));
                $alive = match ($bucket) {
                    'temp_tokens' => (int) $u->temp_tokens_until > BotTime::now(),
                    'sub_tokens' => $u->hasActivePlan(),
                    default => true,
                };
                if ($n > 0) {
                    $target = $alive ? $bucket : 'tokens';
                    $back[$target] = ($back[$target] ?? 0) + $n;
                    $left -= $n;
                }
            }
            if ($left > 0) {
                $back['tokens'] = ($back['tokens'] ?? 0) + $left;
            }

            return $back;
        });
    }

    /** The spend entry of a generation, for refund(). */
    public static function spendOf(int $generationId): ?Transaction
    {
        return Transaction::where('generation_id', $generationId)->where('type', 'spend')->latest('id')->first();
    }

    /** @param callable(User): array<string,int> $plan bucket => signed change */
    private function apply(User $user, string $type, string $title, array $link, ?string $details, callable $plan): Transaction
    {
        return DB::transaction(function () use ($user, $type, $title, $link, $details, $plan) {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $changes = array_filter($plan($locked), fn ($v) => $v !== 0);

            // `tokens = tokens + n` in SQL (like F() in Tortoise): only the changed counters are touched, so a bot write
            // to other columns of the same row is never lost, and the row lock keeps two app writes in order.
            if ($changes) {
                User::query()->whereKey($locked->id)->incrementEach($changes);
                $locked->setRawAttributes(array_merge($locked->getAttributes(),
                    (array) User::query()->whereKey($locked->id)->toBase()->first(self::BUCKETS)), true);
            }

            // The caller's object shows the new balance right away.
            foreach (self::BUCKETS as $bucket) {
                $user->setAttribute($bucket, $locked->{$bucket});
            }
            $user->syncOriginalAttributes(self::BUCKETS);

            return Transaction::create([
                'user_id' => $locked->id,
                'type' => $type,
                'amount' => array_sum($changes),
                'balance_after' => $locked->balance,
                'buckets' => $changes ?: null,
                'title' => $title,
                'details' => $details,
                'generation_id' => $link['generation_id'] ?? null,
                'payment_id' => $link['payment_id'] ?? null,
            ]);
        });
    }
}
