<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AdminOnly;
use App\Models\Payment;
use App\Services\Admin\Settings;
use App\Services\Payments\PaymentService;
use App\Services\Payments\YooKassa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Throwable;

/** Payments with their details, and the YooKassa shop settings. */
class PaymentsController extends Controller
{
    public function index(Request $request)
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'succeeded', 'canceled', 'refunded'])],
            'provider' => ['nullable', Rule::in(['yookassa', 'stars'])],
            'purpose' => ['nullable', Rule::in(['topup', 'plan'])],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'user' => ['nullable', 'string', 'max:64'],
        ]);

        // Every row of the bot table `payments`: the app's own and the ones the bot made.
        $q = Payment::query();
        if (! empty($f['status'])) {
            $q->state($f['status']);
        }
        if (! empty($f['provider'])) {
            $q->provider($f['provider']);
        }
        if (! empty($f['purpose'])) {
            $cat = config('neurogpt.bot.shop.'.($f['purpose'] === 'plan' ? 'sub_category' : 'tokens_category'));
            $q->whereHas('item', fn ($i) => $i->where('category', $cat));
        }
        $tz = config('app.timezone');
        if (! empty($f['from'])) {
            $q->where('payments.created_at', '>=', Carbon::parse($f['from'])->setTimezone($tz));
        }
        if (! empty($f['to'])) {
            $q->where('payments.created_at', '<=', Carbon::parse($f['to'])->setTimezone($tz));
        }
        $term = trim((string) ($f['user'] ?? ''));
        if ($term !== '') {
            // a payment number, a YooKassa payment id, a promo code, or a person
            $q->where(fn (Builder $w) => $w
                ->where('payments.payment_id', $term)
                ->when(ctype_digit($term), fn ($x) => $x->orWhere('payments.id', $term))
                ->orWhereHas('details', fn ($d) => $d->where('promo_code', mb_strtoupper($term)))
                ->orWhereHas('user', fn (Builder $u) => UsersController::search($u, $term)));
        }

        $paid = (clone $q)->state('succeeded');
        $summary = [
            'total' => (clone $q)->count(),
            'succeeded' => (clone $paid)->count(),
            'pending' => (clone $q)->state('pending')->count(),
            'canceled' => (clone $q)->state('canceled')->count(),
            'by_currency' => (clone $paid)->selectRaw('total_valut as currency, sum(total) as total')->groupBy('total_valut')->pluck('total', 'currency')->map(fn ($v) => (float) $v),
            'rub' => (float) (clone $paid)->sum('rub_total'),
            'tokens' => (int) (clone $paid)->join('shop', 'shop.id', '=', 'payments.item_id')
                ->where('shop.category', config('neurogpt.bot.shop.tokens_category'))->sum('shop.amount'),
            'payers' => (clone $paid)->distinct()->count('payments.user_id'),
            'with_promo' => (clone $paid)->whereHas('details', fn ($d) => $d->whereNotNull('promo_id'))->count(),
        ];
        $page = $q->with(['user', 'details', 'item'])->latest('payments.id')->paginate(40);

        return response()->json([
            'data' => collect($page->items())->map(fn (Payment $p) => $this->payment($p)),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
            'summary' => $summary,
        ]);
    }

    /** Asks YooKassa for the real status of a pending payment and applies it (credits tokens if it was paid). */
    public function sync(Payment $payment, PaymentService $payments)
    {
        $payment->load('details');
        try {
            $payment = $payments->sync($payment);
        } catch (Throwable $e) {
            return response()->json(['message' => 'ЮKassa не ответила: проверьте настройки магазина'], 502);
        }

        return response()->json(['data' => $this->payment($payment->load(['user', 'details', 'item']))]);
    }

    // ---------- YooKassa settings ----------

    public function yookassa(Request $request)
    {
        return response()->json(['data' => $this->shop(), 'can_manage' => AdminOnly::isOwner($request->user())]);
    }

    /** Only a main admin changes payment credentials. An empty secret keeps the current one. */
    public function saveYookassa(Request $request)
    {
        abort_unless(AdminOnly::isOwner($request->user()), 403, 'Only the main admin can change payment settings');
        $data = $request->validate([
            'shop_id' => ['nullable', 'string', 'max:32', 'regex:/^\d+$/'],
            'secret_key' => ['nullable', 'string', 'min:16', 'max:255', 'regex:/^[\x21-\x7E]+$/'],
            'return_url' => ['nullable', 'string', 'max:255', 'regex:#^https://#'],
            'verify_ip' => ['required', 'boolean'],
            'receipt.enabled' => ['required', 'boolean'],
            'receipt.email' => ['nullable', 'email', 'max:120', Rule::requiredIf($request->boolean('receipt.enabled'))],
            'receipt.tax_system_code' => ['required', 'integer', 'between:1,6'],
            'receipt.vat_code' => ['required', 'integer', 'between:1,12'],
        ], [
            'receipt.email.required' => 'Для чеков нужен e-mail', 'receipt.email.email' => 'E-mail для чеков указан с ошибкой',
            'return_url.regex' => 'Адрес возврата должен начинаться с https://',
            'shop_id.regex' => 'shopId состоит только из цифр',
            'secret_key.min' => 'Секретный ключ слишком короткий', 'secret_key.regex' => 'В секретном ключе есть лишние символы или пробелы',
        ]);

        $stored = (array) Settings::stored('yookassa');
        $value = [
            'shop_id' => $data['shop_id'] ?? null,
            'verify_ip' => (bool) $data['verify_ip'],
            'receipt' => [
                'enabled' => (bool) $data['receipt']['enabled'], 'email' => $data['receipt']['email'] ?? null,
                'tax_system_code' => (int) $data['receipt']['tax_system_code'], 'vat_code' => (int) $data['receipt']['vat_code'],
            ],
        ];
        if (! empty($data['return_url'])) {
            $value['return_url'] = $data['return_url'];
        }
        if (! empty($data['secret_key'])) {
            $value['secret_key_enc'] = Crypt::encryptString($data['secret_key']);
        } elseif (! empty($stored['secret_key_enc'])) {
            $value['secret_key_enc'] = $stored['secret_key_enc'];
        }
        Settings::put('yookassa', $value);

        return response()->json(['data' => $this->shop(), 'can_manage' => true]);
    }

    /** A live request to YooKassa with the saved credentials. */
    public function checkYookassa(Request $request, YooKassa $yookassa)
    {
        abort_unless(AdminOnly::isOwner($request->user()), 403);
        try {
            $me = $yookassa->me();
        } catch (Throwable $e) {
            $status = method_exists($e, 'getCode') ? (int) $e->getCode() : 0;

            return response()->json(['ok' => false, 'message' => match (true) {
                $status === 401 => 'ЮKassa не приняла shopId или секретный ключ',
                str_contains($e->getMessage(), 'not configured') => 'Не заданы shopId и секретный ключ',
                default => 'ЮKassa не ответила, попробуйте позже',
            }]);
        }

        return response()->json(['ok' => true, 'account_id' => (string) ($me['account_id'] ?? ''), 'test' => (bool) ($me['test'] ?? false), 'status' => $me['status'] ?? null]);
    }

    /** What the panel may see. The secret key never leaves the server: only whether it is set and its last characters. */
    private function shop(): array
    {
        $cfg = config('neurogpt.yookassa');
        $secret = (string) ($cfg['secret_key'] ?? '');
        $stored = (array) Settings::stored('yookassa');

        return [
            'shop_id' => (string) ($cfg['shop_id'] ?? ''),
            'has_secret' => $secret !== '',
            'secret_hint' => $secret !== '' ? '••••'.substr($secret, -4) : null,
            'secret_source' => ! empty($stored['secret_key_enc']) && $secret !== '' ? 'admin' : ($secret !== '' ? 'env' : null),
            'test_mode' => str_starts_with($secret, 'test_'),
            'return_url' => (string) ($cfg['return_url'] ?? ''),
            'verify_ip' => (bool) $cfg['verify_ip'],
            'receipt' => [
                'enabled' => (bool) $cfg['receipt']['enabled'], 'email' => $cfg['receipt']['email'] ?? null,
                'tax_system_code' => (int) $cfg['receipt']['tax_system_code'], 'vat_code' => (int) $cfg['receipt']['vat_code'],
            ],
            'webhook_url' => rtrim((string) config('app.url'), '/').'/api/payments/yookassa/webhook',
            'methods' => PaymentService::methods(),
        ];
    }

    private function payment(Payment $p): array
    {
        $d = $p->details;
        $raw = (array) ($d?->raw ?? []);
        $method = $raw['payment_method'] ?? [];

        return [
            'id' => $p->id, 'status' => $p->status, 'provider' => $p->provider, 'purpose' => $p->purpose, 'tokens' => $p->tokens,
            'plan' => $p->plan, 'period' => $p->period, 'amount' => (float) $p->total, 'currency' => $p->currency,
            'rub_amount' => (float) $p->rub_total, 'price' => (float) ($d?->price ?? $p->rub_total),
            'promo' => $d?->promo_code, 'discount' => (int) ($d?->discount ?? 0),
            'provider_payment_id' => $p->payment_id,
            'cancel_reason' => $d?->cancel_reason,
            // made by the bot itself (no app details): the app only shows it
            'from_bot' => ! $d,
            'item' => $p->item ? ['id' => $p->item->id, 'name' => $p->item->text_name] : null,
            // how it was paid, as YooKassa reports it: "Bank card *4444", "SberPay", ...
            'method_title' => is_array($method) ? ($method['title'] ?? ($method['type'] ?? null)) : null,
            'income' => isset($raw['income_amount']['value']) ? (float) $raw['income_amount']['value'] : null,
            'test' => (bool) ($raw['test'] ?? false),
            'user' => $p->user ? ['id' => $p->user->id, 'telegram_id' => $p->user->telegram_id, 'name' => $p->user->displayName(), 'username' => $p->user->username] : null,
            'created_at' => $p->created_at?->toIso8601String(),
            'credited_at' => $d?->credited_at?->toIso8601String(),
            'shop_item_id' => $p->item_id,
            // 20 % to the inviter (users.ref_balance) and what refunds took back
            'referrer_id' => $d?->referrer_id, 'ref_amount' => (int) $d?->ref_amount, 'ref_reverted' => (int) $d?->ref_reverted,
            'refunded_amount' => (float) $d?->refunded_amount,
        ];
    }
}
