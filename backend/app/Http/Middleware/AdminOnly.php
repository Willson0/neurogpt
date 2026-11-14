<?php

namespace App\Http\Middleware;

use App\Models\Bot\BotAdmin;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Runs after TelegramAuth. Admins are the owners hardcoded in neurogpt.admin_ids
 * plus the people an owner added in the panel (the "admins" setting) and the bot's own admins (bot table `admins`).
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
        return in_array($telegramId, self::owners(), true) || in_array($telegramId, self::added(), true) || self::botAdmin($telegramId);
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
        return array_values(array_map('intval', (array) config('neurogpt.admins')));
    }

    private static function botAdmin(int $telegramId): bool
    {
        if ($telegramId <= 0) {
            return false;
        }
        try {
            return BotAdmin::whereKey($telegramId)->exists();
        } catch (Throwable) {
            return false; // no `admins` table on this database
        }
    }
}
