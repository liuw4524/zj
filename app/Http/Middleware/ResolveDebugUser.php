<?php

namespace App\Http\Middleware;

use App\Support\DebugUsers;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ResolveDebugUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.auth_disabled') || $request->is('api/pay/wechat/notify')) {
            return $next($request);
        }

        $user = DebugUsers::resolve($request->header('X-Debug-User'));
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
