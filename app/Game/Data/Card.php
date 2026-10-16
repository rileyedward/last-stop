<?php

namespace App\Game\Data;

final readonly class Card
{
    public const KINDS = ['build', 'push', 'support', 'routine'];

    /** Weather specials: free_run makes the next run cost nothing. */
    public const SPECIALS = ['free_run'];

    /**
     * @param  array<string, int>  $effects  Department key => change.
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $department,
        public array $effects,
        public string $flavor,
        public string $kind,
        public string $special = '',
    ) {}

    /**
     * Build, push, support or routine, for a deck whose CSV has no kind
     * column: a lone +1 to your own department is routine, your own up and
     * someone else down is a push, your own down is support, and anything
     * else builds.
     *
     * @param  array<string, int>  $effects
     */
    public static function kindOf(?string $department, array $effects): string
    {
        if ($department === null) {
            return 'weather';
        }

        $own = $effects[$department] ?? 0;
        $others = array_filter($effects, fn (int $v, string $k) => $k !== $department && $v !== 0, ARRAY_FILTER_USE_BOTH);

        return match (true) {
            $own === 1 && $others === [] => 'routine',
            $own < 0 => 'support',
            $own > 0 && min([0, ...array_values($others)]) < 0 => 'push',
            default => 'build',
        };
    }

    /**
     * @return array{id: string, name: string, department: ?string, effects: array<string, int>, flavor: string, kind: string, special: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'department' => $this->department,
            'effects' => $this->effects,
            'flavor' => $this->flavor,
            'kind' => $this->kind,
            'special' => $this->special,
        ];
    }
}
