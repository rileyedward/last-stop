<?php

use App\Game\Data\GameData;
use App\Game\Engine\Engine;
use App\Game\Engine\Game;
use App\Game\Engine\Player;
use App\Game\Engine\RuleViolation;
use Random\Engine\Mt19937;
use Random\Randomizer;

function gameData(): GameData
{
    static $data;

    return $data ??= GameData::load(__DIR__.'/../../../resources/game');
}

function engine(int $seed = 1): Engine
{
    return new Engine(gameData(), new Randomizer(new Mt19937($seed)));
}

/**
 * A seeded game for $n players named A, B, C... with ids a, b, c...
 *
 * @param  array<string, int>  $rules  Overrides for the rule numbers.
 */
function newGame(Engine $engine, int $n = 5, array $rules = [], array $fixed = []): Game
{
    $setup = gameData()->rulesFor($n);
    $seats = [];
    for ($i = 0; $i < $n; $i++) {
        $seats[] = ['id' => chr(97 + $i), 'name' => chr(65 + $i)];
    }

    return $engine->start($seats, [...$setup['rules'], ...$rules], $setup['departments'], 0, $fixed);
}

/** A quiet game: no weather, 'a' is the traitor. */
function quietGame(Engine $engine, int $n = 5, array $rules = [], array $traitors = ['a']): Game
{
    return newGame($engine, $n, ['weather_per_day' => 0, ...$rules], ['traitors' => $traitors]);
}

function everyoneReady(Engine $engine, Game $game, int $now = 0): void
{
    foreach ($game->present() as $player) {
        $engine->act($game, $player->id, 'ready', [], $now);
    }
}

function everyonePicks(Engine $engine, Game $game, int $now = 0): void
{
    foreach ($game->players as $player) {
        if ($player->pickState === Player::PICK_NONE && $player->hand !== []) {
            $engine->act($game, $player->id, 'pick', ['card' => $player->hand[0]], $now);
        }
    }
    foreach ($game->players as $player) {
        if ($player->pickState === Player::PICK_PENDING) {
            $engine->act($game, $game->escortFor($player)->id, 'escort', ['decision' => 'allow'], $now);
        }
    }
}

/** Play on until the game reaches $phase: picks, ready, or the clock. */
function advanceTo(Engine $engine, Game $game, string $phase, int &$now = 0): void
{
    $guard = 0;
    while ($game->phase !== $phase && ! $game->isOver() && $guard++ < 60) {
        if ($game->phase === Game::DAY) {
            everyonePicks($engine, $game, $now);
        } elseif (in_array($game->phase, [Game::DUSK, Game::VOTE, Game::ARRIVAL], true)) {
            $now = (int) $game->deadline;
            $engine->tick($game, $now);
        } else {
            everyoneReady($engine, $game, $now);
        }
    }
    expect($game->phase)->toBe($phase);
}

/** Every department at $score. */
function setScores(Game $game, int $score): void
{
    foreach ($game->departments as $dept) {
        $game->scores[$dept] = $score;
    }
}

/** From the reveal, with every department at $score, to the end of dusk. */
function duskWith(Engine $engine, Game $game, int $score, int &$now = 0, ?string $puller = null): void
{
    advanceTo($engine, $game, Game::REVEAL, $now);
    setScores($game, $score);
    everyoneReady($engine, $game, $now);
    if ($puller !== null) {
        expect($game->phase)->toBe(Game::DUSK);
        $engine->act($game, $puller, 'cord', [], $now);
    }
    advanceTo($engine, $game, Game::DEPARTURE, $now);
}

/** Nominate and vote: every listed voter says yes, everyone else no. */
function detain(Engine $engine, Game $game, string $nominator, string $nominee, ?array $yes = null, int $now = 0): void
{
    $engine->act($game, $nominator, 'nominate', ['nominee' => $nominee], $now);
    foreach ($game->players as $player) {
        if ($player->id !== $nominator && $player->id !== $nominee) {
            $engine->act($game, $player->id, 'vote', ['yes' => $yes === null || in_array($player->id, $yes, true)], $now);
        }
    }
}

