<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireApiAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.auth_disabled') || $request->is('api/auth/wechat') || $request->is('api/pay/wechat/notify')) {
            return $next($request);
        }

        return app(Authenticate::class)->handle($request, $next, 'sanctum');
    }
}
