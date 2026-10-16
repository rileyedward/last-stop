<?php

namespace App\Game\Engine;

final class Player
{
    // Where a player's pick stands during the day.
    public const PICK_NONE = 'none';

    public const PICK_LOCKED = 'locked';     // unwatched pick, can still be changed

    public const PICK_PENDING = 'pending';   // detained player's pick waiting on the escort

    public const PICK_REPICK = 'repick';     // vetoed, must pick again or override

    public const PICK_FINAL = 'final';       // detained player's pick that will be played

    public int $overridesLeft = 0;

    /** Days of detention left; 0 means free. */
    public int $detainedDays = 0;

    /** The detained player this player escorts. */
    public ?string $escortOf = null;

    /** Vetoes this escort has left today. */
    public int $vetoesLeft = 0;

    /** Nights before this player can be nominated again. */
    public int $cooldown = 0;

    public bool $everDetained = false;

    /** Removed from the lobby mid-game: the seat plays itself. */
    public bool $absent = false;

    public bool $ready = false;

    /** @var list<string> */
    public array $hand = [];

    public ?string $pick = null;

    public string $pickState = self::PICK_NONE;

    /**
     * Cards the escort vetoed today, in order.
     *
     * @var list<string>
     */
    public array $vetoed = [];

    public ?string $escortDecision = null;

    public bool $overrode = false;

    public bool $autoPicked = false;

    /** Yes or no on tonight's detention vote. */
    public ?bool $vote = null;

    /** Who this player names in the arrival vote. */
    public ?string $accuse = null;

    public function __construct(
        public string $id,
        public string $name,
        public string $department,
        public bool $traitor,
    ) {}

    public function isDetained(): bool
    {
        return $this->detainedDays > 0;
    }

    public function isEscorting(): bool
    {
        return $this->escortOf !== null;
    }

    public function pickIsFinal(): bool
    {
        return in_array($this->pickState, [self::PICK_LOCKED, self::PICK_FINAL], true);
    }

    public function resetForDay(): void
    {
        $this->hand = [];
        $this->pick = null;
        $this->pickState = self::PICK_NONE;
        $this->vetoed = [];
        $this->escortDecision = null;
        $this->overrode = false;
        $this->autoPicked = false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $r = new Reader($data);
        $p = new self($r->string('id'), $r->string('name'), $r->string('department'), $r->bool('traitor'));
        $p->overridesLeft = $r->int('overridesLeft');
        $p->detainedDays = $r->int('detainedDays');
        $p->escortOf = $r->nullableString('escortOf');
        $p->vetoesLeft = $r->int('vetoesLeft');
        $p->cooldown = $r->int('cooldown');
        $p->everDetained = $r->bool('everDetained');
        $p->absent = $r->bool('absent');
        $p->ready = $r->bool('ready');
        $p->hand = $r->strings('hand');
        $p->pick = $r->nullableString('pick');
        $p->pickState = $r->string('pickState', self::PICK_NONE);
        $p->vetoed = $r->strings('vetoed');
        $p->escortDecision = $r->nullableString('escortDecision');
        $p->overrode = $r->bool('overrode');
        $p->autoPicked = $r->bool('autoPicked');
        $p->vote = $r->nullableBool('vote');
        $p->accuse = $r->nullableString('accuse');

        return $p;
    }
}
