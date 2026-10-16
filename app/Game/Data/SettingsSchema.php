<?php

namespace App\Game\Data;

/**
 * Labels, groups and limits for every number a host or the admin can set,
 * in the order the settings screens show them. The rulebook's section 9.
 */
final class SettingsSchema
{
    /**
     * @var array<string, array{label: string, group: string, min: int, max: int, kind: string, help?: string}>
     */
    public const FIELDS = [
        'legs' => ['label' => 'Legs', 'group' => 'The line', 'min' => 1, 'max' => 9, 'kind' => 'number', 'help' => 'Stations from Hollow Pines to Last Stop'],
        'days' => ['label' => 'Days', 'group' => 'The line', 'min' => 1, 'max' => 30, 'kind' => 'number'],
        'weather_per_day' => ['label' => 'Weather cards each dawn', 'group' => 'The line', 'min' => 0, 'max' => 12, 'kind' => 'number'],
        'start_score' => ['label' => 'Department starting score', 'group' => 'The line', 'min' => 0, 'max' => 20, 'kind' => 'number'],
        'score_max' => ['label' => 'Top of the score range', 'group' => 'The line', 'min' => 5, 'max' => 20, 'kind' => 'number'],
        'line_start' => ['label' => 'Starting line', 'group' => 'The line', 'min' => 0, 'max' => 20, 'kind' => 'number'],
        'line_rise' => ['label' => 'Line rises by', 'group' => 'The line', 'min' => 0, 'max' => 5, 'kind' => 'number'],
        'line_rise_every' => ['label' => 'Line rises every (legs)', 'group' => 'The line', 'min' => 0, 'max' => 9, 'kind' => 'number', 'help' => '0 = never rises'],
        'run_cost' => ['label' => 'Run cost per department', 'group' => 'The line', 'min' => 0, 'max' => 5, 'kind' => 'number'],
        'crisis_at' => ['label' => 'Crisis at or below', 'group' => 'The line', 'min' => 0, 'max' => 10, 'kind' => 'number'],
        'hand_size' => ['label' => 'Hand size', 'group' => 'Hands', 'min' => 1, 'max' => 6, 'kind' => 'number'],
        'reduced_hand' => ['label' => 'Hand in crisis or escorting', 'group' => 'Hands', 'min' => 1, 'max' => 6, 'kind' => 'number'],
        'traitors' => ['label' => 'Traitors', 'group' => 'The cord', 'min' => 0, 'max' => 3, 'kind' => 'number'],
        'cord_pulls' => ['label' => 'Cord pulls per game', 'group' => 'The cord', 'min' => 0, 'max' => 5, 'kind' => 'number'],
        'cord_green_only' => ['label' => 'Cord only on a green signal', 'group' => 'The cord', 'min' => 0, 'max' => 1, 'kind' => 'switch'],
        'cord_clue' => ['label' => 'Names in a cord clue', 'group' => 'The cord', 'min' => 0, 'max' => 10, 'kind' => 'number', 'help' => '0 turns clues off'],
        'traitor_must_pull' => ['label' => 'Traitor must pull at least once', 'group' => 'The cord', 'min' => 0, 'max' => 1, 'kind' => 'switch'],
        'detain_days' => ['label' => 'Detain length (days)', 'group' => 'Detaining', 'min' => 1, 'max' => 5, 'kind' => 'number'],
        'cooldown_nights' => ['label' => 'Detain cooldown (nights)', 'group' => 'Detaining', 'min' => 0, 'max' => 5, 'kind' => 'number'],
        'one_detained_at_a_time' => ['label' => 'One detained player at a time', 'group' => 'Detaining', 'min' => 0, 'max' => 1, 'kind' => 'switch'],
        'detained_can_pull' => ['label' => 'Detained players can pull the cord', 'group' => 'Detaining', 'min' => 0, 'max' => 1, 'kind' => 'switch'],
        'escort_vetoes_per_day' => ['label' => 'Escort vetoes per day', 'group' => 'Detaining', 'min' => 0, 'max' => 3, 'kind' => 'number'],
        'overrides_per_player' => ['label' => 'Overrides per game', 'group' => 'Detaining', 'min' => 0, 'max' => 3, 'kind' => 'number'],
        'crisis_loss' => ['label' => 'Two departments in crisis lose', 'group' => 'Winning', 'min' => 0, 'max' => 1, 'kind' => 'switch'],
        'accusation' => ['label' => 'Arrival vote required to win', 'group' => 'Winning', 'min' => 0, 'max' => 1, 'kind' => 'switch'],
        'boarding_seconds' => ['label' => 'Boarding', 'group' => 'Timers', 'min' => 5, 'max' => 600, 'kind' => 'seconds'],
        'dawn_seconds' => ['label' => 'Dawn', 'group' => 'Timers', 'min' => 5, 'max' => 600, 'kind' => 'seconds'],
        'day_seconds' => ['label' => 'Day', 'group' => 'Timers', 'min' => 10, 'max' => 600, 'kind' => 'seconds'],
        'reveal_seconds' => ['label' => 'Reveal', 'group' => 'Timers', 'min' => 5, 'max' => 600, 'kind' => 'seconds'],
        'dusk_seconds' => ['label' => 'Dusk cord window', 'group' => 'Timers', 'min' => 5, 'max' => 120, 'kind' => 'seconds'],
        'departure_seconds' => ['label' => 'Departure result', 'group' => 'Timers', 'min' => 5, 'max' => 600, 'kind' => 'seconds'],
        'night_seconds' => ['label' => 'Night', 'group' => 'Timers', 'min' => 10, 'max' => 900, 'kind' => 'seconds'],
        'vote_seconds' => ['label' => 'Detain vote', 'group' => 'Timers', 'min' => 5, 'max' => 300, 'kind' => 'seconds'],
        'after_vote_seconds' => ['label' => 'Night left after a vote', 'group' => 'Timers', 'min' => 0, 'max' => 300, 'kind' => 'seconds'],
        'arrival_seconds' => ['label' => 'Arrival vote', 'group' => 'Timers', 'min' => 10, 'max' => 600, 'kind' => 'seconds'],
    ];

