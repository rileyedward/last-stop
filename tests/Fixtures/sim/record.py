#!/usr/bin/env python3
"""Record Departure simulator games as replay fixtures for the PHP engine.

Runs the Departure simulator (design/departure/sim/departure_sim.py from the
project library) with the game data shipped in resources/game, and writes
every seat, weather draw, hand, pick, veto, override, cord pull and clue,
nomination, detain vote and arrival vote of each game, plus the scores, leg,
line and detentions after every day. tests/Unit/Game/SimulatorReplayTest.php
feeds the same deal and decisions to the PHP engine and checks it ends up in
the same place every day.

    python3 tests/Fixtures/sim/record.py /path/to/departure_sim.py
"""
import csv
import importlib.util
import json
import os
import random
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
GAME = os.path.normpath(os.path.join(HERE, "..", "..", "..", "resources", "game"))
CAUSES = {"arrived_named": "named", "arrived_never_pulled": "never_pulled"}


def load_sim(path):
    spec = importlib.util.spec_from_file_location("departure_sim", path)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def settings(sim):
    """The simulator's settings.csv for the bots, with the app's rule rows."""
    S = sim.load_settings([])
    with open(os.path.join(GAME, "settings.csv"), newline="") as f:
        for row in csv.DictReader(f):
            S[row["setting"].strip()] = sim.parse(row["value"].strip())
    S["tasks_csv"] = os.path.join(GAME, "tasks.csv")
    S["weather_csv"] = os.path.join(GAME, "weather.csv")
    S["players_csv"] = os.path.join(GAME, "players.csv")
    return S


def capture(fn, names, on_line=None):
    """Call fn and return its result plus the named locals at its return."""
    seen = {}

    def tracer(frame, event, arg):
        if frame.f_code is not fn.__func__.__code__:
            return None

        def local(frame, event, arg):
            if event == "line" and on_line:
                on_line(frame.f_locals)
            if event == "return":
                for n in names:
                    seen[n] = frame.f_locals.get(n)
            return local
        return local

    old = sys.gettrace()
    sys.settrace(tracer)
    try:
        result = fn()
    finally:
        sys.settrace(old)
    return result, seen


def recorder(sim):
    class Recording(sim.Game):
        def __init__(self, *a, **kw):
            super().__init__(*a, **kw)
            self.rec_days = []
            self.rec_votes = {}
            weather_draw = self.wdeck.draw

            def draw(n):
                out = weather_draw(n)
                self.today()["weather"] += [c["name"] for c in out]
                return out

            self.wdeck.draw = draw

        def today(self):
            while len(self.rec_days) < self.day:
                self.rec_days.append({"weather": [], "hands": {}, "first": {}, "final": {},
                                      "veto": {}, "override": {}, "puller": None, "nomination": None})
            return self.rec_days[self.day - 1]

        def choose(self, p, hand, escort):
            pick = super().choose(p, hand, escort)
            day = self.today()
            if str(p.id) not in day["hands"]:
                day["hands"][str(p.id)] = [c["name"] for c in hand]
                day["first"][str(p.id)] = pick["name"]
                day["final"][str(p.id)] = pick["name"]
            return pick

        def escort_step(self, p, escort, hand, pick):
            vetoes, overrides = self.stats["vetoes"], self.stats["overrides"]
            final = super().escort_step(p, escort, hand, pick)
            day = self.today()
            day["veto"][str(p.id)] = self.stats["vetoes"] > vetoes
            day["override"][str(p.id)] = self.stats["overrides"] > overrides
            day["final"][str(p.id)] = final["name"]
            return final

        def seats(self):
            return {str(p.id): {"detained": p.detained, "escorting": None if p.escort_of is None else str(p.escort_of),
                                "cooldown": p.cooldown, "overrides": p.overrides}
                    for p in self.players}

        def day_tasks(self):
            self.today()
            super().day_tasks()
            self.today()["scores_day"] = dict(self.scores)

        def cord_puller(self):
            p = super().cord_puller()
            self.today()["puller"] = None if p is None else str(p.id)
            return p

        def dusk(self):
            green = self.green()
            free = self.free_run
            clues = len(self.clues)
            arrived = super().dusk()
            day = self.today()
            day["green"] = green
            day["clue_size"] = len(self.clues[-1]) if len(self.clues) > clues else 0
            day["outcome"] = "pulled" if day["puller"] is not None else ("ran" if green else "red")
            day["free"] = free and not self.free_run and day["outcome"] == "ran"
            day["leg"] = self.leg
            day["line"] = self.line
            day["scores_dusk"] = dict(self.scores)
            day["arrived"] = arrived
            return arrived

        def night(self):
            _, seen = capture(super().night, ["nominator", "nominee", "yes", "voters"])
            if seen.get("nominator") is not None and seen.get("yes") is not None:
                self.today()["nomination"] = {
                    "nominator": str(seen["nominator"].id), "nominee": str(seen["nominee"].id),
                    "yes": seen["yes"], "voters": seen["voters"], "passed": seen["yes"] * 2 > seen["voters"]}
            self.today()["after_night"] = self.seats()

        def arrival(self):
            state = {"seen": {}}

            def on_line(f):
                votes, p = f.get("votes"), f.get("p")
                if votes is None or p is None:
                    return
                for target, n in votes.items():
                    if n > state["seen"].get(target, 0):
                        self.rec_votes[str(p.id)] = str(target)
                state["seen"] = dict(votes)

            result, _ = capture(super().arrival, [], on_line)
            return result

    return Recording


