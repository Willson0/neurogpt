<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GenerationResource;
use App\Http\Resources\UserResource;
use App\Models\AiModel;
use App\Models\News;
use App\Models\Photoset;
use App\Services\Payments\PaymentService;
use App\Services\Payments\Shop;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    /** Everything the SPA needs to paint the first screen, in one request. */
    public function __invoke(Request $request, Shop $shop)
    {
        $user = $request->user();
        $methods = PaymentService::methods();
        $songs = (bool) config('neurogpt.features.songs');

        $models = AiModel::active()->whereIn('kind', $songs ? ['image', 'video', 'song'] : ['image', 'video'])->get();
        $activeTools = AiModel::active()->where('kind', 'tool')->pluck('slug')->all();

        return response()->json([
            'user' => new UserResource($user),
            'models' => $models->groupBy('kind')->map(fn ($list) => $list->map(fn (AiModel $m) => [
                'id' => $m->slug, 'name' => $m->name, 'hint' => $m->hint, 'price' => $m->price,
                'badge' => $m->badge, 'fast' => $m->is_fast, 'min_plan' => $m->min_plan,
                ...($m->kind === 'video' ? [
                    'formats' => $m->formats(),
                    'durations' => collect($m->durations())->map(fn ($mult, $sec) => ['seconds' => (int) $sec, 'price' => (int) ceil($m->price * $mult)])->values(),
                ] : []),
            ])->values()),
            'photosets' => Photoset::active()->get()->map(fn ($p) => [
                'id' => $p->id, 'kind' => $p->kind ?: 'photo', 'name' => $p->name, 'description' => $p->description,
                'cover_url' => $p->cover_url, 'seed' => $p->seed, 'palette' => $p->palette,
                'trending' => $p->is_trending, 'trend_hint' => $p->trend_hint,
            ])->values(),
            'news' => News::visible()->get(['id', 'title', 'subtitle', 'sticker', 'style', 'action']),
            // The bot table `shop` is the price list (item_id = shop.id); config packs/plans only when the shop has none.
            'packs' => $shop->packs(),
            'plans' => $shop->plans(),
            // Prompts and provider settings stay on the server: the app only gets what it shows.
            'tools' => collect(config('neurogpt.tools'))->only($activeTools)->map(fn ($t, $id) => [
                'id' => $id, 'name' => $t['name'], 'hint' => $t['hint'], 'cost' => $t['cost'], 'icon' => $t['icon'] ?? 'wand',
                'group' => $t['group'] ?? 'fix', 'input' => $t['input'] ?? 'none', 'placeholder' => $t['placeholder'] ?? null,
                'options' => collect($t['options'] ?? [])->map(fn ($o, $key) => ['id' => $key, 'name' => $o['name']])->values(),
                'output' => $t['output'] ?? 'image',
            ])->values(),
            'styles' => collect(config('neurogpt.styles'))->map(fn ($s, $id) => ['id' => $id, 'name' => $s['name']])->values(),
            'bonus' => config('neurogpt.bonus'),
            'theme' => config('neurogpt.theme'),
            'features' => ['songs' => $songs],
            'payments' => $methods,
            // rubles per star: the app recounts a price in Stars after a promo discount
            'stars_rate' => (float) config('neurogpt.stars.rub_per_star'),
            'limits' => ['upload_mb' => (int) floor(config('neurogpt.upload_max_kb') / 1024)],
            'recent' => GenerationResource::collection(
                $user->generations()->with('photoset')->whereIn('status', ['done', 'queued', 'processing'])->latest('id')->limit(12)->get()
            ),
            'bot_username' => config('neurogpt.telegram.bot_username'),
            'invite_link' => $user->inviteLink(),
            'support_url' => config('neurogpt.telegram.support_url'),
        ]);
    }
}
