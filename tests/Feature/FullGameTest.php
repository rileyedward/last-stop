<?php

use App\Game\Data\GameData;
use App\Game\Engine\Engine;
use App\Lobby\Identity;
use Illuminate\Testing\TestResponse;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\TestCase;

/*
 * Fake players play whole trips over the real HTTP endpoints: start a
 * lobby, board with the code, ready up, depart, and play every phase to the
 * final reveal and back to the lobby. Each fake player has its own token
 * cookie, so the server sees separate browsers.
 */

// Seeded deals, so a failure can be replayed.
beforeEach(function () {
    app()->singleton(Engine::class, fn () => new Engine(GameData::default(), new Randomizer(new Mt19937(7))));
});

final class FakePlayer
{
    public string $token;

    public string $id;

    public function __construct(public string $name)
    {
        $this->token = Identity::newToken();
        $this->id = Identity::idFor($this->token);
    }
}

function browser(FakePlayer $player): TestCase
{
    /** @var TestCase $test */
    $test = test();
    $test->flushHeaders();

    return $test->withCookies([Identity::TOKEN_COOKIE => $player->token, Identity::NAME_COOKIE => $player->name]);
}

/** The board as this player sees it. */
function boardOf(FakePlayer $player, string $code): array
{
    $response = browser($player)->get("/board/{$code}");
    $response->assertOk();

    return $response->viewData('page')['props']['state'];
}

function send(FakePlayer $player, string $url, array $data = []): TestResponse
{
    return browser($player)->from('/')->post($url, $data);
}

/** A sensible crew card: the most points and distance overall. */
function bestCard(array $hand): array
{
    return collect($hand)->sortByDesc(fn ($c) => array_sum($c['effects']) + 2 * $c['distance'])->first();
}

/** Post a move and fail loudly if the server refused it. */
function move(FakePlayer $player, string $code, array $data): void
{
    $response = send($player, "/board/{$code}/act", $data);
    $response->assertRedirect();
    $response->assertSessionHasNoErrors();
}

/**
 * Seat $n players, ready them and depart. Returns [code, players].
 *
 * @return array{0: string, 1: list<FakePlayer>}
 */
function boardAndDepart(int $n): array
{
    $players = [];
    foreach (['Ana', 'Ben', 'Cass', 'Dev', 'Eli', 'Fran', 'Gus', 'Hal', 'Ivy', 'Jo'] as $i => $name) {
        if ($i < $n) {
            $players[] = new FakePlayer($name);
        }
    }

    $created = send($players[0], '/lobbies');
    $code = substr((string) $created->headers->get('Location'), -4);
    expect($code)->toMatch('/^[A-HJ-NP-Z2-9]{4}$/');

    foreach (array_slice($players, 1) as $player) {
        send($player, "/board/{$code}/join")->assertRedirect("/board/{$code}");
    }
    foreach ($players as $player) {
        send($player, "/board/{$code}/ready", ['ready' => true])->assertSessionHasNoErrors();
    }

    $lobby = boardOf($players[0], $code)['lobby'];
    expect($lobby['isHost'])->toBeTrue()
        ->and($lobby['seats'])->toHaveCount($n)
        ->and($lobby['canStart'])->toBeTrue();

    send($players[0], "/board/{$code}/start")->assertSessionHasNoErrors();

    return [$code, $players];
}

/** Nobody may see anything secret that isn't theirs. */
function assertNoLeaks(array $state, FakePlayer $viewer): void
{
    $game = $state['game'];
    if ($game === null) {
        return;
    }
    foreach ($game['players'] as $player) {
        expect($player)->not->toHaveKey('hand');
        if ($game['phase'] !== 'finished') {
            expect($player['traitor'])->toBeNull();
        }
    }
    if ($game['phase'] !== 'finished') {
        expect($game['reveal'])->toBeNull();
    }
    if ($game['me'] !== null) {
        expect($game['me']['id'])->toBe($viewer->id);
        if (! $game['me']['traitor']) {
            expect($game['me']['forecast'])->toBeNull()->and($game['me']['partners'])->toBe([]);
        }
    }
}

