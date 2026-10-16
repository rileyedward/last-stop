<?php

use App\Game\Data\GameData;
use App\Game\Engine\Engine;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * Fake players play whole Departure trips over the real HTTP endpoints:
 * start a lobby, board with the code, set the trip up, ready up, depart,
 * and play every phase to the final reveal and back to the lobby. Each fake
 * player has its own token cookie, so the server sees separate browsers.
 */

// Seeded deals, so a failure can be replayed.
beforeEach(function () {
    app()->singleton(Engine::class, fn () => new Engine(app(GameData::class), new Randomizer(new Mt19937(7))));
});

/** Nobody may see anything secret that isn't theirs. */
function assertNoLeaks(array $state, ?FakePlayer $viewer): void
{
    $game = $state['game'];
    if ($game === null) {
        return;
    }
    $over = $game['phase'] === 'finished';
    foreach ($game['players'] as $player) {
        expect($player)->not->toHaveKey('hand');
        if (! $over) {
            expect($player['traitor'])->toBeNull();
        }
    }
    expect($game['dusk'] ?? [])->not->toHaveKey('pulledBy');
    foreach ($game['pulls'] as $pull) {
        expect($pull)->not->toHaveKey('by');
    }
    if (! $over) {
        expect($game['reveal'])->toBeNull();
    }

    $me = $game['me'];
    if ($viewer === null) {
        expect($me)->toBeNull();

        return;
    }
    if ($me !== null) {
        expect($me['id'])->toBe($viewer->id);
        if (! $me['traitor']) {
            expect($me['partners'])->toBe([])
                ->and($me['cordPulled'])->toBeFalse()
                ->and($me['canPull'])->toBeFalse()
                ->and($me['traitorsPulled'])->toBeNull();
        }
        if ($me['escorting'] !== null) {
            $target = collect($game['players'])->firstWhere('id', $me['escorting']['id']);
            expect($target['escortedBy'])->toBe($me['id']);
        }
    }
}

/** Move the clock past the current phase's deadline. */
function runOutClock(array $state): void
{
    test()->travel($state['game']['deadline'] - $state['serverNow'] + 1)->milliseconds();
}

/**
 * Play a trip to the end over HTTP. The plan says what the table does:
 * which day the traitor pulls the cord, which night someone is detained
 * (their escort vetoes their pick the next day and they override), which
 * day nobody picks and the clock plays for them, and who gets named at
 * Last Stop.
 *
 * @param  list<FakePlayer>  $players
 * @param  array{pullDay?: int, detainNight?: int, timeoutDay?: int}  $plan
 * @return array<string, int>
 */
