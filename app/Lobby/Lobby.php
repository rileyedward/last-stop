<?php

namespace App\Lobby;

use App\Game\Engine\Game;
use App\Game\Engine\Reader;

/**
 * One lobby: a group of friends playing trip after trip. Lives in the cache
 * as JSON and disappears when it goes idle.
 */
final class Lobby
{
    public ?string $hostId = null;

    /**
     * Seated passengers in join order.
     *
     * @var list<array{id: string, name: string, ready: bool}>
     */
    public array $seats = [];

    /**
     * People who joined mid-trip, waiting for the next one.
     *
     * @var list<array{id: string, name: string}>
     */
    public array $platform = [];

    public string $preset = 'standard';

    /**
     * Wins per player id. Kept after someone leaves, until the lobby closes.
     *
     * @var array<string, array{name: string, crew: int, traitor: int}>
     */
    public array $wins = [];

    public int $trips = 0;

    public ?Game $game = null;

    /** Whether the finished game's wins have been counted. */
    public bool $recorded = false;

    /**
     * Players who have left the final screen for the lobby.
     *
     * @var list<string>
     */
    public array $backInLobby = [];

    /**
     * Players removed by the host, so their page can say so.
     *
     * @var list<string>
     */
    public array $removed = [];

    /** A one-line notice for everyone, such as "Ready was cleared". */
    public ?string $notice = null;

    public int $version = 0;

    public function __construct(public readonly string $code) {}

    public function gameInProgress(): bool
    {
        return $this->game !== null && ! $this->game->isOver();
    }

    /**
     * @return array{id: string, name: string, ready: bool}|null
     */
    public function seat(string $id): ?array
    {
        foreach ($this->seats as $seat) {
            if ($seat['id'] === $id) {
                return $seat;
            }
        }

        return null;
    }

    public function isSeated(string $id): bool
    {
        return $this->seat($id) !== null;
    }

    public function isOnPlatform(string $id): bool
    {
        return array_any($this->platform, fn (array $p) => $p['id'] === $id);
    }

    public function isMember(string $id): bool
    {
        return $this->isSeated($id) || $this->isOnPlatform($id);
    }

    public function headcount(): int
    {
        return count($this->seats) + count($this->platform);
    }

    public function nameTaken(string $name, string $exceptId): bool
    {
        $lower = mb_strtolower($name);
        foreach ([...$this->seats, ...$this->platform] as $person) {
            if ($person['id'] !== $exceptId && mb_strtolower($person['name']) === $lower) {
                return true;
            }
        }

        return false;
    }

    public function nameOf(string $id): ?string
    {
        foreach ([...$this->seats, ...$this->platform] as $person) {
            if ($person['id'] === $id) {
                return $person['name'];
            }
        }

        return $this->game?->player($id)->name ?? null;
    }

    public function hostName(): string
    {
        return $this->hostId !== null ? ($this->nameOf($this->hostId) ?? 'The host') : 'The host';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'hostId' => $this->hostId,
            'seats' => $this->seats,
            'platform' => $this->platform,
            'preset' => $this->preset,
            'wins' => $this->wins,
            'trips' => $this->trips,
            'game' => $this->game?->toArray(),
            'recorded' => $this->recorded,
            'backInLobby' => $this->backInLobby,
            'removed' => $this->removed,
            'notice' => $this->notice,
            'version' => $this->version,
        ];
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $r = new Reader($data);
        $lobby = new self($r->string('code'));
        $lobby->hostId = $r->nullableString('hostId');
        $lobby->seats = array_map(fn (array $s) => [
            'id' => (new Reader($s))->string('id'),
            'name' => (new Reader($s))->string('name'),
            'ready' => (new Reader($s))->bool('ready'),
        ], $r->maps('seats'));
        $lobby->platform = array_map(fn (array $s) => [
            'id' => (new Reader($s))->string('id'),
            'name' => (new Reader($s))->string('name'),
        ], $r->maps('platform'));
        $lobby->preset = $r->string('preset', 'standard');
        foreach ($r->map('wins') as $id => $row) {
            $w = new Reader(is_array($row) ? $row : []);
            $lobby->wins[$id] = ['name' => $w->string('name'), 'crew' => $w->int('crew'), 'traitor' => $w->int('traitor')];
        }
        $lobby->trips = $r->int('trips');
        $game = $r->map('game');
        $lobby->game = $game === [] ? null : Game::fromArray($game);
        $lobby->recorded = $r->bool('recorded');
        $lobby->backInLobby = $r->strings('backInLobby');
        $lobby->removed = $r->strings('removed');
        $lobby->notice = $r->nullableString('notice');
        $lobby->version = $r->int('version');

        return $lobby;
    }
}
