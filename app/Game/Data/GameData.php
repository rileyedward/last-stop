<?php

namespace App\Game\Data;

use InvalidArgumentException;

/**
 * Everything the game reads: rule numbers, the player-count table, presets,
 * timers and the card decks. The shipped defaults live as CSVs in
 * resources/game; admin edits replace whole sources (see AdminDefaults).
 * Nothing in the engine hard-codes a rule number; it all comes through here.
 */
final class GameData
{
    /** Rows of settings.csv the app uses. */
    public const RULE_KEYS = [
        'score_max', 'crisis_at', 'line_rise', 'line_rise_every', 'run_cost',
        'hand_size', 'reduced_hand', 'cord_pulls', 'cord_green_only', 'cord_clue',
        'traitor_must_pull', 'detain_days', 'cooldown_nights', 'one_detained_at_a_time',
        'detained_can_pull', 'escort_vetoes_per_day', 'overrides_per_player',
        'crisis_loss', 'accusation',
    ];

    /** Rules that are on/off switches. */
    public const SWITCH_KEYS = [
        'cord_green_only', 'traitor_must_pull', 'one_detained_at_a_time',
        'detained_can_pull', 'crisis_loss', 'accusation',
    ];

    /** Columns of players.csv, set per player count. */
    public const TABLE_KEYS = ['traitors', 'legs', 'days', 'weather_per_day', 'start_score', 'line_start', 'cord_clue'];

    public const TIMER_KEYS = [
        'boarding_seconds', 'dawn_seconds', 'day_seconds', 'reveal_seconds', 'dusk_seconds',
        'departure_seconds', 'night_seconds', 'vote_seconds', 'after_vote_seconds', 'arrival_seconds',
    ];

    public const PRESETS = ['easy', 'standard', 'hard'];

    /** The data sources, as CSV file names and their columns. */
    public const SOURCES = [
        'settings' => ['file' => 'settings.csv', 'columns' => ['setting', 'value', 'notes']],
        'timers' => ['file' => 'timers.csv', 'columns' => ['setting', 'value', 'notes']],
        'players' => ['file' => 'players.csv', 'columns' => ['players', 'departments', 'traitors', 'legs', 'days', 'weather_per_day', 'start_score', 'line_start', 'cord_clue']],
        'presets' => ['file' => 'presets.csv', 'columns' => ['preset', 'days_offset', 'notes']],
        'tasks' => ['file' => 'tasks.csv', 'columns' => ['department', 'card', 'kind', 'engineering', 'operations', 'passenger_services', 'security', 'flavor']],
        'weather' => ['file' => 'weather.csv', 'columns' => ['card', 'engineering', 'operations', 'passenger_services', 'security', 'special', 'flavor']],
    ];

    /**
     * @param  array<string, int>  $settings  Rule and timer numbers.
     * @param  array<string, list<Card>>  $tasks  Department key => deck.
     * @param  list<Card>  $weather
     * @param  array<int, array{departments: list<string>, traitors: int, legs: int, days: int, weather_per_day: int, start_score: int, line_start: int, cord_clue: int}>  $table
     * @param  array<string, int>  $presets  Preset => days offset.
     * @param  array<string, Card>  $cards
     * @param  array<string, list<array<string, string>>>  $rows  The sources as read.
     */
    private function __construct(
        public readonly array $settings,
        public readonly array $tasks,
        public readonly array $weather,
        public readonly array $table,
        public readonly array $presets,
        private readonly array $cards,
        public readonly array $rows,
    ) {}

    /**
     * The shipped defaults in resources/game, without admin edits.
     */
    public static function shipped(): self
    {
        return self::load(resource_path('game'));
    }

    /**
     * Read the CSVs in $dir; any source in $overrides replaces its file.
     *
     * @param  array<string, list<array<string, string>>>  $overrides
     */
    public static function load(string $dir, array $overrides = []): self
    {
        $rows = [];
        foreach (self::SOURCES as $source => $meta) {
            $rows[$source] = $overrides[$source] ?? self::csvRows($dir.'/'.$meta['file']);
        }

        return self::fromRows($rows);
    }

