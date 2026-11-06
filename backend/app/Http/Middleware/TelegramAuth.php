<?php

namespace App\Http\Middleware;

use App\Exceptions\InvalidInitData;
use App\Services\Telegram\UserResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorization: tma <initData>. The signature is checked on every request,
 * so there are no tokens to leak or revoke.
 */
class TelegramAuth
{
    public function __construct(private readonly UserResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');
        if (! str_starts_with($header, 'tma ')) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        try {
            $user = $this->resolver->fromInitData(substr($header, 4));
        } catch (InvalidInitData $e) {
            return response()->json(['message' => 'Invalid Telegram data', 'reason' => $e->getMessage()], 401);
        }

        if ($user->is_blocked) {
            return response()->json(['message' => 'Account is blocked'], 403);
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
