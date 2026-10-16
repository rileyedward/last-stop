<?php

namespace App\Game\Engine;

/**
 * The whole state of one Departure game in progress. Plain data: the Engine
 * changes it, the lobby stores it as JSON, the views read it.
 */
final class Game
{
    public const BOARDING = 'boarding';

    public const DAWN = 'dawn';

    public const DAY = 'day';

    public const REVEAL = 'reveal';

    /** The cord window on a green signal. */
    public const DUSK = 'dusk';

    /** The departure check's result: ran, held by the cord, or red. */
    public const DEPARTURE = 'departure';

    public const NIGHT = 'night';

    /** A detention vote during the night. */
    public const VOTE = 'vote';

    public const ARRIVAL = 'arrival';

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

    /** Legs run so far; the train is at station $leg. */
    public int $leg = 0;

    /** Every department must be at or above this for a green signal. */
    public int $line = 0;

    /** Tailwind: the next run costs nothing. */
    public bool $freeRun = false;

    /** Cord pulls the train has left this game. */
    public int $cordLeft = 0;

    /**
     * Every cord pull: the day, who pulled (secret until the end) and the
     * public clue.
     *
     * @var list<array{day: int, by: string, clue: list<string>}>
     */
    public array $pulls = [];

    /**
     * Today's departure check: the signal, whether the cord window is open,
     * who pulled in it (secret), and the outcome once settled.
     *
     * @var array<string, mixed>|null
     */
    public ?array $dusk = null;

    /** @var array<string, Deck> */
    public array $decks = [];

    public Deck $weatherDeck;

    /** @var list<Player> */
    public array $players = [];

    /** @var list<string> */
    public array $weatherToday = [];

    /**
     * Players who have nominated tonight.
     *
     * @var list<string>
     */
    public array $nominatedTonight = [];

    /**
     * The detention vote in progress: nominator, nominee, and how much
     * night was left when it started.
     *
     * @var array{nominator: string, nominee: string, nightLeft: int}|null
     */
    public ?array $vote = null;

    /**
     * One record per day: weather, every hand and pick, the departure check
     * and the night's votes. Hands and pullers are secret until the end.
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

    public function on(string $key): bool
    {
        return ($this->rules[$key] ?? 0) !== 0;
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
     * The escort watching a detained player, if any.
     */
    public function escortFor(Player $player): ?Player
    {
        if (! $player->isDetained()) {
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

    /**
     * Every department at or above the line.
     */
    public function green(): bool
    {
        return array_all($this->departments, fn (string $d) => ($this->scores[$d] ?? 0) >= $this->line);
    }

    /**
     * The line once the train has run $leg legs.
     */
    public function lineFor(int $leg): int
    {
        $every = $this->rule('line_rise_every');
        $rise = $every > 0 ? $this->rule('line_rise') * intdiv($leg, $every) : 0;

        return min($this->rule('score_max'), $this->rule('line_start') + $rise);
    }

    public function anyoneDetained(): bool
    {
        return array_any($this->players, fn (Player $p) => $p->isDetained());
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
            'leg' => $this->leg,
            'line' => $this->line,
            'freeRun' => $this->freeRun,
            'cordLeft' => $this->cordLeft,
            'pulls' => $this->pulls,
            'dusk' => $this->dusk,
            'decks' => array_map(fn (Deck $d) => $d->toArray(), $this->decks),
            'weatherDeck' => $this->weatherDeck->toArray(),
            'players' => array_map(fn (Player $p) => $p->toArray(), $this->players),
            'weatherToday' => $this->weatherToday,
            'nominatedTonight' => $this->nominatedTonight,
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
        $game->leg = $r->int('leg');
        $game->line = $r->int('line');
        $game->freeRun = $r->bool('freeRun');
        $game->cordLeft = $r->int('cordLeft');
        $game->pulls = array_map(fn (array $pull) => [
            'day' => (new Reader($pull))->int('day'),
            'by' => (new Reader($pull))->string('by'),
            'clue' => (new Reader($pull))->strings('clue'),
        ], $r->maps('pulls'));
        $dusk = $r->map('dusk');
        $game->dusk = $dusk === [] ? null : $dusk;
        foreach ($r->map('decks') as $dept => $deck) {
            $d = new Reader(is_array($deck) ? $deck : []);
            $game->decks[$dept] = new Deck($d->strings('pile'), $d->strings('discard'));
        }
        $w = $r->reader('weatherDeck');
        $game->weatherDeck = new Deck($w->strings('pile'), $w->strings('discard'));
        $game->players = array_map(Player::fromArray(...), $r->maps('players'));
        $game->weatherToday = $r->strings('weatherToday');
        $game->nominatedTonight = $r->strings('nominatedTonight');
        $vote = $r->reader('vote');
        $game->vote = $r->has('vote') ? [
            'nominator' => $vote->string('nominator'),
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
