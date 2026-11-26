<?php

namespace App\Http\Middleware;

use App\Exceptions\InvalidInitData;
use App\Http\Controllers\Api\Admin\SessionController;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use App\Services\Telegram\UserResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorization: tma <initData> (or tgl <login widget data> for an admin in a browser). The signature is checked on every request,
 * so there are no tokens to leak or revoke.
 */
class TelegramAuth
{
    public function __construct(private readonly UserResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');
        if ($header === '' && $request->header('X-NeuroGPT-Session') === '1'
            && ! in_array($request->header('Sec-Fetch-Site'), ['cross-site', 'same-site'], true)
            && ($cookie = $request->cookie(SessionController::COOKIE))) {
            try {
                $header = 'tgl '.Crypt::decryptString($cookie);
            } catch (DecryptException) {
                return response()->json(['message' => 'Invalid session'], 401);
            }
        }
        if (! str_starts_with($header, 'tma ') && ! str_starts_with($header, 'tgl ')) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        try {
            // "tma" is the Mini App's initData, "tgl" the Login Widget data of an admin in a browser.
            $user = str_starts_with($header, 'tgl ')
                ? $this->resolver->fromLoginWidget(substr($header, 4))
                : $this->resolver->fromInitData(substr($header, 4));
        } catch (InvalidInitData $e) {
            return response()->json(['message' => 'Invalid Telegram data'], 401);
        }

        if ($user->is_blocked) {
            return response()->json(['message' => 'Account is blocked'], 403);
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
