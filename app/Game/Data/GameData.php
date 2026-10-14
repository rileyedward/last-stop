<?php

namespace App\Game\Data;

use InvalidArgumentException;

/**
 * Everything the game reads from resources/game: rule numbers, the
 * player-count table, presets, timers and the card decks. Nothing in the
 * engine hard-codes a rule number; it all comes through here.
 */
final class GameData
{
    /** Rows of settings.csv the app uses. The rest are simulator bot settings. */
    public const RULE_KEYS = [
        'start_score', 'crisis_at', 'base_speed', 'hand_size', 'reduced_hand',
        'brake_from_night', 'brakes_per_player', 'brakes_per_game', 'brake_rest_nights',
        'brake_slow_days', 'restrict_days', 'cooldown_nights', 'overrides_per_player',
        'traitor_forecast',
    ];

    public const TIMER_KEYS = [
        'boarding_seconds', 'dawn_seconds', 'day_seconds', 'reveal_seconds',
        'night_seconds', 'vote_seconds', 'after_vote_seconds', 'inquiry_seconds',
    ];

    public const PRESETS = ['easy', 'standard', 'hard'];

    private static ?self $default = null;

    /**
     * @param  array<string, int>  $settings
     * @param  array<string, list<Card>>  $tasks  Department key => deck.
     * @param  list<Card>  $weather
     * @param  array<int, array{departments: list<string>, traitors: int, route: int, days: int, weather_per_day: int}>  $table
     * @param  array<int, array<string, int>>  $presets  Players => preset => route.
     * @param  array<string, Card>  $cards
     */
    private function __construct(
        public readonly array $settings,
        public readonly array $tasks,
        public readonly array $weather,
        public readonly array $table,
        public readonly array $presets,
        private readonly array $cards,
    ) {}

    public static function default(): self
    {
        return self::$default ??= self::load(resource_path('game'));
    }

    public static function load(string $dir): self
    {
        $raw = [];
        foreach (['settings.csv', 'timers.csv'] as $file) {
            foreach (self::rows($dir.'/'.$file) as $row) {
                $raw[trim($row['setting'])] = trim($row['value']);
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
        foreach (self::rows($dir.'/'.self::fileName($raw['tasks_csv'] ?? 'tasks.csv')) as $row) {
            $dept = Departments::keyFor($row['department']);
            $counts[$dept] = ($counts[$dept] ?? 0) + 1;
            $card = self::makeCard(sprintf('%s-%02d', Departments::CODES[$dept], $counts[$dept]), $row, $dept);
            $tasks[$dept][] = $card;
            $cards[$card->id] = $card;
        }

        $weather = [];
        foreach (self::rows($dir.'/'.self::fileName($raw['weather_csv'] ?? 'weather-winter.csv')) as $i => $row) {
            $card = self::makeCard(sprintf('WX-%02d', $i + 1), $row, null);
            $weather[] = $card;
            $cards[$card->id] = $card;
        }

        $table = [];
        foreach (self::rows($dir.'/'.self::fileName($raw['players_csv'] ?? 'players.csv')) as $row) {
            $players = (int) $row['players'];
            $table[$players] = [
                'departments' => array_map(Departments::keyFor(...), explode('|', $row['departments'])),
                'traitors' => (int) $row['traitors'],
                'route' => (int) $row['route'],
                'days' => (int) $row['days'],
                'weather_per_day' => trim($row['weather_per_day'] ?? '') !== ''
                    ? (int) $row['weather_per_day']
                    : (int) ($raw['weather_per_day'] ?? 1),
            ];
        }
        ksort($table);

        // Easy and Hard routes: players.csv columns when the simulator
        // exports them, otherwise presets.csv.
        $presets = [];
        if (is_file($dir.'/presets.csv')) {
            foreach (self::rows($dir.'/presets.csv') as $row) {
                $presets[(int) $row['players']] = ['easy' => (int) $row['easy_route'], 'hard' => (int) $row['hard_route']];
            }
        }
        foreach (self::rows($dir.'/'.self::fileName($raw['players_csv'] ?? 'players.csv')) as $row) {
            foreach (['easy', 'hard'] as $preset) {
                if (trim($row[$preset.'_route'] ?? '') !== '') {
                    $presets[(int) $row['players']][$preset] = (int) $row[$preset.'_route'];
                }
            }
        }

        return new self($settings, $tasks, $weather, $table, $presets, $cards);
    }

    public function card(string $id): Card
    {
        return $this->cards[$id] ?? throw new InvalidArgumentException("Unknown card [{$id}].");
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
     * The rule numbers for one game: settings.csv, the player-count row and
     * the chosen preset's route, merged into one flat set.
     *
     * @return array{rules: array<string, int>, departments: list<string>}
     */
    public function rulesFor(int $players, string $preset = 'standard'): array
    {
        $row = $this->table[$players] ?? throw new InvalidArgumentException("No setup for {$players} players.");

        $route = $row['route'];
        if ($preset !== 'standard' && isset($this->presets[$players][$preset])) {
            $route = $this->presets[$players][$preset];
        }

        return [
            'rules' => [
                ...$this->settings,
                'route' => $route,
                'days' => $row['days'],
                'traitors' => $row['traitors'],
                'weather_per_day' => $row['weather_per_day'],
            ],
            'departments' => $row['departments'],
        ];
    }

    /**
     * @param  array<string, string>  $row
     */
    private static function makeCard(string $id, array $row, ?string $dept): Card
    {
        $effects = [];
        foreach (array_keys(Departments::NAMES) as $key) {
            $value = (int) ($row[$key] ?? 0);
            if ($value !== 0) {
                $effects[$key] = $value;
            }
        }
        $distance = (int) ($row['distance'] ?? 0);
        $kind = strtolower(trim($row['kind'] ?? ''));
        if ($dept === null || ! in_array($kind, Card::KINDS, true)) {
            $kind = Card::kindOf($dept, $effects, $distance);
        }

        return new Card(
            id: $id,
            name: trim($row['card']),
            department: $dept,
            effects: $effects,
            distance: $distance,
            flavor: trim($row['flavor'] ?? ''),
            kind: $kind,
        );
    }

    private static function fileName(string $path): string
    {
        return basename(trim($path));
    }

    /**
     * @return list<array<string, string>>
     */
    private static function rows(string $path): array
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new InvalidArgumentException("Cannot read game data file [{$path}].");
        }

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
                $row[$name] = (string) ($line[$i] ?? '');
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }
}