    /**
     * Build the game data from source rows, checking everything the game
     * needs is there. Throws with a message fit to show an admin.
     *
     * @param  array<string, list<array<string, string>>>  $rows
     */
    public static function fromRows(array $rows): self
    {
        $raw = [];
        foreach (['settings', 'timers'] as $source) {
            foreach ($rows[$source] ?? [] as $row) {
                $raw[trim($row['setting'] ?? '')] = trim($row['value'] ?? '');
            }
        }

        $settings = [];
        foreach ([...self::RULE_KEYS, ...self::TIMER_KEYS] as $key) {
            if (! isset($raw[$key]) || ! is_numeric($raw[$key])) {
                throw new InvalidArgumentException("Game setting [{$key}] is missing or not a number.");
            }
            $settings[$key] = (int) $raw[$key];
        }

        $cards = [];
        $tasks = [];
        $counts = [];
        foreach ($rows['tasks'] ?? [] as $i => $row) {
            $dept = self::department($row['department'] ?? '', 'tasks', $i);
            $counts[$dept] = ($counts[$dept] ?? 0) + 1;
            $card = self::makeCard(sprintf('%s-%02d', Departments::CODES[$dept], $counts[$dept]), $row, $dept, $i, 'tasks');
            $tasks[$dept][] = $card;
            $cards[$card->id] = $card;
        }

        $weather = [];
        foreach ($rows['weather'] ?? [] as $i => $row) {
            $card = self::makeCard(sprintf('WX-%02d', $i + 1), $row, null, $i, 'weather');
            $weather[] = $card;
            $cards[$card->id] = $card;
        }
        if ($weather === []) {
            throw new InvalidArgumentException('The weather deck has no cards.');
        }

        $defaultClue = $settings['cord_clue'] ?? 0;
        $table = [];
        foreach ($rows['players'] ?? [] as $i => $row) {
            $players = self::number($row, 'players', 'players', $i);
            $departments = array_map(
                fn (string $name) => self::department($name, 'players', $i),
                array_filter(array_map('trim', explode('|', $row['departments'] ?? '')), fn ($d) => $d !== ''),
            );
            if ($departments === []) {
                throw new InvalidArgumentException('players row '.($i + 1).' has no departments.');
            }
            foreach ($departments as $dept) {
                if (($tasks[$dept] ?? []) === []) {
                    throw new InvalidArgumentException('players row '.($i + 1).' uses '.Departments::NAMES[$dept].', which has no task cards.');
                }
            }
            $table[$players] = [
                'departments' => array_values($departments),
                'traitors' => self::number($row, 'traitors', 'players', $i),
                'legs' => self::number($row, 'legs', 'players', $i),
                'days' => self::number($row, 'days', 'players', $i),
                'weather_per_day' => self::number($row, 'weather_per_day', 'players', $i),
                'start_score' => self::number($row, 'start_score', 'players', $i),
                'line_start' => self::number($row, 'line_start', 'players', $i),
                'cord_clue' => trim($row['cord_clue'] ?? '') !== ''
                    ? self::number($row, 'cord_clue', 'players', $i)
                    : $defaultClue,
            ];
        }
        if ($table === []) {
            throw new InvalidArgumentException('The player-count table is empty.');
        }
        ksort($table);

        $presets = ['standard' => 0];
        foreach ($rows['presets'] ?? [] as $i => $row) {
            $preset = strtolower(trim($row['preset'] ?? ''));
            if (in_array($preset, self::PRESETS, true)) {
                $presets[$preset] = self::number($row, 'days_offset', 'presets', $i, true);
            }
        }

        return new self($settings, $tasks, $weather, $table, $presets, $cards, $rows);
    }

    public function card(string $id): Card
    {
        return $this->cards[$id] ?? throw new InvalidArgumentException("Unknown card [{$id}].");
    }

    public function hasCard(string $id): bool
    {
        return isset($this->cards[$id]);
    }

    public function minPlayers(): int
    {
        $counts = array_keys($this->table);

        return $counts === [] ? 0 : min($counts);
    }

    public function maxPlayers(): int
    {
        $counts = array_keys($this->table);

        return $counts === [] ? 0 : max($counts);
    }

