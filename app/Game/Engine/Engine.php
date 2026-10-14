<?php

namespace App\Game\Engine;

use App\Game\Data\Card;
use App\Game\Data\Departments;
use App\Game\Data\GameData;
use Random\Randomizer;

/**
 * The rules of Last Stop, ported from the balance simulator (sim/lastsim.py).
 *
 * The engine owns no storage and no clock: every call gets the game state
 * and the current time in epoch milliseconds, changes the state, and adds
 * public lines to its log. Rule numbers all come from the game's rules.
 */
final class Engine
{
    /** The department score scale. Fixed by the rulebook, as in the simulator. */
    public const SCORE_MIN = 0;

    public const SCORE_MAX = 10;

    public function __construct(
        private readonly GameData $data,
        private readonly Randomizer $rng,
    ) {}

    // ------------------------------------------------------------ setup

    /**
     * Seat the players, deal departments and roles, shuffle the decks and
     * open the boarding phase.
     *
     * @param  list<array{id: string, name: string}>  $seats
     * @param  array<string, int>  $rules
     * @param  list<string>  $departments
     * @param  array{departments?: array<string, string>, traitors?: list<string>, decks?: array<string, list<string>>, weather?: list<string>}  $fixed
     *                                                                                                                                                   A fixed deal, for tests and replays.
     */
    public function start(array $seats, array $rules, array $departments, int $now, array $fixed = []): Game
    {
        $game = new Game($rules, $departments);
        $n = count($seats);

        foreach ($departments as $dept) {
            $game->scores[$dept] = $game->rule('start_score');
            $ids = array_map(fn (Card $c) => $c->id, $this->data->tasks[$dept] ?? []);
            $game->decks[$dept] = isset($fixed['decks'][$dept])
                ? new Deck($fixed['decks'][$dept])
                : Deck::shuffled($ids, $this->rng);
        }
        $game->weatherDeck = isset($fixed['weather'])
            ? new Deck($fixed['weather'])
            : Deck::shuffled(array_map(fn (Card $c) => $c->id, $this->data->weather), $this->rng);

        $game->trainBrakesLeft = $game->rule('brakes_per_game') > 0 ? $game->rule('brakes_per_game') : -1;

        // Departments as evenly as the table allows, then roles, both shuffled.
        $deal = [];
        for ($i = 0; $i < $n; $i++) {
            $deal[] = $departments[$i % count($departments)];
        }
        $deal = $this->rng->shuffleArray($deal);
        $traitorCount = min($game->rule('traitors'), max(0, $n - 1));
        $roles = $this->rng->shuffleArray([
            ...array_fill(0, $traitorCount, true),
            ...array_fill(0, $n - $traitorCount, false),
        ]);

        foreach ($seats as $i => $seat) {
            $dept = $fixed['departments'][$seat['id']] ?? $deal[$i];
            $traitor = isset($fixed['traitors']) ? in_array($seat['id'], $fixed['traitors'], true) : $roles[$i];
            $player = new Player($seat['id'], $seat['name'], $dept, $traitor);
            $player->brakesLeft = $game->rule('brakes_per_player');
            $player->overridesLeft = $game->rule('overrides_per_player');
            $game->players[] = $player;
        }

        $game->phase = Game::BOARDING;
        $game->deadline = $now + $this->seconds($game, 'boarding_seconds');
        $this->log($game, 'system', 'The train leaves the station. Open your ticket.');

        return $game;
    }

    // ------------------------------------------------------------ clock

    /**
     * Move the game on if the phase deadline has passed, then settle any
     * phase that everyone has already finished.
     */
    public function tick(Game $game, int $now): void
    {
        $guard = 0;
        while (! $game->isOver() && ! $game->isPaused() && $game->deadline !== null
            && $now >= $game->deadline && $guard++ < 100) {
            $this->advance($game, $now, true);
        }

        $this->settle($game, $now);
    }

