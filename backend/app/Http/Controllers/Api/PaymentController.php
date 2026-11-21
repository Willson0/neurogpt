<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\UserResource;
use App\Models\Bot\ShopItem;
use App\Models\Payment;
use App\Services\Payments\PaymentService;
use App\Services\Payments\Promos;
use App\Services\Payments\SubscriptionCatalog;
use App\Services\Payments\YooKassa;
use App\Services\Telegram\Bot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Throwable;

class PaymentController extends Controller
{
    public function topup(Request $request, PaymentService $payments)
    {
        $data = $request->validate([
            'offer_id' => ['required_without:tokens', 'integer', 'min:1'],
            'tokens' => ['required_without:offer_id', 'integer', 'min:1'],
            'expected_price' => ['sometimes', 'integer', 'min:1'],
            'method' => ['nullable', Rule::in(['card', 'stars'])],
            'promo' => ['nullable', 'string', 'max:64'],
            'expected_payable' => ['nullable', 'integer', 'min:1'],
        ]);

        $offers = ShopItem::tokenOffers();
        $offer = isset($data['offer_id']) ? $offers->find($data['offer_id'])
            : $offers->where('amount', $data['tokens'])->orderBy('price')->orderBy('id')->first();
        if (! $offer || (isset($data['expected_price']) && $offer->price !== (int) $data['expected_price'])
            || (isset($data['tokens']) && $offer->amount !== (int) $data['tokens'])) {
            return response()->json(['message' => 'Token offer changed', 'code' => 'token_offer_changed'], 422);
        }

        return $this->respond(fn () => $payments->createTopup($request->user(), $offer, $data['method'] ?? 'card',
            $data['promo'] ?? null, $data['expected_payable'] ?? null));
    }

    public function plan(Request $request, PaymentService $payments)
    {
        $data = $request->validate([
            'offer_id' => ['required_without:plan', 'integer', 'min:1'],
            'expected_price' => ['sometimes', 'integer', 'min:1'],
            'expected_days' => ['sometimes', 'integer', 'min:1'],
            'plan' => ['required_without:offer_id', 'string', 'max:12'],
            'period' => ['required_without:offer_id', Rule::in(['month', 'year'])],
            'method' => ['nullable', Rule::in(['card', 'stars'])],
            'promo' => ['nullable', 'string', 'max:64'],
            'expected_payable' => ['nullable', 'integer', 'min:1'],
            'auto_renew' => ['sometimes', 'boolean'],
        ]);

        $offers = SubscriptionCatalog::purchasable();
        $offer = isset($data['offer_id'])
            ? $offers->find($data['offer_id'])
            : $offers->where('item', $data['plan'])->where('amount', $data['period'] === 'year' ? 365 : 30)->orderBy('id')->first();
        if (! $offer || (isset($data['expected_price']) && (int) $data['expected_price'] !== $offer->price)
            || (isset($data['expected_days']) && (int) $data['expected_days'] !== $offer->amount)) {
            return response()->json(['message' => 'Subscription offer changed', 'code' => 'subscription_offer_changed'], 422);
        }

        return $this->respond(fn () => $payments->createPlan($request->user(), $offer, $data['method'] ?? 'card',
            $data['promo'] ?? null, $data['expected_payable'] ?? null, $request->boolean('auto_renew')));
    }

    public function quote(Request $request, Promos $promos)
    {
        $data = $request->validate([
            'kind' => ['required', 'in:plan,topup'],
            'offer_id' => ['required', 'integer', 'min:1'],
            'promo' => ['required', 'string', 'max:64'],
        ]);
        $offers = $data['kind'] === 'plan' ? SubscriptionCatalog::purchasable() : ShopItem::tokenOffers();
        $offer = $offers->find($data['offer_id']);
        abort_unless($offer, 404);
        $quote = $promos->quote($data['promo'], $offer->price);

        return response()->json(['price' => $quote['amount'], 'stars' => PaymentService::stars($quote['amount'])]);
    }

    public function stopRenewal(Request $request)
    {
        $user = $request->user();
        $user->forceFill(['auto_continue' => false])->save();

        return response()->json(['user' => new UserResource($user)]);
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
     * YooKassa notification. The body only tells us which payment to look at;
     * the status is always re-read from the API before tokens are credited.
     */
    public function webhook(Request $request, PaymentService $payments, YooKassa $yookassa)
    {
        $remoteId = $request->input('object.id');
        if (! is_string($remoteId) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $remoteId)
            || ! in_array($request->input('event'), ['payment.succeeded', 'payment.canceled', 'payment.waiting_for_capture'], true)) {
            return response()->json(['ok' => true]);
        }

        $payment = Payment::where('provider', 'yookassa')->where('provider_payment_id', $remoteId)->first();
        if (! $payment) {
            $object = $request->input('object');
            $localId = $request->input('object.metadata.webapp_payment');
            if (is_array($object) && is_scalar($localId) && ctype_digit((string) $localId)) {
                $candidate = Payment::where('provider', 'yookassa')->whereNull('provider_payment_id')->find($localId);
                if ($candidate && $payments->matchesMetadata($candidate, $object)) {
                    $payment = $candidate;
                }
            }
        }

        if (! $payment || $payment->credited_at) {
            return response()->json(['ok' => true]);
        }

        try {
            $remote = $yookassa->getPayment($remoteId);
            if (($remote['id'] ?? null) !== $remoteId) {
                return response()->json(['ok' => false], 502);
            }
            $payments->apply($payment, $remote);
        } catch (Throwable $e) {
            Log::error('YooKassa webhook failed', ['payment' => $remoteId, 'exception' => $e::class]);

            return response()->json(['ok' => false], 500); // YooKassa will retry
        }

        return response()->json(['ok' => true]);
    }

    private function respond(callable $make)
    {
        try {
            return response()->json(['data' => new PaymentResource($make())], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('Payment was not created', ['error' => Bot::scrub($e->getMessage())]);

            return response()->json(['message' => 'Payment could not be created', 'code' => 'payment_error'], 502);
        }
    }
}
