<?php

namespace App\Game\Engine;

use Random\Randomizer;

/**
 * A draw pile and a discard pile of card ids. Cards come off the end of the
 * pile, and an empty pile reshuffles the discards, as in the simulator.
 */
final class Deck
{
    /**
     * @param  list<string>  $pile
     * @param  list<string>  $discard
     */
    public function __construct(
        public array $pile = [],
        public array $discard = [],
    ) {}

    /**
     * @param  list<string>  $ids
     */
    public static function shuffled(array $ids, Randomizer $rng): self
    {
        return new self($rng->shuffleArray($ids));
    }

    /**
     * @return list<string>
     */
    public function draw(int $count, Randomizer $rng): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $this->refill($rng);
            if ($this->pile === []) {
                break;
            }
            $out[] = array_pop($this->pile);
        }

        return $out;
    }

    public function peek(Randomizer $rng): ?string
    {
        $this->refill($rng);

        return $this->pile === [] ? null : $this->pile[count($this->pile) - 1];
    }

    /**
     * @param  list<string>  $ids
     */
    public function discard(array $ids): void
    {
        array_push($this->discard, ...$ids);
    }

    private function refill(Randomizer $rng): void
    {
        if ($this->pile === [] && $this->discard !== []) {
            $this->pile = $rng->shuffleArray($this->discard);
            $this->discard = [];
        }
    }

    /**
     * @return array{pile: list<string>, discard: list<string>}
     */
    public function toArray(): array
    {
        return ['pile' => $this->pile, 'discard' => $this->discard];
    }

    /**
     * @param  array{pile: list<string>, discard: list<string>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['pile'], $data['discard']);
    }
}
