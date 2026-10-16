<?php

/*
 * The host's settings sheet: presets, custom values, and who may change
 * them. Any change clears everyone's Ready, and a game starts with them.
 */

it('lets the host pick a preset, which shifts the days and clears Ready', function () {
    [$code, $players] = seatPlayers(5);
    readyAll($code, $players);

    send($players[0], "/board/{$code}/preset", ['preset' => 'hard'])->assertSessionHasNoErrors();
    $lobby = boardOf($players[1], $code)['lobby'];

    expect($lobby['preset'])->toBe('hard')
        ->and($lobby['setup']['rules']['days'])->toBe(10)
        ->and($lobby['readyCount'])->toBe(0)
        ->and($lobby['notice'])->toContain('Hard');
});

it('saves only the values that differ from Standard, and starts the game with them', function () {
    [$code, $players] = seatPlayers(5);
    $standard = boardOf($players[0], $code)['lobby']['setup']['rules'];

    send($players[0], "/board/{$code}/settings", ['values' => [
        ...$standard,
        'run_cost' => 2,
        'cord_clue' => 0,
        'legs' => 99,
        'nonsense' => 4,
    ]])->assertSessionHasNoErrors();

    $lobby = boardOf($players[0], $code)['lobby'];
    expect($lobby['preset'])->toBe('custom')
        ->and($lobby['custom'])->toBe(['legs' => 9, 'run_cost' => 2, 'cord_clue' => 0])
        ->and($lobby['setup']['rules'])->toMatchArray(['run_cost' => 2, 'cord_clue' => 0, 'legs' => 9]);

    readyAll($code, $players);
    send($players[0], "/board/{$code}/start")->assertSessionHasNoErrors();
    $game = boardOf($players[0], $code)['game'];

    expect($game['legs'])->toBe(9)
        ->and($game['runCost'])->toBe(2)
        ->and($game['clueSize'])->toBe(0)
        ->and($game['stations'])->toHaveCount(10);
});

it('goes back to Standard when the custom values match it', function () {
    [$code, $players] = seatPlayers(4);
    send($players[0], "/board/{$code}/settings", ['values' => ['run_cost' => 2]]);
    send($players[0], "/board/{$code}/settings", ['values' => ['run_cost' => 1]]);

    expect(boardOf($players[0], $code)['lobby']['preset'])->toBe('standard');
});

it('only lets the host change settings, and not mid-trip', function () {
    [$code, $players] = seatPlayers(4);

    send($players[1], "/board/{$code}/settings", ['values' => ['run_cost' => 2]])
        ->assertSessionHasErrors(['game' => 'Only the host can do that.']);
    send($players[1], "/board/{$code}/preset", ['preset' => 'easy'])->assertSessionHasErrors('game');

    readyAll($code, $players);
    send($players[0], "/board/{$code}/start");
    send($players[0], "/board/{$code}/preset", ['preset' => 'easy'])
        ->assertSessionHasErrors(['game' => "Settings can't change mid-trip."]);
});

it('shows every setting from the rulebook on the sheet', function () {
    [$code, $players] = seatPlayers(4);
    $lobby = boardOf($players[0], $code)['lobby'];
    $keys = collect($lobby['fields'])->pluck('key');

    foreach (['legs', 'days', 'weather_per_day', 'start_score', 'line_start', 'line_rise', 'line_rise_every', 'run_cost',
        'crisis_at', 'hand_size', 'reduced_hand', 'cord_pulls', 'cord_green_only', 'cord_clue', 'traitor_must_pull',
        'detain_days', 'cooldown_nights', 'one_detained_at_a_time', 'detained_can_pull', 'escort_vetoes_per_day',
        'overrides_per_player', 'crisis_loss', 'accusation', 'traitors', 'day_seconds', 'night_seconds'] as $key) {
        expect($keys)->toContain($key);
        expect($lobby['setup']['rules'])->toHaveKey($key);
    }
});
