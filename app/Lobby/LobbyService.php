<?php

namespace App\Lobby;

use App\Events\PlayerUpdated;
use App\Events\TableUpdated;
use App\Game\Data\GameData;
use App\Game\Engine\Engine;
use App\Game\Engine\Game;
use App\Jobs\AdvanceGame;
use Closure;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Everything players and hosts do to a lobby and its game. Each change runs
 * under the lobby's lock, catches the game up with the clock first, then
 * saves, pings the table channel and schedules the next deadline.
 */
final class LobbyService
{
    /** @var list<string> */
    private array $nudges = [];

    public function __construct(
        private readonly LobbyStore $store,
        private readonly Engine $engine,
        private readonly GameData $data,
    ) {}

    public function now(): int
    {
        return (int) now()->getTimestampMs();
    }

    public function find(string $code): ?Lobby
    {
        return $this->store->find(strtoupper($code));
    }

    // ------------------------------------------------------------ lobby

    public function create(string $hostId, string $name): Lobby
    {
        $code = $this->store->newCode();

        return $this->store->locked($code, function () use ($code, $hostId, $name) {
            $lobby = new Lobby($code);
            $lobby->hostId = $hostId;
            $lobby->seats[] = ['id' => $hostId, 'name' => $name, 'ready' => false];
            $this->store->save($lobby);

            return $lobby;
        });
    }

    public function join(string $code, string $id, string $name): Lobby
    {
        return $this->change($code, function (Lobby $lobby) use ($id, $name) {
            if ($lobby->isMember($id)) {
                return;
            }
            if ($lobby->nameTaken($name, $id)) {
                throw new LobbyError("Someone called {$name} is already aboard. Pick a different name for this lobby.", 'name_taken');
            }
            if ($lobby->headcount() >= $this->data->maxPlayers()) {
                throw new LobbyError("{$lobby->hostName()}'s train already has all {$this->data->maxPlayers()} passengers.", 'full');
            }

            $lobby->removed = array_values(array_diff($lobby->removed, [$id]));
            if ($lobby->gameInProgress()) {
                $lobby->platform[] = ['id' => $id, 'name' => $name];

                return;
            }
            $lobby->seats[] = ['id' => $id, 'name' => $name, 'ready' => false];
            $lobby->hostId ??= $id;
            $this->clearReady($lobby);
        });
    }

    public function leave(string $code, string $id): Lobby
    {
        return $this->change($code, function (Lobby $lobby, int $now) use ($id) {
            $this->removeFromLobby($lobby, $id, $now);
        });
    }

    public function setReady(string $code, string $id, bool $ready): Lobby
    {
        return $this->change($code, function (Lobby $lobby) use ($id, $ready) {
            if ($lobby->gameInProgress()) {
                throw new LobbyError('The train has already left.');
            }
            if (! $lobby->isSeated($id)) {
                throw new LobbyError("You don't have a seat in this lobby.");
            }
            foreach ($lobby->seats as $i => $seat) {
                if ($seat['id'] === $id) {
                    $lobby->seats[$i]['ready'] = $ready;
                }
            }
            $this->markBack($lobby, $id);
        });
    }

    public function setPreset(string $code, string $id, string $preset): Lobby
    {
        return $this->change($code, function (Lobby $lobby) use ($id, $preset) {
            $this->mustBeHost($lobby, $id);
            if ($lobby->gameInProgress()) {
                throw new LobbyError("Settings can't change mid-trip.");
            }
            if (! in_array($preset, GameData::PRESETS, true)) {
                throw new LobbyError('Pick Easy, Standard or Hard.');
            }
            if ($preset === $lobby->preset) {
                return;
            }
            $lobby->preset = $preset;
            $this->clearReady($lobby);
            $lobby->notice = $lobby->hostName().' changed the trip to '.ucfirst($preset).". Everyone's Ready was cleared.";
        });
    }

