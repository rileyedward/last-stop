<?php

namespace App\Game\Data;

final class Departments
{
    /**
     * Department names as the CSVs write them, keyed by the column key.
     *
     * @var array<string, string>
     */
    public const NAMES = [
        'engineering' => 'Engineering',
        'operations' => 'Operations',
        'passenger_services' => 'Passenger Services',
        'security' => 'Security',
    ];

    /** @var array<string, string> */
    public const CODES = [
        'engineering' => 'ENG',
        'operations' => 'OPS',
        'passenger_services' => 'PAS',
        'security' => 'SEC',
    ];

    public static function keyFor(string $name): string
    {
        $key = array_search(trim($name), self::NAMES, true);

        if ($key === false) {
            throw new \InvalidArgumentException("Unknown department [{$name}].");
        }

        return $key;
    }
}