    public function pause(Game $game, int $now): void
    {
        if ($game->isOver() || $game->isPaused()) {
            return;
        }
        $game->pausedRemaining = max(0, ($game->deadline ?? $now) - $now);
        $game->deadline = null;
        $this->log($game, 'system', 'The host paused the game.');
    }

    public function resume(Game $game, int $now): void
    {
        if (! $game->isPaused()) {
            return;
        }
        $game->deadline = $now + (int) $game->pausedRemaining;
        $game->pausedRemaining = null;
        $this->log($game, 'system', 'The host resumed the game.');
    }

    public function endEarly(Game $game): void
    {
        if ($game->isOver()) {
            return;
        }
        $game->pausedRemaining = null;
        $game->phase = Game::FINISHED;
        $game->deadline = null;
        $game->winner = null;
        $game->cause = 'ended';
        $game->vote = null;
        $this->log($game, 'result', 'The host ended the trip early. Nobody wins.');
    }

    /**
     * A player left the lobby mid-game: their seat plays itself from now on.
     */
    public function markAbsent(Game $game, string $playerId, int $now): void
    {
        $player = $game->player($playerId);
        if ($player === null || $player->absent) {
            return;
        }
        $player->absent = true;
        $this->log($game, 'system', "{$player->name} left the train. Their seat plays itself.");
        if (! $game->isPaused()) {
            $this->settle($game, $now);
        }
    }

    // ------------------------------------------------------------ actions

    /**
     * Apply one player's action.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws RuleViolation
     */
    public function act(Game $game, string $playerId, string $action, array $payload, int $now): void
    {
        if ($game->isOver()) {
            throw new RuleViolation('This trip is over.');
        }
        if ($game->isPaused()) {
            throw new RuleViolation('The game is paused.');
        }

        $this->tick($game, $now);

        $player = $game->player($playerId);
        if ($player === null || $player->absent) {
            throw new RuleViolation("You don't have a seat on this trip.");
        }

        match ($action) {
            'ready' => $this->ready($game, $player, ($payload['ready'] ?? true) !== false),
            'pick' => $this->pick($game, $player, $this->stringOf($payload, 'card')),
            'escort' => $this->escort($game, $player, $this->stringOf($payload, 'decision')),
            'override' => $this->override($game, $player),
            'brake' => $this->brake($game, $player, $this->stringOf($payload, 'nominee'), $now),
            'vote' => $this->vote($game, $player, ($payload['yes'] ?? false) === true),
            'guess' => $this->guess($game, $player, $this->stringOf($payload, 'suspect')),
            default => throw new RuleViolation('Unknown action.'),
        };

        $this->settle($game, $now);
    }

    private function ready(Game $game, Player $player, bool $ready): void
    {
        if (! in_array($game->phase, [Game::BOARDING, Game::DAWN, Game::REVEAL, Game::NIGHT], true)) {
            throw new RuleViolation('Nothing to be ready for right now.');
        }
        $player->ready = $ready;
    }

    private function pick(Game $game, Player $player, string $card): void
    {
        if ($game->phase !== Game::DAY) {
            throw new RuleViolation('Picks happen during the day.');
        }
        if (! in_array($card, $player->hand, true)) {
            throw new RuleViolation("That card isn't in your hand.");
        }

        if ($game->escortFor($player) === null) {
            $player->pick = $card;
            $player->pickState = Player::PICK_LOCKED;

            return;
        }

        switch ($player->pickState) {
            case Player::PICK_NONE:
                $player->pick = $card;
                $player->pickState = Player::PICK_PENDING;
                break;
            case Player::PICK_REPICK:
                if ($card === $player->vetoed) {
                    throw new RuleViolation('That card was vetoed. Pick another, or use your override.');
                }
                $player->pick = $card;
                $player->pickState = Player::PICK_FINAL;
                break;
            default:
                throw new RuleViolation('Your pick is already in.');
        }
    }

