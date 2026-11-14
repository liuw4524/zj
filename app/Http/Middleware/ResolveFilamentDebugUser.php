<?php

namespace App\Http\Middleware;

use App\Support\DebugUsers;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ResolveFilamentDebugUser
{
    public function handle(Request $request, Closure $next): Response
    {
        Auth::login(DebugUsers::operator());

        return $next($request);
    }
}
