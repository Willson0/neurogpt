<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Bot\ShopItem;
use App\Services\Payments\PaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShopController
{
    public function index()
    {
        return response()->json(['data' => ShopItem::where('category', 'token')
            ->orWhere(fn (Builder $query) => $query->where('category', config('neurogpt.bot.shop.sub_category'))->whereNotIn('item', ['', 'free']))
            ->orderBy('price')->orderBy('id')->get()->map($this->offer(...))]);
    }

    public function update(Request $request, ShopItem $offer)
    {
        abort_unless($offer->category === 'token' || ($offer->category === config('neurogpt.bot.shop.sub_category')
            && ! in_array($offer->item, ['', 'free'], true)), 404);
        $data = $request->validate([
            'text_name' => ['required', 'string', 'max:128'],
            'price' => ['required', 'integer', 'between:1,10000000'],
            'amount' => ['required', 'integer', Rule::when($offer->category === 'token', 'between:1,10000000', 'between:1,3650')],
            'active' => ['required', 'boolean'],
        ]);
        $offer->update($data);

        return response()->json(['data' => $this->offer($offer)]);
    }

    private function offer(ShopItem $offer): array
    {
        return [
            'id' => $offer->id,
            'category' => $offer->category === 'token' ? 'token' : 'subscription',
            'text_name' => $offer->text_name,
            'amount' => $offer->amount,
            'price' => $offer->price,
            'stars' => PaymentService::stars($offer->price),
            'active' => $offer->active,
        ];
    }
}