    public function start(string $code, string $id): Lobby
    {
        return $this->change($code, function (Lobby $lobby, int $now) use ($id) {
            $this->mustBeHost($lobby, $id);
            if ($lobby->gameInProgress()) {
                throw new LobbyError('The train has already left.');
            }
            $count = count($lobby->seats);
            if ($count < $this->data->minPlayers() || $count > $this->data->maxPlayers()) {
                throw new LobbyError("The train departs with {$this->data->minPlayers()} to {$this->data->maxPlayers()} passengers.");
            }
            if (! array_all($lobby->seats, fn (array $s) => $s['ready'])) {
                throw new LobbyError('Everyone has to be ready first.');
            }

            $setup = $this->data->rulesFor($count, $lobby->preset);
            $seats = array_map(fn (array $s) => ['id' => $s['id'], 'name' => $s['name']], $lobby->seats);
            $lobby->game = $this->engine->start($seats, $setup['rules'], $setup['departments'], $now);
            $lobby->recorded = false;
            $lobby->backInLobby = [];
            $lobby->notice = null;
        });
    }

    public function kick(string $code, string $hostId, string $target): Lobby
    {
        return $this->change($code, function (Lobby $lobby, int $now) use ($hostId, $target) {
            $this->mustBeHost($lobby, $hostId);
            if ($target === $hostId) {
                throw new LobbyError("You can't remove yourself. Hand over host first, then leave.");
            }
            if (! $lobby->isMember($target)) {
                throw new LobbyError("They aren't in this lobby.");
            }
            $this->removeFromLobby($lobby, $target, $now);
            $lobby->removed[] = $target;
            $this->nudges[] = $target;
        });
    }

    public function makeHost(string $code, string $hostId, string $target): Lobby
    {
        return $this->change($code, function (Lobby $lobby) use ($hostId, $target) {
            $this->mustBeHost($lobby, $hostId);
            if (! $lobby->isSeated($target)) {
                throw new LobbyError('Only a seated passenger can be host.');
            }
            $lobby->hostId = $target;
        });
    }

    public function resetWins(string $code, string $hostId): Lobby
    {
        return $this->change($code, function (Lobby $lobby) use ($hostId) {
            $this->mustBeHost($lobby, $hostId);
            $lobby->wins = [];
            $lobby->trips = 0;
        });
    }

    public function backToLobby(string $code, string $id): Lobby
    {
        return $this->change($code, function (Lobby $lobby) use ($id) {
            $this->markBack($lobby, $id);
        });
    }

    // ------------------------------------------------------------ game

    /**
     * @param  array<string, mixed>  $payload
     */
    public function act(string $code, string $id, string $action, array $payload): Lobby
    {
        return $this->change($code, function (Lobby $lobby, int $now) use ($id, $action, $payload) {
            if (! $lobby->gameInProgress() || $lobby->game === null) {
                throw new LobbyError('There is no trip in progress.');
            }
            $this->engine->act($lobby->game, $id, $action, $payload, $now);
        });
    }

    public function pause(string $code, string $hostId): Lobby
    {
        return $this->hostGameAction($code, $hostId, fn (Game $game, int $now) => $this->engine->pause($game, $now));
    }

    public function resume(string $code, string $hostId): Lobby
    {
        return $this->hostGameAction($code, $hostId, fn (Game $game, int $now) => $this->engine->resume($game, $now));
    }

    public function endGame(string $code, string $hostId): Lobby
    {
        return $this->hostGameAction($code, $hostId, fn (Game $game) => $this->engine->endEarly($game));
    }

    /**
     * Catch a game up with the clock. Called by the deadline job and on
     * every page load, so the game moves on even without a queue worker.
     */
    public function tick(string $code): ?Lobby
    {
        $code = strtoupper($code);
        $deadlineBefore = null;
        $lobby = $this->store->locked($code, function () use ($code, &$deadlineBefore) {
            $lobby = $this->store->find($code);
            if ($lobby === null || ! $lobby->gameInProgress() || $lobby->game === null) {
                return null;
            }
            $deadlineBefore = $lobby->game->deadline;
            $before = json_encode($lobby->game->toArray());
            $this->engine->tick($lobby->game, $this->now());
            if (json_encode($lobby->game->toArray()) === $before) {
                return null;
            }
            $this->afterGame($lobby);
            $this->store->save($lobby);

            return $lobby;
        });

        if ($lobby !== null) {
            $this->announce($lobby, $deadlineBefore);
        }

        return $lobby;
    }

    public function heartbeat(string $code): void
    {
        $code = strtoupper($code);
        $this->store->locked($code, function () use ($code) {
            $lobby = $this->store->find($code);
            if ($lobby !== null) {
                $this->store->touch($lobby);
            }
        });
    }

    // ------------------------------------------------------------ helpers

