<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin pages need the session flag set by a good login.
 */
class EnsureAdmin
{
    public const SESSION_KEY = 'admin';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->get(self::SESSION_KEY) !== true) {
            return redirect()->route('admin.login');
        }

        return $next($request);
    }
}
