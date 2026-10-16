<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin edits to the game's defaults. Each row replaces one shipped CSV
     * source (rules, timers, the player-count table, presets, a card deck);
     * a missing row means the shipped CSV is used.
     */
    public function up(): void
    {
        Schema::create('game_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value');
            $table->timestamps();
        });

        Schema::create('game_cards', function (Blueprint $table) {
            $table->string('deck')->primary();
            $table->json('rows');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_cards');
        Schema::dropIfExists('game_settings');
    }
};
