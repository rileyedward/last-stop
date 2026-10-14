#!/usr/bin/env python3
"""Record simulator games as replay fixtures for the PHP engine tests.

Runs the balance simulator (sim/lastsim.py from the project library) with the
game data shipped in resources/game, and writes every seat, weather draw,
hand, pick, veto, override and brake of each game, plus the scores and
distance after every day. tests/Unit/Game/SimulatorReplayTest.php feeds the
same deal and decisions to the PHP engine and checks it ends up in the same
place every day.

    python3 tests/Fixtures/sim/record.py /path/to/sim/lastsim.py
"""
import importlib.util
import json
import os
import random
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
GAME = os.path.normpath(os.path.join(HERE, "..", "..", "..", "resources", "game"))


def load_sim(path):
    spec = importlib.util.spec_from_file_location("lastsim", path)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def recorder(sim):
    class Recording(sim.Game):
        def __init__(self, *a, **kw):
            super().__init__(*a, **kw)
            self.rec_days = []
            weather_draw = self.weather.draw

            def draw(n):
                out = weather_draw(n)
                self.today()["weather"] += [c["name"] for c in out]
                return out

            self.weather.draw = draw

        def today(self):
            while len(self.rec_days) < self.day:
                self.rec_days.append({"weather": [], "hands": {}, "first": {}, "final": {},
                                      "veto": {}, "override": {}, "brake": None})
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
            return {str(p.id): {"restricted": p.restricted_days, "escorting": None if p.escort_of is None else str(p.escort_of),
                                "cooldown": p.cooldown, "brakes": p.brakes_left, "overrides": p.overrides_left}
                    for p in self.players}

        def day_tasks(self):
            self.today()
            super().day_tasks()
            day = self.today()
            day["scores"] = dict(self.scores)
            day["distance"] = self.distance
            day["after_day"] = self.seats()

        def governance(self):
            S = self.S
            day = self.today()
            day["brake_open"] = (self.day >= S["brake_from_night"] and self.brake_rest == 0
                                 and self.train_brakes_left > 0)
            super().governance()

        def night(self):
            super().night()
            self.today()["after_night"] = self.seats()

        def restrict(self, puller, nominee, passed):
            failed = self.stats["failed_votes"]
            super().restrict(puller, nominee, passed)
            self.today()["brake"] = {"puller": str(puller.id), "nominee": str(nominee.id),
                                     "passed": self.stats["failed_votes"] == failed}

    return Recording


def record(sim, Recording, S, cards, n, seed, mode, forced):
    tasks, weather, table = cards
    rng = random.Random(seed)
    g = Recording(n, S, tasks, weather, table, rng, mode, forced)
    result = g.play()
    return {
        "name": f"{n} players, {mode}, seed {seed}" + (f", {forced} forced brakes" if forced else ""),
        "players": [{"id": str(p.id), "department": sim.DEPT_KEYS[p.dept], "traitor": p.traitor}
                    for p in g.players],
        "days": g.rec_days,
        "result": {"winner": result["winner"], "cause": result["cause"], "day": result["day"]},
    }


def features(game):
    f = {game["result"]["cause"]}
    for d in game["days"]:
        if any(d["veto"].values()):
            f.add("veto")
        if any(d["override"].values()):
            f.add("override")
        if d["brake"]:
            f.add("brake-passed" if d["brake"]["passed"] else "brake-failed")
    return f


def main():
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    sim = load_sim(sys.argv[1])
    Recording = recorder(sim)
    S = sim.load_settings(os.path.join(GAME, "settings.csv"), [])
    S["tasks_csv"] = os.path.join(GAME, "tasks.csv")
    S["weather_csv"] = os.path.join(GAME, "weather-winter.csv")
    S["players_csv"] = os.path.join(GAME, "players.csv")
    cards = sim.load_cards(S)

    # Per player count: a few plain games of each kind, plus the first games
    # that show something not covered yet (a veto, an override, a failed vote).
    chosen, seen = [], set()
    for n in range(4, 11):
        for seed, mode, forced in ((1, "normal", 0), (2, "normal", 0), (3, "normal", 2),
                                   (1, "honest", 0), (2, "honest", 3)):
            game = record(sim, Recording, S, cards, n, seed * 7 + n, mode, forced)
            chosen.append(game)
            seen |= features(game)
        for seed in range(4, 300):
            game = record(sim, Recording, S, cards, n, seed * 7 + n, "normal", 1)
            if features(game) - seen or (n >= 9 and "override" not in features(game) and seed < 6):
                chosen.append(game)
                seen |= features(game)

    out = os.path.join(HERE, "games.json")
    with open(out, "w") as f:
        json.dump(chosen, f, separators=(",", ":"))
    print(f"Wrote {len(chosen)} games to {out}; features covered: {sorted(seen)}")


if __name__ == "__main__":
    main()
