<?php

namespace App\Game\Data;

final readonly class Card
{
    /**
     * @param  array<string, int>  $effects  Department key => change.
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $department,
        public array $effects,
        public int $distance,
        public string $flavor,
        public string $kind,
    ) {}

    /**
     * Speed, repair, support or routine. The CSVs don't say, so it is read
     * from the numbers: distance up is speed, a lone +1 to your own
     * department is routine, raising your own department is repair, and
     * anything else helps someone else.
     *
     * @param  array<string, int>  $effects
     */
    public static function kindOf(?string $department, array $effects, int $distance): string
    {
        if ($department === null) {
            return 'weather';
        }

        if ($distance > 0) {
            return 'speed';
        }

        $own = $effects[$department] ?? 0;
        $others = array_filter($effects, fn (int $v, string $k) => $k !== $department && $v !== 0, ARRAY_FILTER_USE_BOTH);

        if ($own === 1 && $others === [] && $distance === 0) {
            return 'routine';
        }

        return $own > 0 ? 'repair' : 'support';
    }

    /**
     * @return array{id: string, name: string, department: ?string, effects: array<string, int>, distance: int, flavor: string, kind: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'department' => $this->department,
            'effects' => $this->effects,
            'distance' => $this->distance,
            'flavor' => $this->flavor,
            'kind' => $this->kind,
        ];
    }
}
