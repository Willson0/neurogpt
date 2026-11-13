<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Runs after TelegramAuth: lets through only the Telegram ids listed in neurogpt.admin_ids. */
class AdminOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! self::allows($request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }

    public static function allows(?User $user): bool
    {
        return $user !== null && in_array((int) $user->telegram_id, array_map('intval', (array) config('neurogpt.admin_ids')), true);
    }
}