def record(sim, Recording, S, cards, n, seed, honest):
    tasks, weather, table = cards
    g = Recording(n, S, tasks, weather, table, random.Random(seed), honest=honest)
    result = g.play()
    return {
        "name": f"{n} players, {'honest' if honest else 'normal'}, seed {seed}",
        "players": [{"id": str(p.id), "department": sim.DEPT_KEYS[p.dept], "traitor": p.traitor}
                    for p in g.players],
        "days": g.rec_days,
        "arrival_votes": g.rec_votes,
        "result": {"winner": result["winner"], "cause": CAUSES.get(result["why"], result["why"]), "day": result["day"]},
    }


def features(game):
    f = {game["result"]["cause"]}
    legs = 5
    for d in game["days"]:
        if any(d["veto"].values()):
            f.add("veto")
        if any(d["override"].values()):
            f.add("override")
        if d.get("free"):
            f.add("tailwind")
        if d["outcome"] == "pulled":
            f.add("pull")
            if d["leg"] == legs - 1:
                f.add("pull-last-leg")
        if d["nomination"]:
            f.add("detained" if d["nomination"]["passed"] else "vote-failed")
    if sum(p["traitor"] for p in game["players"]) == 2:
        f.add("two-traitors")
    return f


def main():
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    sim = load_sim(sys.argv[1])
    Recording = recorder(sim)
    S = settings(sim)
    cards = sim.load_cards(S)

    # Per player count: two plain games, one honest game, plus the first
    # games that show something not covered yet.
    chosen, seen = [], set()
    for n in range(4, 11):
        for seed, honest in ((1, False), (2, False), (1, True)):
            game = record(sim, Recording, S, cards, n, seed * 7 + n, honest)
            chosen.append(game)
            seen |= features(game)
        for seed in range(3, 400):
            game = record(sim, Recording, S, cards, n, seed * 7 + n, False)
            if features(game) - seen:
                chosen.append(game)
                seen |= features(game)

    out = os.path.join(HERE, "departure-games.json")
    with open(out, "w") as f:
        json.dump(chosen, f, separators=(",", ":"))
    print(f"Wrote {len(chosen)} games to {out}; features covered: {sorted(seen)}")


if __name__ == "__main__":
    main()
