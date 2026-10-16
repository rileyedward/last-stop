# Last Stop: Departure

A train-themed social deduction game for friends on voice chat, played in the browser on phones. This branch (`v2`) is the **Departure** version: the train is snowed in at Hollow Pines and only leaves when every department reaches the line. A hidden traitor holds their department back and pulls the cord. Reach Last Stop in time and name the traitor to win.

The rulebook lives in the project's design folder (`design/departure/departure-v0.md`); `main` is the earlier Draft 1 game.

## Run it

```
composer setup     # first time: install, .env, key, migrate, build
composer dev       # web server, queue worker, Reverb and Vite together
```

Open `/`, type a name, start a lobby and share the `/board/CODE` link. `/board/CODE/station` is the big screen for screen-sharing. 4 to 10 players.

The admin lives at `/admin`. Set `ADMIN_USERNAME` and `ADMIN_PASSWORD` in `.env` first (the password may be a bcrypt hash).

## How it's built

- Laravel with Inertia, Vue, TypeScript and Tailwind. SQLite locally, Postgres in production.
- **Game data** ships as CSVs in `resources/game/`: rules (`settings.csv`), the per-player-count table (`players.csv`), presets, timers, task cards and weather cards. The admin can override any of them; edits go in the `game_settings` and `game_cards` tables, and Reset goes back to the CSVs.
- **Engine** (`app/Game/Engine`): plain PHP, state + action in, state + log out, ported from the Departure balance simulator.
- **Lobbies and games** live only in the cache, one entry per boarding code, with a lock around every change. Each browser loads only its own view over HTTP; WebSocket pings just say "something changed".
- Players are guests with a token cookie. There are no accounts; the only login is the admin's.

## Checks

```
composer test          # Pint, PHPStan and the Pest suite
npm run check          # format and lint
npm run types:check
npm run build
```

`tests/Unit/Game/SimulatorReplayTest.php` replays games recorded from the Departure simulator through the PHP engine. To re-record them: `python3 tests/Fixtures/sim/record.py path/to/departure_sim.py`.

Deploying: see [docs/deploy-laravel-cloud.md](docs/deploy-laravel-cloud.md).