    private function escort(Game $game, Player $escort, string $decision): void
    {
        $target = $escort->escortOf !== null ? $game->player($escort->escortOf) : null;
        if ($game->phase !== Game::DAY || $target === null || ! $target->isRestricted()) {
            throw new RuleViolation("You're not escorting anyone today.");
        }
        if ($target->pickState !== Player::PICK_PENDING) {
            throw new RuleViolation("There's no pick waiting for you.");
        }

        if ($decision === 'allow') {
            $target->escortDecision = 'allow';
            $target->pickState = Player::PICK_FINAL;

            return;
        }

        if ($decision !== 'veto') {
            throw new RuleViolation('Let it through or veto.');
        }
        if (count($target->hand) < 2) {
            throw new RuleViolation('There is nothing else to pick from, so it goes through.');
        }

        $target->escortDecision = 'veto';
        $target->vetoed = $target->pick;
        $target->pickState = Player::PICK_REPICK;
        $this->log($game, 'veto', "{$target->name}'s first pick was vetoed by their escort, {$escort->name}.", [
            'who' => $target->id,
            'escort' => $escort->id,
        ]);
    }

    private function override(Game $game, Player $player): void
    {
        if ($game->phase !== Game::DAY || $player->pickState !== Player::PICK_REPICK) {
            throw new RuleViolation('There is no veto to override.');
        }
        if ($player->overridesLeft <= 0) {
            throw new RuleViolation('You have already used your override this game.');
        }
        $player->overridesLeft--;
        $player->overrode = true;
        $player->pick = $player->vetoed;
        $player->pickState = Player::PICK_FINAL;
    }

    private function brake(Game $game, Player $puller, string $nomineeId, int $now): void
    {
        if ($game->phase !== Game::NIGHT || ! $game->brakeOpen || $game->brakeUsedTonight) {
            throw new RuleViolation("The brake can't be pulled right now.");
        }
        if (! $this->canPull($puller)) {
            throw new RuleViolation("You can't pull the brake.");
        }
        $nominee = $game->player($nomineeId);
        if ($nominee === null || $nominee->id === $puller->id || ! $this->canBeNominated($nominee)) {
            throw new RuleViolation("That player can't be nominated tonight.");
        }

        $puller->brakesLeft--;
        if ($game->trainBrakesLeft > 0) {
            $game->trainBrakesLeft--;
        }
        $game->brakeRest = $game->rule('brake_rest_nights');
        $game->slowDays = $game->rule('brake_slow_days');
        $game->brakeUsedTonight = true;

        foreach ($game->players as $p) {
            $p->vote = null;
            $p->ready = false;
        }
        $puller->vote = true;
        $game->vote = [
            'puller' => $puller->id,
            'nominee' => $nominee->id,
            'nightLeft' => max(0, ($game->deadline ?? $now) - $now),
        ];
        $game->phase = Game::VOTE;
        $game->deadline = $now + $this->seconds($game, 'vote_seconds');

        $this->recordBrake($game, ['puller' => $puller->id, 'nominee' => $nominee->id]);
        $this->log($game, 'brake', "{$puller->name} pulled the emergency brake and named {$nominee->name}.", [
            'who' => $puller->id,
            'nominee' => $nominee->id,
        ]);
    }

    private function vote(Game $game, Player $player, bool $yes): void
    {
        if ($game->phase !== Game::VOTE || $game->vote === null) {
            throw new RuleViolation('There is no vote right now.');
        }
        if ($player->id === $game->vote['nominee']) {
            throw new RuleViolation("The nominee doesn't vote.");
        }
        if ($player->id === $game->vote['puller']) {
            throw new RuleViolation('You pulled the brake, so your vote is yes.');
        }
        $player->vote = $yes;
    }

    private function guess(Game $game, Player $player, string $suspect): void
    {
        if ($game->phase !== Game::INQUIRY) {
            throw new RuleViolation('The inquiry happens at the end of the trip.');
        }
        if ($player->traitor) {
            throw new RuleViolation('Only the crew names a suspect.');
        }
        $target = $game->player($suspect);
        if ($target === null || $target->id === $player->id) {
            throw new RuleViolation('Name someone else on the train.');
        }
        $player->guess = $target->id;
    }

