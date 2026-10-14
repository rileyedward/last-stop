<?php

use App\Game\Data\Card;
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

/** Play from wherever the game is to the next night. */
function toNight(Engine $engine, Game $game): void
{
    $guard = 0;
    while ($game->phase !== Game::NIGHT && $guard++ < 10) {
        match ($game->phase) {
            Game::DAY => everyonePicks($engine, $game),
            default => everyoneReady($engine, $game),
        };
    }
}

it('loads every rule number from the CSVs', function () {
    $data = gameData();

    expect($data->settings['start_score'])->toBe(6)
        ->and($data->settings['brakes_per_game'])->toBe(3)
        ->and($data->settings['day_seconds'])->toBe(60)
        ->and($data->tasks)->toHaveCount(4)
        ->and($data->tasks['engineering'])->toHaveCount(10)
        ->and($data->weather)->toHaveCount(12)
        ->and($data->minPlayers())->toBe(4)
        ->and($data->maxPlayers())->toBe(10);

    $four = $data->rulesFor(4);
    expect($four['departments'])->toBe(['engineering', 'operations', 'passenger_services'])
        ->and($four['rules']['route'])->toBe(19)
        ->and($four['rules']['weather_per_day'])->toBe(3);

    expect($data->rulesFor(5, 'easy')['rules']['route'])->toBe(15)
        ->and($data->rulesFor(5, 'hard')['rules']['route'])->toBe(18)
        ->and($data->rulesFor(10)['rules']['traitors'])->toBe(2);
});

it('reads each card kind from tasks.csv', function () {
    expect(gameData()->card('ENG-06')->name)->toBe('Thaw the switches')
        ->and(gameData()->card('ENG-06')->kind)->toBe('routine') // the numbers alone would say repair
        ->and(gameData()->card('ENG-01')->kind)->toBe('speed');
});

it('takes Easy and Hard routes from players.csv when it has them', function () {
    $dir = sys_get_temp_dir().'/ls-data-'.uniqid();
    mkdir($dir);
    foreach (glob(__DIR__.'/../../../resources/game/*.csv') as $file) {
        copy($file, $dir.'/'.basename($file));
    }
    $players = file($dir.'/players.csv', FILE_IGNORE_NEW_LINES);
    $players[0] .= ',easy_route,hard_route';
    for ($i = 1; $i < count($players); $i++) {
        $players[$i] .= ',11,33';
    }
    file_put_contents($dir.'/players.csv', implode("\n", $players)."\n");

    $data = GameData::load($dir);
    expect($data->rulesFor(5, 'easy')['rules']['route'])->toBe(11)
        ->and($data->rulesFor(5, 'hard')['rules']['route'])->toBe(33)
        ->and($data->rulesFor(5)['rules']['route'])->toBe(17);

    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);
});

it('derives a kind for every card', function () {
    $kinds = array_map(fn ($card) => $card->kind, gameData()->tasks['passenger_services']);

    expect(gameData()->card('PAS-01')->kind)->toBe('repair')    // Comp the dining car: own +2, OPS -1
        ->and(gameData()->card('OPS-09')->kind)->toBe('support') // Lend a hand to the stewards
        ->and(gameData()->card('PAS-05')->kind)->toBe('speed')  // Seal the rear carriages
        ->and(gameData()->card('PAS-10')->kind)->toBe('routine') // Reassuring announcement
        ->and(gameData()->card('ENG-04')->kind)->toBe('repair') // Full overhaul
        ->and($kinds)->each->toBeIn(['speed', 'repair', 'support', 'routine'])
        ->and(Card::kindOf('engineering', ['engineering' => 1], 0))->toBe('routine');
});

