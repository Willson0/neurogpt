<?php

namespace App\Services\Payments;

use App\Models\Bot\ShopItem;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\Wallet;
use App\Services\Bot\BotSync;
use App\Services\Telegram\Bot;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

class PaymentService
{
    public function __construct(
        private readonly YooKassa $yookassa,
        private readonly Wallet $wallet,
        private readonly Bot $bot,
        private readonly BotSync $botSync,
        private readonly Promos $promos,
    ) {}

    /** Which ways to pay are configured right now. */
    public static function methods(): array
    {
        return [
            'card' => (bool) (config('neurogpt.yookassa.shop_id') && config('neurogpt.yookassa.secret_key')),
            'auto_renewal' => RenewSubscriptions::enabled(),
            'auto_renewal_days' => RenewSubscriptions::primaryDays(),
            'stars' => (bool) (config('neurogpt.stars.enabled') && config('neurogpt.telegram.bot_token')),
        ];
    }

    public static function stars(float|int $rub): int
    {
        return max(1, (int) ceil($rub / (float) config('neurogpt.stars.rub_per_star')));
    }

    public static function tokenOffers(): Collection
    {
        return ShopItem::tokenOffers()->orderBy('amount')->orderBy('price')->orderBy('id')->get()->map(function (ShopItem $offer) {
            $description = json_decode($offer->description ?? '', true);

            return [
                'id' => $offer->id, 'name' => $offer->text_name, 'tokens' => $offer->amount, 'price' => $offer->price,
                'stars' => self::stars($offer->price), 'badge' => $offer->tag,
                'photo' => $description['photo'] ?? null, 'video' => $description['video'] ?? null,
            ];
        });
    }

    public function createTopup(User $user, ShopItem $offer, string $method = 'card', ?string $promo = null, ?int $expected = null): Payment
    {
        return $this->start($user, $method, [
            'purpose' => 'topup', 'tokens' => $offer->amount, 'amount' => $offer->price, 'shop_item_id' => $offer->id,
        ], "Пополнение: {$offer->amount} токенов", $promo, $expected);
    }

    public function createPlan(User $user, ShopItem $offer, string $method = 'card', ?string $promo = null, ?int $expected = null, bool $autoRenew = false): Payment
    {
        if ($autoRenew && ($method !== 'card' || $offer->amount !== RenewSubscriptions::primaryDays() || ! RenewSubscriptions::enabled())) {
            throw ValidationException::withMessages(['auto_renew' => 'Настройки автопродления изменились. Обновите список подписок']);
        }

        return $this->start($user, $method, [
            'renew_opt_in' => $autoRenew, 'renew_base_days' => $autoRenew ? $offer->amount : null, 'renew_max_rub' => $autoRenew ? $offer->price : null,
            'purpose' => 'plan', 'plan' => $offer->item, 'amount' => $offer->price, 'tokens' => 0,
            'shop_item_id' => $offer->id, 'plan_days' => $offer->amount, 'plan_name' => $offer->text_name,
        ], "Подписка {$offer->text_name} на {$offer->amount} дн.", $promo, $expected);
    }

    private function start(User $user, string $method, array $attrs, string $description, ?string $promo, ?int $expected): Payment
    {
        if (! (self::methods()[$method] ?? false)) {
            throw new RuntimeException("Payment method $method is not configured");
        }

        $stars = $method === 'stars';
        $payment = DB::transaction(function () use ($user, $stars, $attrs, $promo, $expected) {
            $quote = $promo ? $this->promos->quote($promo, $attrs['amount'], reserve: true) : [];
            $price = $quote['amount'] ?? $attrs['amount'];
            $payable = $stars ? self::stars($price) : $price;
            if ($expected !== null && $payable !== $expected) {
                throw ValidationException::withMessages(['promo' => 'Цена после скидки изменилась. Примените промокод ещё раз.']);
            }

            return Payment::create(array_merge($attrs, $quote, [
                'user_id' => $user->id,
                'provider' => $stars ? 'stars' : 'yookassa',
                'currency' => $stars ? 'XTR' : 'RUB',
                'amount' => $payable,
                'rub_amount' => $price,
                'idempotence_key' => (string) Str::uuid(),
                'status' => 'pending',
            ]));
        }, 3);

        try {
            if (! $stars) {
                return $this->startYooKassa($payment, $description);
            }
            $this->startStars($payment, $description);
        } catch (Throwable $e) {
            if ($stars || $this->definitiveCreateFailure($e)) {
                $this->cancelPending($payment, 'create_failed');
            }
            throw $e;
        }

        return $payment;
    }