function playTrip(string $code, array $players, array $plan): array
{
    $stats = ['pulls' => 0, 'detentions' => 0, 'vetoes' => 0, 'overrides' => 0, 'timeouts' => 0, 'clues' => 0];
    $byId = collect($players)->keyBy('id');

    for ($step = 0; $step < 600; $step++) {
        $views = [];
        foreach ($players as $player) {
            $views[$player->id] = boardOf($player, $code);
            assertNoLeaks($views[$player->id], $player);
        }
        $station = browser($players[0])->get("/board/{$code}/station")->viewData('page')['props']['state'];
        assertNoLeaks($station, null);

        $any = $views[$players[0]->id];
        $game = $any['game'];
        if ($game['phase'] === 'finished') {
            return $stats;
        }
        $traitors = collect($players)->filter(fn ($p) => $views[$p->id]['game']['me']['traitor'])->values();

        switch ($game['phase']) {
            case 'day':
                if (($plan['timeoutDay'] ?? 0) === $game['day'] && $stats['timeouts'] === 0) {
                    runOutClock($any);
                    $stats['timeouts']++;
                    break;
                }
                foreach ($players as $player) {
                    $me = boardOf($player, $code)['game']['me'];
                    $duty = $me['escorting'];
                    if ($duty !== null && $duty['pickState'] === 'pending' && $duty['decision'] === null) {
                        $veto = $duty['vetoesLeft'] > 0 && count($duty['hand']) - count($duty['vetoed']) > 1;
                        move($player, $code, ['action' => 'escort', 'decision' => $veto ? 'veto' : 'allow']);
                        $stats['vetoes'] += $veto ? 1 : 0;
                    }
                    if ($me['pickState'] === 'none' && $me['hand'] !== []) {
                        move($player, $code, ['action' => 'pick', 'card' => $me['hand'][0]['id']]);
                    } elseif ($me['pickState'] === 'repick') {
                        if ($me['overridesLeft'] > 0) {
                            move($player, $code, ['action' => 'override']);
                            $stats['overrides']++;
                        } else {
                            $other = collect($me['hand'])->first(fn ($c) => ! in_array($c['id'], $me['vetoed'], true));
                            move($player, $code, ['action' => 'pick', 'card' => $other['id']]);
                        }
                    }
                }
                break;

            case 'dusk':
                if (($plan['pullDay'] ?? 0) === $game['day']) {
                    foreach ($traitors as $traitor) {
                        $me = $views[$traitor->id]['game']['me'];
                        if ($me['canPull'] && ! $me['cordPulled']) {
                            move($traitor, $code, ['action' => 'cord']);
                            $stats['pulls']++;
                            // The partner now sees the pull; the crew doesn't.
                            foreach ($players as $p) {
                                expect(boardOf($p, $code)['game']['me']['cordPulled'])->toBe($traitors->contains('id', $p->id));
                            }
                            break;
                        }
                    }
                }
                runOutClock($any);
                $after = boardOf($players[0], $code)['game'];
                if ($after['dusk']['outcome'] === 'pulled') {
                    expect($after['dusk']['clue'])->toHaveCount($after['clueSize'])
                        ->and($after['pulls'])->not->toBe([]);
                    $stats['clues']++;
                }
                break;

            case 'night':
                if (($plan['detainNight'] ?? 0) === $game['day'] && $stats['detentions'] === 0 && $game['vote'] === null && $game['lastVote'] === null) {
                    // A crew member nominates someone else, and everyone says yes.
                    $nominator = collect($players)->first(fn ($p) => ! $traitors->contains('id', $p->id) && $views[$p->id]['game']['me']['canNominate']);
                    $nominee = collect($game['players'])->first(fn ($p) => $p['nominable'] && $p['id'] !== $nominator->id);
                    move($nominator, $code, ['action' => 'nominate', 'nominee' => $nominee['id']]);
                    break;
                }
                foreach ($players as $player) {
                    if (! $views[$player->id]['game']['me']['ready']) {
                        move($player, $code, ['action' => 'ready']);
                    }
                }
                break;

            case 'vote':
                foreach ($players as $player) {
                    $me = $views[$player->id]['game']['me'];
                    if ($me['vote'] === null && $game['vote']['nominee'] !== $me['id']) {
                        move($player, $code, ['action' => 'vote', 'yes' => true]);
                    }
                }
                $after = boardOf($players[0], $code)['game'];
                expect($after['phase'])->toBe('night')
                    ->and($after['lastVote']['passed'])->toBeTrue();
                $stats['detentions']++;
                break;

            case 'arrival':
                // The crew names a traitor; each traitor names someone else.
                $named = $traitors->first();
                foreach ($players as $player) {
                    $suspect = $traitors->contains('id', $player->id)
                        ? collect($players)->first(fn ($p) => ! $traitors->contains('id', $p->id))
                        : $named;
                    move($player, $code, ['action' => 'accuse', 'suspect' => $suspect->id]);
                }
                break;

            default:
                foreach ($players as $player) {
                    if (! $views[$player->id]['game']['me']['ready']) {
                        move($player, $code, ['action' => 'ready']);
                    }
                }
        }
    }

    throw new RuntimeException('The trip never ended.');
}

/** Host sets the trip up, everyone readies, and the train departs. */
function depart(string $code, array $players, array $values): void
{
    send($players[0], "/board/{$code}/settings", ['values' => $values])->assertSessionHasNoErrors();
    readyAll($code, $players);
    send($players[0], "/board/{$code}/start")->assertSessionHasNoErrors();
}

/** Everyone leaves the final screen; the lobby is ready for another trip. */
function backToLobby(string $code, array $players): void
{
    foreach ($players as $player) {
        expect(boardOf($player, $code)['screen'])->toBe('final');
        send($player, "/board/{$code}/back")->assertSessionHasNoErrors();
        expect(boardOf($player, $code)['screen'])->toBe('lobby');
    }
    $lobby = boardOf($players[0], $code)['lobby'];
    expect($lobby['readyCount'])->toBe(0)->and($lobby['inProgress'])->toBeFalse();

    readyAll($code, $players);
    expect(boardOf($players[0], $code)['lobby']['canStart'])->toBeTrue();
}

