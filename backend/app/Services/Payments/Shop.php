<?php

namespace App\Services\Payments;

use App\Models\Bot\ShopItem;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The bot table `shop` is the price list. A purchase is made by the id of its row: price, quantity and what is
 * delivered come from that row on the server, never from the app.
 *
 *  - category "token" (BOT_SHOP_TOKENS_CATEGORY): amount = tokens credited, price = rubles;
 *  - category "sub" (BOT_SHOP_SUB_CATEGORY): item = plan (plus / pro), amount = days (or months, BOT_SHOP_SUB_AMOUNT).
 *    Subscription tokens per month come from the plan in config/admin panel (the shop row has no column for them).
 *
 * Only active rows with a price above zero can be bought. When the shop has no token rows (or no subscription rows)
 * the app falls back to the packs / plans of config/neurogpt.php for that part.
 */
class Shop
{
    private ?Collection $rows = null;

    /** Active rows, cheapest first. */
    private function rows(): Collection
    {
        if ($this->rows === null) {
            try {
                $this->rows = ShopItem::where('active', true)->where('price', '>', 0)->where('amount', '>', 0)
                    ->orderBy('price')->orderBy('id')->get();
            } catch (Throwable) {
                $this->rows = collect(); // no `shop` table on this database
            }
        }

        return $this->rows;
    }

    public function tokenItems(): Collection
    {
        return $this->rows()->where('category', config('neurogpt.bot.shop.tokens_category'))->values();
    }

    public function subItems(): Collection
    {
        $plans = array_diff(array_keys((array) config('neurogpt.plans')), ['free']);

        return $this->rows()->where('category', config('neurogpt.bot.shop.sub_category'))
            ->filter(fn (ShopItem $i) => in_array($i->item, $plans, true))->values();
    }

    /** A row that can be bought right now, or null. */
    public function find(int $id): ?ShopItem
    {
        $item = $this->tokenItems()->firstWhere('id', $id) ?? $this->subItems()->firstWhere('id', $id);

        return $item ? clone $item : null;
    }

    public function isTokens(ShopItem $item): bool
    {
        return $item->category === config('neurogpt.bot.shop.tokens_category');
    }

    /** Days a subscription row gives. */
    public static function days(ShopItem $item): int
    {
        return config('neurogpt.bot.shop.sub_amount') === 'months' ? (int) $item->amount * 30 : (int) $item->amount;
    }

    /** description of a token row may be JSON: {"photo": 15, "video": 0, "emoji": "..."} (how many pictures / videos it is). */
    public static function extras(ShopItem $item): array
    {
        $d = json_decode((string) $item->description, true);
        if (! is_array($d)) {
            return [];
        }

        return array_filter([
            'photo' => isset($d['photo']) && is_numeric($d['photo']) ? (int) $d['photo'] : null,
            'video' => isset($d['video']) && is_numeric($d['video']) ? (int) $d['video'] : null,
            'emoji' => isset($d['emoji']) && is_string($d['emoji']) ? mb_substr($d['emoji'], 0, 8) : null,
        ], fn ($v) => $v !== null);
    }

    /** Token packs for the app: from the shop, or from config when the shop has none. */
    public function packs(): array
    {
        $items = $this->tokenItems();
        if ($items->isEmpty()) {
            return collect(config('neurogpt.packs'))->map(fn ($p) => $p + ['stars' => PaymentService::stars($p['price'])])->values()->all();
        }

        return $items->map(fn (ShopItem $i) => [
            'item_id' => $i->id, 'tokens' => (int) $i->amount, 'price' => (int) $i->price, 'name' => $i->text_name,
            'badge' => $i->tag ?: null, 'stars' => PaymentService::stars($i->price),
        ] + self::extras($i))->values()->all();
    }

    /**
     * Plans for the app. With subscription rows in the shop each plan gets the row of a month (about 30 days) and of a
     * year (about 365 days) with their prices; a plan without rows cannot be bought (available = false).
     */
    public function plans(): array
    {
        $plans = collect(config('neurogpt.plans'));
        $items = $this->subItems();
        $stars = fn ($rub) => $rub ? PaymentService::stars($rub) : 0;

        return $plans->map(function ($p, $key) use ($items, $stars) {
            if ($items->isEmpty() || $key === 'free') {
                return $p + ['available' => true, 'stars_month' => $stars($p['month']), 'stars_year' => $stars($p['year_month'] * 12)];
            }
            $own = $items->where('item', $key);
            $month = $own->filter(fn ($i) => self::days($i) < 300)->sortBy(fn ($i) => abs(self::days($i) - 30))->first();
            $year = $own->filter(fn ($i) => self::days($i) >= 300)->sortBy(fn ($i) => abs(self::days($i) - 365))->first();

            return array_merge($p, [
                'available' => (bool) ($month || $year),
                'month' => $month ? (int) $month->price : 0,
                'year_month' => $year ? round($year->price / 12, 2) : 0,
                'item_month' => $month?->id,
                'item_year' => $year?->id,
                'stars_month' => $month ? $stars($month->price) : 0,
                'stars_year' => $year ? $stars($year->price) : 0,
            ]);
        })->all();
    }

    /** The shop row for "plan + period" from the old endpoint, when the shop sells subscriptions. */
    public function planItem(string $plan, string $period): ?ShopItem
    {
        $id = $this->plans()[$plan]['item_'.$period] ?? null;

        return $id ? $this->find((int) $id) : null;
    }
}
