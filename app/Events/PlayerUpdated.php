<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A nudge for one player only, such as "you were removed from the lobby".
 * Like the table ping it carries no secrets; the page reloads its own view.
 */
class PlayerUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public string $playerId,
        public string $reason,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('player.'.$this->playerId);
    }

    public function broadcastAs(): string
    {
        return 'updated';
    }

    /**
     * @return array{reason: string}
     */
    public function broadcastWith(): array
    {
        return ['reason' => $this->reason];
    }
}
