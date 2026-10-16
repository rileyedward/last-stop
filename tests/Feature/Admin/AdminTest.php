<?php

use App\Game\Data\GameData;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;

/*
 * The lean admin: one login from the environment, the rules page and the
 * card decks. Edits land in game_settings / game_cards and new lobbies use
 * them.
 */

beforeEach(function () {
    config(['last-stop.admin.username' => 'conductor', 'last-stop.admin.password' => 'snowed-in']);
});

function signIn(): void
{
    test()->post('/admin/login', ['username' => 'conductor', 'password' => 'snowed-in'])->assertRedirect('/admin/rules');
}

function csvUpload(string $text): UploadedFile
{
    return UploadedFile::fake()->createWithContent('deck.csv', $text);
}

it('sends visitors to the login page', function () {
    $this->get('/admin/rules')->assertRedirect('/admin/login');
    $this->get('/admin/cards')->assertRedirect('/admin/login');
    $this->get('/admin/login')->assertOk();
});

it('signs in with the environment login and out again', function () {
    signIn();
    $this->get('/admin/rules')->assertOk();

    $this->post('/admin/logout')->assertRedirect('/admin/login');
    $this->get('/admin/rules')->assertRedirect('/admin/login');
});

it('accepts a hashed password', function () {
    config(['last-stop.admin.password' => Hash::make('hashed-secret')]);

    $this->post('/admin/login', ['username' => 'conductor', 'password' => 'hashed-secret'])->assertRedirect('/admin/rules');
});

it('rejects a wrong password', function () {
    $this->from('/admin/login')->post('/admin/login', ['username' => 'conductor', 'password' => 'nope'])
        ->assertRedirect('/admin/login')
        ->assertSessionHasErrors('username');
    $this->get('/admin/rules')->assertRedirect('/admin/login');
});

it('refuses every login when no admin is configured', function () {
    config(['last-stop.admin.username' => null, 'last-stop.admin.password' => null]);

    $this->post('/admin/login', ['username' => '', 'password' => ''])->assertSessionHasErrors();
    $this->post('/admin/login', ['username' => 'conductor', 'password' => 'snowed-in'])->assertSessionHasErrors('username');
});

it('rate limits login attempts', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->post('/admin/login', ['username' => 'conductor', 'password' => 'wrong'.$i]);
    }

    $this->post('/admin/login', ['username' => 'conductor', 'password' => 'snowed-in'])
        ->assertSessionHasErrors(['username' => 'Too many tries. Wait 60 seconds.']);
    $this->get('/admin/rules')->assertRedirect('/admin/login');
});

it('shows every rule, the player table, presets and timers', function () {
    signIn();
    $props = $this->get('/admin/rules')->viewData('page')['props'];

    expect($props['values'])->toMatchArray(['run_cost' => 1, 'cord_pulls' => 2, 'day_seconds' => 60, 'arrival_seconds' => 90])
        ->and($props['table'])->toHaveCount(7)
        ->and($props['table'][0])->toMatchArray(['players' => 4, 'days' => 10, 'cord_clue' => 3])
        ->and($props['presets'])->toBe(['easy' => 1, 'hard' => -1])
        ->and($props['edited'])->toBe([]);
});

it('saves rules, and a new lobby picks them up', function () {
    signIn();
    $this->post('/admin/rules', [
        'values' => ['run_cost' => 2, 'night_seconds' => 90],
        'table' => [['players' => 5, 'days' => 13, 'cord_clue' => 2]],
        'presets' => ['easy' => 2, 'hard' => -2],
    ])->assertRedirect('/admin/rules')->assertSessionHasNoErrors();

    expect(app(GameData::class)->rulesFor(5)['rules'])->toMatchArray(['run_cost' => 2, 'night_seconds' => 90, 'days' => 13, 'cord_clue' => 2])
        ->and(app(GameData::class)->rulesFor(5, 'easy')['rules']['days'])->toBe(15)
        ->and(app(GameData::class)->rulesFor(4)['rules']['days'])->toBe(10);

    [$code, $players] = seatPlayers(5);
    expect(boardOf($players[0], $code)['lobby']['setup']['rules'])->toMatchArray(['run_cost' => 2, 'days' => 13]);

    $this->post('/admin/rules/reset')->assertRedirect('/admin/rules');
    expect(app(GameData::class)->rulesFor(5)['rules']['run_cost'])->toBe(1);
});

it('rejects rule values out of range', function () {
    signIn();
    $this->from('/admin/rules')->post('/admin/rules', [
        'values' => ['run_cost' => 99, 'hand_size' => 'three'],
        'table' => [['players' => 4, 'legs' => 0]],
    ])->assertSessionHasErrors(['run_cost', 'hand_size', 'table.0.legs']);

    expect(app(GameData::class)->settings['run_cost'])->toBe(1);
});

it('only stores the sources that changed', function () {
    signIn();
    $this->post('/admin/rules', ['values' => ['run_cost' => 2], 'presets' => ['easy' => 1, 'hard' => -1]]);

    expect($this->get('/admin/rules')->viewData('page')['props']['edited'])->toBe(['settings']);
});

it('lists both decks and exports them as CSV', function () {
    signIn();
    $props = $this->get('/admin/cards')->viewData('page')['props'];
    expect($props['tasks'])->toHaveCount(40)->and($props['weather'])->toHaveCount(12);

    $csv = $this->get('/admin/cards/weather/export')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
    expect($csv)->toStartWith('card,engineering,operations,passenger_services,security,special,flavor')
        ->and($csv)->toContain('Tailwind,0,0,0,0,free_run');
});

it('imports a weather deck and new games draw from it', function () {
    signIn();
    $this->post('/admin/cards/weather', ['csv' => csvUpload("card,engineering,special\nWhiteout,-3,\nThaw,1,\nTailwind,0,free_run\n")])
        ->assertRedirect('/admin/cards')->assertSessionHasNoErrors();

    $data = app(GameData::class);
    expect(collect($data->weather)->pluck('name')->all())->toBe(['Whiteout', 'Thaw', 'Tailwind'])
        ->and($data->weather[0]->effects)->toBe(['engineering' => -3]);

    $this->post('/admin/cards/weather/reset');
    expect(app(GameData::class)->weather)->toHaveCount(12);
});

it('rejects bad card files without changing the deck', function (string $deck, string $text, string $message) {
    signIn();
    $this->from('/admin/cards')->post("/admin/cards/{$deck}", ['csv' => csvUpload($text)])
        ->assertSessionHasErrors(['csv' => $message]);

    expect(app(GameData::class)->tasks['engineering'])->toHaveCount(10)
        ->and(app(GameData::class)->weather)->toHaveCount(12);
})->with([
    'empty' => ['weather', "card,engineering\n", 'That file has no cards in it.'],
    'no department column' => ['tasks', "card,engineering\nSoup,1\n", 'The file needs a department column. Export the current deck to see the format.'],
    'unknown department' => ['tasks', "department,card\nKitchen,Soup\n", 'tasks row 1: unknown department [Kitchen].'],
    'not a number' => ['weather', "card,engineering\nFog,lots\n", 'weather row 1 (Fog): engineering must be a whole number.'],
    'a department left empty' => ['tasks', "department,card,engineering\nEngineering,Patch,2\n", 'players row 1 uses Operations, which has no task cards.'],
]);

it('rejects a file that is not a CSV', function () {
    signIn();
    $this->from('/admin/cards')->post('/admin/cards/tasks', ['csv' => UploadedFile::fake()->image('cat.png')])
        ->assertSessionHasErrors(['csv' => 'Upload a .csv file.']);
});
