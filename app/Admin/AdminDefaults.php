<?php

namespace App\Admin;

use App\Game\Data\GameData;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * The admin's edits to the game's defaults, kept in two small tables:
 * game_settings (settings, timers, players, presets) and game_cards (tasks,
 * weather). Each stored row replaces one shipped CSV source as a whole; a
 * source with no row uses the CSV in resources/game.
 */
final class AdminDefaults
{
    public const SETTING_SOURCES = ['settings', 'timers', 'players', 'presets'];

    public const DECKS = ['tasks', 'weather'];

    /**
     * The game data with the admin's edits applied. If the stored edits are
     * unreadable or no longer valid, the shipped CSVs are used instead.
     */
    public function gameData(): GameData
    {
        $overrides = $this->overrides();
        if ($overrides === []) {
            return GameData::shipped();
        }

        try {
            return GameData::load(resource_path('game'), $overrides);
        } catch (InvalidArgumentException $e) {
            report($e);

            return GameData::shipped();
        }
    }

    /**
     * Stored sources, keyed by source name. Empty when the tables don't
     * exist yet (a fresh install before migrating).
     *
     * @return array<string, list<array<string, string>>>
     */
    public function overrides(): array
    {
        try {
            $settings = DB::table('game_settings')->whereIn('key', self::SETTING_SOURCES)->pluck('value', 'key');
            $cards = DB::table('game_cards')->whereIn('deck', self::DECKS)->pluck('rows', 'deck');
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ([...$settings->all(), ...$cards->all()] as $source => $json) {
            $rows = json_decode(is_string($json) ? $json : '', true);
            if (is_array($rows)) {
                $out[(string) $source] = array_values(array_map(
                    fn ($row) => array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', is_array($row) ? $row : []),
                    $rows,
                ));
            }
        }

        return $out;
    }

    /**
     * Which sources the admin has changed.
     *
     * @return list<string>
     */
    public function edited(): array
    {
        return array_keys($this->overrides());
    }

    /**
     * Check a set of replacement sources against everything else, then
     * store them. Throws with a message fit for the admin if they don't fit.
     *
     * @param  array<string, list<array<string, string>>>  $sources
     */
    public function save(array $sources): void
    {
        $current = $this->gameData()->rows;
        GameData::fromRows([...$current, ...$sources]);

        DB::transaction(function () use ($sources) {
            foreach ($sources as $source => $rows) {
                $json = json_encode($rows, JSON_THROW_ON_ERROR);
                if (in_array($source, self::DECKS, true)) {
                    DB::table('game_cards')->updateOrInsert(['deck' => $source], ['rows' => $json, 'updated_at' => now(), 'created_at' => now()]);
                } elseif (in_array($source, self::SETTING_SOURCES, true)) {
                    DB::table('game_settings')->updateOrInsert(['key' => $source], ['value' => $json, 'updated_at' => now(), 'created_at' => now()]);
                }
            }
        });

        $this->refresh();
    }

    /**
     * Go back to the shipped CSVs for these sources.
     *
     * @param  list<string>  $sources
     */
    public function reset(array $sources): void
    {
        DB::table('game_settings')->whereIn('key', $sources)->delete();
        DB::table('game_cards')->whereIn('deck', $sources)->delete();
        $this->refresh();
    }

    private function refresh(): void
    {
        app()->forgetInstance(GameData::class);
    }
}