/**
 * Play until the trip is over. Bots pick their first card, an escort vetoes
 * once and the restricted player overrides, someone pulls the brake on the
 * first night it opens, everyone votes yes, and the crew names a suspect.
 * On $timeoutDay nobody picks and the clock plays for them.
 *
 * @param  list<FakePlayer>  $players
 * @return array{days: int, brakes: int, vetoes: int, overrides: int, timeouts: int}
 */
function playToTheEnd(string $code, array $players, int $timeoutDay = 2): array
{
    $stats = ['days' => 0, 'brakes' => 0, 'vetoes' => 0, 'overrides' => 0, 'timeouts' => 0];

    for ($step = 0; $step < 400; $step++) {
        $views = [];
        foreach ($players as $player) {
            $views[$player->id] = boardOf($player, $code);
            assertNoLeaks($views[$player->id], $player);
        }
        $game = $views[$players[0]->id]['game'];
        $phase = $game['phase'];
        $stats['days'] = $game['day'];

        if ($phase === 'finished') {
            return $stats;
        }

        if ($phase === 'day' && $game['day'] === $timeoutDay && $stats['timeouts'] === 0) {
            test()->travel($game['deadline'] - $views[$players[0]->id]['serverNow'] + 1)->milliseconds();
            $stats['timeouts']++;

            continue;
        }

        $pulled = $stats['brakes'];
        foreach ($players as $player) {
            if ($stats['brakes'] !== $pulled) {
                break; // everyone else's view is stale now; look again
            }
            $state = $views[$player->id];
            $me = $state['game']['me'];

            match ($phase) {
                'boarding', 'dawn', 'reveal' => $me['ready'] ?: move($player, $code, ['action' => 'ready']),
                'night' => (function () use ($player, $code, $state, $me, &$stats) {
                    $g = $state['game'];
                    if ($g['brake']['open'] && ! $g['brake']['used'] && $me['canPull'] && $stats['brakes'] < 2) {
                        $target = collect($g['players'])->first(fn ($p) => $p['nominable'] && $p['id'] !== $me['id']);
                        if ($target !== null) {
                            move($player, $code, ['action' => 'brake', 'nominee' => $target['id']]);
                            $stats['brakes']++;

                            return;
                        }
                    }
                    if (! $me['ready']) {
                        move($player, $code, ['action' => 'ready']);
                    }
                })(),
                'vote' => (function () use ($player, $code, $state, $me) {
                    $vote = $state['game']['vote'];
                    if ($vote !== null && $me['vote'] === null && $vote['nominee'] !== $me['id']) {
                        move($player, $code, ['action' => 'vote', 'yes' => true]);
                    }
                })(),
                'day' => (function () use ($player, $code, $me, &$stats) {
                    if ($me['escorting'] !== null && $me['escorting']['pickState'] === 'pending') {
                        $veto = $me['escorting']['decision'] === null && count($me['escorting']['hand']) > 1;
                        move($player, $code, ['action' => 'escort', 'decision' => $veto ? 'veto' : 'allow']);
                        $stats['vetoes'] += $veto ? 1 : 0;
                    }
                    if ($me['pickState'] === 'none' && $me['hand'] !== []) {
                        move($player, $code, ['action' => 'pick', 'card' => bestCard($me['hand'])['id']]);
                    } elseif ($me['pickState'] === 'repick') {
                        if ($me['overridesLeft'] > 0) {
                            move($player, $code, ['action' => 'override']);
                            $stats['overrides']++;
                        } else {
                            $other = collect($me['hand'])->first(fn ($c) => $c['id'] !== $me['vetoed']);
                            move($player, $code, ['action' => 'pick', 'card' => $other['id']]);
                        }
                    }
                })(),
                'inquiry' => (function () use ($player, $code, $state, $me) {
                    if (! $me['traitor'] && $me['guess'] === null) {
                        $suspect = collect($state['game']['players'])->first(fn ($p) => $p['id'] !== $me['id']);
                        move($player, $code, ['action' => 'guess', 'suspect' => $suspect['id']]);
                    }
                })(),
                default => null,
            };
        }
    }

    throw new RuntimeException('The trip never ended.');
}

