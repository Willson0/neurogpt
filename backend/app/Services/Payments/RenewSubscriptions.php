<?php

namespace App\Services\Payments;

use App\Models\Bot\BotTime;
use App\Models\Bot\ShopItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RenewSubscriptions
{
    public const MAX_WAIT_HOURS = 90 * 24;
    public const LATE_GRACE_HOURS = 24;

    public static function enabled(): bool
    {
        return (bool) config('neurogpt.yookassa.auto_renewal') && (bool) config('neurogpt.renewal.enabled');
    }

    public static function primaryDays(): int
    {
        return (int) config('neurogpt.renewal.steps.0.days');
    }

    public function __construct(private readonly PaymentService $payments) {}

    public function run(): void
    {
        if (! PaymentService::methods()['card']) {
            return;
        }

        // Pending remote attempts remain reconcilable after the user cancels future renewals.
        Payment::whereNotNull('renew_for_expiry')->where('status', 'pending')->chunkById(100, function ($attempts) {
            foreach ($attempts as $payment) {
                $this->submit($payment);
            }
        });

        if (! self::enabled()) {
            return;
        }
        $window = [BotTime::from(now()->subHours(self::MAX_WAIT_HOURS + self::LATE_GRACE_HOURS)), BotTime::now()];
        User::where('auto_continue', true)->whereNotNull('last_payment_method_id')
            ->where(function ($q) use ($window) {
                $q->whereBetween('end_sub_time', $window)->orWhere('end_sub_time', 0);
            })
            ->whereExists(fn ($q) => $q->from('wa_payments')->whereColumn('wa_payments.user_id', 'users.id')
                ->where('renew_opt_in', true)->where('status', 'succeeded')->whereBetween('renew_due_at', $window))
            ->chunkById(100, function ($users) {
                foreach ($users as $user) {
                    if ($attempt = $this->reserve($user->id)) {
                        $this->submit($attempt);
                    }
                }
            });
    }

    public static function mandate(int $userId): Builder
    {
        return Payment::where('user_id', $userId)->where('purpose', 'plan')->where('status', 'succeeded')
            ->latest('credited_at')->latest('id');
    }

    private function reserve(int $userId): ?Payment
    {
        return DB::transaction(function () use ($userId) {
            $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
            if (! $user->auto_continue || $user->hasActivePlan()) {
                return null;
            }
            $mandate = self::mandate($user->id)->first();
            if (! $mandate?->renew_opt_in || ! $mandate->renew_method
                || $user->autocont_sub !== $mandate->plan
                || $this->method($user) !== $mandate->renew_method) {
                return null;
            }
            $expiry = max($user->end_sub_time, (int) $mandate->renew_due_at);
            $expires = BotTime::toCarbon($expiry);
            if (! $expires || $expires->isFuture()) {
                return null;
            }
            // A newer bot purchase takes precedence over the authorization from the web app.
            $hasNewerBotPurchase = DB::table('payments')->join('shop', 'shop.id', '=', 'payments.item_id')
                ->where('payments.recipient_id', $user->id)->where('payments.was_bought', true)->where('payments.is_proceed', true)
                ->where('shop.category', config('neurogpt.bot.shop.sub_category'))
                ->where('payments.modified_at', '>', $mandate->credited_at)
                ->when($mandate->bot_payment_id, fn ($q) => $q->where('payments.id', '<>', $mandate->bot_payment_id))
                ->exists();
            if ($hasNewerBotPurchase || Payment::where('user_id', $user->id)->where('purpose', 'plan')
                ->whereNull('renew_for_expiry')->where('status', 'pending')->where('created_at', '>', now()->subHours(2))->exists()) {
                return null;
            }
            $attempts = Payment::where('user_id', $user->id)->where('renew_for_expiry', $expiry)->orderBy('id')->get()->keyBy('plan_days');
            $schedule = $attempts->first()?->renew_schedule ?? $this->schedule($mandate);
            krsort($schedule, SORT_NUMERIC);
            $lastOffset = end($schedule);
            if ($expires->copy()->addHours($lastOffset + self::LATE_GRACE_HOURS)->isPast()) {
                return null;
            }

            $previous = null;
            foreach ($schedule as $days => $offset) {
                if ($attempt = $attempts->get($days)) {
                    if ($attempt->status !== 'canceled' || $attempt->cancel_reason !== 'insufficient_funds') {
                        return null;
                    }
                    $previous = $attempt;
                    continue;
                }

                $offer = SubscriptionCatalog::purchasable()->where('item', $mandate->plan)->where('amount', $days)
                    ->where('price', '<=', $mandate->renew_max_rub)->orderBy('price')->orderBy('id')->first();
                if (! $offer) {
                    continue;
                }
                if ($previous && $offer->price >= (float) $previous->amount) {
                    continue;
                }

                $due = $expires->copy()->addHours($offset);
                if ($previous) {
                    $afterPrevious = $previous->updated_at->copy()->addHours($offset - $schedule[$previous->plan_days]);
                    $due = $due->max($afterPrevious);
                }
                if ($due->isFuture()) {
                    return null;
                }

                return $this->createAttempt($user, $mandate, $offer, $expiry, $schedule);
            }

            return null;
        }, 3);
    }

    private function schedule(Payment $mandate): array
    {
        // Preserve the main period the customer agreed to if an admin later changes the default.
        $base = $mandate->renew_base_days ?? $mandate->plan_days;
        $steps = config('neurogpt.renewal.steps');
        $offset = $steps[0]['wait_hours'];
        $schedule = [$base => $offset];

        foreach (array_slice($steps, 1) as $step) {
            $offset += $step['wait_hours'];
            if ($step['days'] < $base) {
                $schedule[$step['days']] = $offset;
            }
        }

        return $schedule;
    }

    private function method(User $user): ?string
    {
        return DB::table('payment_methods')->where('id', $user->last_payment_method_id)->value('payment_method_id');
    }

    private function createAttempt(User $user, Payment $mandate, ShopItem $offer, int $expiry, array $schedule): Payment
    {
        return Payment::create([
            'user_id' => $user->id, 'provider' => 'yookassa', 'purpose' => 'plan', 'status' => 'pending', 'currency' => 'RUB',
            'plan' => $offer->item, 'amount' => $offer->price, 'rub_amount' => $offer->price, 'tokens' => 0,
            'shop_item_id' => $offer->id, 'plan_days' => $offer->amount, 'plan_name' => $offer->text_name,
            'idempotence_key' => (string) Str::uuid(), 'renew_for_expiry' => $expiry, 'renew_opt_in' => true,
            'renew_max_rub' => $mandate->renew_max_rub, 'renew_method' => $mandate->renew_method,
            'renew_base_days' => $mandate->renew_base_days ?? $mandate->plan_days, 'renew_schedule' => $schedule,
        ]);
    }

    private function submit(Payment $payment): void
    {
        $retry = (bool) ($payment->raw['submission_started'] ?? false);
        try {
            if ($payment->provider_payment_id) {
                $this->payments->sync($payment);

                return;
            }
            if (! self::enabled()) {
                return;
            }
            if ($payment->created_at->lte(now()->subHours(23))) {
                Log::warning('YooKassa renewal requires reconciliation', ['payment' => $payment->id]);

                return;
            }
            $user = $payment->user()->firstOrFail();
            $mandate = self::mandate($user->id)->first();
            if (! $user->auto_continue || $user->hasActivePlan() || ! $mandate?->renew_opt_in
                || max($user->end_sub_time, (int) $mandate->renew_due_at) !== $payment->renew_for_expiry
                || $this->method($user) !== $payment->renew_method) {
                // On a first submission it is safe to close the unused attempt. After a network error, allow webhook recovery.
                if (! isset($payment->raw['submission_started'])) {
                    $this->payments->cancelPending($payment, 'renewal_disabled');
                }

                return;
            }
            $this->payments->startYooKassa($payment, "Автопродление {$payment->subscriptionName()} на {$payment->plan_days} дн.");
        } catch (Throwable $e) {
            // A previous request may have succeeded even if a retry gets a client error, so only the API's
            // verified payment status can end an attempt whose initial outcome was unknown.
            if (! $retry && $this->payments->definitiveCreateFailure($e)) {
                $this->payments->cancelPending($payment, 'create_failed');
            }
            Log::warning('YooKassa renewal request failed', ['payment' => $payment->id, 'exception' => $e::class]);
        }
    }
}
