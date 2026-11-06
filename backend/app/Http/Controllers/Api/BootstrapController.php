<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GenerationResource;
use App\Http\Resources\UserResource;
use App\Models\AiModel;
use App\Models\News;
use App\Models\Photoset;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    /** Everything the SPA needs to paint the first screen, in one request. */
    public function __invoke(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'user' => new UserResource($user),
            'models' => AiModel::active()->whereIn('kind', ['text', 'image', 'video'])->get()
                ->groupBy('kind')
                ->map(fn ($list) => $list->map(fn ($m) => [
                    'id' => $m->slug, 'name' => $m->name, 'hint' => $m->hint, 'price' => $m->price,
                    'badge' => $m->badge, 'fast' => $m->is_fast, 'min_plan' => $m->min_plan,
                ])->values()),
            'photosets' => Photoset::active()->get()->map(fn ($p) => $this->photoset($p))->values(),
            'news' => News::visible()->get(['id', 'title', 'subtitle', 'sticker', 'style', 'action']),
            'packs' => config('neurogpt.packs'),
            'plans' => config('neurogpt.plans'),
            'tools' => collect(config('neurogpt.tools'))->map(fn ($t, $id) => ['id' => $id] + $t)->values(),
            'bonus' => config('neurogpt.bonus'),
            'recent' => GenerationResource::collection(
                $user->generations()->latest('id')->limit(10)->get()
            ),
            'bot_username' => config('neurogpt.telegram.bot_username'),
        ]);
    }

    private function photoset(Photoset $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'description' => $p->description, 'prompt' => $p->prompt,
            'cover_url' => $p->cover_url, 'seed' => $p->seed, 'palette' => $p->palette,
            'trending' => $p->is_trending, 'trend_hint' => $p->trend_hint,
        ];
    }
}