// ------------------------------------------------------------ data

it('loads every rule number from the CSVs', function () {
    $data = gameData();

    expect($data->settings['score_max'])->toBe(10)
        ->and($data->settings['cord_pulls'])->toBe(2)
        ->and($data->settings['detain_days'])->toBe(2)
        ->and($data->settings['day_seconds'])->toBe(60)
        ->and($data->settings['dusk_seconds'])->toBe(15)
        ->and($data->settings['night_seconds'])->toBe(120)
        ->and($data->settings['arrival_seconds'])->toBe(90)
        ->and($data->tasks)->toHaveCount(4)
        ->and($data->tasks['engineering'])->toHaveCount(10)
        ->and($data->weather)->toHaveCount(12)
        ->and($data->minPlayers())->toBe(4)
        ->and($data->maxPlayers())->toBe(10);
});

it('reads card kinds and the weather special', function () {
    $data = gameData();
    $kinds = array_count_values(array_map(fn ($c) => $c->kind, $data->tasks['operations']));
    $tailwind = collect($data->weather)->firstWhere('name', 'Tailwind');

    expect($kinds)->toEqual(['routine' => 2, 'build' => 3, 'push' => 3, 'support' => 2])
        ->and($tailwind->special)->toBe('free_run')
        ->and(collect($data->weather)->firstWhere('name', 'Blizzard')->special)->toBe('');
});

it('looks up each player count', function () {
    $four = gameData()->rulesFor(4);
    $five = gameData()->rulesFor(5);
    $ten = gameData()->rulesFor(10);

    expect($four['departments'])->toBe(['engineering', 'operations', 'passenger_services'])
        ->and($four['rules'])->toMatchArray(['traitors' => 1, 'legs' => 5, 'days' => 10, 'weather_per_day' => 3, 'start_score' => 4, 'line_start' => 5, 'cord_clue' => 3])
        ->and($five['rules'])->toMatchArray(['days' => 11, 'cord_clue' => 4])
        ->and($ten['rules'])->toMatchArray(['traitors' => 2, 'weather_per_day' => 8, 'days' => 11, 'cord_clue' => 3]);
});

it('shifts the days for Easy and Hard and applies custom values', function () {
    expect(gameData()->rulesFor(6, 'easy')['rules']['days'])->toBe(11)
        ->and(gameData()->rulesFor(6, 'hard')['rules']['days'])->toBe(9)
        ->and(gameData()->rulesFor(6, 'standard', ['days' => 14, 'run_cost' => 2, 'nonsense' => 5])['rules'])
        ->toMatchArray(['days' => 14, 'run_cost' => 2])
        ->not->toHaveKey('nonsense');
});

it('rejects game data that is missing something', function (string $source, array $rows, string $message) {
    $all = gameData()->rows;
    $all[$source] = $rows;

    expect(fn () => GameData::fromRows($all))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a setting' => ['settings', [['setting' => 'score_max', 'value' => '10']], 'Game setting [crisis_at]'],
    'a department' => ['tasks', [['department' => 'Kitchen', 'card' => 'Soup']], 'unknown department [Kitchen]'],
    'a number' => ['players', [['players' => '5', 'departments' => 'Engineering', 'traitors' => 'one']], 'traitors must be a whole number'],
    'a special' => ['weather', [['card' => 'Fog', 'special' => 'teleport']], 'unknown special [teleport]'],
]);

it('exports a source as CSV that loads back the same', function () {
    $rows = GameData::parseCsv(gameData()->csv('tasks'));

    expect($rows)->toHaveCount(40)
        ->and($rows[0])->toMatchArray(['department' => 'Engineering', 'card' => 'Routine maintenance', 'kind' => 'routine', 'engineering' => '1']);
});

// ------------------------------------------------------------ setup

it('deals departments evenly and the right number of traitors', function (int $n) {
    $game = newGame(engine($n), $n);
    $counts = array_count_values(array_map(fn (Player $p) => $p->department, $game->players));

    expect($game->players)->toHaveCount($n)
        ->and(count($game->traitors()))->toBe($n >= 9 ? 2 : 1)
        ->and(max($counts) - min($counts))->toBeLessThanOrEqual(1)
        ->and(array_keys($counts))->toEqualCanonicalizing($game->departments);
})->with([4, 5, 6, 7, 8, 9, 10]);

