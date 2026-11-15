<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\UserResource;
use App\Models\Payment;
use App\Services\Payments\PaymentService;
use App\Services\Payments\Promos;
use App\Services\Payments\Shop;
use App\Services\Payments\YooKassa;
use App\Services\Telegram\Bot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

class PaymentController extends Controller
{
    /** A purchase by the id of a bot `shop` row: price and contents are taken from that row on the server. */
    public function item(Request $request, PaymentService $payments, Shop $shop, Promos $promos)
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer', 'min:1'],
            'method' => ['nullable', Rule::in(['card', 'stars'])],
            'promo' => ['nullable', 'string', 'max:255'],
        ]);
        $item = $shop->find((int) $data['item_id']);
        if (! $item) {
            return response()->json(['message' => 'This item cannot be bought', 'code' => 'unknown_item'], 422);
        }
        if ($payments->isItemDowngrade($request->user(), (int) $data['item_id'])) {
            return response()->json(['message' => 'A higher plan is active', 'code' => 'plan_downgrade'], 422);
        }
        [$promo, $refused] = $this->promo($promos, $request, $data['promo'] ?? null, $item);
        if ($refused) {
            return $refused;
        }

        return $this->respond(fn () => $payments->createForItem($request->user(), (int) $data['item_id'], $data['method'] ?? 'card', $promo));
    }

    public function topup(Request $request, PaymentService $payments, Shop $shop, Promos $promos)
    {
        $data = $request->validate([
            'tokens' => ['required', 'integer', Rule::in(array_column($shop->packs(), 'tokens'))],
            'method' => ['nullable', Rule::in(['card', 'stars'])],
            'promo' => ['nullable', 'string', 'max:255'],
        ]);
        [$promo, $refused] = $this->promo($promos, $request, $data['promo'] ?? null, $shop->tokenItems()->firstWhere('amount', $data['tokens']));
        if ($refused) {
            return $refused;
        }

        return $this->respond(fn () => $payments->createTopup($request->user(), $data['tokens'], $data['method'] ?? 'card', $promo));
    }

    public function plan(Request $request, PaymentService $payments, Shop $shop, Promos $promos)
    {
        $data = $request->validate([
            'plan' => ['required', Rule::in(['plus', 'pro'])],
            'period' => ['required', Rule::in(['month', 'year'])],
            'method' => ['nullable', Rule::in(['card', 'stars'])],
            'promo' => ['nullable', 'string', 'max:255'],
        ]);

        if ($payments->isDowngrade($request->user(), $data['plan'])) {
            return response()->json(['message' => 'A higher plan is active', 'code' => 'plan_downgrade'], 422);
        }
        [$promo, $refused] = $this->promo($promos, $request, $data['promo'] ?? null, $shop->planItem($data['plan'], $data['period']));
        if ($refused) {
            return $refused;
        }

        return $this->respond(fn () => $payments->createPlan($request->user(), $data['plan'], $data['period'], $data['method'] ?? 'card', $promo));
    }

    /**
     * "Применить" next to the promo field: is the code valid for this person (and this item), and what does it give.
     * The same check runs again when the payment is created, so a code cannot be used past its limits.
     */
    public function checkPromo(Request $request, Promos $promos, Shop $shop)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:255'], 'item_id' => ['nullable', 'integer', 'min:1']]);
        $item = isset($data['item_id']) ? $shop->find((int) $data['item_id']) : null;
        $promo = $promos->find($data['code']);
        if ($code = $promos->refusal($promo, $request->user(), $item)) {
            return response()->json(['message' => Promos::ERRORS[$code], 'code' => $code], 422);
        }

        return response()->json(['data' => $promos->describe($promo, $item)]);
    }

    /**
     * The payment link: GET /api/pay/{item} (the page /pay/{item} of the app calls it). Opens without sign-in, so a link
     * from anywhere shows what is being sold; paying itself happens inside Telegram (link) with the user signed in.
     */
    public function link(int $item, Shop $shop)
    {
        $row = $shop->any($item);
        abort_unless($row, 404);

        return response()->json(['data' => $shop->card($row) + [
            'telegram_link' => Bot::directLink('pay-'.$row->id),
            'pay_link' => Bot::payLink($row->id),
            'methods' => PaymentService::methods(),
        ]]);
    }

    /** [promo or null, error response or null] */
    private function promo(Promos $promos, Request $request, ?string $code, $item): array
    {
        if (Promos::normalize($code) === '') {
            return [null, null];
        }
        $promo = $promos->find($code);
        if ($error = $promos->refusal($promo, $request->user(), $item)) {
            return [null, response()->json(['message' => Promos::ERRORS[$error], 'code' => $error], 422)];
        }

        return [$promo, null];
    }

    /** Polled by the app after the user comes back from the payment page. */
    public function show(Request $request, Payment $payment, PaymentService $payments)
    {
        abort_unless($payment->user_id === $request->user()->id, 404);
        $payment->load('details');

        try {
            $payment = $payments->sync($payment);
        } catch (Throwable $e) {
            Log::warning('Payment sync failed', ['payment' => $payment->id, 'error' => Bot::scrub($e->getMessage())]);
        }

        return response()->json([
            'data' => new PaymentResource($payment),
            'user' => new UserResource($request->user()->refresh()),
        ]);
    }

    /**
     * YooKassa notification (payment.succeeded, payment.canceled, payment.waiting_for_capture, refund.succeeded).
     * The sender is checked by IP (YooKassaIp). The body only tells us which payment to look at: its status and
     * refunded amount are always re-read from the API, so a forged or replayed notification changes nothing.
     * 200 = handled or not ours, 500 = the API could not be reached and YooKassa should retry.
     */
    public function webhook(Request $request, PaymentService $payments, YooKassa $yookassa)
    {
        $event = (string) $request->input('event');
        $remoteId = match (true) {
            str_starts_with($event, 'payment.') => $request->input('object.id'),
            str_starts_with($event, 'refund.') => $request->input('object.payment_id'),
            default => null,
        };
        if (! is_string($remoteId) || $remoteId === '' || strlen($remoteId) > 64) {
            return response()->json(['ok' => true]);
        }

        $payment = Payment::with('details')->where('payment_id', $remoteId)
            ->whereHas('details', fn ($d) => $d->where('provider', 'yookassa'))->first();
        if (! $payment) {
            return response()->json(['ok' => true]); // not ours
        }

        try {
            $payments->apply($payment, $yookassa->getPayment($remoteId));
        } catch (Throwable $e) {
            Log::error('YooKassa webhook failed', ['event' => $event, 'payment' => $remoteId, 'error' => Bot::scrub($e->getMessage())]);

            return response()->json(['ok' => false], 500); // YooKassa will retry
        }

        return response()->json(['ok' => true]);
    }

    private function respond(callable $make)
    {
        try {
            return response()->json(['data' => new PaymentResource($make())], 201);
        } catch (InvalidArgumentException) {
            return response()->json(['message' => 'This item cannot be bought', 'code' => 'unknown_item'], 422);
        } catch (Throwable $e) {
            Log::error('Payment was not created', ['error' => Bot::scrub($e->getMessage())]);

            return response()->json(['message' => 'Payment could not be created', 'code' => 'payment_error'], 502);
        }
    }
}
