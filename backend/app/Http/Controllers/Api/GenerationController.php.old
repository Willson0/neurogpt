<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientBalance;
use App\Exceptions\ModelLocked;
use App\Http\Controllers\Controller;
use App\Http\Resources\GenerationResource;
use App\Http\Resources\UserResource;
use App\Models\Generation;
use App\Services\Generation\GenerationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GenerationController extends Controller
{
    public function index(Request $request)
    {
        $q = Generation::where('user_id', $request->user()->id)->latest('id');
        if ($request->boolean('favorites')) {
            $q->where('is_favorite', true);
        }

        $page = $q->paginate(30);

        return response()->json([
            'data' => GenerationResource::collection($page->items()),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    public function store(Request $request, GenerationService $service)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['image', 'video', 'tool'])],
            'model' => ['nullable', 'string', 'max:32'],
            'prompt' => ['nullable', 'string', 'max:4000'],
            'photoset_id' => ['nullable', 'integer', 'exists:photosets,id'],
            'tool' => ['required_if:kind,tool', 'nullable', Rule::in(array_keys(config('neurogpt.tools')))],
            'format' => ['nullable', Rule::in(['1:1', '9:16', '16:9'])],
            'duration' => ['nullable', Rule::in([5, 10])],
            'count' => ['nullable', 'integer', 'between:1,4'],
            'style' => ['nullable', 'string', 'max:32'],
            'negative' => ['nullable', 'string', 'max:500'],
            'seed' => ['nullable', 'integer', 'min:0'],
            'file' => ['nullable', 'file', 'max:'.config('neurogpt.upload_max_kb'), 'mimetypes:image/jpeg,image/png,image/webp'],
        ]);

        $needsPrompt = $data['kind'] !== 'tool' && empty($data['photoset_id']);
        if ($needsPrompt && trim((string) ($data['prompt'] ?? '')) === '') {
            return response()->json(['message' => 'Prompt is required', 'errors' => ['prompt' => ['Напишите запрос']]], 422);
        }
        if ($data['kind'] === 'tool' && ! $request->hasFile('file')) {
            return response()->json(['message' => 'File is required', 'errors' => ['file' => ['Сначала выберите фото']]], 422);
        }
        if (! empty($data['photoset_id']) && ! $request->hasFile('file')) {
            return response()->json(['message' => 'Photo is required', 'errors' => ['file' => ['Сначала выберите фото']]], 422);
        }

        try {
            $items = $service->create($request->user(), $data, $request->file('file'));
        } catch (ModelLocked $e) {
            return response()->json(['message' => 'Model requires a subscription', 'code' => 'model_locked', 'plan' => $e->plan], 403);
        } catch (InsufficientBalance $e) {
            return response()->json([
                'message' => 'Not enough tokens', 'code' => 'insufficient_balance',
                'required' => $e->required, 'balance' => $e->balance,
            ], 402);
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
}