it('starts snowed in at the starting score and line', function () {
    $game = newGame(engine(), 5);

    expect($game->scores)->toBe(['engineering' => 4, 'operations' => 4, 'passenger_services' => 4, 'security' => 4])
        ->and($game->leg)->toBe(0)
        ->and($game->line)->toBe(5)
        ->and($game->cordLeft)->toBe(2)
        ->and($game->phase)->toBe(Game::BOARDING);
});

// ------------------------------------------------------------ dawn and day

it('draws the table\'s weather each dawn and notes Tailwind', function () {
    $data = gameData();
    $ids = collect($data->weather)->mapWithKeys(fn ($c) => [$c->name => $c->id]);
    $engine = engine();
    $game = newGame($engine, 5, [], ['weather' => [$ids['Blizzard'], $ids['Tailwind'], $ids['Frozen switches']]]);

    everyoneReady($engine, $game);

    expect($game->phase)->toBe(Game::DAWN)
        ->and($game->weatherToday)->toBe([$ids['Frozen switches'], $ids['Tailwind'], $ids['Blizzard']])
        ->and($game->scores)->toBe(['engineering' => 1, 'operations' => 3, 'passenger_services' => 3, 'security' => 4])
        ->and($game->freeRun)->toBeTrue()
        ->and(collect($game->log)->pluck('kind'))->toContain('tailwind');
});

it('reshuffles the weather deck without repeating a card in one dawn', function () {
    $engine = engine(3);
    $game = newGame($engine, 10, ['days' => 30]);
    $now = 0;

    for ($day = 1; $day <= 4; $day++) {
        advanceTo($engine, $game, Game::DAWN, $now);
        expect($game->weatherToday)->toHaveCount(8)
            ->and(array_unique($game->weatherToday))->toHaveCount(8);
        if ($day < 4) {
            duskWith($engine, $game, 2, $now);
        }
    }
});

it('deals hands, reduced for crisis departments', function () {
    $engine = engine();
    $game = quietGame($engine);
    $game->scores['engineering'] = 2;
    advanceTo($engine, $game, Game::DAY);

    foreach ($game->players as $player) {
        expect($player->hand)->toHaveCount($player->department === 'engineering' ? 2 : 3);
    }
});

it('plays a random card for anyone who misses the day timer', function () {
    $engine = engine();
    $game = quietGame($engine);
    advanceTo($engine, $game, Game::DAY);
    $engine->act($game, 'a', 'pick', ['card' => $game->player('a')->hand[1]], 0);

    $engine->tick($game, (int) $game->deadline);

    expect($game->phase)->toBe(Game::REVEAL)
        ->and($game->player('a')->autoPicked)->toBeFalse()
        ->and($game->player('b')->autoPicked)->toBeTrue()
        ->and($game->player('b')->pick)->toBeIn($game->player('b')->hand);
});

it('lets a player change their pick until the day ends, and ends early once all are in', function () {
    $engine = engine();
    $game = quietGame($engine);
    advanceTo($engine, $game, Game::DAY);
    $a = $game->player('a');
    $engine->act($game, 'a', 'pick', ['card' => $a->hand[0]], 0);
    $engine->act($game, 'a', 'pick', ['card' => $a->hand[2]], 0);

    expect($a->pick)->toBe($a->hand[2])
        ->and(fn () => $engine->act($game, 'a', 'pick', ['card' => 'XXX-01'], 0))->toThrow(RuleViolation::class);

    everyonePicks($engine, $game);
    expect($game->phase)->toBe(Game::REVEAL);
});