it('deals departments evenly and the right number of traitors', function (int $n) {
    $game = newGame(engine($n), $n);
    $setup = gameData()->rulesFor($n);
    $counts = array_count_values(array_map(fn (Player $p) => $p->department, $game->players));

    expect($game->players)->toHaveCount($n)
        ->and(count($game->traitors()))->toBe($setup['rules']['traitors'])
        ->and(max($counts) - min($counts))->toBeLessThanOrEqual(1)
        ->and(array_keys($game->scores))->toBe($setup['departments'])
        ->and(array_unique($game->scores))->toBe([$setup['departments'][0] => 6]);
})->with([4, 5, 6, 7, 8, 9, 10]);

it('runs boarding, dawn and day on their clocks', function () {
    $engine = engine();
    $game = newGame($engine);

    expect($game->phase)->toBe(Game::BOARDING)->and($game->deadline)->toBe(20_000);

    $engine->tick($game, 20_000);
    expect($game->phase)->toBe(Game::DAWN)
        ->and($game->day)->toBe(1)
        ->and($game->weatherToday)->toHaveCount(3)
        ->and($game->deadline)->toBe(30_000);

    $engine->tick($game, 30_000);
    expect($game->phase)->toBe(Game::DAY)
        ->and($game->deadline)->toBe(90_000);
    foreach ($game->players as $player) {
        expect($player->hand)->toHaveCount($game->inCrisis($player->department) ? 2 : 3);
    }
});

it('plays a random card for anyone who misses the day timer', function () {
    $engine = engine();
    $game = newGame($engine);
    everyoneReady($engine, $game);
    everyoneReady($engine, $game);

    $first = $game->players[0];
    $engine->act($game, $first->id, 'pick', ['card' => $first->hand[1]], 1_000);
    $engine->tick($game, $game->deadline);

    expect($game->phase)->toBe(Game::REVEAL);
    $picks = $game->history[0]['picks'];
    expect($picks[0]['pick'])->toBe($first->hand[1])->and($picks[0]['auto'])->toBeFalse();
    foreach (array_slice($picks, 1) as $pick) {
        expect($pick['auto'])->toBeTrue()->and($pick['hand'])->toContain($pick['pick']);
    }
});

it('lets an unrestricted player change their pick until the day ends', function () {
    $engine = engine();
    $game = newGame($engine);
    everyoneReady($engine, $game);
    everyoneReady($engine, $game);

    $a = $game->player('a');
    $engine->act($game, 'a', 'pick', ['card' => $a->hand[0]], 0);
    $engine->act($game, 'a', 'pick', ['card' => $a->hand[2]], 0);

    expect($a->pick)->toBe($a->hand[2])->and($game->phase)->toBe(Game::DAY);
    expect(fn () => $engine->act($game, 'a', 'pick', ['card' => 'ENG-99'], 0))->toThrow(RuleViolation::class);
});

it('ends the day early once every pick is in', function () {
    $engine = engine();
    $game = newGame($engine);
    everyoneReady($engine, $game);
    everyoneReady($engine, $game);
    everyonePicks($engine, $game);

    expect($game->phase)->toBe(Game::REVEAL)
        ->and($game->history[0]['distanceAfter'])->toBe($game->distance);
});

it('opens the brake from the configured night and limits pulls', function () {
    $engine = engine();
    $game = newGame($engine, 5, ['brakes_per_game' => 2, 'route' => 99, 'days' => 20, 'crisis_at' => -1]);

    toNight($engine, $game);
    expect($game->day)->toBe(1)->and($game->brakeOpen)->toBeFalse();
    expect(fn () => $engine->act($game, 'a', 'brake', ['nominee' => 'b'], 0))->toThrow(RuleViolation::class);

    everyoneReady($engine, $game);
    toNight($engine, $game);
    everyoneReady($engine, $game);
    toNight($engine, $game);
    expect($game->day)->toBe(3)->and($game->brakeOpen)->toBeTrue();

    $engine->act($game, 'a', 'brake', ['nominee' => 'b'], 0);
    expect($game->phase)->toBe(Game::VOTE)
        ->and($game->player('a')->vote)->toBeTrue()
        ->and($game->trainBrakesLeft)->toBe(1)
        ->and($game->slowDays)->toBe(1);

    // Only one pull a night, and the nominee can't vote.
    expect(fn () => $engine->act($game, 'b', 'vote', ['yes' => false], 0))->toThrow(RuleViolation::class);
    foreach (['c', 'd', 'e'] as $id) {
        $engine->act($game, $id, 'vote', ['yes' => $id !== 'e'], 0);
    }
    expect($game->phase)->toBe(Game::NIGHT)
        ->and($game->player('b')->restrictedDays)->toBe(2)
        ->and($game->player('a')->escortOf)->toBe('b')
        ->and(fn () => $engine->act($game, 'c', 'brake', ['nominee' => 'd'], 0))->toThrow(RuleViolation::class);

    // The next day has no base speed, and the escort draws the reduced hand.
    everyoneReady($engine, $game);
    expect($game->baseToday)->toBe(0);
    everyoneReady($engine, $game);
    expect($game->player('a')->hand)->toHaveCount(2);
});

