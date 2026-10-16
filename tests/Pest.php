<?php

use App\Lobby\Identity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/*
 * Fake players for feature tests: each has its own token cookie, so the
 * server sees separate browsers.
 */
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

/** Post a move and fail loudly if the server refused it. */
function move(FakePlayer $player, string $code, array $data): void
{
    $response = send($player, "/board/{$code}/act", $data);
    $response->assertRedirect();
    $response->assertSessionHasNoErrors();
}

/**
 * Seat $n players in a new lobby and ready them. Returns [code, players].
 *
 * @return array{0: string, 1: list<FakePlayer>}
 */
function seatPlayers(int $n): array
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

    return [$code, $players];
}

function readyAll(string $code, array $players): void
{
    foreach ($players as $player) {
        send($player, "/board/{$code}/ready", ['ready' => true])->assertSessionHasNoErrors();
    }
}
