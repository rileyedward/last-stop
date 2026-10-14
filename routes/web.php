<?php

use App\Http\Controllers\Game\BoardController;
use App\Http\Controllers\Game\HomeController;
use App\Http\Controllers\Game\LobbyController;
use App\Http\Middleware\EnsurePlayerToken;
use Illuminate\Support\Facades\Route;

Route::middleware(EnsurePlayerToken::class)->group(function () {
    Route::get('/', [HomeController::class, 'show'])->name('home');
    Route::get('name', [HomeController::class, 'editName'])->name('name.edit');
    Route::post('name', [HomeController::class, 'storeName'])->name('name.store');

    Route::post('lobbies', [LobbyController::class, 'store'])->name('lobbies.store');
    Route::get('join', [LobbyController::class, 'join'])->name('join');
    Route::post('join', [LobbyController::class, 'find'])->name('join.find');

    Route::prefix('board/{code}')->where(['code' => '[A-Za-z0-9]{4}'])->name('board.')->group(function () {
        Route::get('/', [BoardController::class, 'show'])->name('show');
        Route::get('station', [BoardController::class, 'station'])->name('station');
        Route::post('join', [LobbyController::class, 'board'])->name('join');
        Route::post('heartbeat', [BoardController::class, 'heartbeat'])->name('heartbeat');
        Route::post('leave', [BoardController::class, 'leave'])->name('leave');
        Route::post('ready', [BoardController::class, 'ready'])->name('ready');
        Route::post('preset', [BoardController::class, 'preset'])->name('preset');
        Route::post('start', [BoardController::class, 'start'])->name('start');
        Route::post('kick', [BoardController::class, 'kick'])->name('kick');
        Route::post('host', [BoardController::class, 'host'])->name('host');
        Route::post('wins/reset', [BoardController::class, 'resetWins'])->name('wins.reset');
        Route::post('back', [BoardController::class, 'back'])->name('back');
        Route::post('pause', [BoardController::class, 'pause'])->name('pause');
        Route::post('resume', [BoardController::class, 'resume'])->name('resume');
        Route::post('end', [BoardController::class, 'end'])->name('end');
        Route::post('act', [BoardController::class, 'act'])->name('act');
    });
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