it('stops the brake once the train has used its pulls', function () {
    $engine = engine();
    $game = newGame($engine, 5, ['brakes_per_game' => 1, 'brake_from_night' => 1, 'route' => 99, 'days' => 20, 'crisis_at' => -1]);
    toNight($engine, $game);

    $engine->act($game, 'a', 'brake', ['nominee' => 'b'], 0);
    $engine->tick($game, $game->deadline); // nobody votes: missing votes count as no

    expect($game->player('b')->isRestricted())->toBeFalse()
        ->and($game->history[0]['brake']['passed'])->toBeFalse()
        ->and($game->history[0]['brake']['yes'])->toBe(1);

    everyoneReady($engine, $game);
    toNight($engine, $game);
    expect($game->brakeOpen)->toBeFalse();
});

it('fails a tied vote', function () {
    $engine = engine();
    $game = newGame($engine, 5, ['brake_from_night' => 1, 'route' => 99, 'days' => 20, 'crisis_at' => -1]);
    toNight($engine, $game);

    $engine->act($game, 'a', 'brake', ['nominee' => 'b'], 0);
    $engine->act($game, 'c', 'vote', ['yes' => true], 0);
    $engine->act($game, 'd', 'vote', ['yes' => false], 0);
    $engine->act($game, 'e', 'vote', ['yes' => false], 0);
    expect($game->player('b')->isRestricted())->toBeFalse();
});

it('resumes the night after a vote with at least the after-vote time', function () {
    $engine = engine();
    $game = newGame($engine, 5, ['brake_from_night' => 1, 'route' => 99, 'days' => 20, 'crisis_at' => -1]);
    toNight($engine, $game);
    $nightEnds = $game->deadline;

    $engine->act($game, 'a', 'brake', ['nominee' => 'b'], $nightEnds - 5_000);
    $voteEnds = $game->deadline;
    expect($voteEnds)->toBe($nightEnds - 5_000 + 30_000);

    $engine->tick($game, $voteEnds);
    expect($game->phase)->toBe(Game::NIGHT)
        ->and($game->deadline)->toBe($voteEnds + 30_000);
});

/** A game where A restricted B on night 1, now in day 2. */
function restrictedGame(Engine $engine): Game
{
    $game = newGame($engine, 5, ['brake_from_night' => 1, 'route' => 99, 'days' => 20, 'crisis_at' => -1]);
    toNight($engine, $game);
    $engine->act($game, 'a', 'brake', ['nominee' => 'b'], 0);
    foreach (['c', 'd', 'e'] as $id) {
        $engine->act($game, $id, 'vote', ['yes' => true], 0);
    }
    everyoneReady($engine, $game); // night
    everyoneReady($engine, $game); // dawn

    return $game;
}