    /**
     * The rule numbers for one game: settings and timers, the player-count
     * row with the preset's day offset, then any custom values on top.
     *
     * @param  array<string, int>  $custom
     * @return array{rules: array<string, int>, departments: list<string>}
     */
    public function rulesFor(int $players, string $preset = 'standard', array $custom = []): array
    {
        $row = $this->table[$players] ?? throw new InvalidArgumentException("No setup for {$players} players.");

        $rules = [
            ...$this->settings,
            'traitors' => $row['traitors'],
            'legs' => $row['legs'],
            'days' => max(1, $row['days'] + ($this->presets[$preset] ?? 0)),
            'weather_per_day' => $row['weather_per_day'],
            'start_score' => $row['start_score'],
            'line_start' => $row['line_start'],
            'cord_clue' => $row['cord_clue'],
        ];
        foreach ($custom as $key => $value) {
            if (array_key_exists($key, $rules)) {
                $rules[$key] = $value;
            }
        }

        return ['rules' => $rules, 'departments' => $row['departments']];
    }

    /**
     * Every key a host can set in a custom lobby.
     *
     * @return list<string>
     */
    public static function customKeys(): array
    {
        return [...self::TABLE_KEYS, ...self::RULE_KEYS, ...self::TIMER_KEYS];
    }

    /**
     * Rows as CSV text, for export.
     */
    public function csv(string $source): string
    {
        $columns = self::SOURCES[$source]['columns'] ?? throw new InvalidArgumentException("Unknown source [{$source}].");
        $handle = fopen('php://temp', 'r+');
        assert($handle !== false);
        fputcsv($handle, $columns, escape: '\\');
        foreach ($this->rows[$source] ?? [] as $row) {
            fputcsv($handle, array_map(fn (string $c) => $row[$c] ?? '', $columns), escape: '\\');
        }
        rewind($handle);
        $text = (string) stream_get_contents($handle);
        fclose($handle);

        return $text;
    }

    /**
     * @param  array<string, string>  $row
     */
    private static function makeCard(string $id, array $row, ?string $dept, int $index, string $source): Card
    {
        $name = trim($row['card'] ?? '');
        if ($name === '') {
            throw new InvalidArgumentException("{$source} row ".($index + 1).' has no card name.');
        }
        $effects = [];
        foreach (array_keys(Departments::NAMES) as $key) {
            $value = trim($row[$key] ?? '');
            if ($value !== '' && ! preg_match('/^-?\d+$/', $value)) {
                throw new InvalidArgumentException("{$source} row ".($index + 1)." ({$name}): {$key} must be a whole number.");
            }
            if ((int) $value !== 0) {
                $effects[$key] = (int) $value;
            }
        }
        $kind = strtolower(trim($row['kind'] ?? ''));
        if ($dept === null || ! in_array($kind, Card::KINDS, true)) {
            $kind = Card::kindOf($dept, $effects);
        }
        $special = strtolower(trim($row['special'] ?? ''));
        if ($special !== '' && ! in_array($special, Card::SPECIALS, true)) {
            throw new InvalidArgumentException("{$source} row ".($index + 1)." ({$name}): unknown special [{$special}].");
        }

        return new Card(
            id: $id,
            name: $name,
            department: $dept,
            effects: $effects,
            flavor: trim($row['flavor'] ?? ''),
            kind: $kind,
            special: $special,
        );
    }

    private static function department(string $name, string $source, int $index): string
    {
        try {
            return Departments::keyFor($name);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException("{$source} row ".($index + 1).": unknown department [{$name}].");
        }
    }

    /**
     * @param  array<string, string>  $row
     */
    private static function number(array $row, string $column, string $source, int $index, bool $signed = false): int
    {
        $value = trim($row[$column] ?? '');
        if (! preg_match($signed ? '/^-?\d+$/' : '/^\d+$/', $value)) {
            throw new InvalidArgumentException("{$source} row ".($index + 1).": {$column} must be a whole number.");
        }

        return (int) $value;
    }

    /**
     * @return list<array<string, string>>
     */
    public static function csvRows(string $path): array
    {
        $text = @file_get_contents($path);
        if ($text === false) {
            throw new InvalidArgumentException("Cannot read game data file [{$path}].");
        }

        return self::parseCsv($text);
    }

    /**
     * @return list<array<string, string>>
     */
    public static function parseCsv(string $text): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $handle = fopen('php://temp', 'r+');
        assert($handle !== false);
        fwrite($handle, $text);
        rewind($handle);

        $header = fgetcsv($handle, escape: '\\');
        if ($header === false) {
            fclose($handle);

            return [];
        }
        $header = array_map(fn ($h) => trim((string) $h), $header);

        $rows = [];
        while (($line = fgetcsv($handle, escape: '\\')) !== false) {
            if ($line === [null]) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $name) {
                $row[$name] = trim((string) ($line[$i] ?? ''));
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }
}
