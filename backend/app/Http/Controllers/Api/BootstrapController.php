<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GenerationResource;
use App\Http\Resources\UserResource;
use App\Models\AiModel;
use App\Models\News;
use App\Models\Photoset;
use App\Services\Payments\PaymentService;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    /** Everything the SPA needs to paint the first screen, in one request. */
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $methods = PaymentService::methods();
        $stars = fn ($rub) => PaymentService::stars($rub);
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
                'id' => $p->id, 'name' => $p->name, 'description' => $p->description,
                'cover_url' => $p->cover_url, 'seed' => $p->seed, 'palette' => $p->palette,
                'trending' => $p->is_trending, 'trend_hint' => $p->trend_hint,
            ])->values(),
            'news' => News::visible()->get(['id', 'title', 'subtitle', 'sticker', 'style', 'action']),
            'packs' => collect(config('neurogpt.packs'))->map(fn ($p) => $p + ['stars' => $stars($p['price'])])->values(),
            'plans' => collect(config('neurogpt.plans'))->map(fn ($p) => $p + [
                'stars_month' => $p['month'] ? $stars($p['month']) : 0,
                'stars_year' => $p['year_month'] ? $stars($p['year_month'] * 12) : 0,
            ]),
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
