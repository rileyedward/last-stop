# Last Stop

A train-themed social deduction game for friends on voice chat.

> **Status:** Inactive — not in active development, though I may come back to it someday. Kept for reference and to run locally.

## Overview

### What is Last Stop?

Last Stop is a social deduction game played in the browser on phones while everyone talks over voice chat. The train is snowed in at Hollow Pines and only leaves when every department reaches the line. A hidden traitor holds their department back and pulls the cord. Reach Last Stop in time and name the traitor to win.

This is the **Departure** version of the game. The earlier Draft 1 game lives on the `v1` branch. The rulebook lives in the project's design folder (`design/departure/departure-v0.md`).

### Key Features

- **Lobbies**: Type a name, start a lobby and share the `/board/CODE` link. No accounts; players are guests with a token cookie. 4 to 10 players.
- **Station Screen**: `/board/CODE/station` is a big screen for screen-sharing on the call.
- **Live Updates**: Each phone loads only its own view, and WebSocket pings say when something changed. Without WebSockets, pages poll every 3 seconds.
- **Admin**: Rules, the per-player-count table, presets, timers, task cards and weather cards, all editable at `/admin` with a Reset back to the defaults.

## Getting Started

### Prerequisites

Ensure you have the following installed. You can verify each one by running the provided command in your terminal.

1. **PHP** (8.3+) is required for the application. Check it by running:

    ```bash
    php --version
    ```

2. **Composer** manages PHP dependencies. Check it by running:

    ```bash
    composer --version
    ```

3. **Node** and **NPM** manage frontend dependencies. Check them by running:

    ```bash
    node --version
    npm --version
    ```

### Installation

1. Duplicate the example environment file and configure it with your settings. Set `ADMIN_USERNAME` and `ADMIN_PASSWORD` for the one admin login (the password may be plain text or a bcrypt hash), and fill in the `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET` values (any strings work locally):

    ```bash
    cp .env.example .env
    ```

2. Install PHP and JavaScript dependencies:

    ```bash
    composer install
    npm install
    ```

3. Generate a new PHP application key:

    ```bash
    php artisan key:generate
    ```

4. Create the SQLite database file:

    ```bash
    touch database/database.sqlite
    ```

5. Apply database migrations:

    ```bash
    php artisan migrate
    ```

6. Start the development environment, which runs the web server, queue worker, Reverb and Vite together:

    ```bash
    composer dev
    ```

Alternatively, `composer setup` installs dependencies, copies `.env`, generates the key, migrates and builds the frontend in one go.

The admin panel lives at `/admin`. Leave `ADMIN_USERNAME` and `ADMIN_PASSWORD` empty and it refuses every login.

## Game Data

The game defaults ship as CSVs in `resources/game/`: the rules (`settings.csv`), the per-player-count table (`players.csv`), presets, timers, task cards and weather cards. Edits made in the admin go in the `game_settings` and `game_cards` tables, and Reset goes back to the CSVs.

The engine in `app/Game/Engine` is plain PHP, state and action in, state and log out, ported from the Departure balance simulator. Lobbies and games live only in the cache, one entry per boarding code, with a lock around every change.

## Deployment

The app is set up to run on Laravel Cloud with a PostgreSQL database; all schema and queries are portable. Production needs a shared cache with locks (a Key-Value store with `CACHE_STORE=redis`), a queue worker for phase timers, and Cloud's managed WebSockets. Use `php artisan migrate --force` as the deploy command. See [docs/deploy-laravel-cloud.md](docs/deploy-laravel-cloud.md) for the full checklist.

## Testing

Run the Pest test suite along with Pint formatting and PHPStan checks:

```bash
composer test
```

Run every CI check, including frontend linting and type checks:

```bash
composer ci:check
```

`tests/Unit/Game/SimulatorReplayTest.php` replays games recorded from the Departure simulator through the PHP engine. To re-record them:

```bash
python3 tests/Fixtures/sim/record.py path/to/departure_sim.py
```

## Contributing

This project isn't in active development, so I'm not reviewing issues or pull requests. You're welcome to fork it and take it in your own direction under the license below.

## License

Released under the [MIT License](LICENSE).