    public function canPull(Player $player): bool
    {
        return $player->brakesLeft > 0 && ! $player->isRestricted() && $player->escortOf === null && ! $player->absent;
    }

    public function canBeNominated(Player $player): bool
    {
        return ! $player->isRestricted() && $player->cooldown === 0 && $player->escortOf === null;
    }

    // ------------------------------------------------------------ phases

    /**
     * Close the current phase and open the next one.
     */
    private function advance(Game $game, int $now, bool $timedOut): void
    {
        switch ($game->phase) {
            case Game::BOARDING:
                $this->startDawn($game, $now);
                break;
            case Game::DAWN:
                $this->startDay($game, $now);
                break;
            case Game::DAY:
                foreach ($game->players as $player) {
                    $this->finishPick($game, $player);
                }
                $this->resolveDay($game, $now);
                break;
            case Game::REVEAL:
                $game->winner !== null ? $this->startInquiry($game, $now) : $this->startNight($game, $now);
                break;
            case Game::NIGHT:
                $this->endNight($game);
                $this->startDawn($game, $now);
                break;
            case Game::VOTE:
                $this->resolveVote($game, $now);
                break;
            case Game::INQUIRY:
                $this->finish($game);
                break;
        }
    }

    /**
     * Let absent seats act, then advance while everyone who can act has.
     */
    private function settle(Game $game, int $now): void
    {
        $guard = 0;
        while (! $game->isOver() && ! $game->isPaused() && $guard++ < 100) {
            foreach ($game->players as $player) {
                if ($player->absent) {
                    $this->actForAbsent($game, $player);
                }
            }
            if (! $this->phaseComplete($game)) {
                return;
            }
            $this->advance($game, $now, false);
        }
    }

    private function actForAbsent(Game $game, Player $player): void
    {
        $player->ready = true;
        if ($game->phase === Game::DAY) {
            $this->finishPick($game, $player);
            $target = $player->escortOf !== null ? $game->player($player->escortOf) : null;
            if ($target !== null && $target->pickState === Player::PICK_PENDING) {
                $target->escortDecision = 'allow';
                $target->pickState = Player::PICK_FINAL;
            }
        }
    }

    private function phaseComplete(Game $game): bool
    {
        $present = $game->present();
        if ($present === []) {
            return false;
        }

        return match ($game->phase) {
            Game::BOARDING, Game::DAWN, Game::REVEAL, Game::NIGHT => array_all($present, fn (Player $p) => $p->ready),
            Game::DAY => array_all($game->players, fn (Player $p) => $p->pickIsFinal()),
            Game::VOTE => array_all($this->voters($game), fn (Player $p) => $p->absent || $p->vote !== null),
            Game::INQUIRY => array_all($present, fn (Player $p) => $p->traitor || $p->guess !== null),
            default => false,
        };
    }

    /**
     * @return list<Player>
     */
    private function voters(Game $game): array
    {
        $nominee = $game->vote['nominee'] ?? null;

        return array_values(array_filter($game->players, fn (Player $p) => $p->id !== $nominee));
    }

