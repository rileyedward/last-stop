# Deploying Last Stop to Laravel Cloud

A checklist for getting the `v2` branch (Departure) onto Laravel Cloud for a playtest. Names of buttons and resources can shift in the Cloud dashboard; the [Cloud docs](https://cloud.laravel.com/docs) are the reference.

## What production needs, and why

| Need                      | Why                                                                                                                     | On Cloud                                                                                                                                                                                                              |
| ------------------------- | ----------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| A shared cache with locks | Lobbies and games live only in the cache, and every move takes a cache lock. All app instances must see the same cache. | A Key-Value store (Valkey/Redis), with `CACHE_STORE=redis`. The database cache also works if you'd rather not add a store.                                                                                            |
| A queue worker            | Phase timers wake up through a delayed `AdvanceGame` job.                                                               | A queue worker on the app cluster (or a queue cluster). Any page that is open also moves the game on when its timer hits zero, so a missing worker slows nothing down while people are playing, but keep one running. |
| WebSockets                | Instant updates between phones.                                                                                         | Cloud's managed WebSockets (Reverb). Without them the game still works: pages poll every 3 seconds.                                                                                                                   |
| A database                | Sessions, the jobs table, and the admin's edits to the game defaults (`game_settings`, `game_cards`). No raw SQL.       | Laravel Postgres. The standard migrations run as is.                                                                                                                                                                  |

## Steps

1. **Create the app** from the GitHub repo and point the environment at the branch you deploy (`v2` for Departure).
2. **Attach resources** to the environment:
    - a Postgres database,
    - a Key-Value store (cache),
    - WebSockets (managed Reverb).
3. **Environment variables** (Cloud fills in the database, cache and Reverb connection details when you attach those resources; check they're there):
    ```
    APP_NAME="Last Stop"
    APP_ENV=production
    APP_DEBUG=false
    DB_CONNECTION=pgsql
    CACHE_STORE=redis
    SESSION_DRIVER=database
    QUEUE_CONNECTION=database
    BROADCAST_CONNECTION=reverb
    INERTIA_SSR_ENABLED=false
    ADMIN_USERNAME=...
    ADMIN_PASSWORD=...
    ```
    - `ADMIN_USERNAME` and `ADMIN_PASSWORD` are the one login for `/admin`. The password can be a bcrypt hash (`Hash::make('...')` in tinker) so the plain text never sits in the dashboard. Leave them empty and `/admin` refuses every login.
    - The `VITE_REVERB_*` variables must exist **at build time**, because the browser bundle bakes them in. If Cloud only injects `REVERB_*`, add `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT` (443) and `VITE_REVERB_SCHEME` (https) yourself, with the same values.
    - `INERTIA_SSR_ENABLED=false` keeps it simple. To use server-side rendering instead, turn on Cloud's Inertia SSR option, build with `npm run build:ssr`, and leave it at `true`.
4. **Build commands**: `composer install --no-dev --optimize-autoloader`, then `npm ci && npm run build` (or `npm run build:ssr`, see above).
5. **Deploy command**: `php artisan migrate --force`. On v2 this adds the `game_settings` and `game_cards` tables the admin saves into.
6. **Queue worker**: enable one for the environment (`php artisan queue:work`).
7. **Hibernation**: turn it off for the evening of the playtest, so the first tap of the night isn't slow and the worker keeps running.
8. **Smoke test** after deploying:
    - open the site on your phone, type a name, start a lobby,
    - open the invite link in a private window on another device and board,
    - check the passenger list updates on both without refreshing (that's Reverb working),
    - open `/board/CODE/station` on a laptop,
    - sign in at `/admin` and check the Rules and Cards pages load.

## Good to know

- **A cache flush or a cache restart ends every lobby in progress.** That's by design for the prototype (tech plan, Part 2).
- **Lobbies expire** 30 minutes after the last activity (`LAST_STOP_LOBBY_IDLE_MINUTES`). Open pages send a heartbeat, so an active table never expires.
- **No accounts.** v2 removed the starter kit's register, login and settings pages. Players are guests; the only login is the admin's.
- **Rule numbers and cards** ship in `resources/game/*.csv`. The admin can change every default and import new decks at `/admin` without a redeploy; Reset goes back to the CSVs. Edit cards between games: a game in progress refers to its cards by position in the deck.
