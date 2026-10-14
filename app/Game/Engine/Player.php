<?php

namespace App\Game\Engine;

final class Player
{
    // Where a player's pick stands during the day.
    public const PICK_NONE = 'none';

    public const PICK_LOCKED = 'locked';     // unrestricted pick, can still be changed

    public const PICK_PENDING = 'pending';   // restricted pick waiting on the escort

    public const PICK_REPICK = 'repick';     // vetoed, must pick again or override

    public const PICK_FINAL = 'final';       // restricted pick that will be played

    public int $brakesLeft = 0;

    public int $overridesLeft = 0;

    public int $restrictedDays = 0;

    public ?string $escortOf = null;

    public int $cooldown = 0;

    public bool $everRestricted = false;

    /** Removed from the lobby mid-game: the seat plays itself. */
    public bool $absent = false;

    public bool $ready = false;

    /** @var list<string> */
    public array $hand = [];

    public ?string $pick = null;

    public string $pickState = self::PICK_NONE;

    public ?string $vetoed = null;

    public ?string $escortDecision = null;

    public bool $overrode = false;

    public bool $autoPicked = false;

    public ?bool $vote = null;

    public ?string $guess = null;

    public function __construct(
        public string $id,
        public string $name,
        public string $department,
        public bool $traitor,
    ) {}

    public function isRestricted(): bool
    {
        return $this->restrictedDays > 0;
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
        $this->vetoed = null;
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
        $p->brakesLeft = $r->int('brakesLeft');
        $p->overridesLeft = $r->int('overridesLeft');
        $p->restrictedDays = $r->int('restrictedDays');
        $p->escortOf = $r->nullableString('escortOf');
        $p->cooldown = $r->int('cooldown');
        $p->everRestricted = $r->bool('everRestricted');
        $p->absent = $r->bool('absent');
        $p->ready = $r->bool('ready');
        $p->hand = $r->strings('hand');
        $p->pick = $r->nullableString('pick');
        $p->pickState = $r->string('pickState', self::PICK_NONE);
        $p->vetoed = $r->nullableString('vetoed');
        $p->escortDecision = $r->nullableString('escortDecision');
        $p->overrode = $r->bool('overrode');
        $p->autoPicked = $r->bool('autoPicked');
        $p->vote = $r->nullableBool('vote');
        $p->guess = $r->nullableString('guess');

        return $p;
    }
}