it('applies every pick together and clamps scores to the range', function () {
    $engine = engine();
    $game = quietGame($engine, 4, ['start_score' => 9], ['a']);
    advanceTo($engine, $game, Game::DAY);
    foreach ($game->players as $player) {
        // Each player's best card for their own department.
        $best = collect($player->hand)->sortByDesc(fn ($id) => gameData()->card($id)->effects[$player->department] ?? 0)->first();
        $engine->act($game, $player->id, 'pick', ['card' => $best], 0);
    }

    expect(max($game->scores))->toBeLessThanOrEqual(10)
        ->and(min($game->scores))->toBeGreaterThanOrEqual(0);
});

// ------------------------------------------------------------ dusk

it('stays put on a red signal with no cord window', function () {
    $engine = engine();
    $game = quietGame($engine);

    duskWith($engine, $game, 4);

    expect($game->dusk)->toMatchArray(['green' => false, 'window' => false, 'outcome' => 'red'])
        ->and($game->leg)->toBe(0)
        ->and($game->scores['engineering'])->toBe(4);
});

it('opens the cord window on green for its full time, then runs a leg', function () {
    $engine = engine();
    $game = quietGame($engine);
    advanceTo($engine, $game, Game::REVEAL);
    setScores($game, 6);
    everyoneReady($engine, $game);

    expect($game->phase)->toBe(Game::DUSK)
        ->and(fn () => $engine->act($game, 'b', 'ready', [], 0))->toThrow(RuleViolation::class);
    $engine->tick($game, (int) $game->deadline - 1);
    expect($game->phase)->toBe(Game::DUSK);

    $engine->tick($game, (int) $game->deadline);

    expect($game->phase)->toBe(Game::DEPARTURE)
        ->and($game->dusk['outcome'])->toBe('ran')
        ->and($game->leg)->toBe(1)
        ->and($game->line)->toBe(5)
        ->and($game->scores)->each->toBe(5);
});

it('raises the line every two legs', function () {
    $engine = engine();
    $game = quietGame($engine, 5, ['cord_pulls' => 0]);
    $now = 0;
    $lines = [];
    for ($leg = 1; $leg <= 4; $leg++) {
        duskWith($engine, $game, 9, $now);
        $lines[] = $game->line;
    }

    expect($lines)->toBe([5, 6, 6, 7])
        ->and($game->lineFor(0))->toBe(5)
        ->and(quietGame($engine, 5, ['line_rise_every' => 0])->lineFor(4))->toBe(5);
});

it('makes the next run free after Tailwind', function () {
    $engine = engine();
    $game = quietGame($engine, 5, ['cord_pulls' => 0]);
    $game->freeRun = true;

    duskWith($engine, $game, 6);

    expect($game->dusk['free'])->toBeTrue()
        ->and($game->freeRun)->toBeFalse()
        ->and($game->scores)->each->toBe(6);
});

it('lets only a traitor pull the cord, and the pull leaves a clue', function () {
    $engine = engine();
    $game = quietGame($engine, 6);
    advanceTo($engine, $game, Game::REVEAL);
    setScores($game, 6);
    everyoneReady($engine, $game);

    expect(fn () => $engine->act($game, 'b', 'cord', [], 0))->toThrow(RuleViolation::class, "can't pull");
    $engine->act($game, 'a', 'cord', [], 0);
    expect($game->phase)->toBe(Game::DUSK);
    $engine->tick($game, (int) $game->deadline);

    expect($game->dusk['outcome'])->toBe('pulled')
        ->and($game->leg)->toBe(0)
        ->and($game->scores)->each->toBe(6)
        ->and($game->cordLeft)->toBe(1)
        ->and($game->pulls)->toHaveCount(1)
        ->and($game->pulls[0]['by'])->toBe('a')
        ->and($game->pulls[0]['clue'])->toHaveCount(4)
        ->and($game->pulls[0]['clue'])->toContain('a')
        ->and(collect($game->log)->last(fn ($e) => $e['kind'] === 'cord')['clue'])->toBe($game->pulls[0]['clue']);
});

