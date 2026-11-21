<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class GenerationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $g = $this->resource;
        $music = $g->params['music'] ?? null;
        $result = $g->music_result ?? [];
        $disk = Storage::disk('public');

        return [
            'id' => $g->id,
            'kind' => $g->kind,
            'status' => $g->status,
            'title' => $g->title(),
            'prompt' => $g->prompt,
            'cost' => $g->cost,
            'tool' => $g->tool,
            'photoset_id' => $g->photoset_id,
            'params' => $music ? (object) [] : ($g->params ?: (object) []),
            'result_url' => $g->result_path ? $disk->url($g->result_path) : null,
            ...($music ? ['music' => [
                'operation' => $music['operation'],
                'model' => $music['version'],
                'name' => $result['name'] ?? $music['name'],
                'parent_id' => $music['parent_id'],
                'track' => $music['track'],
                'phrase' => $result['phrase'] ?? null,
                'profile_type' => $result['profile_type'] ?? null,
                'lyrics' => $result['lyrics'] ?? [],
                'style' => $result['style'] ?? null,
                'tracks' => array_map(fn (array $track, int $index) => [
                    'url' => $disk->url($track['path']),
                    'download_url' => URL::temporarySignedRoute('music.download', now()->addHours(12), ['generation' => $g->id, 'track' => $index]),
                    'title' => $track['title'],
                    'duration' => $track['duration'] ?? null,
                    'lyrics' => $track['lyrics'] ?? null,
                    'style' => $track['style'] ?? null,
                    'editable' => ! empty($track['id']),
                ], $result['tracks'] ?? [], array_keys($result['tracks'] ?? [])),
            ]] : []),
            'error' => $g->error,
            'is_favorite' => $g->is_favorite,
            'rating' => $g->rating,
            'created_at' => $g->created_at?->toIso8601String(),
            'finished_at' => $g->finished_at?->toIso8601String(),
        ];
    }
}
