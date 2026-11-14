<?php

namespace App\Services\Payments;

use App\Models\Bot\ShopItem;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\Wallet;
use App\Services\Bot\BotSync;
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

    public function __construct(
        private readonly YooKassa $yookassa,
        private readonly Wallet $wallet,
        private readonly Bot $bot,
        private readonly BotSync $botSync,
        private readonly Shop $shop,
    ) {}

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

    /**
     * The main way to pay: by the id of a row of the bot table `shop`. Price, quantity and what is delivered are read
     * from that row here, the app only says which row.
     */
    public function createForItem(User $user, int $itemId, string $method = 'card'): Payment
    {
        $item = $this->shop->find($itemId);
        if (! $item) {
            throw new InvalidArgumentException('Unknown shop item');
        }

        return $this->start($user, $method, $this->itemAttrs($item), (string) $item->text_name);
    }

    /** What a shop row delivers, as wa_payments columns. */
    public function itemAttrs(ShopItem $item): array
    {
        if ($this->shop->isTokens($item)) {
            return ['purpose' => 'topup', 'shop_item_id' => $item->id, 'tokens' => (int) $item->amount, 'amount' => (int) $item->price];
        }

        $days = Shop::days($item);
        $perMonth = (int) config("neurogpt.plans.{$item->item}.tokens", 0);

        return [
            'purpose' => 'plan', 'shop_item_id' => $item->id, 'plan' => (string) $item->item, 'days' => $days,
            'period' => $days >= 300 ? 'year' : 'month', 'amount' => (int) $item->price,
            // subscription tokens: the plan's monthly tokens for every started 30 days
            'tokens' => $perMonth * max(1, (int) round($days / 30)),
        ];
    }

    /** Is this shop row a subscription cheaper than the active one? */
    public function isItemDowngrade(User $user, int $itemId): bool
    {
        $item = $this->shop->find($itemId);

        return $item && ! $this->shop->isTokens($item) && $this->isDowngrade($user, (string) $item->item);
    }

    /** Old endpoint (a pack by its token count). When the shop sells token packs, the price comes from the shop row. */
    public function createTopup(User $user, int $tokens, string $method = 'card'): Payment
    {
        $items = $this->shop->tokenItems();
        if ($items->isNotEmpty()) {
            $item = $items->firstWhere('amount', $tokens) ?? throw new InvalidArgumentException('Unknown pack');

            return $this->createForItem($user, $item->id, $method);
        }

        $pack = collect(config('neurogpt.packs'))->firstWhere('tokens', $tokens);
        if (! $pack) {
            throw new InvalidArgumentException('Unknown pack');
        }

        return $this->start($user, $method, [
            'purpose' => 'topup', 'tokens' => $pack['tokens'], 'amount' => $pack['price'],
        ], "Пополнение: {$pack['tokens']} токенов");
    }

    /** Old endpoint (plan + period). When the shop sells subscriptions, the matching shop row is used. */
    public function createPlan(User $user, string $plan, string $period, string $method = 'card'): Payment
    {
        $cfg = config("neurogpt.plans.$plan");
        if (! $cfg || $plan === 'free' || ! in_array($period, ['month', 'year'], true)) {
            throw new InvalidArgumentException('Unknown plan');
        }

        if ($this->shop->subItems()->isNotEmpty()) {
            $item = $this->shop->planItem($plan, $period) ?? throw new InvalidArgumentException('Unknown plan');

            return $this->createForItem($user, $item->id, $method);
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
            // The ruble price, also for Stars: it goes to the bot's payments.rub_total and users.paid_money.
            'rub_amount' => $attrs['amount'],
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
            array_filter(['payment_id' => (string) $payment->id, 'user_id' => (string) $user->id, 'item_id' => $payment->shop_item_id ? (string) $payment->shop_item_id : null]),
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
        $title = $payment->shop_item_id ? $description
            : ($payment->purpose === 'topup' ? "{$payment->tokens} токенов" : 'Подписка '.config("neurogpt.plans.{$payment->plan}.name"));
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
            $this->creditReferrer($locked);
            $this->botSync->mirrorPayment($locked);

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

    /**
     * Applies a payment object read from the YooKassa API (never a webhook body). Safe to run any number of times:
     * the payment row is locked, delivery happens once (credited_at), the referral commission once (ref_amount),
     * and a refund only takes back what has not been taken back yet (refunded_amount, ref_reverted).
     */
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
                if (($remote['paid'] ?? null) !== true || $currency !== 'RUB' || $paid === null || abs((float) $paid - (float) $locked->amount) > 0.001) {
                    Log::critical('YooKassa payment mismatch', ['payment' => $locked->id, 'paid' => $paid, 'currency' => $currency]);
                    $locked->update(['status' => 'canceled', 'cancel_reason' => 'amount_mismatch', 'raw' => $remote]);

                    return $locked;
                }
                $this->fulfil($locked, 'ЮKassa');
                $locked->update(['status' => 'succeeded', 'credited_at' => now(), 'raw' => $remote]);
                $this->creditReferrer($locked);
                $this->botSync->mirrorPayment($locked);
            } elseif ($status === 'canceled' && $locked->status === 'pending') {
                $locked->update([
                    'status' => 'canceled',
                    'cancel_reason' => $remote['cancellation_details']['reason'] ?? null,
                    'raw' => $remote,
                ]);
            }

            if ($locked->credited_at && isset($remote['refunded_amount']['value'])) {
                $this->applyRefund($locked, (float) $remote['refunded_amount']['value']);
            }

            return $locked;
        });
    }

    /**
     * refund.succeeded (the total comes from the API: payment.refunded_amount). The inviter loses the same share of the
     * commission as the share of the price that was returned; a full refund takes it all back. users.ref_balance may
     * go below zero when the inviter has already been paid out: the next commissions cover it.
     * Tokens and the subscription of the buyer are NOT taken back automatically (support decides).
     */
    private function applyRefund(Payment $locked, float $refundedTotal): void
    {
        $total = (float) $locked->amount;
        $refunded = min($total, max(0.0, round($refundedTotal, 2)));
        $before = (float) $locked->refunded_amount;
        if ($refunded <= $before + 0.001) {
            return; // nothing new: a repeated notification or a re-check
        }

        $full = $refunded >= $total - 0.001;
        $target = $full ? (int) $locked->ref_amount : (int) floor($locked->ref_amount * $refunded / max(0.01, $total));
        $take = $target - (int) $locked->ref_reverted;
        if ($take > 0 && $locked->referrer_id) {
            User::query()->whereKey($locked->referrer_id)->decrement('ref_balance', $take);
        }

        // users.paid_money was raised by the ruble price when the payment was mirrored to the bot: lower it by the refund.
        if ($locked->bot_payment_id) {
            $rubShare = ($refunded - $before) * ((float) ($locked->rub_amount ?? $total) / max(0.01, $total));
            User::query()->whereKey($locked->user_id)->decrement('paid_money', round($rubShare, 2));
        }

        $locked->update([
            'refunded_amount' => $refunded,
            'refunded_at' => now(),
            'ref_reverted' => max((int) $locked->ref_reverted, $target),
            'status' => $full ? 'refunded' : $locked->status,
        ]);
        Log::info('YooKassa refund applied', ['payment' => $locked->id, 'refunded' => $refunded, 'ref_taken_back' => max(0, $take)]);
    }

    /**
     * The inviter (users.referal_user_from of the buyer) gets referral_percent (20 %) of the ruble price into
     * users.ref_balance, once per payment. Called inside the payment's locked transaction right after delivery.
     * The buyer's users.ref_paid is set to true at the first commission ("this referral has brought money").
     */
    private function creditReferrer(Payment $locked): void
    {
        if ($locked->referrer_id || $locked->ref_amount) {
            return;
        }
        $buyer = User::query()->whereKey($locked->user_id)->first(['id', 'referal_user_from', 'ref_paid']);
        $referrerId = (int) ($buyer?->referal_user_from ?? 0);
        if (! $buyer || ! $referrerId || $referrerId === (int) $buyer->id) {
            return;
        }
        $referrer = User::query()->whereKey($referrerId)->first();
        if (! $referrer || $referrer->is_blocked) {
            return;
        }

        $percent = max(0, min(100, (int) config('neurogpt.bonus.referral_percent', 20)));
        $amount = (int) floor((float) ($locked->rub_amount ?? $locked->amount) * $percent / 100);
        if ($amount <= 0) {
            return;
        }

        // ref_balance = ref_balance + n in SQL: the bot's writes to other columns of that row are not touched.
        User::query()->whereKey($referrerId)->increment('ref_balance', $amount);
        if (! $buyer->ref_paid) {
            User::query()->whereKey($buyer->id)->update(['ref_paid' => true]);
        }
        $locked->update(['referrer_id' => $referrerId, 'ref_amount' => $amount]);
    }

    private function fulfil(Payment $payment, string $via): void
    {
        $user = User::query()->whereKey($payment->user_id)->lockForUpdate()->firstOrFail();

        if ($payment->purpose === 'topup') {
            $this->wallet->credit($user, $payment->tokens, 'topup', 'Пополнение', ['payment_id' => $payment->id], $via);

            return;
        }

        $active = $user->activePlan();
        if ((self::RANK[$payment->plan] ?? 0) >= (self::RANK[$active] ?? 0)) {
            // Same plan: the paid time is added on top. Upgrade: the new plan starts now.
            $base = ($active === $payment->plan && $user->plan_expires_at?->isFuture()) ? $user->plan_expires_at : now();
            // A shop row gives days (shop.amount), the config plans give months.
            $until = $payment->days ? $base->copy()->addDays($payment->days) : $base->copy()->addMonths($payment->period === 'year' ? 12 : 1);
            // The bot's columns: sub, start_sub_time, end_sub_time (only these are written).
            $user->setPlan($payment->plan, $until)->save();
        }
        // (A cheaper plan paid while a higher one is active keeps the higher plan; the tokens are still credited.)

        $name = config("neurogpt.plans.{$payment->plan}.name");
        // Subscription tokens go to the bot's sub_tokens: they can be spent while the subscription is active.
        $this->wallet->credit($user, $payment->tokens, 'subscription', "Подписка {$name}", ['payment_id' => $payment->id], $via, 'sub_tokens');
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