it('counts two traitors pulling at once as one pull', function () {
    $engine = engine();
    $game = quietGame($engine, 9, [], ['a', 'b']);
    advanceTo($engine, $game, Game::REVEAL);
    setScores($game, 6);
    everyoneReady($engine, $game);
    $engine->act($game, 'b', 'cord', [], 0);
    $engine->act($game, 'a', 'cord', [], 0);
    $engine->tick($game, (int) $game->deadline);

    expect($game->cordLeft)->toBe(1)
        ->and($game->pulls)->toHaveCount(1)
        ->and($game->pulls[0]['by'])->toBe('b')
        ->and($game->pulls[0]['clue'])->toHaveCount(3);
});

it('gives no clue when the clue size is zero', function () {
    $engine = engine();
    $game = quietGame($engine, 5, ['cord_clue' => 0]);

    $now = 0;
    duskWith($engine, $game, 6, $now, 'a');

    expect($game->dusk['outcome'])->toBe('pulled')
        ->and($game->pulls[0]['clue'])->toBe([]);
});

it('runs straight on once the train has used its pulls', function () {
    $engine = engine();
    $game = quietGame($engine, 5, ['cord_pulls' => 1]);
    $now = 0;
    duskWith($engine, $game, 6, $now, 'a');
    advanceTo($engine, $game, Game::REVEAL, $now);
    setScores($game, 6);
    everyoneReady($engine, $game, $now);

    expect($game->phase)->toBe(Game::DEPARTURE)
        ->and($game->dusk)->toMatchArray(['window' => false, 'outcome' => 'ran']);
});

it('opens the window on any signal with cord_green_only off', function () {
    $engine = engine();
    $game = quietGame($engine, 5, ['cord_green_only' => 0]);

    $now = 0;
    duskWith($engine, $game, 3, $now, 'a');

    expect($game->dusk)->toMatchArray(['green' => false, 'window' => true, 'outcome' => 'pulled'])
        ->and($game->cordLeft)->toBe(1);
});

it('opens the window even when the only traitor is detained, but they cannot pull', function () {
    $engine = engine();
    $game = quietGame($engine);
    $now = 0;
    duskWith($engine, $game, 4, $now);
    advanceTo($engine, $game, Game::NIGHT, $now);
    detain($engine, $game, 'b', 'a');
    expect($game->player('a')->isDetained())->toBeTrue();
    advanceTo($engine, $game, Game::REVEAL, $now);
    setScores($game, 6);
    everyoneReady($engine, $game, $now);

    expect($game->phase)->toBe(Game::DUSK)
        ->and(fn () => $engine->act($game, 'a', 'cord', [], $now))->toThrow(RuleViolation::class);

    $game2 = quietGame($engine, 5, ['detained_can_pull' => 1]);
    $game2->player('a')->detainedDays = 1;
    duskWith($engine, $game2, 6, $now, 'a');
    expect($game2->dusk['outcome'])->toBe('pulled');
});

// ------------------------------------------------------------ night

it('detains on a strict majority, the nominator escorting from the next day', function () {
    $engine = engine();
    $game = quietGame($engine, 5);
    $now = 0;
    duskWith($engine, $game, 4, $now);
    advanceTo($engine, $game, Game::NIGHT, $now);

    $engine->act($game, 'b', 'nominate', ['nominee' => 'c'], $now);
    expect($game->phase)->toBe(Game::VOTE)
        ->and($game->player('b')->vote)->toBeTrue()
        ->and(fn () => $engine->act($game, 'c', 'vote', ['yes' => false], $now))->toThrow(RuleViolation::class, "doesn't vote");

    // Four voters (b, a, d, e): b and one more is 2 of 4, not a majority.
    $engine->act($game, 'a', 'vote', ['yes' => true], $now);
    $engine->act($game, 'd', 'vote', ['yes' => false], $now);
    $engine->act($game, 'e', 'vote', ['yes' => false], $now);
    expect($game->phase)->toBe(Game::NIGHT)
        ->and($game->player('c')->isDetained())->toBeFalse()
        ->and(fn () => $engine->act($game, 'b', 'nominate', ['nominee' => 'c'], $now))->toThrow(RuleViolation::class, 'already nominated');

    // A failed vote lets someone else nominate; a missed vote is a no.
    $engine->act($game, 'd', 'nominate', ['nominee' => 'c'], $now);
    $engine->act($game, 'a', 'vote', ['yes' => true], $now);
    $engine->act($game, 'b', 'vote', ['yes' => true], $now);
    $engine->tick($game, (int) $game->deadline);

    expect($game->phase)->toBe(Game::NIGHT)
        ->and($game->player('c')->detainedDays)->toBe(2)
        ->and($game->player('d')->escortOf)->toBe('c')
        ->and(fn () => $engine->act($game, 'e', 'nominate', ['nominee' => 'b'], $now))->toThrow(RuleViolation::class, 'Nobody can be nominated');

    advanceTo($engine, $game, Game::DAY, $now);
    expect($game->escortFor($game->player('c'))->id)->toBe('d')
        ->and($game->player('d')->hand)->toHaveCount(2);
});