    /**
     * Keep only known keys, as whole numbers within their limits.
     *
     * @param  array<mixed>  $values
     * @return array<string, int>
     */
    public static function clean(array $values): array
    {
        $out = [];
        foreach (self::FIELDS as $key => $field) {
            if (! array_key_exists($key, $values) || ! is_numeric($values[$key])) {
                continue;
            }
            $out[$key] = max($field['min'], min($field['max'], (int) $values[$key]));
        }

        return $out;
    }

    /**
     * Problems with a set of values, keyed by field, for the admin form.
     *
     * @param  array<mixed>  $values
     * @return array<string, string>
     */
    public static function errors(array $values): array
    {
        $errors = [];
        foreach ($values as $key => $value) {
            $field = self::FIELDS[$key] ?? null;
            if ($field === null) {
                continue;
            }
            if (! is_numeric($value) || ! preg_match('/^-?\d+$/', trim((string) $value))) {
                $errors[$key] = "{$field['label']} must be a whole number.";
            } elseif ((int) $value < $field['min'] || (int) $value > $field['max']) {
                $errors[$key] = "{$field['label']} must be between {$field['min']} and {$field['max']}.";
            }
        }

        return $errors;
    }

    /**
     * The fields as the screens need them.
     *
     * @return list<array{key: string, label: string, group: string, min: int, max: int, kind: string, help: string}>
     */
    public static function forScreens(): array
    {
        $out = [];
        foreach (self::FIELDS as $key => $field) {
            $out[] = ['key' => $key, 'help' => '', ...$field];
        }

        return $out;
    }
}