it('shows the escort the pick and lets them veto once', function () {
    $engine = engine();
    $game = restrictedGame($engine);
    $b = $game->player('b');

    expect($game->escortFor($b)?->id)->toBe('a')->and($b->hand)->toHaveCount(3);

    $engine->act($game, 'b', 'pick', ['card' => $b->hand[0]], 0);
    expect($b->pickState)->toBe(Player::PICK_PENDING)
        ->and(fn () => $engine->act($game, 'b', 'pick', ['card' => $b->hand[1]], 0))->toThrow(RuleViolation::class);

    $engine->act($game, 'a', 'escort', ['decision' => 'veto'], 0);
    expect($b->pickState)->toBe(Player::PICK_REPICK)
        ->and(end($game->log)['kind'])->toBe('veto')
        ->and(end($game->log)['text'])->toBe("B's first pick was vetoed by their escort, A.")
        ->and(fn () => $engine->act($game, 'b', 'pick', ['card' => $b->hand[0]], 0))->toThrow(RuleViolation::class);

    $engine->act($game, 'b', 'pick', ['card' => $b->hand[1]], 0);
    expect($b->pickState)->toBe(Player::PICK_FINAL)
        ->and($b->pick)->toBe($b->hand[1])
        ->and(fn () => $engine->act($game, 'a', 'escort', ['decision' => 'veto'], 0))->toThrow(RuleViolation::class);
});

it('lets a restricted player override a veto once per game', function () {
    $engine = engine();
    $game = restrictedGame($engine);
    $b = $game->player('b');
    $first = $b->hand[0];

    $engine->act($game, 'b', 'pick', ['card' => $first], 0);
    $engine->act($game, 'a', 'escort', ['decision' => 'veto'], 0);
    $engine->act($game, 'b', 'override', [], 0);

    expect($b->pick)->toBe($first)->and($b->overridesLeft)->toBe(0);

    everyonePicks($engine, $game);
    $line = array_values(array_filter($game->log, fn ($l) => ($l['who'] ?? null) === 'b' && $l['kind'] === 'override'));
    expect($line)->toHaveCount(1)->and($line[0]['text'])->toContain('(OVERRIDE)');

    // Day two of the restriction: no override left.
    everyoneReady($engine, $game);
    everyoneReady($engine, $game);
    everyoneReady($engine, $game);
    $engine->act($game, 'b', 'pick', ['card' => $b->hand[0]], 0);
    $engine->act($game, 'a', 'escort', ['decision' => 'veto'], 0);
    expect(fn () => $engine->act($game, 'b', 'override', [], 0))->toThrow(RuleViolation::class);
});

it('settles an escort flow left hanging when the day ends', function () {
    $engine = engine();
    $game = restrictedGame($engine);
    $b = $game->player('b');

    $engine->act($game, 'b', 'pick', ['card' => $b->hand[2]], 0);
    $engine->tick($game, $game->deadline);

    $pick = collect($game->history[1]['picks'])->firstWhere('player', 'b');
    expect($pick['pick'])->toBe($pick['hand'][2])->and($pick['auto'])->toBeFalse()->and($pick['escort'])->toBe('a');
});

it('ends the restriction after its days and blocks nominating them for the cooldown', function () {
    $engine = engine();
    $game = restrictedGame($engine);

    toNight($engine, $game); // day 2
    expect($game->player('b')->restrictedDays)->toBe(1);
    everyoneReady($engine, $game);
    toNight($engine, $game); // day 3

    expect($game->player('b')->restrictedDays)->toBe(0)
        ->and($game->player('a')->escortOf)->toBeNull()
        ->and($game->player('b')->cooldown)->toBe(1)
        ->and(fn () => $engine->act($game, 'c', 'brake', ['nominee' => 'b'], 0))->toThrow(RuleViolation::class);

    everyoneReady($engine, $game);
    toNight($engine, $game); // day 4
    expect($game->player('b')->cooldown)->toBe(0);
    $engine->act($game, 'c', 'brake', ['nominee' => 'b'], 0);
    expect($game->phase)->toBe(Game::VOTE);
});

it('gives each traitor the forecast at night', function () {
    $engine = engine();
    $game = newGame($engine);
    toNight($engine, $game);
    $forecast = $game->forecast;

    everyoneReady($engine, $game);
    expect($forecast)->not->toBeNull()->and($game->weatherToday[0])->toBe($forecast);

    $off = newGame($engine, 5, ['traitor_forecast' => 0]);
    toNight($engine, $off);
    expect($off->forecast)->toBeNull();
});

