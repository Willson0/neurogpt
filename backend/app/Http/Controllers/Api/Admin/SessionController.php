<?php

namespace App\Http\Controllers\Api\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Cookie;

class SessionController
{
    public const COOKIE = 'ng_admin_login';

    public function store(Request $request)
    {
        $header = (string) $request->header('Authorization');
        abort_unless(str_starts_with($header, 'tgl '), 401);
        $cookie = Cookie::create(self::COOKIE)
            ->withValue(Crypt::encryptString(substr($header, 4)))
            ->withExpires(time() + (int) config('neurogpt.telegram.login_ttl'))
            ->withPath('/api')->withSecure(! app()->isLocal())->withHttpOnly(true)->withSameSite('strict');

        return response()->noContent()->withCookie($cookie);
    }

    public function destroy()
    {
        return response()->noContent()->withCookie(Cookie::create(self::COOKIE)
            ->withExpires(1)->withPath('/api')->withSecure(! app()->isLocal())->withHttpOnly(true)->withSameSite('strict'));
    }
}
