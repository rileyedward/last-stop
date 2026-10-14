<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something changed in a lobby. Carries no game data at all: each browser
 * reloads its own view over HTTP, so nothing secret ever goes out on a
 * channel. The station board listens to this channel too.
 */
class TableUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public string $code,
        public int $version,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('table.'.$this->code);
    }

    public function broadcastAs(): string
    {
        return 'updated';
    }

    /**
     * @return array{version: int}
     */
    public function broadcastWith(): array
    {
        return ['version' => $this->version];
    }
}