    public function startYooKassa(Payment $payment, string $description): Payment
    {
        $returnUrl = rtrim(config('neurogpt.yookassa.return_url') ?? config('app.url'), '/');
        $receipt = $this->receipt($payment, $description);
        if ($payment->renew_for_expiry) {
            // YooKassa compares the retry body, so a receipt settings edit must not change an in-flight request.
            if (array_key_exists('receipt', $payment->raw ?? [])) {
                $receipt = $payment->raw['receipt'];
            } else {
                $payment->update(['raw' => ['submission_started' => true, 'receipt' => $receipt]]);
            }
        }
        $remote = $this->yookassa->createPayment(
            $payment->idempotence_key,
            (float) $payment->amount,
            $description,
            $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'payment='.$payment->id,
            ['webapp_payment' => (string) $payment->id, 'webapp_user' => (string) $payment->user_id, 'webapp_order' => $payment->idempotence_key],
            $receipt,
            (bool) $payment->renew_opt_in,
            $payment->renew_method,
        );

        if (! is_string($remote['id'] ?? null) || $remote['id'] === '') {
            throw new RuntimeException('YooKassa returned no payment identifier');
        }

        return $this->apply($payment, $remote, fromCreate: true);
    }

    public function definitiveCreateFailure(Throwable $e): bool
    {
        return $e instanceof RequestException && in_array($e->response->status(), [400, 401, 403, 404, 422], true);
    }

