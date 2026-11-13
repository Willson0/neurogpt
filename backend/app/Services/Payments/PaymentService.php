<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\Wallet;
use App\Services\Telegram\Bot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class PaymentService
{
    private const RANK = ['free' => 0, 'plus' => 1, 'pro' => 2];

    public function __construct(private readonly YooKassa $yookassa, private readonly Wallet $wallet, private readonly Bot $bot) {}

    /** Which ways to pay are configured right now. */
    public static function methods(): array
    {
        return [
            'card' => (bool) (config('neurogpt.yookassa.shop_id') && config('neurogpt.yookassa.secret_key')),
            'stars' => (bool) (config('neurogpt.stars.enabled') && config('neurogpt.telegram.bot_token')),
        ];
    }

    public static function stars(float|int $rub): int
    {
        return max(1, (int) ceil($rub / (float) config('neurogpt.stars.rub_per_star')));
    }

    public function createTopup(User $user, int $tokens, string $method = 'card'): Payment
    {
        $pack = collect(config('neurogpt.packs'))->firstWhere('tokens', $tokens);
        if (! $pack) {
            throw new InvalidArgumentException('Unknown pack');
        }

        return $this->start($user, $method, [
            'purpose' => 'topup', 'tokens' => $pack['tokens'], 'amount' => $pack['price'],
        ], "Пополнение: {$pack['tokens']} токенов");
    }

    public function createPlan(User $user, string $plan, string $period, string $method = 'card'): Payment
    {
        $cfg = config("neurogpt.plans.$plan");
        if (! $cfg || $plan === 'free' || ! in_array($period, ['month', 'year'], true)) {
            throw new InvalidArgumentException('Unknown plan');
        }

        $amount = $period === 'year' ? $cfg['year_month'] * 12 : $cfg['month'];

        return $this->start($user, $method, [
            'purpose' => 'plan', 'plan' => $plan, 'period' => $period, 'amount' => $amount,
            'tokens' => $cfg['tokens'] * ($period === 'year' ? 12 : 1),
        ], "Подписка {$cfg['name']}, ".($period === 'year' ? 'год' : 'месяц'));
    }

    /** Buying a cheaper plan while a higher one is active would silently take the higher one away. */
    public function isDowngrade(User $user, string $plan): bool
    {
        return (self::RANK[$plan] ?? 0) < (self::RANK[$user->activePlan()] ?? 0);
    }

    private function start(User $user, string $method, array $attrs, string $description): Payment
    {
        if (! (self::methods()[$method] ?? false)) {
            throw new RuntimeException("Payment method $method is not configured");
        }

        $stars = $method === 'stars';
        $payment = Payment::create(array_merge($attrs, [
            'user_id' => $user->id,
            'provider' => $stars ? 'stars' : 'yookassa',
            'currency' => $stars ? 'XTR' : 'RUB',
            'amount' => $stars ? self::stars($attrs['amount']) : $attrs['amount'],
            'idempotence_key' => (string) Str::uuid(),
            'status' => 'pending',
        ]));

        try {
            $stars ? $this->startStars($payment, $description) : $this->startYooKassa($payment, $user, $description);
        } catch (Throwable $e) {
            $payment->update(['status' => 'canceled', 'cancel_reason' => 'create_failed']);
            throw $e;
        }

        return $payment;
    }

    private function startYooKassa(Payment $payment, User $user, string $description): void
    {
        $returnUrl = rtrim(config('neurogpt.yookassa.return_url'), '/');
        $remote = $this->yookassa->createPayment(
            $payment->idempotence_key,
            (float) $payment->amount,
            $description,
            $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'payment='.$payment->id,
            ['payment_id' => (string) $payment->id, 'user_id' => (string) $user->id],
            $this->receipt($payment, $description),
        );

        $payment->update([
            'provider_payment_id' => $remote['id'],
            'confirmation_url' => $remote['confirmation']['confirmation_url'] ?? null,
            'raw' => $remote,
        ]);
    }

    private function startStars(Payment $payment, string $description): void
    {
        $title = $payment->purpose === 'topup' ? "{$payment->tokens} токенов" : 'Подписка '.config("neurogpt.plans.{$payment->plan}.name");
        $link = $this->bot->createStarsInvoice($title, $description.' в NeuroGPT', self::starsPayload($payment), (int) $payment->amount);
        if (! $link) {
            throw new RuntimeException('Telegram did not create the Stars invoice');
        }

        $payment->update(['confirmation_url' => $link]);
    }

    /** The payload Telegram echoes back: our payment id plus its secret key, so it cannot be guessed. */
    public static function starsPayload(Payment $payment): string
    {
        return 'pay:'.$payment->id.':'.$payment->idempotence_key;
    }

    public function findByStarsPayload(?string $payload): ?Payment
    {
        if (! is_string($payload) || ! preg_match('/^pay:(\d+):([0-9a-f-]{36})$/', $payload, $m)) {
            return null;
        }
        $payment = Payment::where('provider', 'stars')->find((int) $m[1]);

        return $payment && hash_equals($payment->idempotence_key, $m[2]) ? $payment : null;
    }

    /** Telegram asks "may this invoice be paid?" right before charging. Null means yes, a string is the refusal. */
    public function checkStars(?Payment $payment, int $telegramUserId, string $currency, int $amount): ?string
    {
        if (! $payment || $payment->user?->telegram_id !== $telegramUserId) {
            return 'Счёт не найден. Откройте приложение и попробуйте ещё раз.';
        }
        if ($payment->status !== 'pending') {
            return 'Этот счёт уже оплачен или устарел. Создайте новый в приложении.';
        }
        if ($currency !== 'XTR' || $amount !== (int) $payment->amount) {
            return 'Сумма счёта изменилась. Создайте новый в приложении.';
        }

        return null;
    }

    /** successful_payment from Telegram: the Stars are already taken, so the purchase is delivered exactly once. */
    public function confirmStars(Payment $payment, string $currency, int $amount, string $chargeId, array $raw): Payment
    {
        return DB::transaction(function () use ($payment, $currency, $amount, $chargeId, $raw) {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->credited_at) {
                return $locked;
            }
            if ($currency !== 'XTR' || $amount !== (int) $locked->amount) {
                Log::critical('Stars payment amount mismatch', ['payment' => $locked->id, 'paid' => $amount, 'expected' => (int) $locked->amount]);
                $locked->update(['status' => 'canceled', 'cancel_reason' => 'amount_mismatch', 'provider_payment_id' => $chargeId, 'raw' => $raw]);

                return $locked;
            }

            $this->fulfil($locked, 'Telegram Stars');
            $locked->update(['status' => 'succeeded', 'credited_at' => now(), 'provider_payment_id' => $chargeId, 'raw' => $raw]);

            return $locked;
        });
    }

    private function receipt(Payment $payment, string $description): ?array
    {
        $cfg = config('neurogpt.yookassa.receipt');
        if (! $cfg['enabled'] || ! $cfg['email']) {
            return null;
        }

        $value = number_format((float) $payment->amount, 2, '.', '');

        return [
            'customer' => ['email' => $cfg['email']],
            'tax_system_code' => $cfg['tax_system_code'],
            'items' => [[
                'description' => mb_substr($description, 0, 128),
                'quantity' => '1.00',
                'amount' => ['value' => $value, 'currency' => 'RUB'],
                'vat_code' => $cfg['vat_code'],
                'payment_mode' => 'full_prepayment',
                'payment_subject' => 'service',
            ]],
        ];
    }

    /**
     * Pull the real status from YooKassa and apply it. Webhook bodies are never
     * trusted on their own: only this server-to-server answer counts.
     */
    public function sync(Payment $payment): Payment
    {
        if ($payment->provider !== 'yookassa' || $payment->status !== 'pending' || ! $payment->provider_payment_id) {
            return $payment;
        }

        $remote = $this->yookassa->getPayment($payment->provider_payment_id);

        return $this->apply($payment, $remote);
    }

    public function apply(Payment $payment, array $remote): Payment
    {
        return DB::transaction(function () use ($payment, $remote) {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $status = $remote['status'] ?? 'pending';

            // The answer must describe this very payment.
            if (($remote['id'] ?? null) !== $locked->provider_payment_id) {
                return $locked;
            }

            if ($status === 'succeeded' && ! $locked->credited_at) {
                // Paid in full, in rubles, exactly what we asked for.
                $paid = $remote['amount']['value'] ?? null;
                $currency = $remote['amount']['currency'] ?? null;
                if (($remote['paid'] ?? true) !== true || $currency !== 'RUB' || $paid === null || abs((float) $paid - (float) $locked->amount) > 0.001) {
                    Log::critical('YooKassa payment mismatch', ['payment' => $locked->id, 'paid' => $paid, 'currency' => $currency]);
                    $locked->update(['status' => 'canceled', 'cancel_reason' => 'amount_mismatch', 'raw' => $remote]);

                    return $locked;
                }
                $this->fulfil($locked, 'ЮKassa');
                $locked->update(['status' => 'succeeded', 'credited_at' => now(), 'raw' => $remote]);
            } elseif ($status === 'canceled' && $locked->status === 'pending') {
                $locked->update([
                    'status' => 'canceled',
                    'cancel_reason' => $remote['cancellation_details']['reason'] ?? null,
                    'raw' => $remote,
                ]);
            }

            return $locked;
        });
    }

    private function fulfil(Payment $payment, string $via): void
    {
        $user = User::query()->whereKey($payment->user_id)->lockForUpdate()->firstOrFail();

        if ($payment->purpose === 'topup') {
            $this->wallet->credit($user, $payment->tokens, 'topup', 'Пополнение', ['payment_id' => $payment->id], $via);

            return;
        }

        $months = $payment->period === 'year' ? 12 : 1;
        $active = $user->activePlan();
        if ((self::RANK[$payment->plan] ?? 0) >= (self::RANK[$active] ?? 0)) {
            // Same plan: the paid time is added on top. Upgrade: the new plan starts now.
            $base = ($active === $payment->plan && $user->plan_expires_at?->isFuture()) ? $user->plan_expires_at : now();
            $user->update(['plan' => $payment->plan, 'plan_expires_at' => $base->copy()->addMonths($months)]);
        }
        // (A cheaper plan paid while a higher one is active keeps the higher plan; the tokens are still credited.)

        $name = config("neurogpt.plans.{$payment->plan}.name");
        $this->wallet->credit($user, $payment->tokens, 'subscription', "Подписка {$name}", ['payment_id' => $payment->id], $via);
    }

    /** Pending payments nobody finished: YooKassa ones are re-checked first, then everything old is closed. */
    public function expireStale(int $hours = 24): int
    {
        $closed = 0;
        Payment::where('status', 'pending')->where('created_at', '<', now()->subHours($hours))->orderBy('id')
            ->chunkById(100, function ($payments) use (&$closed) {
                foreach ($payments as $payment) {
                    try {
                        $payment = $this->sync($payment);
                    } catch (Throwable $e) {
                        continue; // YooKassa is unreachable: try again on the next run
                    }
                    if ($payment->status === 'pending') {
                        $closed += Payment::whereKey($payment->id)->where('status', 'pending')
                            ->update(['status' => 'canceled', 'cancel_reason' => 'expired']);
                    }
                }
            });

        return $closed;
    }
}