it('resumes the night after a vote with at least the after-vote time', function () {
    $engine = engine();
    $game = quietGame($engine);
    $now = 0;
    duskWith($engine, $game, 4, $now);
    advanceTo($engine, $game, Game::NIGHT, $now);
    $now = (int) $game->deadline - 5000;
    detain($engine, $game, 'b', 'c', [], $now);

    expect($game->phase)->toBe(Game::NIGHT)
        ->and($game->deadline)->toBe($now + 30000);
});

it('releases after the detention days and blocks nominating them for a night', function () {
    $engine = engine();
    $game = quietGame($engine);
    $now = 0;
    duskWith($engine, $game, 4, $now);
    advanceTo($engine, $game, Game::NIGHT, $now);
    detain($engine, $game, 'b', 'c');

    duskWith($engine, $game, 4, $now); // day 2, detained
    advanceTo($engine, $game, Game::NIGHT, $now);
    expect($game->player('c')->detainedDays)->toBe(1);

    duskWith($engine, $game, 4, $now); // day 3, detained
    advanceTo($engine, $game, Game::NIGHT, $now);
    expect($game->player('c')->isDetained())->toBeFalse()
        ->and($game->player('b')->escortOf)->toBeNull()
        ->and($game->player('c')->cooldown)->toBe(1)
        ->and(fn () => $engine->act($game, 'd', 'nominate', ['nominee' => 'c'], $now))->toThrow(RuleViolation::class, "can't be nominated");

    duskWith($engine, $game, 4, $now);
    advanceTo($engine, $game, Game::NIGHT, $now);
    expect($game->player('c')->cooldown)->toBe(0);
    $engine->act($game, 'd', 'nominate', ['nominee' => 'c'], $now);
    expect($game->phase)->toBe(Game::VOTE);
});

it('allows nominations while someone is detained with one_detained_at_a_time off', function () {
    $engine = engine();
    $game = quietGame($engine, 6, ['one_detained_at_a_time' => 0]);
    $now = 0;
    duskWith($engine, $game, 4, $now);
    advanceTo($engine, $game, Game::NIGHT, $now);
    detain($engine, $game, 'b', 'c');
    detain($engine, $game, 'd', 'e');

    expect($game->player('e')->isDetained())->toBeTrue()
        ->and(fn () => $engine->act($game, 'b', 'nominate', ['nominee' => 'f'], $now))->toThrow(RuleViolation::class);
});

// ------------------------------------------------------------ escort

function detainedDay(Engine $engine, array $rules = []): Game
{
    $game = quietGame($engine, 5, $rules);
    $now = 0;
    duskWith($engine, $game, 4, $now);
    advanceTo($engine, $game, Game::NIGHT, $now);
    detain($engine, $game, 'b', 'a');
    advanceTo($engine, $game, Game::DAY, $now);

    return $game;
}

it('shows the escort the pick and lets them veto once a day', function () {
    $engine = engine();
    $game = detainedDay($engine);
    $a = $game->player('a');
    $engine->act($game, 'a', 'pick', ['card' => $a->hand[0]], 0);
    expect($a->pickState)->toBe(Player::PICK_PENDING);

    $engine->act($game, 'b', 'escort', ['decision' => 'veto'], 0);
    expect($a->pickState)->toBe(Player::PICK_REPICK)
        ->and(fn () => $engine->act($game, 'a', 'pick', ['card' => $a->hand[0]], 0))->toThrow(RuleViolation::class, 'vetoed');

    $engine->act($game, 'a', 'pick', ['card' => $a->hand[1]], 0);
    expect($a->pickState)->toBe(Player::PICK_FINAL)
        ->and(collect($game->log)->last()['kind'])->toBe('veto');
});