it('plays a full trip from boarding to the final reveal', function (int $n) {
    [$code, $players] = boardAndDepart($n);

    $first = boardOf($players[1], $code);
    expect($first['screen'])->toBe('game')
        ->and($first['game']['phase'])->toBe('boarding')
        ->and($first['game']['me']['department'])->toBeString();

    $traitors = collect($players)->filter(fn ($p) => boardOf($p, $code)['game']['me']['traitor']);
    expect($traitors)->toHaveCount($n >= 9 ? 2 : 1);
    if ($n >= 9) {
        foreach ($traitors as $traitor) {
            expect(boardOf($traitor, $code)['game']['me']['partners'])->toHaveCount(1);
        }
    }

    $stats = playToTheEnd($code, $players);

    $final = boardOf($players[0], $code);
    $game = $final['game'];
    expect($final['screen'])->toBe('final')
        ->and($game['phase'])->toBe('finished')
        ->and($game['winner'])->toBeIn(['crew', 'traitor'])
        ->and($game['reveal']['days'])->toHaveCount($game['day'])
        ->and($game['reveal']['traitors'])->toHaveCount($traitors->count())
        ->and($stats['timeouts'])->toBe($game['day'] >= 2 ? 1 : 0);

    // Every hand dealt is in the reveal, including what was passed on.
    foreach ($game['reveal']['days'] as $day) {
        expect($day['picks'])->toHaveCount($n);
        foreach ($day['picks'] as $pick) {
            expect(collect($pick['hand'])->pluck('id'))->toContain($pick['pick']);
        }
    }

    // Wins are counted once, for the winning side.
    $lobby = $final['lobby'];
    $winners = $game['winner'] === 'crew' ? $n - $traitors->count() : $traitors->count();
    expect(collect($lobby['wins'])->sum('total'))->toBe($winners)->and($lobby['trips'])->toBe(1);

    // Back to the lobby: ready is cleared and a new trip can start.
    send($players[0], "/board/{$code}/back")->assertSessionHasNoErrors();
    $back = boardOf($players[0], $code);
    expect($back['screen'])->toBe('lobby')
        ->and($back['lobby']['readyCount'])->toBe(0)
        ->and(boardOf($players[1], $code)['screen'])->toBe('final');
})->with([4, 5, 10]);

it('exercises the brake, the escort, the veto and the override', function () {
    // Seeded deals, so a trip reliably lasts long enough for the brake.
    foreach (range(1, 30) as $seed) {
        app()->singleton(Engine::class, fn () => new Engine(GameData::default(), new Randomizer(new Mt19937($seed))));
        [$code, $players] = boardAndDepart(6);
        $stats = playToTheEnd($code, $players);
        if ($stats['brakes'] > 0 && $stats['vetoes'] > 0 && $stats['overrides'] > 0) {
            break;
        }
    }

    $log = collect(boardOf($players[0], $code)['game']['log']);
    expect($stats['brakes'])->toBeGreaterThan(0)
        ->and($stats['timeouts'])->toBe(1)
        ->and($stats['vetoes'])->toBeGreaterThan(0)
        ->and($stats['overrides'])->toBeGreaterThan(0)
        ->and($log->where('kind', 'brake'))->toHaveCount($stats['brakes'])
        ->and($log->where('kind', 'vote'))->toHaveCount($stats['brakes'])
        ->and($log->where('kind', 'veto'))->toHaveCount($stats['vetoes'])
        ->and($log->where('kind', 'override'))->toHaveCount($stats['overrides']);
});

it('keeps the station board free of secrets', function () {
    [$code, $players] = boardAndDepart(5);
    move($players[0], $code, ['action' => 'ready']);

    $response = browser($players[0])->get("/board/{$code}/station");
    $state = $response->viewData('page')['props']['state'];

    expect($state['game']['me'] ?? null)->toBeNull()
        ->and($state['game']['reveal'])->toBeNull()
        ->and(json_encode($state))->not->toContain('"hand"');
});

it('puts late arrivals on the platform and seats them after the trip', function () {
    [$code, $players] = boardAndDepart(4);
    $late = new FakePlayer('Zed');

    send($late, "/board/{$code}/join")->assertRedirect("/board/{$code}");
    $view = boardOf($late, $code);
    expect($view['screen'])->toBe('platform')->and($view['game']['me'])->toBeNull();

    playToTheEnd($code, $players);

    $after = boardOf($late, $code);
    expect($after['membership'])->toBe('seated')->and($after['screen'])->toBe('lobby');
});

