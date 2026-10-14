<?php

namespace App\Lobby;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Who is this browser? A random token in an httpOnly cookie, remembered for
 * a year. The player id shown to other browsers is a hash of the token, so
 * it can't be used to take over the seat.
 */
final class Identity
{
    public const TOKEN_COOKIE = 'ls_token';

    public const NAME_COOKIE = 'ls_name';

    public const LOBBY_COOKIE = 'ls_lobby';

    public const COOKIE_MINUTES = 60 * 24 * 365;

    public static function token(Request $request): ?string
    {
        $token = $request->cookie(self::TOKEN_COOKIE);

        return is_string($token) && strlen($token) >= 32 ? $token : null;
    }

    public static function newToken(): string
    {
        return Str::random(48);
    }

    public static function idFor(string $token): string
    {
        return substr(hash('sha256', $token), 0, 16);
    }

    public static function id(Request $request): ?string
    {
        $token = $request->attributes->get('ls_token');
        $token = is_string($token) ? $token : self::token($request);

        return $token === null ? null : self::idFor($token);
    }

    public static function name(Request $request): ?string
    {
        $name = $request->cookie(self::NAME_COOKIE);

        return is_string($name) && trim($name) !== '' ? self::cleanName($name) : null;
    }

    public static function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', strip_tags($name)));

        return mb_substr($name, 0, (int) config('last-stop.name_max', 16));
    }

    public static function guest(Request $request): ?Guest
    {
        $id = self::id($request);

        return $id === null ? null : new Guest($id, self::name($request) ?? 'Passenger');
    }
}
