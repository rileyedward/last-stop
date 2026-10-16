<?php

namespace App\Game\Engine;

use App\Game\Data\Card;
use App\Game\Data\Departments;
use App\Game\Data\GameData;
use Random\Randomizer;

/**
 * The rules of Last Stop: Departure, ported from the Departure simulator
 * (design/departure/sim/departure_sim.py) and its rulebook.
 *
 * The engine owns no storage and no clock: every call gets the game state
 * and the current time in epoch milliseconds, changes the state, and adds
 * public lines to its log. Rule numbers all come from the game's rules.
 */
final class Engine
{
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
     * @param  array{departments?: array<string, string>, traitors?: list<string>, decks?: array<string, list<string>>, weather?: list<string>}  $fixed  A fixed deal, for tests and replays.
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
        $game->scoresAtDawn = $game->scores;
        $game->weatherDeck = isset($fixed['weather'])
            ? new Deck($fixed['weather'])
            : Deck::shuffled(array_map(fn (Card $c) => $c->id, $this->data->weather), $this->rng);

        $game->leg = 0;
        $game->line = $game->lineFor(0);
        $game->cordLeft = $game->rule('cord_pulls');

        // Departments as evenly as the table allows, then roles, both shuffled.
        $deal = [];
        for ($i = 0; $i < $n; $i++) {
            $deal[] = $departments[$i % count($departments)];
        }
        $deal = $this->rng->shuffleArray($deal);
        $traitorCount = min(max(0, $game->rule('traitors')), max(0, $n - 1));
        $roles = $this->rng->shuffleArray([
            ...array_fill(0, $traitorCount, true),
            ...array_fill(0, $n - $traitorCount, false),
        ]);

        foreach ($seats as $i => $seat) {
            $dept = $fixed['departments'][$seat['id']] ?? $deal[$i];
            $traitor = isset($fixed['traitors']) ? in_array($seat['id'], $fixed['traitors'], true) : $roles[$i];
            $player = new Player($seat['id'], $seat['name'], $dept, $traitor);
            $player->overridesLeft = $game->rule('overrides_per_player');
            $game->players[] = $player;
        }

