<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bot\Promo;
use App\Models\Bot\ShopItem;
use App\Models\PaymentDetail;
use App\Models\PromoRule;
use App\Models\PromoUse;
use App\Services\Payments\PaymentService;
use App\Services\Payments\Promos;
use App\Services\Payments\Shop;
use App\Services\Telegram\Bot;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Promo codes in the admin panel. The code, the discount and the uses left are the bot table `promos`
 * (the bot sees the same codes); dates, per-person limit, items, minimum price and on/off are wa_promo_rules.
 * Also the bot's shop with a payment link for every row.
 */
class PromosController extends Controller
{
    public function shop(Shop $shop)
    {
        $rows = ShopItem::orderBy('category')->orderBy('price')->orderBy('id')->get();

        return response()->json([
            'data' => $rows->map(fn (ShopItem $i) => [
                'id' => $i->id, 'name' => $i->text_name, 'category' => $i->category, 'item' => $i->item, 'amount' => (int) $i->amount,
                'price' => (int) $i->price, 'stars' => PaymentService::stars($i->price), 'active' => (bool) $i->active,
                'can_buy' => (bool) $shop->find($i->id), 'pay_link' => Bot::payLink($i->id), 'telegram_link' => Bot::directLink('pay-'.$i->id),
            ]),
            'rub_per_star' => (float) config('neurogpt.stars.rub_per_star'),
        ]);
    }

    public function index()
    {
        $promos = Promo::with('rules')->orderByDesc('id')->get();
        $uses = PromoUse::selectRaw('promo_id, count(*) as n, sum(discount_rub) as off')->groupBy('promo_id')->get()->keyBy('promo_id');
        $revenue = PaymentDetail::query()->join('payments', 'payments.id', '=', 'wa_payment_details.payment_id')
            ->where('payments.was_bought', true)->whereNotNull('promo_id')
            ->selectRaw('promo_id, sum(payments.rub_total) as rub')->groupBy('promo_id')->pluck('rub', 'promo_id');

        return response()->json(['data' => $promos->map(fn (Promo $p) => $this->row($p, $uses->get($p->id), (float) ($revenue[$p->id] ?? 0)))]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $promo = DB::transaction(function () use ($data) {
            $promo = Promo::create(['name' => $data['code'], 'discount' => $data['discount'], 'uses' => $data['uses'] ?? -1]);
            PromoRule::create(['promo_id' => $promo->id] + $this->rules($data));

            return $promo;
        });

        return response()->json(['data' => $this->row($promo->load('rules'))], 201);
    }

    public function update(Request $request, Promo $promo)
    {
        $data = $this->validated($request, $promo);
        DB::transaction(function () use ($promo, $data) {
            $promo->update(['name' => $data['code'], 'discount' => $data['discount'], 'uses' => $data['uses'] ?? -1]);
            PromoRule::updateOrCreate(['promo_id' => $promo->id], $this->rules($data));
        });
        $uses = PromoUse::where('promo_id', $promo->id)->selectRaw('count(*) as n, sum(discount_rub) as off')->first();

        return response()->json(['data' => $this->row($promo->refresh()->load('rules'), $uses)]);
    }

    /** Removes the code from the bot table too (the bot stops accepting it). Past purchases keep their discount and history. */
    public function destroy(Promo $promo)
    {
        DB::transaction(function () use ($promo) {
            PromoRule::whereKey($promo->id)->delete();
            $promo->delete();
        });

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request, ?Promo $promo = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[\pL\pN_-]+$/u'],
            'discount' => ['required', 'integer', 'between:1,99'],
            'uses' => ['nullable', 'integer', 'min:0', 'max:100000000'], // uses left; empty = no limit
            'active' => ['required', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'per_user' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'min_price' => ['nullable', 'integer', 'min:1'],
            'item_ids' => ['nullable', 'array', 'max:100'],
            'item_ids.*' => ['integer', 'min:1'],
            'category' => ['nullable', 'string', 'max:128'],
            'new_buyers_only' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'code.regex' => 'В коде только буквы, цифры, _ и -, без пробелов',
            'discount.between' => 'Скидка от 1 до 99 %',
        ]);
        $data['code'] = Promos::normalize($data['code']);
        $taken = Promo::whereRaw('upper(name) = ?', [$data['code']])->when($promo, fn ($q) => $q->whereKeyNot($promo->id))->exists();
        if ($taken) {
            abort(response()->json(['message' => 'Такой код уже есть', 'errors' => ['code' => ['Такой код уже есть']]], 422));
        }
        if (! empty($data['starts_at']) && ! empty($data['expires_at']) && Carbon::parse($data['expires_at'])->lte(Carbon::parse($data['starts_at']))) {
            abort(response()->json(['message' => 'Дата окончания раньше начала', 'errors' => ['expires_at' => ['Дата окончания раньше начала']]], 422));
        }

        return $data;
    }

    private function rules(array $d): array
    {
        $tz = config('app.timezone');

        return [
            'active' => (bool) $d['active'],
            'starts_at' => ! empty($d['starts_at']) ? Carbon::parse($d['starts_at'])->setTimezone($tz) : null,
            'expires_at' => ! empty($d['expires_at']) ? Carbon::parse($d['expires_at'])->setTimezone($tz) : null,
            'per_user' => $d['per_user'] ?? null,
            'min_price' => $d['min_price'] ?? null,
            'item_ids' => ! empty($d['item_ids']) ? array_values(array_unique(array_map('intval', $d['item_ids']))) : null,
            'category' => ($d['category'] ?? null) ?: null,
            'new_buyers_only' => (bool) $d['new_buyers_only'],
            'note' => $d['note'] ?? null,
        ];
    }

    private function row(Promo $p, $uses = null, float $revenue = 0): array
    {
        $r = $p->rules;
        $state = match (true) {
            $r && ! $r->active => 'off',
            $r?->expires_at && $r->expires_at->isPast() => 'expired',
            $r?->starts_at && $r->starts_at->isFuture() => 'scheduled',
            (int) $p->uses === 0 => 'used_up',
            default => 'active',
        };

        return [
            'id' => $p->id, 'code' => $p->name, 'discount' => (int) $p->discount,
            'uses' => (int) $p->uses < 0 ? null : (int) $p->uses, // left; null = no limit
            'active' => $r ? (bool) $r->active : true,
            'starts_at' => $r?->starts_at?->toIso8601String(), 'expires_at' => $r?->expires_at?->toIso8601String(),
            'per_user' => $r?->per_user, 'min_price' => $r?->min_price, 'item_ids' => $r?->item_ids ?? [],
            'category' => $r?->category, 'new_buyers_only' => (bool) $r?->new_buyers_only, 'note' => $r?->note,
            'state' => $state,
            'used' => (int) ($uses->n ?? 0), 'discount_given' => (float) ($uses->off ?? 0), 'revenue' => $revenue,
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}