    private function startDawn(Game $game, int $now): void
    {
        $game->day++;
        if ($game->slowDays > 0) {
            $game->baseToday = 0;
            $game->slowDays--;
        } else {
            $game->baseToday = $game->rule('base_speed');
        }

        $game->scoresAtDawn = $game->scores;
        $game->weatherToday = [];
        $game->weatherDistance = 0;
        $game->forecast = null;
        $game->brakeOpen = false;
        $game->brakeUsedTonight = false;
        $game->vote = null;
        foreach ($game->players as $player) {
            $player->ready = false;
            $player->vote = null;
            $player->resetForDay();
        }

        $this->log($game, 'day', "Dawn of day {$game->day}.");
        for ($i = 0; $i < $game->rule('weather_per_day'); $i++) {
            $drawn = $game->weatherDeck->draw(1, $this->rng);
            if ($drawn === []) {
                break;
            }
            $card = $this->data->card($drawn[0]);
            $game->scores = $this->apply($game, $game->scores, $card->effects);
            $game->weatherDistance += $card->distance;
            $game->weatherDeck->discard($drawn);
            $game->weatherToday[] = $card->id;
            $this->log($game, 'weather', $card->name, ['card' => $card->id, 'fx' => $this->fx($game, $card)]);
        }

        $game->history[] = [
            'day' => $game->day,
            'base' => $game->baseToday,
            'weather' => $game->weatherToday,
            'weatherDistance' => $game->weatherDistance,
            'scoresBefore' => $game->scoresAtDawn,
            'scoresAfterWeather' => $game->scores,
            'distanceBefore' => $game->distance,
        ];

        $game->phase = Game::DAWN;
        $game->deadline = $now + $this->seconds($game, 'dawn_seconds');
    }

    private function startDay(Game $game, int $now): void
    {
        foreach ($game->players as $player) {
            $player->ready = false;
            $player->resetForDay();
            $player->hand = $game->decks[$player->department]->draw($this->handSize($game, $player), $this->rng);
            if ($player->hand === []) {
                $player->pickState = Player::PICK_FINAL;
            }
        }

        $game->phase = Game::DAY;
        $game->deadline = $now + $this->seconds($game, 'day_seconds');
    }

    public function handSize(Game $game, Player $player): int
    {
        $reduced = $player->escortOf !== null || $game->inCrisis($player->department);

        return $game->rule($reduced ? 'reduced_hand' : 'hand_size');
    }

    /**
     * Whatever a player hasn't settled when the day ends is settled for
     * them: a random card, or the escort letting the pick through.
     */
    private function finishPick(Game $game, Player $player): void
    {
        switch ($player->pickState) {
            case Player::PICK_NONE:
                if ($player->hand !== []) {
                    $player->pick = $this->randomOf($player->hand);
                    $player->autoPicked = true;
                }
                $player->pickState = $game->escortFor($player) !== null ? Player::PICK_FINAL : Player::PICK_LOCKED;
                break;
            case Player::PICK_PENDING:
                $player->escortDecision = 'allow';
                $player->pickState = Player::PICK_FINAL;
                break;
            case Player::PICK_REPICK:
                $rest = array_values(array_filter($player->hand, fn (string $c) => $c !== $player->vetoed));
                $player->pick = $rest === [] ? $player->vetoed : $this->randomOf($rest);
                $player->autoPicked = true;
                $player->pickState = Player::PICK_FINAL;
                break;
        }
    }

