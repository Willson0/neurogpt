<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\UserResource;
use App\Models\Payment;
use App\Services\Payments\PaymentService;
use App\Services\Payments\YooKassa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class PaymentController extends Controller
{
    public function topup(Request $request, PaymentService $payments)
    {
        $data = $request->validate([
            'tokens' => ['required', 'integer', Rule::in(array_column(config('neurogpt.packs'), 'tokens'))],
        ]);

        return $this->respond(fn () => $payments->createTopup($request->user(), $data['tokens']));
    }

    public function plan(Request $request, PaymentService $payments)
    {
        $data = $request->validate([
            'plan' => ['required', Rule::in(['plus', 'pro'])],
            'period' => ['required', Rule::in(['month', 'year'])],
        ]);

        return $this->respond(fn () => $payments->createPlan($request->user(), $data['plan'], $data['period']));
    }

    /** Polled by the app after the user comes back from the payment page. */
    public function show(Request $request, Payment $payment, PaymentService $payments)
    {
        abort_unless($payment->user_id === $request->user()->id, 404);

        try {
            $payment = $payments->sync($payment);
        } catch (Throwable $e) {
            report($e); // keep the last known status; the webhook may still arrive
        }

        return response()->json([
            'data' => new PaymentResource($payment),
            'user' => new UserResource($request->user()->refresh()),
        ]);
    }

    /**
     * YooKassa notification. The body only tells us which payment to look at;
     * the status is always re-read from the API before tokens are credited.
     */
    public function webhook(Request $request, PaymentService $payments, YooKassa $yookassa)
    {
        $remoteId = $request->input('object.id');
        if (! is_string($remoteId) || ! str_starts_with((string) $request->input('event'), 'payment.')) {
            return response()->json(['ok' => true]);
        }

        $payment = Payment::where('provider_payment_id', $remoteId)->first();
        if (! $payment) {
            return response()->json(['ok' => true]); // not ours
        }

        try {
            $payments->apply($payment, $yookassa->getPayment($remoteId));
        } catch (Throwable $e) {
            Log::error('YooKassa webhook failed', ['payment' => $remoteId, 'error' => $e->getMessage()]);

            return response()->json(['ok' => false], 500); // YooKassa will retry
        }

        return response()->json(['ok' => true]);
    }

    private function respond(callable $make)
    {
        try {
            return response()->json(['data' => new PaymentResource($make())], 201);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Payment could not be created', 'code' => 'payment_error'], 502);
        }
    }
}
