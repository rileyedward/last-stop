<?php

use App\Lobby\Guest;
use App\Lobby\LobbyStore;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// One player's own nudges. Only the browser holding that player's token.
Broadcast::channel('player.{id}', function ($user, string $id) {
    return $user instanceof Guest && $user->id === $id;
}, ['guards' => ['player']]);

// Who is connected right now, for the online dots in the lobby.
Broadcast::channel('lobby.{code}', function ($user, string $code) {
    if (! $user instanceof Guest) {
        return false;
    }
    $lobby = app(LobbyStore::class)->find($code);
    if ($lobby === null || ! $lobby->isMember($user->id)) {
        return false;
    }

    return ['id' => $user->id, 'name' => $lobby->nameOf($user->id) ?? $user->name];
}, ['guards' => ['player']]);