    private function resolveDay(Game $game, int $now): void
    {
        $totals = [];
        $cardDistance = 0;
        $picks = [];
        foreach ($game->players as $player) {
            $card = $player->pick !== null ? $this->data->card($player->pick) : null;
            if ($card !== null) {
                foreach ($card->effects as $dept => $value) {
                    $totals[$dept] = ($totals[$dept] ?? 0) + $value;
                }
                $cardDistance += $card->distance;
            }
            $picks[] = [
                'player' => $player->id,
                'hand' => $player->hand,
                'pick' => $player->pick,
                'vetoed' => $player->vetoed,
                'override' => $player->overrode,
                'auto' => $player->autoPicked,
                'escort' => $game->escortFor($player)?->id,
            ];
        }

        $scoresBefore = $game->scores;
        $game->scores = $this->apply($game, $game->scores, $totals);
        $distanceBefore = $game->distance;
        $dayDistance = max(0, $game->baseToday + $game->weatherDistance + $cardDistance);
        $game->distance += $dayDistance;

        foreach ($game->players as $player) {
            $game->decks[$player->department]->discard($player->hand);
        }

        // Log the picks as they'll be revealed.
        foreach ($game->players as $player) {
            if ($player->pick === null) {
                continue;
            }
            $card = $this->data->card($player->pick);
            $note = $player->overrode ? ' (OVERRIDE)' : ($player->autoPicked ? ' (timer ran out)' : '');
            $this->log($game, $player->overrode ? 'override' : 'pick', "{$player->name} played {$card->name}{$note}", [
                'who' => $player->id,
                'card' => $card->id,
                'fx' => $this->fx($game, $card),
                'override' => $player->overrode,
                'auto' => $player->autoPicked,
            ]);
        }
        $this->log($game, 'result', "Day {$game->day} resolved. Distance {$distanceBefore} → {$game->distance}.", [
            'fx' => ['distance' => $dayDistance],
        ]);

        $last = count($game->history) - 1;
        $game->history[$last] = [
            ...$game->history[$last],
            'picks' => $picks,
            'scoresAfterWeather' => $scoresBefore,
            'scoresAfter' => $game->scores,
            'cardDistance' => $cardDistance,
            'dayDistance' => $dayDistance,
            'distanceAfter' => $game->distance,
        ];

        // Restrictions tick down once the day is played.
        foreach ($game->players as $player) {
            if ($player->restrictedDays > 0) {
                $player->restrictedDays--;
                if ($player->restrictedDays === 0) {
                    $player->cooldown = $game->rule('cooldown_nights');
                    foreach ($game->players as $escort) {
                        if ($escort->escortOf === $player->id) {
                            $escort->escortOf = null;
                        }
                    }
                }
            }
        }

        // End-of-day checks, in rulebook order.
        if ($game->distance >= $game->rule('route')) {
            $game->winner = 'crew';
            $game->cause = 'arrived';
        } elseif ($game->crisisCount() >= 2) {
            $game->winner = 'traitor';
            $game->cause = 'crisis';
        } elseif ($game->day >= $game->rule('days')) {
            $game->winner = 'traitor';
            $game->cause = 'clock';
        }

        foreach ($game->players as $player) {
            $player->ready = false;
        }
        $game->phase = Game::REVEAL;
        $game->deadline = $now + $this->seconds($game, 'reveal_seconds');
    }

    private function startNight(Game $game, int $now): void
    {
        foreach ($game->players as $player) {
            $player->ready = false;
            $player->vote = null;
        }

        $game->forecast = $game->rule('traitor_forecast') ? $game->weatherDeck->peek($this->rng) : null;

        $game->brakeUsedTonight = false;
        if ($game->day < $game->rule('brake_from_night')) {
            $game->brakeOpen = false;
        } elseif ($game->brakeRest > 0) {
            $game->brakeRest--;
            $game->brakeOpen = false;
        } else {
            $game->brakeOpen = $game->trainBrakesLeft !== 0;
        }

        $this->log($game, 'night', $game->brakeOpen
            ? "Night {$game->day}. The emergency brake is unlocked."
            : "Night {$game->day}.");

        $game->phase = Game::NIGHT;
        $game->deadline = $now + $this->seconds($game, 'night_seconds');
    }

    private function resolveVote(Game $game, int $now): void
    {
        if ($game->vote === null) {
            $game->phase = Game::NIGHT;

            return;
        }

        $puller = $game->player($game->vote['puller']);
        $nominee = $game->player($game->vote['nominee']);
        $votes = [];
        $yes = 0;
        $no = 0;
        foreach ($this->voters($game) as $voter) {
            $votes[$voter->id] = $voter->vote === true;
            $voter->vote === true ? $yes++ : $no++;
        }
        $passed = $yes > $no;

        if ($passed && $puller !== null && $nominee !== null) {
            $nominee->restrictedDays = $game->rule('restrict_days');
            $nominee->everRestricted = true;
            $puller->escortOf = $nominee->id;
        }

        $this->recordBrake($game, ['votes' => $votes, 'yes' => $yes, 'no' => $no, 'passed' => $passed]);
        $nomineeName = $nominee->name ?? 'the nominee';
        $this->log($game, 'vote', $passed
            ? "The vote carried {$yes} to {$no}. {$nomineeName} is restricted for {$game->rule('restrict_days')} days, escorted by ".($puller->name ?? 'the puller').'.'
            : "The vote failed {$yes} to {$no}. {$nomineeName} stays free.", [
                'votes' => $votes,
                'passed' => $passed,
                'nominee' => $nominee?->id,
            ]);

        foreach ($game->players as $player) {
            $player->ready = false;
        }
        $left = $game->vote['nightLeft'];
        $game->vote = null;
        $game->phase = Game::NIGHT;
        $game->deadline = $now + max($left, $this->seconds($game, 'after_vote_seconds'));
    }

