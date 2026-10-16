<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lobbies
    |--------------------------------------------------------------------------
    |
    | A lobby lives in the cache and closes this many minutes after the last
    | activity. Open pages send a heartbeat, so this is roughly "30 minutes
    | after the last person leaves" from the lobby plan.
    |
    */

    'lobby_idle_minutes' => (int) env('LAST_STOP_LOBBY_IDLE_MINUTES', 30),

    'heartbeat_seconds' => 300,

    'code_alphabet' => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',

    'code_length' => 4,

    'name_max' => 16,

    /*
    |--------------------------------------------------------------------------
    | Admin
    |--------------------------------------------------------------------------
    |
    | One admin login, no users table. The password may be plain text or a
    | bcrypt/argon hash (php artisan tinker: Hash::make('...')).
    |
    */

    'admin' => [
        'username' => env('ADMIN_USERNAME'),
        'password' => env('ADMIN_PASSWORD'),
        'attempts_per_minute' => 5,
    ],

];
