<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class GenerationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $g = $this->resource;

        return [
            'id' => $g->id,
            'kind' => $g->kind,
            'status' => $g->status,
            'prompt' => $g->prompt,
            'cost' => $g->cost,
            'tool' => $g->tool,
            'photoset_id' => $g->photoset_id,
            'params' => $g->params ?: (object) [],
            'result_url' => $g->result_path ? Storage::disk('public')->url($g->result_path) : null,
            'error' => $g->error,
            'is_favorite' => $g->is_favorite,
            'rating' => $g->rating,
            'created_at' => $g->created_at?->toIso8601String(),
            'finished_at' => $g->finished_at?->toIso8601String(),
        ];
    }
}
