<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\UserResource;
use App\Models\Payment;
use App\Services\Payments\PaymentService;
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
    public function item(Request $request, PaymentService $payments, Shop $shop)
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer', 'min:1'],
            'method' => ['nullable', Rule::in(['card', 'stars'])],
        ]);
        if (! $shop->find((int) $data['item_id'])) {
            return response()->json(['message' => 'This item cannot be bought', 'code' => 'unknown_item'], 422);
        }
        if ($payments->isItemDowngrade($request->user(), (int) $data['item_id'])) {
            return response()->json(['message' => 'A higher plan is active', 'code' => 'plan_downgrade'], 422);
        }

        return $this->respond(fn () => $payments->createForItem($request->user(), (int) $data['item_id'], $data['method'] ?? 'card'));
    }

    public function topup(Request $request, PaymentService $payments)
    {
        $data = $request->validate([
            'tokens' => ['required', 'integer', Rule::in(array_column(app(Shop::class)->packs(), 'tokens'))],
            'method' => ['nullable', Rule::in(['card', 'stars'])],
        ]);

        return $this->respond(fn () => $payments->createTopup($request->user(), $data['tokens'], $data['method'] ?? 'card'));
    }

    public function plan(Request $request, PaymentService $payments)
    {
        $data = $request->validate([
            'plan' => ['required', Rule::in(['plus', 'pro'])],
            'period' => ['required', Rule::in(['month', 'year'])],
            'method' => ['nullable', Rule::in(['card', 'stars'])],
        ]);

        if ($payments->isDowngrade($request->user(), $data['plan'])) {
            return response()->json(['message' => 'A higher plan is active', 'code' => 'plan_downgrade'], 422);
        }

        return $this->respond(fn () => $payments->createPlan($request->user(), $data['plan'], $data['period'], $data['method'] ?? 'card'));
    }

    /** Polled by the app after the user comes back from the payment page. */
    public function show(Request $request, Payment $payment, PaymentService $payments)
    {
        abort_unless($payment->user_id === $request->user()->id, 404);

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

        $payment = Payment::where('provider', 'yookassa')->where('provider_payment_id', $remoteId)->first();
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