    private function endNight(Game $game): void
    {
        foreach ($game->players as $player) {
            if ($player->cooldown > 0) {
                $player->cooldown--;
            }
        }
        $game->forecast = null;
    }

    private function startInquiry(Game $game, int $now): void
    {
        foreach ($game->players as $player) {
            $player->guess = null;
            $player->ready = false;
        }
        $hasCrew = array_any($game->present(), fn (Player $p) => ! $p->traitor);
        if (! $hasCrew || $game->traitors() === []) {
            $this->finish($game);

            return;
        }
        $game->phase = Game::INQUIRY;
        $game->deadline = $now + $this->seconds($game, 'inquiry_seconds');
        $this->log($game, 'system', 'Arrival inquiry: the crew names a suspect.');
    }

    private function finish(Game $game): void
    {
        $game->phase = Game::FINISHED;
        $game->deadline = null;
        $names = implode(' and ', array_map(fn (Player $p) => $p->name, $game->traitors()));
        $this->log($game, 'result', match ($game->cause) {
            'arrived' => "The train reached Last Stop. The crew wins. The traitor was {$names}.",
            'crisis' => "Two departments in crisis. The traitor wins: {$names}.",
            'clock' => "Out of days. The traitor wins: {$names}.",
            default => 'The trip is over.',
        });
    }

    // ------------------------------------------------------------ helpers

    /**
     * @param  array<string, int>  $scores
     * @param  array<string, int>  $effects
     * @return array<string, int>
     */
    private function apply(Game $game, array $scores, array $effects): array
    {
        foreach ($game->departments as $dept) {
            $scores[$dept] = max(self::SCORE_MIN, min(self::SCORE_MAX, ($scores[$dept] ?? 0) + ($effects[$dept] ?? 0)));
        }

        return $scores;
    }

    /**
     * A card's effects as shown in the log, limited to departments in play.
     *
     * @return array<string, int>
     */
    private function fx(Game $game, Card $card): array
    {
        $fx = [];
        foreach ($card->effects as $dept => $value) {
            if (in_array($dept, $game->departments, true)) {
                $fx[$dept] = $value;
            }
        }
        if ($card->distance !== 0) {
            $fx['distance'] = $card->distance;
        }

        return $fx;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function recordBrake(Game $game, array $data): void
    {
        $last = count($game->history) - 1;
        if ($last < 0) {
            return;
        }
        $brake = $game->history[$last]['brake'] ?? [];
        $game->history[$last]['brake'] = [...(is_array($brake) ? $brake : []), ...$data];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function log(Game $game, string $kind, string $text, array $extra = []): void
    {
        $game->log[] = ['day' => $game->day, 'kind' => $kind, 'text' => $text, ...$extra];
    }

    private function seconds(Game $game, string $key): int
    {
        return $game->rule($key) * 1000;
    }

    /**
     * @param  non-empty-list<string>  $items
     */
    private function randomOf(array $items): string
    {
        return $items[$this->rng->getInt(0, count($items) - 1)];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringOf(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    public static function departmentName(string $key): string
    {
        return Departments::NAMES[$key] ?? $key;
    }
}
