<?php

namespace App\Lobby;

use App\Game\Data\Departments;
use App\Game\Data\GameData;
use App\Game\Data\SettingsSchema;
use App\Game\Engine\Engine;
use App\Game\Engine\Game;
use App\Game\Engine\Player;
use App\Game\Engine\Reader;

/**
 * What one browser is allowed to see. Everyone gets the public table; a
 * seated player also gets their own secrets, and nobody gets anyone else's.
 * Roles, hands and who pulled the cord go public only once the trip is over.
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
        $setup = $this->data->rulesFor($setupFor, $lobby->preset, $lobby->customRules());

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
            'custom' => $lobby->customRules(),
            'setup' => [
                'players' => $setupFor,
                'departments' => $setup['departments'],
                'rules' => $setup['rules'],
                'standard' => $this->data->rulesFor($setupFor)['rules'],
                'presetDays' => array_map(
                    fn (string $preset) => $this->data->rulesFor($setupFor, $preset)['rules']['days'],
                    array_combine(GameData::PRESETS, GameData::PRESETS),
                ),
            ],
            'fields' => SettingsSchema::forScreens(),
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
        $legs = $game->rule('legs');

        $departments = array_map(fn (string $key) => [
            'key' => $key,
            'name' => Departments::NAMES[$key],
            'code' => Departments::CODES[$key],
            'score' => $game->scores[$key] ?? 0,
            'atDawn' => $game->scoresAtDawn[$key] ?? ($game->scores[$key] ?? 0),
            'crisis' => $game->inCrisis($key),
            'atLine' => ($game->scores[$key] ?? 0) >= $game->line,
        ], $game->departments);

        $players = array_map(fn (Player $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'department' => $p->department,
            'ready' => $p->ready,
            'status' => $this->status($game, $p),
            'detained' => $p->detainedDays,
            'escortOf' => $p->escortOf,
            'escortedBy' => $game->escortFor($p)?->id,
            'cooldown' => $p->cooldown,
            'overridesLeft' => $p->overridesLeft,
            'absent' => $p->absent,
            'nominable' => $this->engine->canBeNominated($p),
            'canNominate' => $this->engine->canNominate($game, $p),
            'voted' => $game->phase === Game::VOTE && $p->vote !== null,
            'accused' => $game->phase === Game::ARRIVAL && $p->accuse !== null,
            'everDetained' => $p->everDetained,
            'isMe' => $p->id === $viewerId,
            'traitor' => $over ? $p->traitor : null,
        ], $game->players);

        $stations = [];
        for ($leg = 0; $leg <= $legs; $leg++) {
            $stations[] = ['name' => Engine::stationName($leg, $legs), 'line' => $game->lineFor(min($leg, $legs - 1))];
        }

        $rules = $game->rules;

        return [
            'phase' => $game->phase,
            'paused' => $game->isPaused(),
            'deadline' => $game->deadline,
            'remaining' => $game->pausedRemaining,
            'day' => $game->day,
            'days' => $rules['days'] ?? 0,
            'legs' => $legs,
            'leg' => $game->leg,
            'line' => $game->line,
            'stations' => $stations,
            'freeRun' => $game->freeRun,
            'cordLeft' => $game->cordLeft,
            'cordPulls' => $rules['cord_pulls'] ?? 0,
            'clueSize' => $rules['cord_clue'] ?? 0,
            'scoreMax' => $rules['score_max'] ?? 10,
            'crisisAt' => $rules['crisis_at'] ?? 0,
            'runCost' => $rules['run_cost'] ?? 0,
            'handSize' => $rules['hand_size'] ?? 0,
            'reducedHand' => $rules['reduced_hand'] ?? 0,
            'detainDays' => $rules['detain_days'] ?? 0,
            'weatherPerDay' => $rules['weather_per_day'] ?? 0,
            'traitorCount' => $rules['traitors'] ?? 0,
            'accusation' => ($rules['accusation'] ?? 1) === 1,
            'mustPull' => ($rules['traitor_must_pull'] ?? 1) === 1,
            'departments' => $departments,
            'weather' => array_map(fn (string $id) => $this->card($id), $game->weatherToday),
            'players' => $players,
            'dusk' => $this->dusk($game),
            'pulls' => array_map(fn (array $pull) => ['day' => $pull['day'], 'clue' => $pull['clue']], $game->pulls),
            'vote' => $game->vote === null ? null : [
                'nominator' => $game->vote['nominator'],
                'nominee' => $game->vote['nominee'],
            ],
            'nominationsOpen' => $this->engine->nominationsOpen($game),
            'lastVote' => $this->lastVote($game),
            'today' => $this->today($game),
            'log' => $game->log,
            'winner' => $over ? $game->winner : null,
            'cause' => $over ? $game->cause : null,
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
     * Today's departure check as everyone sees it: never who pulled.
     *
     * @return array<string, mixed>|null
     */
    private function dusk(Game $game): ?array
    {
        if ($game->dusk === null) {
            return null;
        }
        $d = new Reader($game->dusk);

        return [
            'green' => $d->bool('green'),
            'window' => $d->bool('window'),
            'line' => $d->int('line'),
            'scoresBefore' => $d->intMap('scoresBefore'),
            'outcome' => $d->nullableString('outcome'),
            'clue' => $d->strings('clue'),
            'free' => $d->bool('free'),
            'arrived' => $d->bool('arrived'),
            'legBefore' => $d->int('legBefore'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function me(Game $game, Player $me): array
    {
        $escorting = null;
        $target = $me->escortOf !== null ? $game->player($me->escortOf) : null;
        if ($target !== null && $target->isDetained()) {
            $escorting = [
                'id' => $target->id,
                'name' => $target->name,
                'department' => $target->department,
                'hand' => array_map(fn (string $id) => $this->card($id), $target->hand),
                'pick' => $target->pick,
                'pickState' => $target->pickState,
                'decision' => $target->escortDecision,
                'vetoed' => $target->vetoed,
                'vetoesLeft' => $me->vetoesLeft,
                'overridesLeft' => $target->overridesLeft,
                'dayOfDetention' => $game->rule('detain_days') - $target->detainedDays + 1,
            ];
        }

        $partners = $me->traitor
            ? array_values(array_map(fn (Player $p) => $p->name, array_filter($game->traitors(), fn (Player $p) => $p->id !== $me->id)))
            : [];
        $teammates = array_values(array_map(
            fn (Player $p) => $p->name,
            array_filter($game->players, fn (Player $p) => $p->id !== $me->id && $p->department === $me->department),
        ));

        // Only traitors learn that the cord was pulled before the window closes.
        $pulledBy = $me->traitor && $game->phase === Game::DUSK ? ($game->dusk['pulledBy'] ?? null) : null;
        $myPulls = $me->traitor ? count(array_filter($game->pulls, fn (array $p) => $p['by'] === $me->id)) : 0;

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
            'canPull' => $game->phase === Game::DUSK && $this->engine->canPull($game, $me),
            'cordPulled' => $pulledBy !== null,
            'pulledByMe' => $pulledBy === $me->id,
            'traitorsPulled' => $me->traitor ? count($game->pulls) : null,
            'myPulls' => $myPulls,
            'ready' => $me->ready,
            'vote' => $me->vote,
            'accuse' => $me->accuse,
            'detained' => $me->detainedDays,
            'escortedBy' => $game->escortFor($me)?->name,
            'escorting' => $escorting,
            'canNominate' => $game->phase === Game::NIGHT && $this->engine->nominationsOpen($game) && $this->engine->canNominate($game, $me),
            'nominatedTonight' => in_array($me->id, $game->nominatedTonight, true),
        ];
    }

    /**
     * Tonight's latest settled detention vote.
     *
     * @return array<string, mixed>|null
     */
    private function lastVote(Game $game): ?array
    {
        if (! in_array($game->phase, [Game::NIGHT, Game::VOTE], true)) {
            return null;
        }
        $last = end($game->history);
        $noms = is_array($last) ? ($last['nominations'] ?? []) : [];
        if (! is_array($noms) || $noms === []) {
            return null;
        }
        $vote = new Reader(is_array(end($noms)) ? end($noms) : []);

        return [
            'nominator' => $vote->string('nominator'),
            'nominee' => $vote->string('nominee'),
            'votes' => $vote->boolMap('votes'),
            'yes' => $vote->int('yes'),
            'no' => $vote->int('no'),
            'passed' => $vote->bool('passed'),
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
                'weather' => array_map(fn (string $id) => $this->card($id), $day->strings('weather')),
                'scoresBefore' => $day->intMap('scoresBefore'),
                'scoresAfterWeather' => $day->intMap('scoresAfterWeather'),
                'scoresAfter' => $day->intMap('scoresAfter'),
                'picks' => array_map(function (array $pick) {
                    $p = new Reader($pick);

                    return [
                        'player' => $p->string('player'),
                        'card' => $p->nullableString('pick') !== null ? $this->card($p->string('pick')) : null,
                        'override' => $p->bool('override'),
                        'auto' => $p->bool('auto'),
                        'vetoed' => $p->strings('vetoed') !== [],
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

        $days = array_map(function (array $record) {
            $day = new Reader($record);
            $dusk = $day->reader('dusk');
            $arrival = $day->reader('arrival');

            return [
                'day' => $day->int('day'),
                'weather' => array_map(fn (string $id) => $this->card($id), $day->strings('weather')),
                'scoresAfter' => $day->intMap('scoresAfter'),
                'picks' => array_map(function (array $pick) {
                    $p = new Reader($pick);

                    return [
                        'player' => $p->string('player'),
                        'hand' => array_map(fn (string $id) => $this->card($id), $p->strings('hand')),
                        'pick' => $p->nullableString('pick'),
                        'vetoed' => $p->strings('vetoed'),
                        'override' => $p->bool('override'),
                        'auto' => $p->bool('auto'),
                        'escort' => $p->nullableString('escort'),
                    ];
                }, $day->maps('picks')),
                'dusk' => $day->has('dusk') ? [
                    'green' => $dusk->bool('green'),
                    'outcome' => $dusk->string('outcome'),
                    'puller' => $dusk->nullableString('puller'),
                    'clue' => $dusk->strings('clue'),
                    'free' => $dusk->bool('free'),
                    'legAfter' => $dusk->int('legAfter'),
                    'scoresAfter' => $dusk->intMap('scoresAfter'),
                ] : null,
                'nominations' => array_map(function (array $n) {
                    $v = new Reader($n);

                    return [
                        'nominator' => $v->string('nominator'),
                        'nominee' => $v->string('nominee'),
                        'yes' => $v->int('yes'),
                        'no' => $v->int('no'),
                        'passed' => $v->bool('passed'),
                    ];
                }, $day->maps('nominations')),
                'arrival' => $day->has('arrival') ? [
                    'tally' => $arrival->intMap('tally'),
                    'top' => $arrival->nullableString('top'),
                    'tie' => $arrival->bool('tie'),
                ] : null,
            ];
        }, $game->history);

        return [
            'traitors' => $traitors,
            'pulls' => $game->pulls,
            'accusations' => array_values(array_map(fn (Player $p) => ['id' => $p->id, 'suspect' => $p->accuse], array_filter(
                $game->players,
                fn (Player $p) => $p->accuse !== null,
            ))),
            'days' => $days,
            'everDetained' => array_values(array_map(fn (Player $p) => $p->id, array_filter($game->players, fn (Player $p) => $p->everDetained))),
            'leg' => $game->leg,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function card(string $id): array
    {
        return $this->data->card($id)->toArray();
    }
}
