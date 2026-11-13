<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientBalance;
use App\Exceptions\ModelLocked;
use App\Exceptions\TooManyActive;
use App\Http\Controllers\Controller;
use App\Http\Resources\GenerationResource;
use App\Http\Resources\UserResource;
use App\Models\Generation;
use App\Services\Generation\GenerationService;
use App\Services\Generation\PromptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class GenerationController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'kind' => ['nullable', Rule::in(['image', 'video', 'tool', 'song'])],
            'favorites' => ['nullable', 'boolean'],
        ]);

        $q = Generation::with('photoset')->where('user_id', $request->user()->id)->latest('id');
        if ($request->boolean('favorites')) {
            $q->where('is_favorite', true);
        }
        if (! empty($filters['kind'])) {
            $q->where('kind', $filters['kind']);
        }
        // The gallery shows results; failed and cancelled rows live in the operations history.
        $q->whereIn('status', ['done', 'queued', 'processing']);

        $page = $q->paginate(30);

        return response()->json([
            'data' => GenerationResource::collection($page->items()),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    public function store(Request $request, GenerationService $service)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['image', 'video', 'song', 'tool'])],
            'model' => ['nullable', 'string', 'max:32', 'regex:/^[a-z0-9_-]+$/'],
            'prompt' => ['nullable', 'string', 'max:4000'],
            'photoset_id' => ['nullable', 'integer', 'exists:photosets,id'],
            'tool' => ['required_if:kind,tool', 'nullable', Rule::in(array_keys(config('neurogpt.tools')))],
            'option' => ['nullable', 'string', 'max:32'],
            'format' => ['nullable', Rule::in(['1:1', '9:16', '16:9'])],
            'duration' => ['nullable', 'integer', 'between:1,120'],
            'count' => ['nullable', 'integer', 'between:1,4'],
            'style' => ['nullable', 'string', 'max:32'],
            'negative' => ['nullable', 'string', 'max:500'],
            'source_generation_id' => ['nullable', 'integer'],
            'file' => [
                'nullable', 'file', 'max:'.config('neurogpt.upload_max_kb'), 'mimetypes:image/jpeg,image/png,image/webp',
                'dimensions:min_width=64,min_height=64,max_width=8192,max_height=8192',
            ],
        ], [
            'file.max' => 'Фото больше 10 МБ',
            'file.mimetypes' => 'Подойдёт JPG, PNG или WebP',
            'file.dimensions' => 'Фото слишком маленькое или слишком большое',
        ]);

        if ($data['kind'] === 'song' && ! config('neurogpt.features.songs')) {
            return response()->json(['message' => 'Songs are coming soon', 'code' => 'coming_soon'], 422);
        }

        $isTool = $data['kind'] === 'tool';
        $needsPrompt = ! $isTool && empty($data['photoset_id']);
        if ($needsPrompt && trim((string) ($data['prompt'] ?? '')) === '') {
            return response()->json(['message' => 'Prompt is required', 'errors' => ['prompt' => ['Напишите запрос']]], 422);
        }
        if (($isTool || ! empty($data['photoset_id'])) && ! $request->hasFile('file') && empty($data['source_generation_id'])) {
            return response()->json(['message' => 'Photo is required', 'errors' => ['file' => ['Сначала выберите фото']]], 422);
        }
        if (! empty($data['photoset_id']) && $data['kind'] !== 'image') {
            return response()->json(['message' => 'Photosets are images', 'errors' => ['kind' => ['Фотосет создаёт картинку']]], 422);
        }
        // A reference picture makes sense for images and as the first frame of a video only.
        $file = in_array($data['kind'], ['image', 'video', 'tool'], true) ? $request->file('file') : null;

        try {
            $items = $service->create($request->user(), $data, $file);
        } catch (ModelLocked $e) {
            return response()->json(['message' => 'Model requires a subscription', 'code' => 'model_locked', 'plan' => $e->plan], 403);
        } catch (InsufficientBalance $e) {
            return response()->json([
                'message' => 'Not enough tokens', 'code' => 'insufficient_balance',
                'required' => $e->required, 'balance' => $e->balance,
            ], 402);
        } catch (TooManyActive $e) {
            return response()->json(['message' => 'Too many generations in progress', 'code' => 'too_many_active', 'limit' => $e->limit], 429);
        }

        return response()->json([
            'data' => GenerationResource::collection(collect($items)),
            'user' => new UserResource($request->user()->refresh()),
        ], 201);
    }

    public function show(Request $request, Generation $generation)
    {
        abort_unless($generation->user_id === $request->user()->id, 404);

        return response()->json([
            'data' => new GenerationResource($generation),
            'user' => new UserResource($request->user()->refresh()),
        ]);
    }

    public function update(Request $request, Generation $generation)
    {
        abort_unless($generation->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'is_favorite' => ['sometimes', 'boolean'],
            'rating' => ['sometimes', 'nullable', Rule::in([-1, 1])],
        ]);
        $generation->update($data);

        return response()->json(['data' => new GenerationResource($generation)]);
    }

    public function cancel(Request $request, Generation $generation, GenerationService $service)
    {
        abort_unless($generation->user_id === $request->user()->id, 404);
        $cancelled = $service->cancel($generation);

        return response()->json([
            'cancelled' => $cancelled,
            'data' => new GenerationResource($generation->refresh()),
            'user' => new UserResource($request->user()->refresh()),
        ]);
    }

    /** Free helper: rewrites a short idea into a detailed prompt. Capped per user per day. */
    public function improve(Request $request, PromptService $prompts)
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'min:2', 'max:2000'],
            'kind' => ['nullable', Rule::in(['image', 'video'])],
        ]);

        $key = 'improve:'.$request->user()->id.':'.now()->toDateString();
        Cache::add($key, 0, now()->endOfDay());
        if (Cache::increment($key) > (int) config('neurogpt.limits.improve_per_day')) {
            return response()->json(['message' => 'Daily limit reached', 'code' => 'improve_limit'], 429);
        }

        return response()->json(['prompt' => $prompts->improve($data['prompt'], $data['kind'] ?? 'image')]);
    }
}