    /**
     * @param  Closure(Lobby, int): void  $change
     */
    private function change(string $code, Closure $change): Lobby
    {
        $code = strtoupper($code);
        $this->nudges = [];
        $deadlineBefore = null;

        $lobby = $this->store->locked($code, function () use ($code, $change, &$deadlineBefore) {
            $lobby = $this->store->find($code) ?? throw new LobbyError("No train at {$code}. Check the letters, or ask for the invite link.", 'not_found');
            $now = $this->now();
            $deadlineBefore = $lobby->game?->deadline;
            if ($lobby->gameInProgress() && $lobby->game !== null) {
                $this->engine->tick($lobby->game, $now);
            }
            $change($lobby, $now);
            $this->afterGame($lobby);
            $this->store->save($lobby);

            return $lobby;
        });

        $this->announce($lobby, $deadlineBefore);

        return $lobby;
    }

    /**
     * @param  Closure(Game, int): void  $action
     */
    private function hostGameAction(string $code, string $hostId, Closure $action): Lobby
    {
        return $this->change($code, function (Lobby $lobby, int $now) use ($hostId, $action) {
            $this->mustBeHost($lobby, $hostId);
            if (! $lobby->gameInProgress() || $lobby->game === null) {
                throw new LobbyError('There is no trip in progress.');
            }
            $action($lobby->game, $now);
        });
    }

    /**
     * When a trip ends: count the wins once, seat the people waiting on the
     * platform, and clear everyone's Ready.
     */
    private function afterGame(Lobby $lobby): void
    {
        if ($lobby->game === null || ! $lobby->game->isOver() || $lobby->recorded) {
            return;
        }
        $lobby->recorded = true;

        $game = $lobby->game;
        if ($game->winner !== null) {
            $lobby->trips++;
            foreach ($game->players as $player) {
                $row = $lobby->wins[$player->id] ?? ['name' => $player->name, 'crew' => 0, 'traitor' => 0];
                $row['name'] = $lobby->nameOf($player->id) ?? $player->name;
                if ($game->winner === 'crew' && ! $player->traitor) {
                    $row['crew']++;
                } elseif ($game->winner === 'traitor' && $player->traitor) {
                    $row['traitor']++;
                }
                $lobby->wins[$player->id] = $row;
            }
        }

        foreach ($lobby->platform as $person) {
            if (count($lobby->seats) < $this->data->maxPlayers()) {
                $lobby->seats[] = ['id' => $person['id'], 'name' => $person['name'], 'ready' => false];
            }
        }
        $lobby->platform = [];
        $lobby->hostId ??= $lobby->seats[0]['id'] ?? null;
        $this->clearReady($lobby);
    }

    private function removeFromLobby(Lobby $lobby, string $id, int $now): void
    {
        if ($lobby->gameInProgress() && $lobby->game !== null && $lobby->game->player($id) !== null) {
            $this->engine->markAbsent($lobby->game, $id, $now);
        }

        $lobby->seats = array_values(array_filter($lobby->seats, fn (array $s) => $s['id'] !== $id));
        $lobby->platform = array_values(array_filter($lobby->platform, fn (array $p) => $p['id'] !== $id));

        if ($lobby->hostId === $id) {
            $lobby->hostId = $lobby->seats[0]['id'] ?? null;
        }
    }

    private function markBack(Lobby $lobby, string $id): void
    {
        if (! in_array($id, $lobby->backInLobby, true)) {
            $lobby->backInLobby[] = $id;
        }
    }

    private function clearReady(Lobby $lobby): void
    {
        foreach ($lobby->seats as $i => $seat) {
            $lobby->seats[$i]['ready'] = false;
        }
    }

    private function mustBeHost(Lobby $lobby, string $id): void
    {
        if ($lobby->hostId !== $id) {
            throw new LobbyError('Only the host can do that.');
        }
    }

    private function announce(Lobby $lobby, ?int $deadlineBefore): void
    {
        // A ping that fails to send must never undo a move: pages also poll.
        try {
            broadcast(new TableUpdated($lobby->code, $lobby->version));
            foreach ($this->nudges as $id) {
                broadcast(new PlayerUpdated($id, 'removed'));
            }
        } catch (Throwable $e) {
            report($e);
        }
        $this->nudges = [];

        $deadline = $lobby->game?->deadline;
        if ($deadline !== null && $deadline !== $deadlineBefore && $lobby->gameInProgress()) {
            AdvanceGame::dispatch($lobby->code)->delay(Carbon::createFromTimestampMs($deadline + 250));
        }
    }
}
