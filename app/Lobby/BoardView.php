<?php

namespace App\Lobby;

use App\Game\Data\Card;
use App\Game\Data\Departments;
use App\Game\Data\GameData;
use App\Game\Engine\Engine;
use App\Game\Engine\Game;
use App\Game\Engine\Player;
use App\Game\Engine\Reader;

/**
 * What one browser is allowed to see. Everyone gets the public table; a
 * seated player also gets their own secrets, and nobody gets anyone else's.
 * Hands and roles go public only once the trip is over.
 */
final class BoardView
{
    public function __construct(
        private readonly GameData $data,
        private readonly Engine $engine,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forPlayer(Lobby $lobby, string $viewerId, ?string $viewerName, int $now): array
    {
        $membership = match (true) {
            $lobby->isSeated($viewerId) => 'seated',
            $lobby->isOnPlatform($viewerId) => 'platform',
            in_array($viewerId, $lobby->removed, true) => 'removed',
            default => 'visitor',
        };

        $inGame = $lobby->game?->player($viewerId) !== null && $membership === 'seated';
        $game = $lobby->game;

        $screen = match (true) {
            $membership === 'removed' => 'removed',
            $membership === 'visitor' => $lobby->headcount() >= $this->data->maxPlayers() ? 'full' : 'join',
            $membership === 'platform' => 'platform',
            $game !== null && ! $game->isOver() && $inGame => 'game',
            $game !== null && ! $game->isOver() => 'platform',
            $game !== null && $game->isOver() && $inGame && ! in_array($viewerId, $lobby->backInLobby, true) => 'final',
            default => 'lobby',
        };

        return [
            'serverNow' => $now,
            'me' => ['id' => $viewerId, 'name' => $viewerName],
            'membership' => $membership,
            'screen' => $screen,
            'lobby' => $this->lobby($lobby, $viewerId),
            'game' => $game === null ? null : $this->game($game, $inGame ? $viewerId : null),
        ];
    }

    /**
     * The station board: the public table only.
     *
     * @return array<string, mixed>
     */
    public function forStation(Lobby $lobby, int $now): array
    {
        return [
            'serverNow' => $now,
            'lobby' => $this->lobby($lobby, null),
            'game' => $lobby->game === null ? null : $this->game($lobby->game, null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lobby(Lobby $lobby, ?string $viewerId): array
    {
        $count = count($lobby->seats);
        $setupFor = max($this->data->minPlayers(), min($this->data->maxPlayers(), $count));
        $setup = $this->data->rulesFor($setupFor, $lobby->preset)['rules'];

        $wins = [];
        foreach ($lobby->wins as $id => $row) {
            $wins[] = [
                'id' => $id,
                'name' => $row['name'],
                'crew' => $row['crew'],
                'traitor' => $row['traitor'],
                'total' => $row['crew'] + $row['traitor'],
                'isMe' => $id === $viewerId,
            ];
        }
        usort($wins, fn ($a, $b) => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);

        $readyCount = count(array_filter($lobby->seats, fn (array $s) => $s['ready']));

        return [
            'code' => $lobby->code,
            'version' => $lobby->version,
            'hostId' => $lobby->hostId,
            'hostName' => $lobby->hostName(),
            'isHost' => $viewerId !== null && $lobby->hostId === $viewerId,
            'seats' => array_map(fn (array $s) => [
                ...$s,
                'isHost' => $s['id'] === $lobby->hostId,
                'isMe' => $s['id'] === $viewerId,
            ], $lobby->seats),
            'platform' => array_map(fn (array $p) => [...$p, 'isMe' => $p['id'] === $viewerId], $lobby->platform),
            'preset' => $lobby->preset,
            'setup' => [
                'players' => $setupFor,
                'route' => $setup['route'],
                'days' => $setup['days'],
                'weather' => $setup['weather_per_day'],
                'traitors' => $setup['traitors'],
                'forecast' => $setup['traitor_forecast'] === 1,
                'brakeFrom' => $setup['brake_from_night'],
                'brakesPerPlayer' => $setup['brakes_per_player'],
                'brakesPerGame' => $setup['brakes_per_game'],
                'restrictDays' => $setup['restrict_days'],
                'startScore' => $setup['start_score'],
                'crisisAt' => $setup['crisis_at'],
                'daySeconds' => $setup['day_seconds'],
                'nightSeconds' => $setup['night_seconds'],
            ],
            'readyCount' => $readyCount,
            'canStart' => ! $lobby->gameInProgress()
                && $count >= $this->data->minPlayers() && $count <= $this->data->maxPlayers()
                && $readyCount === $count,
            'minPlayers' => $this->data->minPlayers(),
            'maxPlayers' => $this->data->maxPlayers(),
            'wins' => $wins,
            'trips' => $lobby->trips,
            'notice' => $lobby->notice,
            'inProgress' => $lobby->gameInProgress(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function game(Game $game, ?string $viewerId): array
    {
        $me = $viewerId !== null ? $game->player($viewerId) : null;
        $over = $game->isOver();

        $departments = array_map(fn (string $key) => [
            'key' => $key,
            'name' => Departments::NAMES[$key],
            'code' => Departments::CODES[$key],
            'score' => $game->scores[$key] ?? 0,
            'atDawn' => $game->scoresAtDawn[$key] ?? ($game->scores[$key] ?? 0),
            'crisis' => $game->inCrisis($key),
        ], $game->departments);

        $players = array_map(fn (Player $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'department' => $p->department,
            'ready' => $p->ready,
            'status' => $this->status($game, $p),
            'restricted' => $p->restrictedDays,
            'escortOf' => $p->escortOf,
            'escortedBy' => $game->escortFor($p)?->id,
            'cooldown' => $p->cooldown,
            'brakesLeft' => $p->brakesLeft,
            'overridesLeft' => $p->overridesLeft,
            'absent' => $p->absent,
            'nominable' => $this->engine->canBeNominated($p),
            'canPull' => $this->engine->canPull($p),
            'voted' => $game->phase === Game::VOTE && $p->vote !== null,
            'guessed' => $game->phase === Game::INQUIRY && $p->guess !== null,
            'isMe' => $p->id === $viewerId,
            'traitor' => $over ? $p->traitor : null,
        ], $game->players);

        $rules = $game->rules;

        return [
            'phase' => $game->phase,
            'paused' => $game->isPaused(),
            'deadline' => $game->deadline,
            'remaining' => $game->pausedRemaining,
            'day' => $game->day,
            'days' => $rules['days'] ?? 0,
            'route' => $rules['route'] ?? 0,
            'distance' => $game->distance,
            'baseToday' => $game->baseToday,
            'slowDays' => $game->slowDays,
            'weatherPerDay' => $rules['weather_per_day'] ?? 0,
            'crisisAt' => $rules['crisis_at'] ?? 0,
            'handSize' => $rules['hand_size'] ?? 0,
            'reducedHand' => $rules['reduced_hand'] ?? 0,
            'restrictDays' => $rules['restrict_days'] ?? 0,
            'traitorCount' => $rules['traitors'] ?? 0,
            'departments' => $departments,
            'weather' => array_map(fn (string $id) => $this->card($id), $game->weatherToday),
            'weatherDistance' => $game->weatherDistance,
            'players' => $players,
            'brake' => [
                'open' => $game->brakeOpen,
                'used' => $game->brakeUsedTonight,
                'trainLeft' => $game->trainBrakesLeft,
                'perGame' => $rules['brakes_per_game'] ?? 0,
                'fromNight' => $rules['brake_from_night'] ?? 0,
            ],
            'vote' => $game->vote === null ? null : [
                'puller' => $game->vote['puller'],
                'nominee' => $game->vote['nominee'],
            ],
            'lastBrake' => $this->lastBrake($game),
            'today' => $this->today($game),
            'log' => $game->log,
            'winner' => in_array($game->phase, [Game::REVEAL, Game::INQUIRY, Game::FINISHED], true) ? $game->winner : null,
            'cause' => in_array($game->phase, [Game::REVEAL, Game::INQUIRY, Game::FINISHED], true) ? $game->cause : null,
            'me' => $me === null ? null : $this->me($game, $me),
            'reveal' => $over ? $this->reveal($game) : null,
        ];
    }

    private function status(Game $game, Player $p): string
    {
        if ($game->phase !== Game::DAY) {
            return 'idle';
        }

        return match ($p->pickState) {
            Player::PICK_LOCKED, Player::PICK_FINAL => 'picked',
            Player::PICK_PENDING => 'escort',
            Player::PICK_REPICK => 'vetoed',
            default => 'thinking',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function me(Game $game, Player $me): array
    {
        $escorting = null;
        $target = $me->escortOf !== null ? $game->player($me->escortOf) : null;
        if ($target !== null && $target->isRestricted()) {
            $escorting = [
                'id' => $target->id,
                'name' => $target->name,
                'department' => $target->department,
                'hand' => array_map(fn (string $id) => $this->card($id), $target->hand),
                'pick' => $target->pick,
                'pickState' => $target->pickState,
                'decision' => $target->escortDecision,
                'vetoed' => $target->vetoed,
                'overridesLeft' => $target->overridesLeft,
                'dayOfRestriction' => $game->rule('restrict_days') - $target->restrictedDays + 1,
            ];
        }

        $partners = $me->traitor
            ? array_values(array_map(fn (Player $p) => $p->name, array_filter($game->traitors(), fn (Player $p) => $p->id !== $me->id)))
            : [];
        $teammates = array_values(array_map(
            fn (Player $p) => $p->name,
            array_filter($game->players, fn (Player $p) => $p->id !== $me->id && $p->department === $me->department),
        ));

        $forecast = $me->traitor && in_array($game->phase, [Game::NIGHT, Game::VOTE], true) && $game->forecast !== null
            ? $this->card($game->forecast)
            : null;

        return [
            'id' => $me->id,
            'department' => $me->department,
            'traitor' => $me->traitor,
            'partners' => $partners,
            'teammates' => $teammates,
            'hand' => array_map(fn (string $id) => $this->card($id), $me->hand),
            'pick' => $me->pick,
            'pickState' => $me->pickState,
            'vetoed' => $me->vetoed,
            'autoPicked' => $me->autoPicked,
            'overridesLeft' => $me->overridesLeft,
            'brakesLeft' => $me->brakesLeft,
            'canPull' => $this->engine->canPull($me),
            'ready' => $me->ready,
            'vote' => $me->vote,
            'guess' => $me->guess,
            'restricted' => $me->restrictedDays,
            'escortedBy' => $game->escortFor($me)?->name,
            'escorting' => $escorting,
            'forecast' => $forecast,
            'forecastOn' => ($game->rules['traitor_forecast'] ?? 0) === 1,
        ];
    }

    /**
     * The most recent brake pull, once its vote is settled.
     *
     * @return array<string, mixed>|null
     */
    private function lastBrake(Game $game): ?array
    {
        $last = end($game->history);
        if ($last === false || ! isset($last['brake']) || ! is_array($last['brake']) || ! array_key_exists('passed', $last['brake'])) {
            return null;
        }
        $brake = new Reader($last['brake']);

        return [
            'day' => (new Reader($last))->int('day'),
            'puller' => $brake->string('puller'),
            'nominee' => $brake->string('nominee'),
            'votes' => $brake->boolMap('votes'),
            'yes' => $brake->int('yes'),
            'no' => $brake->int('no'),
            'passed' => $brake->bool('passed'),
        ];
    }

    /**
     * The last resolved day, as the table saw it at the reveal.
     *
     * @return array<string, mixed>|null
     */
    private function today(Game $game): ?array
    {
        for ($i = count($game->history) - 1; $i >= 0; $i--) {
            $day = new Reader($game->history[$i]);
            if (! $day->has('picks')) {
                continue;
            }

            return [
                'day' => $day->int('day'),
                'base' => $day->int('base'),
                'weather' => array_map(fn (string $id) => $this->card($id), $day->strings('weather')),
                'weatherDistance' => $day->int('weatherDistance'),
                'cardDistance' => $day->int('cardDistance'),
                'dayDistance' => $day->int('dayDistance'),
                'scoresBefore' => $day->intMap('scoresBefore'),
                'scoresAfterWeather' => $day->intMap('scoresAfterWeather'),
                'scoresAfter' => $day->intMap('scoresAfter'),
                'distanceBefore' => $day->int('distanceBefore'),
                'distanceAfter' => $day->int('distanceAfter'),
                'picks' => array_map(function (array $pick) {
                    $p = new Reader($pick);

                    return [
                        'player' => $p->string('player'),
                        'card' => $p->nullableString('pick') !== null ? $this->card($p->string('pick')) : null,
                        'override' => $p->bool('override'),
                        'auto' => $p->bool('auto'),
                        'vetoed' => $p->nullableString('vetoed') !== null,
                    ];
                }, $day->maps('picks')),
            ];
        }

        return null;
    }

    /**
     * Everything, for the end of the trip.
     *
     * @return array<string, mixed>
     */
    private function reveal(Game $game): array
    {
        $traitors = array_map(fn (Player $p) => $p->id, $game->traitors());

        $guesses = [];
        foreach ($game->players as $p) {
            if (! $p->traitor) {
                $guesses[] = [
                    'id' => $p->id,
                    'suspect' => $p->guess,
                    'correct' => $p->guess !== null && in_array($p->guess, $traitors, true),
                ];
            }
        }

        $days = array_map(function (array $record) {
            $day = new Reader($record);
            $brake = $day->reader('brake');

            return [
                'day' => $day->int('day'),
                'weather' => array_map(fn (string $id) => $this->card($id), $day->strings('weather')),
                'dayDistance' => $day->int('dayDistance'),
                'scoresAfter' => $day->intMap('scoresAfter'),
                'distanceAfter' => $day->int('distanceAfter'),
                'picks' => array_map(function (array $pick) {
                    $p = new Reader($pick);

                    return [
                        'player' => $p->string('player'),
                        'hand' => array_map(fn (string $id) => $this->card($id), $p->strings('hand')),
                        'pick' => $p->nullableString('pick'),
                        'vetoed' => $p->nullableString('vetoed'),
                        'override' => $p->bool('override'),
                        'auto' => $p->bool('auto'),
                    ];
                }, $day->maps('picks')),
                'brake' => $day->has('brake') ? [
                    'puller' => $brake->string('puller'),
                    'nominee' => $brake->string('nominee'),
                    'passed' => $brake->bool('passed'),
                    'yes' => $brake->int('yes'),
                    'no' => $brake->int('no'),
                ] : null,
            ];
        }, $game->history);

        return [
            'traitors' => $traitors,
            'guesses' => $guesses,
            'days' => $days,
            'brakes' => count(array_filter($days, fn (array $d) => $d['brake'] !== null)),
            'everRestricted' => array_values(array_map(fn (Player $p) => $p->id, array_filter($game->players, fn (Player $p) => $p->everRestricted))),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function card(string $id): array
    {
        return $this->cardData($this->data->card($id));
    }

    /**
     * @return array<string, mixed>
     */
    private function cardData(Card $card): array
    {
        return $card->toArray();
    }
}