    public function cancelPending(Payment $payment, string $reason): void
    {
        DB::transaction(function () use ($payment, $reason) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'pending') {
                $locked->update(['status' => 'canceled', 'cancel_reason' => $reason]);
                $this->promos->release($locked);
            }
        }, 3);
    }

    private function startStars(Payment $payment, string $description): void
    {
        $title = $payment->purpose === 'topup' ? "{$payment->tokens} токенов" : 'Подписка '.$payment->subscriptionName();
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
            if ($locked->promo_released) {
                return $locked;
            }
            if ($currency !== 'XTR' || $amount !== (int) $locked->amount) {
                Log::critical('Stars payment amount mismatch', ['payment' => $locked->id, 'paid' => $amount, 'expected' => (int) $locked->amount]);
                $locked->update(['status' => 'canceled', 'cancel_reason' => 'amount_mismatch', 'provider_payment_id' => $chargeId, 'raw' => $raw]);
                $this->promos->release($locked);

                return $locked;
            }

            $this->fulfil($locked, 'Telegram Stars');
            $locked->update(['status' => 'succeeded', 'credited_at' => now(), 'provider_payment_id' => $chargeId, 'raw' => $raw]);
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

    public function apply(Payment $payment, array $remote, bool $fromCreate = false): Payment
    {
        return DB::transaction(function () use ($payment, $remote, $fromCreate) {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $status = $remote['status'] ?? 'pending';

            $id = $remote['id'] ?? null;
            if ($locked->provider !== 'yookassa' || ! is_string($id) || $id === '' || $locked->credited_at) {
                return $locked;
            }
            if ($locked->provider_payment_id === null && ($fromCreate || $this->matchesMetadata($locked, $remote))) {
                $locked->provider_payment_id = $id;
                $locked->save();
            }
            if ($fromCreate && empty($locked->confirmation_url) && isset($remote['confirmation']['confirmation_url'])) {
                $locked->update(['confirmation_url' => $remote['confirmation']['confirmation_url']]);
            }
            if ($id !== $locked->provider_payment_id) {
                return $locked;
            }

            if ($status === 'succeeded' && ! $locked->promo_released) {
                // Paid in full, in rubles, exactly what we asked for.
                $paid = $remote['amount']['value'] ?? null;
                $currency = $remote['amount']['currency'] ?? null;
                if (($remote['paid'] ?? false) !== true || $currency !== 'RUB' || ! is_string($paid)
                    || ! preg_match('/^\d{1,10}\.\d{2}$/D', $paid)
                    || (int) str_replace('.', '', $paid) !== (int) str_replace('.', '', $locked->amount)) {
                    Log::critical('YooKassa payment mismatch', ['payment' => $locked->id, 'paid' => $paid, 'currency' => $currency]);
                    $locked->update(['status' => 'canceled', 'cancel_reason' => 'amount_mismatch', 'raw' => $remote]);
                    $this->promos->release($locked);

                    return $locked;
                }
                $this->fulfil($locked, 'ЮKassa', $remote['payment_method'] ?? []);
                $locked->update(['status' => 'succeeded', 'credited_at' => now(), 'raw' => $remote]);
                $this->botSync->mirrorPayment($locked);
            } elseif ($status === 'canceled' && $locked->status === 'pending') {
                $locked->update([
                    'status' => 'canceled',
                    'cancel_reason' => $remote['cancellation_details']['reason'] ?? null,
                    'raw' => $remote,
                ]);
                $this->promos->release($locked);
            } elseif ($locked->status === 'pending') {
                $locked->update(['raw' => $remote]);
            }

            return $locked;
        }, 3);
    }

    public function matchesMetadata(Payment $payment, array $object): bool
    {
        $metadata = $object['metadata'] ?? [];
        $key = $metadata['webapp_order'] ?? null;

        return is_string($key) && hash_equals($payment->idempotence_key, $key)
            && in_array($metadata['webapp_payment'] ?? null, [$payment->id, (string) $payment->id], true)
            && in_array($metadata['webapp_user'] ?? null, [$payment->user_id, (string) $payment->user_id], true);
    }

    private function fulfil(Payment $payment, string $via, array $method = []): void
    {
        $user = User::query()->whereKey($payment->user_id)->lockForUpdate()->firstOrFail();

        if ($payment->purpose === 'topup') {
            $this->wallet->credit($user, $payment->tokens, 'topup', 'Пополнение', ['payment_id' => $payment->id], $via);

            return;
        }

        // Preserve paid time, including when the user chooses another offer.
        $base = $user->hasActivePlan() ? $user->plan_expires_at->copy() : now();
        $until = $payment->plan_days ? $base->addDays($payment->plan_days) : $base->addMonths($payment->period === 'year' ? 12 : 1);
        $user->setPlan($payment->plan, $until)->forceFill(['was_subed' => true]);
        if ($payment->renew_opt_in && ! $payment->renew_for_expiry) {
            $this->saveRenewalMethod($user, $payment, $method);
        }
        $user->save();
        if ($payment->renew_opt_in) {
            $payment->update(['renew_due_at' => $user->end_sub_time]);
        }

        $name = $payment->subscriptionName();
        // Older invoices may include subscription tokens.
        $this->wallet->credit($user, $payment->tokens, 'subscription', "Подписка {$name}", ['payment_id' => $payment->id], $via, 'sub_tokens');
    }

    private function saveRenewalMethod(User $user, Payment $payment, array $method): void
    {
        $card = $method['card'] ?? [];
        $id = $method['id'] ?? null;
        if (($method['saved'] ?? false) !== true || ($method['type'] ?? '') !== 'bank_card'
            || ! is_string($id) || $id === '' || strlen($id) > 256) {
            $payment->update(['renew_opt_in' => false]);

            return;
        }
        $methods = DB::table('payment_methods');
        $local = $methods->where('payment_method_id', $id)->value('id') ?? DB::table('payment_methods')->insertGetId([
            'payment_method_id' => $id, 'created_at' => now(), 'first6' => substr($card['first6'] ?? '', 0, 7),
            'last4' => substr($card['last4'] ?? '', 0, 5), 'card_type' => substr($card['card_type'] ?? '', 0, 24),
        ]);
        $user->forceFill(['last_payment_method_id' => $local, 'auto_continue' => true, 'autocont_sub' => $payment->plan]);
        $payment->update(['renew_method' => $id]);
    }

    /** Reconcile open provider invoices and expire unused Telegram invoices. */
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
                    if ($payment->status === 'pending' && $payment->provider === 'stars') {
                        $this->cancelPending($payment, 'expired');
                        $closed++;
                    }
                }
            });

        return $closed;
    }
}
