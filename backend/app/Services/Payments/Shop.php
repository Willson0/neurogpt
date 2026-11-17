<?php

namespace App\Services\Payments;

use App\Models\Bot\BotConfig;
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
            return collect(config('neurogpt.packs'))->map(fn ($p) => $p + ['stars' => PaymentService::stars($p['price']), 'category' => config('neurogpt.bot.shop.tokens_category')])->values()->all();
        }

        return $items->map(fn (ShopItem $i) => [
            'item_id' => $i->id, 'tokens' => (int) $i->amount, 'price' => (int) $i->price, 'name' => $i->text_name,
            'badge' => $i->tag ?: null, 'stars' => PaymentService::stars($i->price), 'category' => $i->category,
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
        $category = config('neurogpt.bot.shop.sub_category');

        return $plans->map(function ($p, $key) use ($items, $stars, $category) {
            $p['category'] = $category;
            // the bot's daily tokens of this plan (table `config`: daily_tokens / free|plus|pro / N)
            $p['daily_tokens'] = BotConfig::int(BotConfig::DAILY_TOKENS, (string) $key);
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

    /**
     * The shop row for a pack / plan that comes from config (the shop has no such row). payments.item_id is required,
     * so a matching row is reused, or a hidden one (active = 0, tag "webapp") is added: it does not appear in the bot menu.
     */
    public function rowFor(array $attrs): ShopItem
    {
        $cfg = config('neurogpt.bot.shop');
        if ($attrs['purpose'] === 'topup') {
            $found = ShopItem::where(['category' => $cfg['tokens_category'], 'amount' => (int) $attrs['tokens']])
                ->where('price', (int) round($attrs['price']))->orderByDesc('active')->orderBy('id')->first();
            $row = ['category' => $cfg['tokens_category'], 'item' => $cfg['tokens_item'], 'amount' => (int) $attrs['tokens']];
            $name = "{$attrs['tokens']} токенов";
        } else {
            $days = (int) ($attrs['days'] ?? 30);
            $row = ['category' => $cfg['sub_category'], 'item' => (string) $attrs['plan'], 'amount' => $cfg['sub_amount'] === 'months' ? max(1, (int) round($days / 30)) : $days];
            $found = ShopItem::where($row)->where('price', (int) round($attrs['price']))->orderByDesc('active')->orderBy('id')->first();
            $name = 'Подписка '.config("neurogpt.plans.{$attrs['plan']}.name", $attrs['plan']).($days >= 300 ? ', год' : ', месяц');
        }

        return $found ?? ShopItem::create($row + [
            'active' => false, 'text_name' => mb_substr($name, 0, 128), 'price' => (int) round($attrs['price']),
            'description' => 'Создано веб-приложением', 'tag' => 'webapp',
        ]);
    }

    /** Any row by id for the payment link page, also an inactive one (then it cannot be bought). */
    public function any(int $id): ?ShopItem
    {
        try {
            return ShopItem::find($id);
        } catch (Throwable) {
            return null;
        }
    }

    /** One shop row as the app shows it on the payment screen. */
    public function card(ShopItem $item): array
    {
        $tokens = $this->isTokens($item);

        return [
            'item_id' => (int) $item->id,
            'name' => (string) $item->text_name,
            'category' => (string) $item->category,
            'kind' => $tokens ? 'tokens' : 'plan',
            'tokens' => $tokens ? (int) $item->amount : (int) config("neurogpt.plans.{$item->item}.tokens", 0) * max(1, (int) round(self::days($item) / 30)),
            'plan' => $tokens ? null : (string) $item->item,
            'days' => $tokens ? null : self::days($item),
            'price' => (int) $item->price,
            'stars' => PaymentService::stars($item->price),
            'badge' => $item->tag ?: null,
            'available' => (bool) $this->find((int) $item->id),
        ] + ($tokens ? self::extras($item) : []);
    }

    /** The shop row for "plan + period" from the old endpoint, when the shop sells subscriptions. */
    public function planItem(string $plan, string $period): ?ShopItem
    {
        $id = $this->plans()[$plan]['item_'.$period] ?? null;

        return $id ? $this->find((int) $id) : null;
    }
}
