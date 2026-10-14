<?php

namespace App\Http\Middleware;

use App\Lobby\Identity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Give every browser a player token cookie on its first visit.
 */
class EnsurePlayerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = Identity::token($request);

        if ($token === null) {
            $token = Identity::newToken();
            Cookie::queue(Cookie::make(Identity::TOKEN_COOKIE, $token, Identity::COOKIE_MINUTES, httpOnly: true, sameSite: 'lax'));
        }

        $request->attributes->set('ls_token', $token);

        return $next($request);
    }
}
