<?php

namespace App\Services\Payments;

use App\Models\Bot\Promo;
use App\Models\Bot\ShopItem;
use App\Models\Payment;
use App\Models\PaymentDetail;
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

    public function __construct(
        private readonly YooKassa $yookassa,
        private readonly Wallet $wallet,
        private readonly Bot $bot,
        private readonly Shop $shop,
        private readonly Promos $promos,
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
     * from that row here, the app only says which row (and maybe a promo code).
     */
    public function createForItem(User $user, int $itemId, string $method = 'card', ?Promo $promo = null): Payment
    {
        $item = $this->shop->find($itemId);
        if (! $item) {
            throw new InvalidArgumentException('Unknown shop item');
        }

        return $this->start($user, $method, $item, $this->itemAttrs($item), (string) $item->text_name, $promo);
    }

    /** What a shop row delivers, as wa_payment_details columns (price = rubles before a discount). */
    public function itemAttrs(ShopItem $item): array
    {
        if ($this->shop->isTokens($item)) {
            return ['purpose' => 'topup', 'tokens' => (int) $item->amount, 'price' => (int) $item->price];
        }

        $days = Shop::days($item);
        $perMonth = (int) config("neurogpt.plans.{$item->item}.tokens", 0);

        return [
            'purpose' => 'plan', 'plan' => (string) $item->item, 'days' => $days,
            'period' => $days >= 300 ? 'year' : 'month', 'price' => (int) $item->price,
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
    public function createTopup(User $user, int $tokens, string $method = 'card', ?Promo $promo = null): Payment
    {
        $items = $this->shop->tokenItems();
        if ($items->isNotEmpty()) {
            $item = $items->firstWhere('amount', $tokens) ?? throw new InvalidArgumentException('Unknown pack');

            return $this->createForItem($user, $item->id, $method, $promo);
        }

        $pack = collect(config('neurogpt.packs'))->firstWhere('tokens', $tokens);
        if (! $pack) {
            throw new InvalidArgumentException('Unknown pack');
        }
        // payments.item_id is required: a hidden shop row (active = 0) stands for a config pack
        $attrs = ['purpose' => 'topup', 'tokens' => $pack['tokens'], 'price' => $pack['price']];

        return $this->start($user, $method, $this->shop->rowFor($attrs), $attrs, "Пополнение: {$pack['tokens']} токенов", $promo);
    }

    /** Old endpoint (plan + period). When the shop sells subscriptions, the matching shop row is used. */
    public function createPlan(User $user, string $plan, string $period, string $method = 'card', ?Promo $promo = null): Payment
    {
        $cfg = config("neurogpt.plans.$plan");
        if (! $cfg || $plan === 'free' || ! in_array($period, ['month', 'year'], true)) {
            throw new InvalidArgumentException('Unknown plan');
        }

        if ($this->shop->subItems()->isNotEmpty()) {
            $item = $this->shop->planItem($plan, $period) ?? throw new InvalidArgumentException('Unknown plan');

            return $this->createForItem($user, $item->id, $method, $promo);
        }

        $attrs = [
            'purpose' => 'plan', 'plan' => $plan, 'period' => $period, 'days' => $period === 'year' ? 365 : 30,
            'price' => $period === 'year' ? $cfg['year_month'] * 12 : $cfg['month'],
            'tokens' => $cfg['tokens'] * ($period === 'year' ? 12 : 1),
        ];

        return $this->start($user, $method, $this->shop->rowFor($attrs), $attrs, "Подписка {$cfg['name']}, ".($period === 'year' ? 'год' : 'месяц'), $promo);
    }

    /** Buying a cheaper plan while a higher one is active would silently take the higher one away. */
    public function isDowngrade(User $user, string $plan): bool
    {
        return (self::RANK[$plan] ?? 0) < (self::RANK[$user->activePlan()] ?? 0);
    }

    /**
     * Writes the payment into the bot table `payments` right away (is_proceed = 0, was_bought = 0) and opens it at the
     * provider. The bot therefore sees every purchase of the app, also the unpaid and the canceled ones.
     */
    private function start(User $user, string $method, ShopItem $item, array $attrs, string $description, ?Promo $promo): Payment
    {
        if (! (self::methods()[$method] ?? false)) {
            throw new RuntimeException("Payment method $method is not configured");
        }

        $stars = $method === 'stars';
        $percent = $promo ? Promos::percent($promo) : 0;
        $rub = Promos::apply((float) $attrs['price'], $percent);
        $key = (string) Str::uuid();

        $payment = DB::transaction(function () use ($user, $item, $attrs, $stars, $rub, $key, $promo, $percent) {
            $payment = Payment::create([
                'payment_id' => $key, // the YooKassa id replaces it as soon as YooKassa answers
                'is_autopayment' => false,
                'was_bought' => false,
                'is_proceed' => false,
                'total' => $stars ? self::stars($rub) : $rub,
                'total_valut' => $stars ? 'XTR' : 'RUB',
                // The ruble price, also for Stars: payments.rub_total and users.paid_money count rubles.
                'rub_total' => $rub,
                'item_id' => $item->id,
                'user_id' => $user->id,
                'recipient_id' => $user->id,
            ]);
            $payment->setRelation('details', PaymentDetail::create($attrs + [
                'payment_id' => $payment->id,
                'provider' => $stars ? 'stars' : 'yookassa',
                'idempotence_key' => $key,
                'status' => 'pending',
                'promo_id' => $promo?->id,
                'promo_code' => $promo?->name,
                'discount' => $percent,
            ]));

            return $payment;
        });

        if ($percent) {
            $description .= " (промокод {$promo->name}, −{$percent}%)";
        }

        try {
            $stars ? $this->startStars($payment, $description) : $this->startYooKassa($payment, $user, $description);
        } catch (Throwable $e) {
            $this->markCanceled($payment, 'create_failed');
            throw $e;
        }

        return $payment;
    }

    /** is_proceed = 1, was_bought = 0: what the bot table calls a payment that ended without a purchase. */
    private function markCanceled(Payment $payment, ?string $reason, array $raw = []): void
    {
        Payment::whereKey($payment->id)->where('was_bought', false)->update(['is_proceed' => true, 'modified_at' => now()]);
        $payment->details->update(array_filter(['status' => 'canceled', 'cancel_reason' => $reason, 'raw' => $raw ?: null], fn ($v) => $v !== null));
        $payment->refresh();
    }

    private function startYooKassa(Payment $payment, User $user, string $description): void
    {
        $returnUrl = rtrim(config('neurogpt.yookassa.return_url'), '/');
        $remote = $this->yookassa->createPayment(
            $payment->idempotence_key,
            (float) $payment->total,
            $description,
            $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'payment='.$payment->id,
            array_filter(['payment_id' => (string) $payment->id, 'user_id' => (string) $user->id, 'item_id' => (string) $payment->item_id, 'promo' => $payment->details->promo_code]),
            $this->receipt($payment, $description),
        );

        $payment->update(['payment_id' => Str::limit((string) $remote['id'], 128, '')]);
        $payment->details->update(['confirmation_url' => $remote['confirmation']['confirmation_url'] ?? null, 'raw' => $remote]);
    }

    private function startStars(Payment $payment, string $description): void
    {
        $link = $this->bot->createStarsInvoice(Str::limit($description, 32, ''), $description.' в NeuroGPT', self::starsPayload($payment), (int) $payment->total);
        if (! $link) {
            throw new RuntimeException('Telegram did not create the Stars invoice');
        }

        $payment->details->update(['confirmation_url' => $link]);
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
        $payment = Payment::with('details')->whereHas('details', fn ($d) => $d->where('provider', 'stars'))->find((int) $m[1]);

        return $payment && hash_equals((string) $payment->details->idempotence_key, $m[2]) ? $payment : null;
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
        if ($currency !== 'XTR' || $amount !== (int) $payment->total) {
            return 'Сумма счёта изменилась. Создайте новый в приложении.';
        }

        return null;
    }

    /** successful_payment from Telegram: the Stars are already taken, so the purchase is delivered exactly once. */
    public function confirmStars(Payment $payment, string $currency, int $amount, string $chargeId, array $raw): Payment
    {
        return DB::transaction(function () use ($payment, $currency, $amount, $chargeId, $raw) {
            $locked = $this->lock($payment);
            if ($locked->details->credited_at) {
                return $locked;
            }
            if ($currency !== 'XTR' || $amount !== (int) $locked->total) {
                Log::critical('Stars payment amount mismatch', ['payment' => $locked->id, 'paid' => $amount, 'expected' => (int) $locked->total]);
                $locked->update(['payment_id' => Str::limit($chargeId, 128, '')]);
                $this->markCanceled($locked, 'amount_mismatch', $raw);

                return $locked;
            }

            // payments.payment_id becomes the Telegram charge id (needed for a Stars refund)
            $locked->update(['payment_id' => Str::limit($chargeId, 128, '')]);
            $this->markPaid($locked, 'Telegram Stars', $raw);

            return $locked->refresh();
        });
    }

    /** The payment with its details row locked: two webhooks / polls of one payment run one after the other. */
    private function lock(Payment $payment): Payment
    {
        $details = PaymentDetail::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
        $locked = Payment::query()->whereKey($payment->id)->firstOrFail();
        $locked->setRelation('details', $details);

        return $locked;
    }

    /**
     * Paid: was_bought = is_proceed = 1 in the bot table, the purchase is delivered, users.paid_money grows, the inviter
     * gets the commission and the promo code use is counted.
     *
     * The flags are claimed with "UPDATE ... WHERE was_bought = 0": if the bot has already marked this row as bought
     * (and delivered it itself), the app does not deliver it a second time.
     */
    private function markPaid(Payment $locked, string $via, array $raw): void
    {
        $claimed = Payment::whereKey($locked->id)->where('was_bought', false)
            ->update(['was_bought' => true, 'is_proceed' => true, 'modified_at' => now()]);
        if (! $claimed) {
            Log::warning('Payment was already marked as bought in the bot table, not delivered again', ['payment' => $locked->id]);
            $locked->details->update(['status' => 'succeeded', 'credited_at' => now(), 'cancel_reason' => 'delivered_by_bot', 'raw' => $raw]);

            return;
        }

        $this->fulfil($locked, $via);
        $locked->details->update(['status' => 'succeeded', 'credited_at' => now(), 'raw' => $raw]);
        User::whereKey($locked->user_id)->increment('paid_money', (float) $locked->rub_total);
        $this->creditReferralTokens($locked, $via);
        $this->creditReferrer($locked);
        $this->promos->consume($locked);
    }

    private function receipt(Payment $payment, string $description): ?array
    {
        $cfg = config('neurogpt.yookassa.receipt');
        if (! $cfg['enabled'] || ! $cfg['email']) {
            return null;
        }

        $value = number_format((float) $payment->total, 2, '.', '');

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
        if ($payment->provider !== 'yookassa' || $payment->status !== 'pending' || ! $payment->details?->confirmation_url) {
            return $payment;
        }

        $remote = $this->yookassa->getPayment($payment->payment_id);

        return $this->apply($payment, $remote);
    }

    /**
     * Applies a payment object read from the YooKassa API (never a webhook body). Safe to run any number of times:
     * the details row is locked, delivery happens once (credited_at + the was_bought claim), the referral commission
     * once (ref_amount), and a refund only takes back what has not been taken back yet (refunded_amount, ref_reverted).
     */
    public function apply(Payment $payment, array $remote): Payment
    {
        return DB::transaction(function () use ($payment, $remote) {
            $locked = $this->lock($payment);
            $status = $remote['status'] ?? 'pending';

            // The answer must describe this very payment.
            if (($remote['id'] ?? null) !== $locked->payment_id) {
                return $locked;
            }

            if ($status === 'succeeded' && ! $locked->details->credited_at) {
                // Paid in full, in rubles, exactly what we asked for.
                $paid = $remote['amount']['value'] ?? null;
                $currency = $remote['amount']['currency'] ?? null;
                if (($remote['paid'] ?? null) !== true || $currency !== 'RUB' || $paid === null || abs((float) $paid - (float) $locked->total) > 0.001) {
                    Log::critical('YooKassa payment mismatch', ['payment' => $locked->id, 'paid' => $paid, 'currency' => $currency]);
                    $this->markCanceled($locked, 'amount_mismatch', $remote);

                    return $locked;
                }
                $this->markPaid($locked, 'ЮKassa', $remote);
                $locked->refresh();
            } elseif ($status === 'canceled' && $locked->status === 'pending') {
                $this->markCanceled($locked, $remote['cancellation_details']['reason'] ?? 'canceled', $remote);
            }

            if ($locked->details->credited_at && isset($remote['refunded_amount']['value'])) {
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
        $d = $locked->details;
        $total = (float) $locked->total;
        $refunded = min($total, max(0.0, round($refundedTotal, 2)));
        $before = (float) $d->refunded_amount;
        if ($refunded <= $before + 0.001) {
            return; // nothing new: a repeated notification or a re-check
        }

        $full = $refunded >= $total - 0.001;
        $target = $full ? (int) $d->ref_amount : (int) floor($d->ref_amount * $refunded / max(0.01, $total));
        $take = $target - (int) $d->ref_reverted;
        if ($take > 0 && $d->referrer_id) {
            User::query()->whereKey($d->referrer_id)->decrement('ref_balance', $take);
        }

        // users.paid_money was raised by the ruble price when the payment was paid: lower it by the refund.
        if ($d->cancel_reason !== 'delivered_by_bot') {
            $rubShare = ($refunded - $before) * ((float) $locked->rub_total / max(0.01, $total));
            User::query()->whereKey($locked->user_id)->decrement('paid_money', round($rubShare, 2));
        }

        // The bot row stays was_bought = 1 (the purchase was delivered); "refunded" is kept in the details.
        $d->update([
            'refunded_amount' => $refunded,
            'refunded_at' => now(),
            'ref_reverted' => max((int) $d->ref_reverted, $target),
            'status' => $full ? 'refunded' : $d->status,
        ]);
        Log::info('YooKassa refund applied', ['payment' => $locked->id, 'refunded' => $refunded, 'ref_taken_back' => max(0, $take)]);
    }

    /**
     * The inviter (users.referal_user_from of the buyer) gets referral_percent (20 %) of the ruble price into
     * users.ref_balance, once per payment. Called inside the payment's locked transaction right after delivery.
     * (users.ref_paid of the buyer is set by creditReferralTokens at the first purchase.)
     */
    private function creditReferrer(Payment $locked): void
    {
        $d = $locked->details;
        if ($d->referrer_id || $d->ref_amount) {
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
        // from the rubles actually paid (after a promo discount)
        $amount = (int) floor((float) $locked->rub_total * $percent / 100);
        if ($amount <= 0) {
            return;
        }

        // ref_balance = ref_balance + n in SQL: the bot's writes to other columns of that row are not touched.
        User::query()->whereKey($referrerId)->increment('ref_balance', $amount);
        $d->update(['referrer_id' => $referrerId, 'ref_amount' => $amount]);
    }

    /**
     * The referral tokens (bonus.referral_tokens, 50) are given at the FIRST paid purchase of a person who came by an
     * invite link, not when they start the bot or open the app. bonus.referral_tokens_to says who gets them: "friend"
     * (the buyer) or "inviter". The same path runs for the YooKassa webhook, the Stars webhook and a manual re-check.
     *
     * The buyer's users.ref_paid ("the referral reward is paid") is the once-only flag, claimed with
     * "UPDATE ... WHERE ref_paid = 0": a second purchase, a repeated notification or a reward the bot already paid
     * (it set ref_paid itself) give nothing.
     */
    private function creditReferralTokens(Payment $locked, string $via): void
    {
        $buyer = User::query()->whereKey($locked->user_id)->first(['id', 'referal_user_from', 'ref_paid']);
        $referrerId = (int) ($buyer?->referal_user_from ?? 0);
        if (! $buyer || $buyer->ref_paid || ! $referrerId || $referrerId === (int) $buyer->id) {
            return;
        }
        if (! User::query()->whereKey($buyer->id)->where('ref_paid', false)->update(['ref_paid' => true])) {
            return; // claimed by a parallel payment or by the bot
        }

        $amount = (int) config('neurogpt.bonus.referral_tokens', 50);
        $toInviter = config('neurogpt.bonus.referral_tokens_to') === 'inviter';
        $receiver = User::query()->whereKey($toInviter ? $referrerId : $buyer->id)->lockForUpdate()->first();
        if ($amount <= 0 || ! $receiver || ($toInviter && $receiver->is_blocked)) {
            return;
        }
        $this->wallet->credit($receiver, $amount, 'referral',
            $toInviter ? 'Бонус за приглашённого друга' : 'Бонус за первую покупку по приглашению',
            ['payment_id' => $locked->id], $via);
    }

    private function fulfil(Payment $payment, string $via): void
    {
        $user = User::query()->whereKey($payment->user_id)->lockForUpdate()->firstOrFail();
        $payment = $payment->details; // purpose, tokens, plan, days, period
        $paymentId = $payment->payment_id;

        if ($payment->purpose === 'topup') {
            $this->wallet->credit($user, $payment->tokens, 'topup', 'Пополнение', ['payment_id' => $paymentId], $via);

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
        $this->wallet->credit($user, $payment->tokens, 'subscription', "Подписка {$name}", ['payment_id' => $paymentId], $via, 'sub_tokens');
    }

    /** Pending payments of the app nobody finished: YooKassa ones are re-checked first, then everything old is closed. */
    public function expireStale(int $hours = 24): int
    {
        $closed = 0;
        Payment::own()->with('details')->state('pending')->where('created_at', '<', now()->subHours($hours))->orderBy('id')
            ->chunkById(100, function ($payments) use (&$closed) {
                foreach ($payments as $payment) {
                    try {
                        $payment = $this->sync($payment);
                    } catch (Throwable $e) {
                        continue; // YooKassa is unreachable: try again on the next run
                    }
                    if ($payment->status === 'pending') {
                        $this->markCanceled($payment, 'expired');
                        $closed++;
                    }
                }
            });

        return $closed;
    }
}
