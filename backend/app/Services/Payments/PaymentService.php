<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PaymentService
{
    public function __construct(private readonly YooKassa $yookassa, private readonly Wallet $wallet) {}

    public function createTopup(User $user, int $tokens): Payment
    {
        $pack = collect(config('neurogpt.packs'))->firstWhere('tokens', $tokens);
        if (! $pack) {
            throw new InvalidArgumentException('Unknown pack');
        }

        return $this->start($user, [
            'purpose' => 'topup', 'tokens' => $pack['tokens'], 'amount' => $pack['price'],
        ], "Пополнение: {$pack['tokens']} токенов");
    }

    public function createPlan(User $user, string $plan, string $period): Payment
    {
        $cfg = config("neurogpt.plans.$plan");
        if (! $cfg || $plan === 'free' || ! in_array($period, ['month', 'year'], true)) {
            throw new InvalidArgumentException('Unknown plan');
        }

        $amount = $period === 'year' ? $cfg['year_month'] * 12 : $cfg['month'];

        return $this->start($user, [
            'purpose' => 'plan', 'plan' => $plan, 'period' => $period, 'amount' => $amount,
            'tokens' => $cfg['tokens'] * ($period === 'year' ? 12 : 1),
        ], "Подписка {$cfg['name']}, ".($period === 'year' ? 'год' : 'месяц'));
    }

    private function start(User $user, array $attrs, string $description): Payment
    {
        $payment = Payment::create($attrs + [
            'user_id' => $user->id,
            'idempotence_key' => (string) Str::uuid(),
            'status' => 'pending',
        ]);

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

        return $payment;
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
        if ($payment->status !== 'pending' || ! $payment->provider_payment_id) {
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

            if ($status === 'succeeded' && ! $locked->credited_at) {
                // The amount must match what we asked for.
                $paid = $remote['amount']['value'] ?? null;
                if ($paid === null || abs((float) $paid - (float) $locked->amount) > 0.001) {
                    $locked->update(['status' => 'canceled', 'cancel_reason' => 'amount_mismatch', 'raw' => $remote]);

                    return $locked;
                }
                $this->fulfil($locked);
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

    private function fulfil(Payment $payment): void
    {
        $user = User::query()->whereKey($payment->user_id)->lockForUpdate()->firstOrFail();

        if ($payment->purpose === 'topup') {
            $this->wallet->credit($user, $payment->tokens, 'topup', 'Пополнение', ['payment_id' => $payment->id], 'ЮKassa');

            return;
        }

        $months = $payment->period === 'year' ? 12 : 1;
        $base = ($user->plan === $payment->plan && $user->plan_expires_at?->isFuture()) ? $user->plan_expires_at : now();
        $user->update(['plan' => $payment->plan, 'plan_expires_at' => $base->copy()->addMonths($months)]);

        $name = config("neurogpt.plans.{$payment->plan}.name");
        $this->wallet->credit($user, $payment->tokens, 'subscription', "Подписка {$name}", ['payment_id' => $payment->id], 'ЮKassa');
    }
}
