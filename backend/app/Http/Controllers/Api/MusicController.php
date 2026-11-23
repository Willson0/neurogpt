<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\GenerationResource;
use App\Models\Generation;
use App\Services\Generation\GenerationRunner;
use App\Services\Generation\GenerationService;
use App\Services\Generation\MusicStudio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MusicController
{
    public function store(Request $request, MusicStudio $studio, GenerationService $service, GenerationController $generations)
    {
        abort_unless(config('neurogpt.features.songs'), 404);
        $data = $studio->prepare($request);
        $file = $data['music']['file_field'] ? $request->file('file') : null;

        return $generations->queue($request, $service, $data, $file);
    }

    public function profiles(Request $request)
    {
        $query = Generation::where('user_id', $request->user()->id)->where('kind', 'song')->where('status', 'done')
            ->whereIn('params->music->operation', ['persona', 'voice_create'])->whereNotNull('music_result')
            ->latest('id');

        return response()->json(['data' => GenerationResource::collection($query->get())]);
    }

    public function download(Request $request, int $generation, int $track, MusicStudio $studio)
    {
        [$song, $audio] = $studio->track($request->user(), $generation, $track);

        return $this->attachment($song, $audio['path'], $track);
    }

    public function signedDownload(int $generation, int $track)
    {
        $song = Generation::where('kind', 'song')->where('status', 'done')->findOrFail($generation);
        $path = $song->music_result['tracks'][$track]['path']
            ?? ($track === 0 && ! isset($song->params['music']) ? $song->result_path : null);
        abort_unless($path, 404);

        return $this->attachment($song, $path, $track);
    }

    private function attachment(Generation $song, string $path, int $track)
    {
        $disk = Storage::disk('public');
        abort_unless($disk->exists($path), 404);

        return $disk->download($path, 'neurogpt-'.$song->id.'-'.($track + 1).'.'.pathinfo($path, PATHINFO_EXTENSION), [
            'Access-Control-Allow-Origin' => 'https://web.telegram.org',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function send(Request $request, int $generation, int $track, MusicStudio $studio, GenerationRunner $runner)
    {
        [$song, $audio] = $studio->track($request->user(), $generation, $track);
        abort_unless(Storage::disk('public')->exists($audio['path']), 404);
        if (! $runner->sendTracks($song, [$track => $audio])) {
            return response()->json(['message' => 'Не удалось отправить трек. Откройте чат с ботом и нажмите «Старт».'], 502);
        }

        return response()->json(['sent' => true]);
    }
}
