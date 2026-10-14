<?php

namespace App\Lobby;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Lobbies in the cache: one JSON entry per boarding code, expiring when idle,
 * with a lock around every change so simultaneous taps don't collide.
 */
final class LobbyStore
{
    private function cache(): Repository
    {
        return Cache::store();
    }

    public function find(string $code): ?Lobby
    {
        $json = $this->cache()->get($this->key($code));
        if (! is_string($json)) {
            return null;
        }
        $data = json_decode($json, true);

        return is_array($data) ? Lobby::fromArray($data) : null;
    }

    public function exists(string $code): bool
    {
        return $this->cache()->has($this->key($code));
    }

    public function save(Lobby $lobby): void
    {
        $lobby->version++;
        $this->put($lobby);
    }

    /**
     * Keep an idle lobby alive without changing it.
     */
    public function touch(Lobby $lobby): void
    {
        $this->put($lobby);
    }

    public function forget(string $code): void
    {
        $this->cache()->forget($this->key($code));
    }

    /**
     * Run a change to one lobby while holding its lock.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function locked(string $code, Closure $callback): mixed
    {
        return Cache::lock('lobby-lock:'.$code, 10)->block(5, $callback);
    }

    public function newCode(): string
    {
        $alphabet = (string) config('last-stop.code_alphabet');
        $length = (int) config('last-stop.code_length');

        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while ($this->exists($code));

        return $code;
    }

    private function put(Lobby $lobby): void
    {
        $this->cache()->put(
            $this->key($lobby->code),
            json_encode($lobby->toArray(), JSON_THROW_ON_ERROR),
            now()->addMinutes((int) config('last-stop.lobby_idle_minutes')),
        );
    }

    private function key(string $code): string
    {
        return 'lobby:'.strtoupper($code);
    }
}
