<?php

namespace App\Http\Controllers\Api\Admin;

use App\Services\Admin\ProviderJournal;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProviderEventsController
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'provider' => ['nullable', Rule::in(ProviderJournal::PROVIDERS)],
            'outcome' => ['nullable', 'in:ok,error'],
            'q' => ['nullable', 'string', 'max:128'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $events = DB::table('wa_provider_events')->orderByDesc('id');
        foreach (['provider', 'outcome'] as $key) {
            if (filled($filters[$key] ?? null)) {
                $events->where($key, $filters[$key]);
            }
        }
        $text = trim($filters['q'] ?? '');
        if ($text !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($text)).'%';
            $events->where(function ($query) use ($pattern, $text) {
                foreach (['host', 'operation', 'model', 'feature', 'reason', 'task_id', 'request_id', 'provider_code'] as $column) {
                    $query->orWhereRaw("LOWER($column) LIKE ? ESCAPE '!'", [$pattern]);
                }
                if (ctype_digit($text) && strlen($text) <= 18) {
                    $query->orWhere('generation_id', (int) $text)->orWhere('http_status', (int) $text);
                }
            });
        }
        $page = $events->simplePaginate(40);

        return response()->json([
            'data' => array_map(function ($row) {
                $row->created_at = Carbon::parse($row->created_at, config('app.timezone'))->toIso8601String();

                return $row;
            }, $page->items()),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }
}
