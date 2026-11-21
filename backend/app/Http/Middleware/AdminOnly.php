<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after TelegramAuth. Admins are the owners hardcoded in neurogpt.admin_ids
 * plus the bot's shared list of administrators.
 */
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
        return $user !== null && self::allowsId((int) $user->telegram_id);
    }

    public static function allowsId(int $telegramId): bool
    {
        return in_array($telegramId, self::owners(), true) || DB::table('admins')->where('id', $telegramId)->exists();
    }

    /** The main admin can manage the list of admins and cannot be removed from the code-defined list. */
    public static function isOwner(?User $user): bool
    {
        return $user !== null && in_array((int) $user->telegram_id, self::owners(), true);
    }

    /** @return list<int> */
    public static function owners(): array
    {
        return array_map('intval', (array) config('neurogpt.admin_ids'));
    }

    /** @return list<int> */
    public static function added(): array
    {
        return DB::table('admins')->whereNotIn('id', self::owners())->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