it('gives the escort more vetoes when the setting says so', function () {
    $engine = engine();
    $game = detainedDay($engine, ['escort_vetoes_per_day' => 2]);
    $a = $game->player('a');
    $engine->act($game, 'a', 'pick', ['card' => $a->hand[0]], 0);
    $engine->act($game, 'b', 'escort', ['decision' => 'veto'], 0);
    $engine->act($game, 'a', 'pick', ['card' => $a->hand[1]], 0);
    expect($a->pickState)->toBe(Player::PICK_PENDING);
    $engine->act($game, 'b', 'escort', ['decision' => 'veto'], 0);
    $engine->act($game, 'a', 'pick', ['card' => $a->hand[2]], 0);

    expect($a->pickState)->toBe(Player::PICK_FINAL)
        ->and($a->vetoed)->toBe([$a->hand[0], $a->hand[1]]);
});

it('lets a detained player override a veto once per game', function () {
    $engine = engine();
    $game = detainedDay($engine);
    $a = $game->player('a');
    $engine->act($game, 'a', 'pick', ['card' => $a->hand[0]], 0);
    $engine->act($game, 'b', 'escort', ['decision' => 'veto'], 0);
    $engine->act($game, 'a', 'override', [], 0);

    expect($a->pick)->toBe($a->hand[0])
        ->and($a->overridesLeft)->toBe(0)
        ->and($a->pickState)->toBe(Player::PICK_FINAL);

    everyonePicks($engine, $game);
    expect(collect($game->log)->firstWhere('kind', 'override')['text'])->toContain('OVERRIDE');
});

it('settles an escort flow left hanging when the day ends', function () {
    $engine = engine();
    $game = detainedDay($engine);
    $a = $game->player('a');
    $engine->act($game, 'a', 'pick', ['card' => $a->hand[0]], 0);
    $engine->tick($game, (int) $game->deadline);

    expect($a->pick)->toBe($game->history[1]['picks'][0]['pick'])
        ->and($game->history[1]['picks'][0]['pick'])->toBe($game->history[1]['picks'][0]['hand'][0]);
});

// ------------------------------------------------------------ the end

it('gives the traitor the game on the clock with no night after the last day', function () {
    $engine = engine();
    $game = quietGame($engine, 5, ['days' => 2]);
    $now = 0;
    duskWith($engine, $game, 4, $now);
    advanceTo($engine, $game, Game::NIGHT, $now);
    duskWith($engine, $game, 4, $now);
    everyoneReady($engine, $game, $now);

    expect($game->phase)->toBe(Game::FINISHED)
        ->and($game->winner)->toBe('traitor')
        ->and($game->cause)->toBe('clock');
});

/** Run the train to Last Stop, pulling the cord once first if asked. */
function toArrival(Engine $engine, array $rules = [], bool $pull = true, array $traitors = ['a']): Game
{
    $game = quietGame($engine, 5, ['legs' => 1, ...$rules], $traitors);
    $now = 0;
    if ($pull) {
        duskWith($engine, $game, 6, $now, $traitors[0]);
        advanceTo($engine, $game, Game::NIGHT, $now);
    }
    duskWith($engine, $game, 6, $now);
    expect($game->dusk['arrived'])->toBeTrue();
    everyoneReady($engine, $game, $now);

    return $game;
}

function accuseAll(Engine $engine, Game $game, array $votes): void
{
    foreach ($votes as $voter => $suspect) {
        $engine->act($game, $voter, 'accuse', ['suspect' => $suspect], 0);
    }
    if (! $game->isOver()) {
        $engine->tick($game, (int) $game->deadline);
    }
}

