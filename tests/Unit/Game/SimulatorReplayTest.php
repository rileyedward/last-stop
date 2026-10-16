<?php

use App\Game\Data\GameData;
use App\Game\Engine\Engine;
use App\Game\Engine\Game;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * Games recorded from the Departure simulator (tests/Fixtures/sim/record.py),
 * replayed through the PHP engine with the same seating, the same deck
 * draws and the same decisions. Both must agree after every day: scores
 * after the picks, the departure check, the leg and line, detentions, and
 * the winner. Clue names are random on both sides, so only their size and
 * the puller's place in them are checked.
 */

function departureGames(): array
{
    return json_decode(file_get_contents(__DIR__.'/../../Fixtures/sim/departure-games.json'), true);
}

function replayDeparture(array $fixture): void
{
    $data = GameData::load(__DIR__.'/../../../resources/game');
    $ids = [];
    foreach ($data->tasks as $deck) {
        foreach ($deck as $card) {
            $ids[$card->name] = $card->id;
        }
    }
    foreach ($data->weather as $card) {
        $ids[$card->name] = $card->id;
    }

    $players = $fixture['players'];
    $n = count($players);
    $pid = fn (string $simId) => 'p'.$simId;

    // Lay out every draw of the game in the order the PHP engine draws
    // (seat order), so the piles never need a reshuffle.
    $piles = [];
    $weather = [];
    foreach ($fixture['days'] as $day) {
        foreach ($day['weather'] as $name) {
            $weather[] = $ids[$name];
        }
        foreach ($players as $p) {
            foreach ($day['hands'][$p['id']] ?? [] as $name) {
                $piles[$p['department']][] = $ids[$name];
            }
        }
    }

    $setup = $data->rulesFor($n);
    $engine = new Engine($data, new Randomizer(new Mt19937(1)));
    $seats = array_map(fn ($p) => ['id' => $pid($p['id']), 'name' => 'P'.$p['id']], $players);
    $game = $engine->start($seats, $setup['rules'], $setup['departments'], 0, [
        'departments' => array_column(array_map(fn ($p) => [$pid($p['id']), $p['department']], $players), 1, 0),
        'traitors' => array_values(array_map(fn ($p) => $pid($p['id']), array_filter($players, fn ($p) => $p['traitor']))),
        'decks' => array_map(fn ($pile) => array_reverse($pile), $piles),
        'weather' => array_reverse($weather),
    ]);

    $now = 0;
    $allReady = function () use ($engine, $game, &$now) {
        foreach ($game->players as $player) {
            $engine->act($game, $player->id, 'ready', [], $now);
        }
    };

    $seatsMatch = function (array $expected, string $label) use ($game, $pid) {
        foreach ($expected as $simId => $seat) {
            $player = $game->player($pid((string) $simId));
            expect([
                'detained' => $player->detainedDays,
                'escorting' => $player->escortOf,
                'cooldown' => $player->cooldown,
                'overrides' => $player->overridesLeft,
            ])->toBe([...$seat, 'escorting' => $seat['escorting'] === null ? null : $pid($seat['escorting'])], "$label, seat {$simId}");
        }
    };

    $allReady(); // boarding
    foreach ($fixture['days'] as $i => $day) {
        $label = "{$fixture['name']}, day ".($i + 1);
        expect($game->phase)->toBe(Game::DAWN, $label)
            ->and($game->day)->toBe($i + 1, $label)
            ->and($game->weatherToday)->toBe(array_map(fn ($w) => $ids[$w], $day['weather']), $label);

        $allReady(); // dawn
        expect($game->phase)->toBe(Game::DAY, $label);

        foreach ($game->players as $player) {
            $simId = substr($player->id, 1);
            expect($player->hand)->toBe(array_map(fn ($c) => $ids[$c], $day['hands'][$simId]), "$label, hand of {$player->id}");
        }

        foreach ($game->players as $player) {
            $simId = substr($player->id, 1);
            if (array_key_exists($simId, $day['veto'])) {
                $escort = $game->escortFor($player);
                expect($escort)->not->toBeNull($label);
                $engine->act($game, $player->id, 'pick', ['card' => $ids[$day['first'][$simId]]], $now);
                if ($day['veto'][$simId]) {
                    $engine->act($game, $escort->id, 'escort', ['decision' => 'veto'], $now);
                    $day['override'][$simId]
                        ? $engine->act($game, $player->id, 'override', [], $now)
                        : $engine->act($game, $player->id, 'pick', ['card' => $ids[$day['final'][$simId]]], $now);
                } else {
                    $engine->act($game, $escort->id, 'escort', ['decision' => 'allow'], $now);
                }
            } else {
                expect($game->escortFor($player))->toBeNull("$label, {$player->id} has no escort");
                $engine->act($game, $player->id, 'pick', ['card' => $ids[$day['final'][$simId]]], $now);
            }
        }

        expect($game->phase)->toBe(Game::REVEAL, $label)
            ->and($game->scores)->toEqual($day['scores_day'], "$label, scores after the picks");

        $allReady(); // reveal
        if ($day['green'] && $game->cordLeft > 0) {
            expect($game->phase)->toBe(Game::DUSK, "$label, cord window");
            if ($day['puller'] !== null) {
                $engine->act($game, $pid($day['puller']), 'cord', [], $now);
            }
            $now = (int) $game->deadline;
            $engine->tick($game, $now);
        } else {
            expect($day['puller'])->toBeNull($label);
        }

        expect($game->phase)->toBe(Game::DEPARTURE, $label)
            ->and($game->dusk['green'])->toBe($day['green'], "$label, signal")
            ->and($game->dusk['outcome'])->toBe($day['outcome'], "$label, departure")
            ->and($game->dusk['free'])->toBe($day['free'], "$label, free run")
            ->and($game->leg)->toBe($day['leg'], "$label, leg")
            ->and($game->line)->toBe($day['line'], "$label, line")
            ->and($game->scores)->toEqual($day['scores_dusk'], "$label, scores after dusk");
        if ($day['outcome'] === 'pulled') {
            $clue = end($game->pulls)['clue'];
            expect($clue)->toHaveCount($day['clue_size'], "$label, clue size")
                ->and($clue)->toContain($pid($day['puller']));
        }

        $last = $i === count($fixture['days']) - 1;
        if ($last) {
            $allReady(); // departure
            if ($game->phase === Game::ARRIVAL) {
                foreach ($fixture['arrival_votes'] as $voter => $suspect) {
                    $engine->act($game, $pid($voter), 'accuse', ['suspect' => $pid($suspect)], $now);
                }
                if (! $game->isOver()) {
                    $now = (int) $game->deadline;
                    $engine->tick($game, $now);
                }
            }
            expect($game->phase)->toBe(Game::FINISHED, $label)
                ->and($game->winner)->toBe($fixture['result']['winner'], $label)
                ->and($game->cause)->toBe($fixture['result']['cause'], $label)
                ->and($game->day)->toBe($fixture['result']['day'], $label);

            return;
        }

        $allReady(); // departure
        expect($game->phase)->toBe(Game::NIGHT, $label);

        if ($day['nomination'] !== null) {
            $nom = $day['nomination'];
            $engine->act($game, $pid($nom['nominator']), 'nominate', ['nominee' => $pid($nom['nominee'])], $now);
            expect($game->phase)->toBe(Game::VOTE, $label);
            $yesLeft = $nom['yes'] - 1;
            $voters = 1;
            foreach ($game->players as $player) {
                if ($player->id !== $pid($nom['nominee']) && $player->id !== $pid($nom['nominator'])) {
                    $voters++;
                    $engine->act($game, $player->id, 'vote', ['yes' => $yesLeft-- > 0], $now);
                }
            }
            expect($voters)->toBe($nom['voters'], "$label, voters")
                ->and($game->phase)->toBe(Game::NIGHT, $label)
                ->and($game->player($pid($nom['nominee']))->isDetained())->toBe($nom['passed'], "$label, detained");
        }
        $seatsMatch($day['after_night'], "$label, after the night");

        $allReady(); // night
    }
}

it('replays Departure simulator games day by day', function (array $fixture) {
    replayDeparture($fixture);
})->with(fn () => array_combine(array_column(departureGames(), 'name'), array_map(fn ($g) => [$g], departureGames())));
