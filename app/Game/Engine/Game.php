<?php

namespace App\Game\Engine;

/**
 * The whole state of one game in progress. Plain data: the Engine changes
 * it, the lobby stores it as JSON, the views read it.
 */
final class Game
{
    public const BOARDING = 'boarding';

    public const DAWN = 'dawn';

    public const DAY = 'day';

    public const REVEAL = 'reveal';

    public const NIGHT = 'night';

    public const VOTE = 'vote';

    public const INQUIRY = 'inquiry';

    public const FINISHED = 'finished';

    public string $phase = self::BOARDING;

    /** When the current phase ends, in epoch milliseconds. */
    public ?int $deadline = null;

    /** Milliseconds left on the clock while paused, or null if running. */
    public ?int $pausedRemaining = null;

    public int $day = 0;

    /** @var array<string, int> */
    public array $scores = [];

    /** Scores before today's weather, for showing what dawn did. */
    /** @var array<string, int> */
    public array $scoresAtDawn = [];

    public int $distance = 0;

    public int $baseToday = 0;

    public int $slowDays = 0;

    /** Brake pulls the train has left this game; -1 means no cap. */
    public int $trainBrakesLeft = -1;

    public int $brakeRest = 0;

    /** Whether the brake can be pulled tonight. */
    public bool $brakeOpen = false;

    public bool $brakeUsedTonight = false;

    /** @var array<string, Deck> */
    public array $decks = [];

    public Deck $weatherDeck;

    /** @var list<Player> */
    public array $players = [];

    /** @var list<string> */
    public array $weatherToday = [];

    public int $weatherDistance = 0;

    public ?string $forecast = null;

    /**
     * The brake vote in progress: puller, nominee, and when night resumes.
     *
     * @var array{puller: string, nominee: string, nightLeft: int}|null
     */
    public ?array $vote = null;

    /**
     * One record per day: weather, every hand and pick, the result and the
     * night's brake. Hands are secret until the game ends.
     *
     * @var list<array<string, mixed>>
     */
    public array $history = [];

    /**
     * The public log, oldest first.
     *
     * @var list<array<string, mixed>>
     */
    public array $log = [];

    public ?string $winner = null;

    public ?string $cause = null;

    /**
     * @param  array<string, int>  $rules
     * @param  list<string>  $departments
     */
    public function __construct(
        public array $rules,
        public array $departments,
    ) {
        $this->weatherDeck = new Deck;
    }

    public function rule(string $key): int
    {
        return $this->rules[$key] ?? throw new \InvalidArgumentException("Unknown rule [{$key}].");
    }

    public function player(string $id): ?Player
    {
        foreach ($this->players as $player) {
            if ($player->id === $id) {
                return $player;
            }
        }

        return null;
    }

    /**
     * The escort watching a restricted player, if any.
     */
    public function escortFor(Player $player): ?Player
    {
        if (! $player->isRestricted()) {
            return null;
        }

        foreach ($this->players as $other) {
            if ($other->escortOf === $player->id) {
                return $other;
            }
        }

        return null;
    }

    /**
     * @return list<Player>
     */
    public function present(): array
    {
        return array_values(array_filter($this->players, fn (Player $p) => ! $p->absent));
    }

    /**
     * @return list<Player>
     */
    public function traitors(): array
    {
        return array_values(array_filter($this->players, fn (Player $p) => $p->traitor));
    }

    public function inCrisis(string $department): bool
    {
        return ($this->scores[$department] ?? 0) <= $this->rule('crisis_at');
    }

    public function crisisCount(): int
    {
        return count(array_filter($this->departments, fn (string $d) => $this->inCrisis($d)));
    }

    public function isOver(): bool
    {
        return $this->phase === self::FINISHED;
    }

    public function isPaused(): bool
    {
        return $this->pausedRemaining !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'phase' => $this->phase,
            'deadline' => $this->deadline,
            'pausedRemaining' => $this->pausedRemaining,
            'day' => $this->day,
            'rules' => $this->rules,
            'departments' => $this->departments,
            'scores' => $this->scores,
            'scoresAtDawn' => $this->scoresAtDawn,
            'distance' => $this->distance,
            'baseToday' => $this->baseToday,
            'slowDays' => $this->slowDays,
            'trainBrakesLeft' => $this->trainBrakesLeft,
            'brakeRest' => $this->brakeRest,
            'brakeOpen' => $this->brakeOpen,
            'brakeUsedTonight' => $this->brakeUsedTonight,
            'decks' => array_map(fn (Deck $d) => $d->toArray(), $this->decks),
            'weatherDeck' => $this->weatherDeck->toArray(),
            'players' => array_map(fn (Player $p) => $p->toArray(), $this->players),
            'weatherToday' => $this->weatherToday,
            'weatherDistance' => $this->weatherDistance,
            'forecast' => $this->forecast,
            'vote' => $this->vote,
            'history' => $this->history,
            'log' => $this->log,
            'winner' => $this->winner,
            'cause' => $this->cause,
        ];
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $r = new Reader($data);
        $game = new self($r->intMap('rules'), $r->strings('departments'));
        $game->phase = $r->string('phase', self::BOARDING);
        $game->deadline = $r->nullableInt('deadline');
        $game->pausedRemaining = $r->nullableInt('pausedRemaining');
        $game->day = $r->int('day');
        $game->scores = $r->intMap('scores');
        $game->scoresAtDawn = $r->intMap('scoresAtDawn');
        $game->distance = $r->int('distance');
        $game->baseToday = $r->int('baseToday');
        $game->slowDays = $r->int('slowDays');
        $game->trainBrakesLeft = $r->int('trainBrakesLeft', -1);
        $game->brakeRest = $r->int('brakeRest');
        $game->brakeOpen = $r->bool('brakeOpen');
        $game->brakeUsedTonight = $r->bool('brakeUsedTonight');
        foreach ($r->map('decks') as $dept => $deck) {
            $d = new Reader(is_array($deck) ? $deck : []);
            $game->decks[$dept] = new Deck($d->strings('pile'), $d->strings('discard'));
        }
        $w = $r->reader('weatherDeck');
        $game->weatherDeck = new Deck($w->strings('pile'), $w->strings('discard'));
        $game->players = array_map(Player::fromArray(...), $r->maps('players'));
        $game->weatherToday = $r->strings('weatherToday');
        $game->weatherDistance = $r->int('weatherDistance');
        $game->forecast = $r->nullableString('forecast');
        $vote = $r->reader('vote');
        $game->vote = $r->has('vote') ? [
            'puller' => $vote->string('puller'),
            'nominee' => $vote->string('nominee'),
            'nightLeft' => $vote->int('nightLeft'),
        ] : null;
        $game->history = $r->maps('history');
        $game->log = $r->maps('log');
        $game->winner = $r->nullableString('winner');
        $game->cause = $r->nullableString('cause');

        return $game;
    }
}
