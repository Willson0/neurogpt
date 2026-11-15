<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** The app sends button presses and screen views in batches; they feed the admin analytics. */
class EventController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'events' => ['required', 'array', 'max:50'],
            'events.*.type' => ['required', Rule::in(['click', 'view'])],
            'events.*.name' => ['nullable', 'string', 'max:200'],
            'events.*.screen' => ['nullable', 'string', 'max:200'],
            // Seconds since the press, so a batch sent later keeps the real time (capped at 10 minutes).
            'events.*.ago' => ['nullable', 'integer', 'between:0,600'],
        ]);
        $user = $request->user()->id;
        $now = now();
        $rows = [];
        foreach ($data['events'] as $e) {
            $name = trim(preg_replace('/\s+/u', ' ', (string) ($e['name'] ?? '')));
            if ($name === '') {
                continue;
            }
            $rows[] = [
                'user_id' => $user, 'type' => $e['type'], 'name' => Str::limit($name, 64, ''),
                'screen' => isset($e['screen']) ? Str::limit(trim($e['screen']), 48, '') : null,
                'created_at' => $now->copy()->subSeconds((int) ($e['ago'] ?? 0)),
            ];
        }
        if ($rows) {
            Event::insert($rows);
        }

        return response()->json(['ok' => true, 'saved' => count($rows)]);
    }
}
