<?php

namespace App\Services\Billing;

use App\Exceptions\InsufficientBalance;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Every balance change goes through here: a locked row, a ledger entry, one DB transaction.
 */
class Wallet
{
    public function credit(User $user, int $amount, string $type, string $title, array $link = [], ?string $details = null): Transaction
    {
        return $this->apply($user, abs($amount), $type, $title, $link, $details);
    }

    /** @throws InsufficientBalance */
    public function debit(User $user, int $amount, string $title, array $link = [], ?string $details = null): Transaction
    {
        return $this->apply($user, -abs($amount), 'spend', $title, $link, $details);
    }

    private function apply(User $user, int $delta, string $type, string $title, array $link, ?string $details): Transaction
    {
        return DB::transaction(function () use ($user, $delta, $type, $title, $link, $details) {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->balance + $delta < 0) {
                throw new InsufficientBalance(abs($delta), $locked->balance);
            }

            $locked->balance += $delta;
            $locked->save();
            $user->balance = $locked->balance;

            return Transaction::create([
                'user_id' => $locked->id,
                'type' => $type,
                'amount' => $delta,
                'balance_after' => $locked->balance,
                'title' => $title,
                'details' => $details,
                'generation_id' => $link['generation_id'] ?? null,
                'payment_id' => $link['payment_id'] ?? null,
            ]);
        });
    }
}
