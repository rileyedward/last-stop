<?php

namespace App\Jobs;

use App\Lobby\LobbyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Wakes up at a phase deadline and moves the game on. Safe to run any number
 * of times: the engine only advances a phase whose deadline has passed.
 */
class AdvanceGame implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $code) {}

    public function handle(LobbyService $lobbies): void
    {
        $lobbies->tick($this->code);
    }
}