        $game->phase = Game::BOARDING;
        $game->deadline = $now + $this->seconds($game, 'boarding_seconds');
        $this->log($game, 'system', 'Snowed in at Hollow Pines. Open your ticket.');

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
            $this->advance($game, $now);
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
            'cord' => $this->cord($game, $player),
            'nominate' => $this->nominate($game, $player, $this->stringOf($payload, 'nominee'), $now),
            'vote' => $this->vote($game, $player, ($payload['yes'] ?? false) === true),
            'accuse' => $this->accuse($game, $player, $this->stringOf($payload, 'suspect')),
            default => throw new RuleViolation('Unknown action.'),
        };

        $this->settle($game, $now);
    }

    private function ready(Game $game, Player $player, bool $ready): void
    {
        if (! in_array($game->phase, [Game::BOARDING, Game::DAWN, Game::REVEAL, Game::DEPARTURE, Game::NIGHT], true)) {
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

        $escort = $game->escortFor($player);
        if ($escort === null) {
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
                if (in_array($card, $player->vetoed, true)) {
                    throw new RuleViolation('That card was vetoed. Pick another, or use your override.');
                }
                $player->pick = $card;
                $player->escortDecision = null;
                $player->pickState = $this->canVeto($player, $escort) ? Player::PICK_PENDING : Player::PICK_FINAL;
                break;
            default:
                throw new RuleViolation('Your pick is already in.');
        }
    }

    /**
     * The escort may veto while they have vetoes left today and the hand
     * has a card that hasn't been vetoed or picked.
     */
    private function canVeto(Player $target, Player $escort): bool
    {
        $rest = array_diff($target->hand, $target->vetoed, [$target->pick]);

        return $escort->vetoesLeft > 0 && $rest !== [];
    }

    private function escort(Game $game, Player $escort, string $decision): void
    {
        $target = $escort->escortOf !== null ? $game->player($escort->escortOf) : null;
        if ($game->phase !== Game::DAY || $target === null || ! $target->isDetained()) {
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
        if (! $this->canVeto($target, $escort)) {
            throw new RuleViolation($escort->vetoesLeft > 0
                ? 'There is nothing else to pick from, so it goes through.'
                : 'You have no vetoes left today.');
        }

        $escort->vetoesLeft--;
        $target->escortDecision = 'veto';
        $target->vetoed[] = (string) $target->pick;
        $target->pickState = Player::PICK_REPICK;
        $this->log($game, 'veto', "{$target->name}'s pick was vetoed by their escort, {$escort->name}.", [
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
        $player->pick = $player->vetoed[count($player->vetoed) - 1];
        $player->pickState = Player::PICK_FINAL;
    }

    private function cord(Game $game, Player $player): void
    {
        if ($game->phase !== Game::DUSK || $game->dusk === null) {
            throw new RuleViolation('The cord can only be pulled while the train waits at dusk.');
        }
        if (! $this->canPull($game, $player)) {
            throw new RuleViolation("You can't pull the cord.");
        }
        // With two traitors the first pull counts; a second is the same pull.
        $game->dusk['pulledBy'] ??= $player->id;
    }

    private function nominate(Game $game, Player $nominator, string $nomineeId, int $now): void
    {
        if ($game->phase !== Game::NIGHT || ! $this->nominationsOpen($game)) {
            throw new RuleViolation('Nobody can be nominated right now.');
        }
        if (! $this->canNominate($game, $nominator)) {
            throw new RuleViolation(in_array($nominator->id, $game->nominatedTonight, true)
                ? 'You have already nominated tonight.'
                : "You can't nominate while detained or escorting.");
        }
        $nominee = $game->player($nomineeId);
        if ($nominee === null || $nominee->id === $nominator->id || ! $this->canBeNominated($nominee)) {
            throw new RuleViolation("That player can't be nominated tonight.");
        }

        $game->nominatedTonight[] = $nominator->id;
        foreach ($game->players as $p) {
            $p->vote = null;
            $p->ready = false;
        }
        $nominator->vote = true;
        $game->vote = [
            'nominator' => $nominator->id,
            'nominee' => $nominee->id,
            'nightLeft' => max(0, ($game->deadline ?? $now) - $now),
        ];
        $game->phase = Game::VOTE;
        $game->deadline = $now + $this->seconds($game, 'vote_seconds');

        $this->log($game, 'nominate', "{$nominator->name} nominated {$nominee->name} to be detained.", [
            'who' => $nominator->id,
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
        if ($player->id === $game->vote['nominator']) {
            throw new RuleViolation('You nominated them, so your vote is yes.');
        }
        $player->vote = $yes;
    }

    private function accuse(Game $game, Player $player, string $suspect): void
    {
        if ($game->phase !== Game::ARRIVAL) {
            throw new RuleViolation('The arrival vote happens at Last Stop.');
        }
        $target = $game->player($suspect);
        if ($target === null || $target->id === $player->id) {
            throw new RuleViolation('Name someone else on the train.');
        }
        $player->accuse = $target->id;
    }

    public function canPull(Game $game, Player $player): bool
    {
        return $player->traitor
            && ! $player->absent
            && $game->cordLeft > 0
            && (! $player->isDetained() || $game->on('detained_can_pull'));
    }

    /**
     * Whether anyone may be nominated tonight: with one detained player at a
     * time, not while someone is detained.
     */
    public function nominationsOpen(Game $game): bool
    {
        return ! ($game->on('one_detained_at_a_time') && $game->anyoneDetained());
    }

    public function canNominate(Game $game, Player $player): bool
    {
        return ! $player->absent
            && ! $player->isDetained()
            && ! $player->isEscorting()
            && ! in_array($player->id, $game->nominatedTonight, true);
    }

    public function canBeNominated(Player $player): bool
    {
        return ! $player->isDetained() && $player->cooldown === 0 && ! $player->isEscorting();
    }

    // ------------------------------------------------------------ phases

    /**
     * Close the current phase and open the next one.
     */
    private function advance(Game $game, int $now): void
    {
        switch ($game->phase) {
            case Game::BOARDING:
            case Game::NIGHT:
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
                if ($game->on('crisis_loss') && $game->crisisCount() >= 2) {
                    $this->finish($game, 'traitor', 'crisis');
                } else {
                    $this->startDusk($game, $now);
                }
                break;
            case Game::DUSK:
                $this->resolveDusk($game, $now);
                break;
            case Game::DEPARTURE:
                $this->afterDeparture($game, $now);
                break;
            case Game::VOTE:
                $this->resolveVote($game, $now);
                break;
            case Game::ARRIVAL:
                $this->resolveArrival($game);
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
            $this->advance($game, $now);
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
            Game::BOARDING, Game::DAWN, Game::REVEAL, Game::DEPARTURE, Game::NIGHT => array_all($present, fn (Player $p) => $p->ready),
            Game::DAY => array_all($game->players, fn (Player $p) => $p->pickIsFinal()),
            Game::VOTE => array_all($this->voters($game), fn (Player $p) => $p->absent || $p->vote !== null),
            Game::ARRIVAL => array_all($present, fn (Player $p) => $p->accuse !== null),
            // The cord window always runs its full time, so its length
            // never hints at whether anyone was deciding.
            default => false,
        };
    }

    /**
     * Everyone except the nominee votes on a detention.
     *
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
        $game->scoresAtDawn = $game->scores;
        $game->weatherToday = [];
        $game->dusk = null;
        $game->vote = null;
        $game->nominatedTonight = [];
        foreach ($game->players as $player) {
            $player->ready = false;
            $player->vote = null;
            $player->resetForDay();
        }

        $this->log($game, 'day', "Dawn of day {$game->day}.");

        // Draw the whole dawn first, then discard it, as the simulator does:
        // a reshuffle mid-dawn never brings back today's own cards.
        $drawn = $game->weatherDeck->draw($game->rule('weather_per_day'), $this->rng);
        $tailwind = false;
        foreach ($drawn as $id) {
            $card = $this->data->card($id);
            $game->scores = $this->apply($game, $game->scores, $card->effects);
            if ($card->special === 'free_run') {
                $game->freeRun = true;
                $tailwind = true;
            }
            $game->weatherToday[] = $card->id;
            $this->log($game, 'weather', $card->name, ['card' => $card->id, 'fx' => $this->fx($game, $card)]);
        }
        $game->weatherDeck->discard($drawn);
        if ($tailwind) {
            $this->log($game, 'tailwind', 'Tailwind: the next run costs nothing.');
        }

        $game->history[] = [
            'day' => $game->day,
            'weather' => $game->weatherToday,
            'scoresBefore' => $game->scoresAtDawn,
            'scoresAfterWeather' => $game->scores,
            'leg' => $game->leg,
            'line' => $game->line,
        ];

        $game->phase = Game::DAWN;
        $game->deadline = $now + $this->seconds($game, 'dawn_seconds');
    }

    private function startDay(Game $game, int $now): void
    {
        foreach ($game->players as $player) {
            $player->ready = false;
            $player->resetForDay();
            $player->vetoesLeft = $player->isEscorting() ? $game->rule('escort_vetoes_per_day') : 0;
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
        $reduced = $player->isEscorting() || $game->inCrisis($player->department);

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
                $rest = array_values(array_diff($player->hand, $player->vetoed));
                $player->pick = $rest === [] ? $player->vetoed[count($player->vetoed) - 1] : $this->randomOf($rest);
                $player->autoPicked = true;
                $player->pickState = Player::PICK_FINAL;
                break;
        }
    }

    private function resolveDay(Game $game, int $now): void
    {
        $totals = [];
        $picks = [];
        foreach ($game->players as $player) {
            $card = $player->pick !== null ? $this->data->card($player->pick) : null;
            if ($card !== null) {
                foreach ($card->effects as $dept => $value) {
                    $totals[$dept] = ($totals[$dept] ?? 0) + $value;
                }
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

        foreach ($game->players as $player) {
            $game->decks[$player->department]->discard($player->hand);
        }

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

        $this->record($game, [
            'picks' => $picks,
            'scoresAfterWeather' => $scoresBefore,
            'scoresAfter' => $game->scores,
        ]);

        foreach ($game->players as $player) {
            $player->ready = false;
        }
        $game->phase = Game::REVEAL;
        $game->deadline = $now + $this->seconds($game, 'reveal_seconds');
    }

    /**
     * The departure check. On a green signal (or any signal, with
     * cord_green_only off) and pulls left, the cord window opens for
     * everyone, whether or not a traitor is able to pull, so the window
     * itself gives nothing away. Otherwise straight to the result.
     */
    private function startDusk(Game $game, int $now): void
    {
        $green = $game->green();
        $window = $game->cordLeft > 0 && ($green || ! $game->on('cord_green_only'));
        $game->dusk = [
            'green' => $green,
            'window' => $window,
            'line' => $game->line,
            'scoresBefore' => $game->scores,
            'pulledBy' => null,
        ];

        foreach ($game->players as $player) {
            $player->ready = false;
        }

        if (! $window) {
            $this->resolveDusk($game, $now);

            return;
        }

        $this->log($game, 'signal', 'Signal green. The train waits at the platform.');
        $game->phase = Game::DUSK;
        $game->deadline = $now + $this->seconds($game, 'dusk_seconds');
    }

    private function resolveDusk(Game $game, int $now): void
    {
        $dusk = $game->dusk ?? ['green' => $game->green(), 'window' => false, 'line' => $game->line, 'scoresBefore' => $game->scores, 'pulledBy' => null];
        $green = (bool) $dusk['green'];
        $pulledBy = is_string($dusk['pulledBy'] ?? null) ? $dusk['pulledBy'] : null;
        $legBefore = $game->leg;
        $free = false;
        $arrived = false;
        $clue = [];

        if ($pulledBy !== null) {
            $outcome = 'pulled';
            $game->cordLeft--;
            $clue = $this->clue($game, $pulledBy);
            $game->pulls[] = ['day' => $game->day, 'by' => $pulledBy, 'clue' => $clue];
            $names = implode(', ', array_map(fn (string $id) => $game->player($id)->name ?? '?', $clue));
            $this->log($game, 'cord', $clue === []
                ? 'Someone pulled the cord. The train stays put.'
                : "Someone pulled the cord. The train stays put. The clue names {$names}.", [
                    'clue' => $clue,
                ]);
        } elseif ($green) {
            $outcome = 'ran';
            $game->leg++;
            if ($game->freeRun) {
                $game->freeRun = false;
                $free = true;
            } else {
                foreach ($game->departments as $dept) {
                    $game->scores[$dept] = max(0, ($game->scores[$dept] ?? 0) - $game->rule('run_cost'));
                }
            }
            $arrived = $game->leg >= $game->rule('legs');
            if (! $arrived) {
                $game->line = $game->lineFor($game->leg);
            }
            $this->log($game, 'run', $arrived
                ? 'Signal green. The train pulls into Last Stop.'
                : 'Signal green. The train runs to '.self::stationName($game->leg, $game->rule('legs')).'.'
                    .($free ? ' Tailwind: the run is free.' : " Every department pays {$game->rule('run_cost')}."), [
                        'leg' => $game->leg,
                        'free' => $free,
                    ]);
        } else {
            $outcome = 'red';
            $short = array_values(array_filter($game->departments, fn (string $d) => ($game->scores[$d] ?? 0) < $game->line));
            $this->log($game, 'signal', 'Signal red. '.implode(', ', array_map(self::departmentName(...), $short)).' below the line. The train stays put.', [
                'short' => $short,
            ]);
        }

        $game->dusk = [
            ...$dusk,
            'outcome' => $outcome,
            'clue' => $clue,
            'free' => $free,
            'arrived' => $arrived,
            'legBefore' => $legBefore,
        ];
        $this->record($game, [
            'dusk' => [
                'green' => $green,
                'window' => (bool) $dusk['window'],
                'outcome' => $outcome,
                'puller' => $pulledBy,
                'clue' => $clue,
                'free' => $free,
                'arrived' => $arrived,
                'legAfter' => $game->leg,
                'lineAfter' => $game->line,
                'scoresAfter' => $game->scores,
            ],
        ]);

        foreach ($game->players as $player) {
            $player->ready = false;
        }
        $game->phase = Game::DEPARTURE;
        $game->deadline = $now + $this->seconds($game, 'departure_seconds');
    }

    /**
     * The players named after a pull: the puller plus random others, up to
     * the clue size, in a shuffled order.
     *
     * @return list<string>
     */
    private function clue(Game $game, string $pullerId): array
    {
        $size = $game->rule('cord_clue');
        if ($size <= 0) {
            return [];
        }
        $others = array_values(array_map(
            fn (Player $p) => $p->id,
            array_filter($game->players, fn (Player $p) => $p->id !== $pullerId),
        ));
        $named = [$pullerId, ...array_slice($this->rng->shuffleArray($others), 0, $size - 1)];

        return $this->rng->shuffleArray($named);
    }

    private function afterDeparture(Game $game, int $now): void
    {
        if (($game->dusk['arrived'] ?? false) === true) {
            $this->startArrival($game, $now);

            return;
        }
        if ($game->day >= $game->rule('days')) {
            $this->finish($game, 'traitor', 'clock');

            return;
        }

        // Detentions tick down once the day is over.
        foreach ($game->players as $player) {
            if ($player->detainedDays > 0) {
                $player->detainedDays--;
                if ($player->detainedDays === 0) {
                    // Counted down at the start of each night, so the
                    // release night itself is a cooldown night.
                    $player->cooldown = $game->rule('cooldown_nights') + 1;
                    foreach ($game->players as $escort) {
                        if ($escort->escortOf === $player->id) {
                            $escort->escortOf = null;
                        }
                    }
                    $this->log($game, 'system', "{$player->name} is released.");
                }
            }
        }

        $this->startNight($game, $now);
    }

    private function startNight(Game $game, int $now): void
    {
        foreach ($game->players as $player) {
            $player->ready = false;
            $player->vote = null;
            if ($player->cooldown > 0) {
                $player->cooldown--;
            }
        }
        $game->nominatedTonight = [];
        $game->vote = null;

        $this->log($game, 'night', "Night {$game->day}.");

        $game->phase = Game::NIGHT;
        $game->deadline = $now + $this->seconds($game, 'night_seconds');
    }

    private function resolveVote(Game $game, int $now): void
    {
        if ($game->vote === null) {
            $game->phase = Game::NIGHT;

            return;
        }

        $nominator = $game->player($game->vote['nominator']);
        $nominee = $game->player($game->vote['nominee']);
        $votes = [];
        $yes = 0;
        foreach ($this->voters($game) as $voter) {
            $votes[$voter->id] = $voter->vote === true;
            $yes += $voter->vote === true ? 1 : 0;
        }
        $voters = count($votes);
        $passed = $yes * 2 > $voters;

        if ($passed && $nominator !== null && $nominee !== null) {
            $nominee->detainedDays = $game->rule('detain_days');
            $nominee->everDetained = true;
            $nominator->escortOf = $nominee->id;
        }

        $last = count($game->history) - 1;
        if ($last >= 0) {
            $nominations = $game->history[$last]['nominations'] ?? [];
            $game->history[$last]['nominations'] = [...(is_array($nominations) ? $nominations : []), [
                'nominator' => $game->vote['nominator'],
                'nominee' => $game->vote['nominee'],
                'votes' => $votes,
                'yes' => $yes,
                'no' => $voters - $yes,
                'passed' => $passed,
            ]];
        }

        $no = $voters - $yes;
        $nomineeName = $nominee->name ?? 'the nominee';
        $days = $game->rule('detain_days');
        $this->log($game, 'vote', $passed
            ? "The vote carried {$yes} to {$no}. {$nomineeName} is detained for {$days} ".($days === 1 ? 'day' : 'days').', escorted by '.($nominator->name ?? 'the nominator').'.'
            : "The vote failed {$yes} to {$no}. {$nomineeName} stays free.", [
                'votes' => $votes,
                'passed' => $passed,
                'nominee' => $nominee?->id,
                'nominator' => $nominator?->id,
            ]);

        foreach ($game->players as $player) {
            $player->ready = false;
        }
        $left = $game->vote['nightLeft'];
        $game->vote = null;
        $game->phase = Game::NIGHT;
        $game->deadline = $now + max($left, $this->seconds($game, 'after_vote_seconds'));
    }

    private function startArrival(Game $game, int $now): void
    {
        if (! $game->on('accusation') || $game->traitors() === []) {
            $this->finish($game, 'crew', 'arrived');

            return;
        }
        foreach ($game->players as $player) {
            $player->accuse = null;
            $player->ready = false;
        }
        $game->phase = Game::ARRIVAL;
        $game->deadline = $now + $this->seconds($game, 'arrival_seconds');
        $this->log($game, 'arrival', 'Last Stop. Everyone names the traitor.');
    }

    /**
     * Most votes on a traitor (either one) and the crew wins. A wrong name
     * or a tie and the traitor wins, unless no traitor ever pulled the cord.
     */
    private function resolveArrival(Game $game): void
    {
        $tally = [];
        foreach ($game->players as $player) {
            if ($player->accuse !== null) {
                $tally[$player->accuse] = ($tally[$player->accuse] ?? 0) + 1;
            }
        }
        arsort($tally);
        $counts = array_values($tally);
        $tie = $counts === [] || (count($counts) > 1 && $counts[0] === $counts[1]);
        $top = $tie ? null : $game->player((string) array_key_first($tally));

        $this->record($game, ['arrival' => ['tally' => $tally, 'top' => $top?->id, 'tie' => $tie]]);

        if ($top !== null && $top->traitor) {
            $this->finish($game, 'crew', 'named');
        } elseif ($game->on('traitor_must_pull') && $game->pulls === []) {
            $this->finish($game, 'crew', 'never_pulled');
        } else {
            $this->finish($game, 'traitor', $tie ? 'tie_vote' : 'wrong_name');
        }
    }

    private function finish(Game $game, string $winner, string $cause): void
    {
        $game->winner = $winner;
        $game->cause = $cause;
        $game->phase = Game::FINISHED;
        $game->deadline = null;
        $game->vote = null;
        $names = implode(' and ', array_map(fn (Player $p) => $p->name, $game->traitors()));
        $traitor = count($game->traitors()) > 1 ? 'traitors were' : 'traitor was';
        $this->log($game, 'result', match ($cause) {
            'arrived' => "The train reached Last Stop. The crew wins. The {$traitor} {$names}.",
            'named' => "The crew named a traitor at Last Stop. The crew wins. The {$traitor} {$names}.",
            'never_pulled' => "The crew missed at Last Stop, but the cord was never pulled. The crew wins. The {$traitor} {$names}.",
            'wrong_name' => "The crew named the wrong person at Last Stop. The traitor wins: {$names}.",
            'tie_vote' => "The arrival vote tied. The traitor wins: {$names}.",
            'crisis' => "Two departments in crisis. The traitor wins: {$names}.",
            'clock' => "Out of days before Last Stop. The traitor wins: {$names}.",
            default => 'The trip is over.',
        });
    }

    // ------------------------------------------------------------ helpers

    /**
     * Station names along the line: Hollow Pines, the stations between, and
     * Last Stop at the end.
     */
    public static function stationName(int $leg, int $legs): string
    {
        $between = ['Cedar Hollow', 'Frostmere', 'Ashby Junction', 'Whitecap', 'Pinecrest', 'Ironside', 'Coldwater', 'Larch Pass'];

        return match (true) {
            $leg <= 0 => 'Hollow Pines',
            $leg >= $legs => 'Last Stop',
            default => $between[($leg - 1) % count($between)],
        };
    }

    /**
     * @param  array<string, int>  $scores
     * @param  array<string, int>  $effects
     * @return array<string, int>
     */
    private function apply(Game $game, array $scores, array $effects): array
    {
        $max = $game->rule('score_max');
        foreach ($game->departments as $dept) {
            $scores[$dept] = max(0, min($max, ($scores[$dept] ?? 0) + ($effects[$dept] ?? 0)));
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

        return $fx;
    }

    /**
     * Merge data into today's history record.
     *
     * @param  array<string, mixed>  $data
     */
    private function record(Game $game, array $data): void
    {
        $last = count($game->history) - 1;
        if ($last >= 0) {
            $game->history[$last] = [...$game->history[$last], ...$data];
        }
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