it('lets the crew name suspects once the train arrives', function () {
    $engine = engine();
    $game = newGame($engine, 5, ['route' => 1, 'base_speed' => 5]);
    everyoneReady($engine, $game);
    everyoneReady($engine, $game);
    everyonePicks($engine, $game);

    expect($game->phase)->toBe(Game::REVEAL)->and($game->winner)->toBe('crew')->and($game->cause)->toBe('arrived');
    everyoneReady($engine, $game);
    expect($game->phase)->toBe(Game::INQUIRY);

    $traitor = $game->traitors()[0];
    expect(fn () => $engine->act($game, $traitor->id, 'guess', ['suspect' => 'a'], 0))->toThrow(RuleViolation::class);
    foreach ($game->players as $player) {
        if (! $player->traitor) {
            expect(fn () => $engine->act($game, $player->id, 'guess', ['suspect' => $player->id], 0))->toThrow(RuleViolation::class);
            $engine->act($game, $player->id, 'guess', ['suspect' => $traitor->id], 0);
        }
    }
    expect($game->phase)->toBe(Game::FINISHED);
});

it('gives the traitor the game on two crises or the last day', function () {
    $engine = engine();
    $crisis = newGame($engine, 5, ['start_score' => 1, 'crisis_at' => 2]);
    everyoneReady($engine, $crisis);
    everyoneReady($engine, $crisis);
    everyonePicks($engine, $crisis);
    expect($crisis->winner)->toBe('traitor')->and($crisis->cause)->toBe('crisis');

    $clock = newGame($engine, 5, ['days' => 1, 'route' => 99, 'crisis_at' => -1]);
    everyoneReady($engine, $clock);
    everyoneReady($engine, $clock);
    everyonePicks($engine, $clock);
    expect($clock->winner)->toBe('traitor')->and($clock->cause)->toBe('clock');
});

it('lets an absent seat play itself', function () {
    $engine = engine();
    $game = newGame($engine);
    $engine->markAbsent($game, 'e', 0);
    foreach (['a', 'b', 'c', 'd'] as $id) {
        $engine->act($game, $id, 'ready', [], 0);
    }
    expect($game->phase)->toBe(Game::DAWN);
    foreach (['a', 'b', 'c', 'd'] as $id) {
        $engine->act($game, $id, 'ready', [], 0);
    }
    foreach (['a', 'b', 'c', 'd'] as $id) {
        $engine->act($game, $id, 'pick', ['card' => $game->player($id)->hand[0]], 0);
    }

    expect($game->phase)->toBe(Game::REVEAL)
        ->and(collect($game->history[0]['picks'])->firstWhere('player', 'e')['auto'])->toBeTrue()
        ->and(fn () => $engine->act($game, 'e', 'ready', [], 0))->toThrow(RuleViolation::class);
});

it('pauses and resumes the clock', function () {
    $engine = engine();
    $game = newGame($engine);
    $engine->pause($game, 5_000);

    expect($game->pausedRemaining)->toBe(15_000)
        ->and(fn () => $engine->act($game, 'a', 'ready', [], 6_000))->toThrow(RuleViolation::class);
    $engine->tick($game, 999_000);
    expect($game->phase)->toBe(Game::BOARDING);

    $engine->resume($game, 100_000);
    expect($game->deadline)->toBe(115_000);
});

it('ends early with no winner', function () {
    $engine = engine();
    $game = newGame($engine);
    $engine->endEarly($game);

    expect($game->phase)->toBe(Game::FINISHED)->and($game->winner)->toBeNull()->and($game->cause)->toBe('ended');
});

it('round-trips through JSON', function () {
    $engine = engine();
    $game = restrictedGame($engine);
    $copy = Game::fromArray(json_decode(json_encode($game->toArray()), true));

    expect($copy->toArray())->toEqual($game->toArray());
});
