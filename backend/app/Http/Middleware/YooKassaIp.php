<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class YooKassaIp
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('neurogpt.yookassa.verify_ip') && ! IpUtils::checkIp((string) $request->ip(), config('neurogpt.yookassa.allowed_ips'))) {
            abort(403);
        }

        return $next($request);
    }
}
