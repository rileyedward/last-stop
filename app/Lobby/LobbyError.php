<?php

namespace App\Lobby;

use RuntimeException;

/**
 * Something a player tried that the lobby can't do. The message is shown as
 * is; the reason lets pages pick the right screen (for example "full").
 */
final class LobbyError extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'invalid')
    {
        parent::__construct($message);
    }
}
