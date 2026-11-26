<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Everything users have made: pictures, video, audio, tool results and chat answers, with the prompts. */
class ContentController extends Controller
{
    public function generations(Request $request)
    {
        $f = $request->validate([
            'kind' => ['nullable', Rule::in(['image', 'video', 'song', 'tool', 'photoset'])],
            'status' => ['nullable', Rule::in(['queued', 'processing', 'done', 'failed', 'cancelled'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'user' => ['nullable', 'string', 'max:64'],
            'q' => ['nullable', 'string', 'max:200'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $q = Generation::query();
        match ($f['kind'] ?? null) {
            null => null,
            'photoset' => $q->whereNotNull('photoset_id'),
            'image' => $q->where('kind', 'image')->whereNull('photoset_id'),
            default => $q->where('kind', $f['kind']),
        };
        if (! empty($f['status'])) {
            $q->where('status', $f['status']);
        }
        $this->period($q, 'wa_generations.created_at', $f);
        $this->byUser($q, 'user', $f['user'] ?? null);
        if (($f['q'] ?? '') !== '') {
            $q->where('prompt', 'like', '%'.addcslashes($f['q'], '%_\\').'%');
        }

        $summary = (clone $q)->selectRaw("count(*) as total, coalesce(sum(case when status = 'done' then 1 else 0 end), 0) as done, coalesce(sum(case when status = 'done' then cost else 0 end), 0) as tokens")->first();
        $page = $q->with(['user', 'model', 'photoset'])->latest('id')->paginate((int) ($f['per_page'] ?? 40));

        return response()->json([
            'data' => collect($page->items())->map(fn (Generation $g) => $this->generation($g)),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
            'summary' => ['total' => (int) $summary->total, 'done' => (int) $summary->done, 'tokens' => (int) $summary->tokens],
        ]);
    }

    public function showGeneration(Generation $generation)
    {
        $generation->load(['user', 'model', 'photoset']);
        $events = \Illuminate\Support\Facades\DB::table('wa_provider_events')
            ->where('generation_id', $generation->id)->where('outcome', 'error')->latest('id')->limit(30)
            ->get(['provider', 'operation', 'reason', 'http_status', 'provider_code', 'request_id', 'task_id', 'created_at']);

        return response()->json(['data' => $this->generation($generation) + ['logs' => $events]]);
    }

    /**
     * The photo a user attached. It is private, so it is streamed here instead of living in /storage.
     * Uploads are removed once the job finishes, so this answers only while a generation is waiting or running.
     */
    public function input(Generation $generation)
    {
        $disk = Storage::disk('local');
        abort_unless($generation->input_path && $disk->fileExists($generation->input_path), 404);

        return response($disk->get($generation->input_path), 200, [
            'Content-Type' => $disk->mimeType($generation->input_path) ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function destroyGeneration(Generation $generation)
    {
        abort_if($generation->isActive(), 409, 'Generation is still running');
        $paths = array_filter([$generation->result_path, ...array_column($generation->music_result['tracks'] ?? [], 'path')]);
        Storage::disk('public')->delete(array_unique($paths));
        // Several pictures of one request share the uploaded photo.
        if ($generation->input_path && ! Generation::where('input_path', $generation->input_path)->whereKeyNot($generation->id)->exists()) {
            \App\Services\Generation\GenerationService::removeInput($generation->input_path);
        }
        $generation->delete();

        return response()->json(['ok' => true]);
    }

    /** Chat with the text models: the question and the answer are both rows here. */
    public function messages(Request $request)
    {
        $f = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'user' => ['nullable', 'string', 'max:64'],
            'q' => ['nullable', 'string', 'max:200'],
            'role' => ['nullable', Rule::in(['user', 'assistant'])],
        ]);

        $q = ChatMessage::query()->with('chat.user');
        $this->period($q, 'wa_chat_messages.created_at', $f);
        $this->byUser($q, 'chat.user', $f['user'] ?? null);
        if (! empty($f['role'])) {
            $q->where('role', $f['role']);
        }
        if (($f['q'] ?? '') !== '') {
            $q->where('content', 'like', '%'.addcslashes($f['q'], '%_\\').'%');
        }
        $total = (clone $q)->count();
        $page = $q->latest('id')->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (ChatMessage $m) => [
                'id' => $m->id, 'chat_id' => $m->chat_id, 'role' => $m->role, 'content' => $m->content, 'cost' => $m->cost,
                'user' => $this->user($m->chat?->user), 'created_at' => $m->created_at?->toIso8601String(),
            ]),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
            'summary' => ['total' => $total],
        ]);
    }

    /** from/to arrive as ISO dates with the admin's time zone; "to" is inclusive to the second. */
    private function period(Builder $q, string $column, array $f): void
    {
        $tz = config('app.timezone');
        if (! empty($f['from'])) {
            $q->where($column, '>=', Carbon::parse($f['from'])->setTimezone($tz));
        }
        if (! empty($f['to'])) {
            $q->where($column, '<=', Carbon::parse($f['to'])->setTimezone($tz));
        }
    }

    /** Telegram id, internal "#id", or a part of the username or name. */
    private function byUser(Builder $q, string $relation, ?string $term): void
    {
        $term = trim((string) $term);
        if ($term === '') {
            return;
        }
        $q->whereHas($relation, fn (Builder $u) => UsersController::search($u, $term));
    }

    private function generation(Generation $g): array
    {
        return [
            'id' => $g->id, 'kind' => $g->kind, 'status' => $g->status, 'title' => $g->title(), 'prompt' => $g->prompt,
            'cost' => $g->cost, 'tool' => $g->tool, 'params' => $g->params ?: (object) [],
            'model' => $g->model ? ['name' => $g->model->name, 'slug' => $g->model->slug, 'provider_model' => $g->model->provider_model] : null,
            'photoset' => $g->photoset?->name,
            'user' => $this->user($g->user),
            'result_url' => $g->result_path ? Storage::disk('public')->url($g->result_path) : null,
            'result_text' => $g->result_text,
            'music' => isset($g->params['music']) ? [
                'title' => $g->params['music']['name'] ?? null,
                'tracks' => collect($g->music_result['tracks'] ?? [])->filter(fn ($track) => ! empty($track['path']))
                    ->values()->map(fn ($track) => [
                        'title' => $track['title'] ?? null,
                        'url' => Storage::disk('public')->url($track['path']),
                        'duration' => $track['duration'] ?? null,
                    ]),
                'lyrics' => $g->music_result['lyrics'] ?? [],
            ] : null,
            'has_input' => $g->input_path && Storage::disk('local')->fileExists($g->input_path),
            'error' => $g->error, 'rating' => $g->rating, 'is_favorite' => $g->is_favorite,
            'created_at' => $g->created_at?->toIso8601String(),
            'seconds' => $g->started_at && $g->finished_at ? (int) $g->started_at->diffInSeconds($g->finished_at) : null,
        ];
    }

    private function user(?User $u): ?array
    {
        return $u ? ['id' => $u->id, 'telegram_id' => $u->telegram_id, 'name' => $u->displayName(), 'username' => $u->username] : null;
    }
}