it('lets the crew win by naming the traitor at Last Stop', function () {
    $engine = engine();
    $game = toArrival($engine);
    expect($game->phase)->toBe(Game::ARRIVAL);

    accuseAll($engine, $game, ['a' => 'b', 'b' => 'a', 'c' => 'a', 'd' => 'a', 'e' => 'b']);

    expect($game->winner)->toBe('crew')->and($game->cause)->toBe('named');
});

it('gives the traitor the game on a wrong name or a tie', function () {
    $engine = engine();
    $wrong = toArrival($engine);
    accuseAll($engine, $wrong, ['a' => 'b', 'b' => 'c', 'c' => 'b', 'd' => 'b']);
    $tie = toArrival($engine);
    accuseAll($engine, $tie, ['a' => 'b', 'b' => 'a', 'c' => 'b', 'd' => 'a']);

    expect([$wrong->winner, $wrong->cause])->toBe(['traitor', 'wrong_name'])
        ->and([$tie->winner, $tie->cause])->toBe(['traitor', 'tie_vote']);
});

it('gives the crew the game if the traitor never pulled the cord', function () {
    $engine = engine();
    $game = toArrival($engine, [], false);
    accuseAll($engine, $game, ['a' => 'b', 'b' => 'c', 'c' => 'b']);

    expect([$game->winner, $game->cause])->toBe(['crew', 'never_pulled']);

    $off = toArrival($engine, ['traitor_must_pull' => 0], false);
    accuseAll($engine, $off, ['a' => 'b', 'b' => 'c', 'c' => 'b']);
    expect($off->winner)->toBe('traitor');
});

it('counts either traitor in the arrival vote', function () {
    $engine = engine();
    $game = toArrival($engine, [], true, ['a', 'b']);
    accuseAll($engine, $game, ['c' => 'b', 'd' => 'b', 'e' => 'b']);

    expect([$game->winner, $game->cause])->toBe(['crew', 'named']);
});

it('lets the crew win on arrival alone with the accusation off', function () {
    $engine = engine();
    $game = toArrival($engine, ['accusation' => 0], false);

    expect($game->phase)->toBe(Game::FINISHED)
        ->and([$game->winner, $game->cause])->toBe(['crew', 'arrived']);
});

it('ends the game on two crises after the reveal with crisis loss on', function () {
    $engine = engine();
    $game = quietGame($engine, 5, ['crisis_loss' => 1]);
    advanceTo($engine, $game, Game::REVEAL);
    $game->scores['engineering'] = 1;
    $game->scores['security'] = 2;
    everyoneReady($engine, $game);

    expect([$game->winner, $game->cause])->toBe(['traitor', 'crisis']);
});

// ------------------------------------------------------------ table

it('lets an absent seat play itself', function () {
    $engine = engine();
    $game = quietGame($engine);
    $engine->markAbsent($game, 'e', 0);
    advanceTo($engine, $game, Game::DAY);
    everyonePicks($engine, $game);

    expect($game->phase)->toBe(Game::REVEAL)
        ->and($game->player('e')->autoPicked)->toBeTrue();
});

it('pauses and resumes the clock', function () {
    $engine = engine();
    $game = quietGame($engine);
    $deadline = $game->deadline;
    $engine->pause($game, 5000);

    expect(fn () => $engine->act($game, 'a', 'ready', [], 6000))->toThrow(RuleViolation::class, 'paused');
    $engine->resume($game, 100000);
    expect($game->deadline)->toBe(100000 + $deadline - 5000);
});

it('ends early with no winner', function () {
    $engine = engine();
    $game = quietGame($engine);
    $engine->endEarly($game);

    expect($game->isOver())->toBeTrue()->and($game->winner)->toBeNull()->and($game->cause)->toBe('ended');
});

it('round-trips through JSON', function () {
    $engine = engine();
    $game = quietGame($engine);
    $now = 0;
    duskWith($engine, $game, 6, $now, 'a');
    advanceTo($engine, $game, Game::NIGHT, $now);
    detain($engine, $game, 'b', 'c');

    $copy = Game::fromArray(json_decode(json_encode($game->toArray()), true));

    expect($copy->toArray())->toEqual($game->toArray());
});
