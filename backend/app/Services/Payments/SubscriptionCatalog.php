<?php

namespace App\Services\Payments;

use App\Models\Bot\ShopItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SubscriptionCatalog
{
    private static function query(): Builder
    {
        return ShopItem::where('category', config('neurogpt.bot.shop.sub_category'));
    }

    public static function purchasable(): Builder
    {
        $query = self::query();
        $length = $query->getConnection()->getDriverName() === 'sqlite' ? 'length' : 'char_length';

        return $query->where('active', true)->whereNotIn('item', ['', 'free'])
            ->whereRaw("$length(item) <= 12")->where('amount', '>', 0)->where('price', '>', 0);
    }

    public static function offers(): Collection
    {
        $offers = self::purchasable()->orderBy('price')->orderBy('id')->get();
        $daily = DB::table('config')->where('category', 'daily_tokens')->whereIn('name', $offers->pluck('item')->unique())
            ->orderBy('id')->pluck('value', 'name');

        return $offers->map(fn (ShopItem $item) => [
            'id' => $item->id,
            'plan' => $item->item,
            'name' => $item->text_name,
            'days' => $item->amount,
            'price' => $item->price,
            'stars' => PaymentService::stars($item->price),
            'description' => $item->description,
            'tag' => $item->tag,
            'daily_tokens' => max(0, (int) ($daily[$item->item] ?? 0)),
        ]);
    }

    public static function names(): array
    {
        $names = self::query()->where(function (Builder $query) {
            $query->where('active', true)->orWhere('item', 'free');
        })->orderByDesc('active')->orderBy('id')->get(['item', 'text_name'])->unique('item')
            ->filter(fn (ShopItem $item) => $item->item !== '' && mb_strlen($item->item) <= 12)
            ->pluck('text_name', 'item')->all();

        return ['free' => $names['free'] ?? 'Бесплатный'] + $names;
    }

    public static function name(string $plan): string
    {
        return self::query()->where('item', $plan)->orderByDesc('active')->orderBy('id')->value('text_name')
            ?? ($plan === 'free' ? 'Бесплатный' : $plan);
    }
}