it('plays a crew win: a cord pull and its clue, a detention with escort and veto, then the traitor named at Last Stop', function (int $n) {
    [$code, $players] = seatPlayers($n);
    // Green from the first dusk, two legs, nothing in the way but the cord.
    depart($code, $players, ['legs' => 2, 'weather_per_day' => 0, 'start_score' => 9, 'line_start' => 5, 'run_cost' => 0, 'line_rise' => 0, 'days' => 8]);

    $first = boardOf($players[1], $code);
    expect($first['screen'])->toBe('game')
        ->and($first['game']['phase'])->toBe('boarding')
        ->and($first['game']['me']['department'])->toBeString();
    $traitors = collect($players)->filter(fn ($p) => boardOf($p, $code)['game']['me']['traitor']);
    expect($traitors)->toHaveCount($n >= 9 ? 2 : 1);
    foreach ($traitors as $traitor) {
        expect(boardOf($traitor, $code)['game']['me']['partners'])->toHaveCount($n >= 9 ? 1 : 0);
    }

    $stats = playTrip($code, $players, ['pullDay' => 1, 'detainNight' => 1, 'timeoutDay' => 3]);

    $final = boardOf($players[0], $code);
    $game = $final['game'];
    expect($final['screen'])->toBe('final')
        ->and($game['winner'])->toBe('crew')
        ->and($game['cause'])->toBe('named')
        ->and($stats)->toMatchArray(['pulls' => 1, 'clues' => 1, 'detentions' => 1, 'vetoes' => 1, 'overrides' => 1])
        ->and($game['reveal']['pulls'])->toHaveCount(1)
        ->and($traitors->pluck('id'))->toContain($game['reveal']['pulls'][0]['by'])
        ->and($game['reveal']['pulls'][0]['clue'])->toContain($game['reveal']['pulls'][0]['by'])
        ->and($game['reveal']['accusations'])->toHaveCount($n)
        ->and($game['reveal']['days'])->toHaveCount($game['day']);

    // Every hand dealt is in the reveal, including what was passed on.
    foreach ($game['reveal']['days'] as $day) {
        expect($day['picks'])->toHaveCount($n);
        foreach ($day['picks'] as $pick) {
            expect(collect($pick['hand'])->pluck('id'))->toContain($pick['pick']);
        }
    }

    // Wins are counted once, for the crew.
    $lobby = $final['lobby'];
    expect(collect($lobby['wins'])->sum('crew'))->toBe($n - $traitors->count())
        ->and(collect($lobby['wins'])->sum('traitor'))->toBe(0)
        ->and($lobby['trips'])->toBe(1);

    backToLobby($code, $players);
})->with([4, 5, 10]);

it('plays a traitor win on the clock, with a timeout', function (int $n) {
    [$code, $players] = seatPlayers($n);
    // Red every dusk: the train never leaves Hollow Pines.
    depart($code, $players, ['days' => 2, 'weather_per_day' => 0, 'start_score' => 1, 'line_start' => 9]);

    $stats = playTrip($code, $players, ['timeoutDay' => 1]);

    $game = boardOf($players[0], $code)['game'];
    expect($game['winner'])->toBe('traitor')
        ->and($game['cause'])->toBe('clock')
        ->and($game['day'])->toBe(2)
        ->and($game['reveal']['leg'])->toBe(0)
        ->and($stats['timeouts'])->toBe(1)
        ->and(collect($game['reveal']['days'][0]['picks'])->every(fn ($p) => $p['auto']))->toBeTrue()
        ->and(collect($game['log'])->pluck('kind'))->not->toContain('arrival');

    $lobby = boardOf($players[0], $code)['lobby'];
    expect(collect($lobby['wins'])->sum('traitor'))->toBe($n >= 9 ? 2 : 1);

    backToLobby($code, $players);
})->with([4, 5, 10]);

it('lets someone join mid-trip on the platform and seats them for the next one', function () {
    [$code, $players] = seatPlayers(4);
    depart($code, $players, ['days' => 1, 'weather_per_day' => 0, 'start_score' => 1]);

    $late = new FakePlayer('Kai');
    send($late, "/board/{$code}/join");
    $view = boardOf($late, $code);
    expect($view['screen'])->toBe('platform')->and($view['game']['me'])->toBeNull();
    assertNoLeaks($view, $late);

    playTrip($code, $players, []);
    expect(boardOf($late, $code)['lobby']['seats'])->toHaveCount(5);
});