it('turns away an eleventh passenger and duplicate names', function () {
    [$code] = boardAndDepart(10);

    send(new FakePlayer('Kit'), "/board/{$code}/join")->assertSessionHasErrors(['code']);
    expect(boardOf(new FakePlayer('Kit'), $code)['screen'])->toBe('full');

    $code2 = substr((string) send(new FakePlayer('Ana'), '/lobbies')->headers->get('Location'), -4);
    send(new FakePlayer('ana'), "/board/{$code2}/join")->assertSessionHasErrors(['code']);
});

it('lets the host remove a player mid-trip and their seat plays itself', function () {
    [$code, $players] = boardAndDepart(5);
    send($players[0], "/board/{$code}/kick", ['player' => $players[4]->id])->assertSessionHasNoErrors();

    expect(boardOf($players[4], $code)['screen'])->toBe('removed');
    $stats = playToTheEnd($code, array_slice($players, 0, 4));

    expect(boardOf($players[0], $code)['game']['phase'])->toBe('finished')
        ->and(boardOf($players[0], $code)['lobby']['seats'])->toHaveCount(4)
        ->and($stats['days'])->toBeGreaterThan(0);
});

it('lets only the host pause, resume and end the trip', function () {
    [$code, $players] = boardAndDepart(4);

    send($players[1], "/board/{$code}/pause")->assertSessionHasErrors(['game']);
    send($players[0], "/board/{$code}/pause")->assertSessionHasNoErrors();
    expect(boardOf($players[0], $code)['game']['paused'])->toBeTrue();

    $this->travel(5)->minutes();
    expect(boardOf($players[0], $code)['game']['phase'])->toBe('boarding');
    send($players[1], "/board/{$code}/act", ['action' => 'ready'])->assertSessionHasErrors(['game']);

    send($players[0], "/board/{$code}/resume")->assertSessionHasNoErrors();
    send($players[0], "/board/{$code}/end")->assertSessionHasNoErrors();

    $view = boardOf($players[0], $code);
    expect($view['game']['phase'])->toBe('finished')
        ->and($view['game']['cause'])->toBe('ended')
        ->and($view['lobby']['trips'])->toBe(0);
});

it('needs everyone ready before departing, and a preset change clears ready', function () {
    $players = [new FakePlayer('Ana'), new FakePlayer('Ben'), new FakePlayer('Cass'), new FakePlayer('Dev')];
    $code = substr((string) send($players[0], '/lobbies')->headers->get('Location'), -4);
    foreach (array_slice($players, 1) as $p) {
        send($p, "/board/{$code}/join");
    }
    foreach ($players as $p) {
        send($p, "/board/{$code}/ready", ['ready' => true]);
    }

    send($players[1], "/board/{$code}/preset", ['preset' => 'hard'])->assertSessionHasErrors(['game']);
    send($players[0], "/board/{$code}/preset", ['preset' => 'hard'])->assertSessionHasNoErrors();

    $lobby = boardOf($players[0], $code)['lobby'];
    expect($lobby['readyCount'])->toBe(0)
        ->and($lobby['setup']['route'])->toBe(20)
        ->and($lobby['notice'])->toContain('Ready was cleared');

    send($players[0], "/board/{$code}/start")->assertSessionHasErrors(['game']);
});

it('asks for a name first and remembers it', function () {
    $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->component('Name'));

    $this->post('/name', ['name' => '  Cass  '])
        ->assertRedirect('/')
        ->assertCookie(Identity::NAME_COOKIE, 'Cass');
});

it('passes host to the next seat when the host leaves', function () {
    $players = [new FakePlayer('Ana'), new FakePlayer('Ben')];
    $code = substr((string) send($players[0], '/lobbies')->headers->get('Location'), -4);
    send($players[1], "/board/{$code}/join");

    send($players[0], "/board/{$code}/leave")->assertRedirect('/');
    expect(boardOf($players[1], $code)['lobby']['isHost'])->toBeTrue();
});
