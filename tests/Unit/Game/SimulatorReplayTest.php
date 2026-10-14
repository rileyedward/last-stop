<?php

use App\Game\Data\GameData;
use App\Game\Engine\Engine;
use App\Game\Engine\Game;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * Games recorded from the balance simulator (tests/Fixtures/sim/record.py),
 * replayed through the PHP engine with the same seating, the same deck
 * draws and the same decisions. Both must agree after every day.
 */

function simGames(): array
{
    return json_decode(file_get_contents(__DIR__.'/../../Fixtures/sim/games.json'), true);
}

function replay(array $fixture): void
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
            if ($game->phase !== Game::INQUIRY && ! in_array($game->phase, [Game::DAY, Game::VOTE], true)) {
                $engine->act($game, $player->id, 'ready', [], $now);
            }
        }
    };

    $seats = function (array $expected, string $label) use ($game, $pid) {
        foreach ($expected as $simId => $seat) {
            $player = $game->player($pid((string) $simId));
            expect([
                'restricted' => $player->restrictedDays,
                'escorting' => $player->escortOf,
                'cooldown' => $player->cooldown,
                'brakes' => $player->brakesLeft,
                'overrides' => $player->overridesLeft,
            ])->toBe([...$seat, 'escorting' => $seat['escorting'] === null ? null : $pid($seat['escorting'])], "$label, seat {$simId}");
        }
    };

    $allReady(); // boarding
    foreach ($fixture['days'] as $i => $day) {
        $label = "{$fixture['name']}, day ".($i + 1);
        if ($i > 0) {
            $seats($fixture['days'][$i - 1]['after_night'], "$label, after the night before");
        }
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
            ->and($game->scores)->toEqual($day['scores'], $label)
            ->and($game->distance)->toBe($day['distance'], $label);
        $seats($day['after_day'], "$label, after the day");

        $last = $i === count($fixture['days']) - 1;
        if ($last) {
            expect($game->winner)->toBe($fixture['result']['winner'], $label)
                ->and($game->cause)->toBe($fixture['result']['cause'], $label)
                ->and($game->day)->toBe($fixture['result']['day'], $label);

            return;
        }
        expect($game->winner)->toBeNull($label);

        $allReady(); // reveal
        expect($game->phase)->toBe(Game::NIGHT, $label)
            ->and($game->brakeOpen)->toBe($day['brake_open'], "$label, brake open");

        if ($day['brake'] !== null) {
            $brake = $day['brake'];
            expect($game->brakeOpen)->toBeTrue("$label, brake open");
            $engine->act($game, $pid($brake['puller']), 'brake', ['nominee' => $pid($brake['nominee'])], $now);
            expect($game->phase)->toBe(Game::VOTE, $label);
            foreach ($game->players as $player) {
                if ($player->id !== $pid($brake['nominee']) && $player->id !== $pid($brake['puller'])) {
                    $engine->act($game, $player->id, 'vote', ['yes' => $brake['passed']], $now);
                }
            }
            expect($game->phase)->toBe(Game::NIGHT, $label)
                ->and($game->player($pid($brake['nominee']))->isRestricted())->toBe($brake['passed'], $label);
        }

        $allReady(); // night
    }
}

it('replays simulator games day by day', function (array $fixture) {
    replay($fixture);
})->with(fn () => array_combine(array_column(simGames(), 'name'), array_map(fn ($g) => [$g], simGames())));
