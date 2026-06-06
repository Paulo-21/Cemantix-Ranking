"""
Benchmark Kemeny-Young (CP-SAT) avec génération synthétique de données.

Simule le contexte réel :
  - N candidats (joueurs)
  - D jours de soumissions
  - Taux de participation variable (pas tout le monde joue chaque jour)
  - Construit la matrice de victoires par duels
  - Résout avec OR-Tools CP-SAT
  - Mesure temps, qualité, et détecte les cycles de Condorcet
"""

import random
import time
import itertools
import statistics
from dataclasses import dataclass, field
from typing import Optional
from ortools.sat.python import cp_model


# ─────────────────────────────────────────────
# 1. GÉNÉRATEUR DE DONNÉES SYNTHÉTIQUES
# ─────────────────────────────────────────────

@dataclass
class BenchmarkConfig:
    n_candidates: int        # Nombre de joueurs
    n_days: int              # Nombre de jours simulés
    participation_rate: float = 0.6   # Proba qu'un joueur participe un jour donné
    seed: Optional[int] = None

    # Modèle de force latente : chaque joueur a un "niveau" qui biaise
    # son heure de soumission. None = uniforme (aléatoire pur)
    use_skill_model: bool = True


@dataclass
class SyntheticData:
    users: list[int]
    usernames: dict[int, str]
    wins: dict[int, dict[int, int]]   # wins[a][b] = nb jours où a bat b
    n_duels: int                       # Nombre total de duels observés
    n_cycles: int                      # Cycles de Condorcet détectés (triplets)
    days_data: list[list[int]]         # Ordre de soumission par jour


def generate_data(cfg: BenchmarkConfig) -> SyntheticData:
    rng = random.Random(cfg.seed)

    users = list(range(1, cfg.n_candidates + 1))
    usernames = {u: f"player_{u:03d}" for u in users}

    # Niveau latent de chaque joueur (plus bas = tend à soumettre plus tôt)
    if cfg.use_skill_model:
        skill = {u: rng.gauss(0.5, 0.2) for u in users}
    else:
        skill = {u: 0.5 for u in users}

    wins = {u: {v: 0 for v in users} for u in users}
    days_data = []

    for _ in range(cfg.n_days):
        # Sous-ensemble de joueurs présents ce jour
        present = [u for u in users if rng.random() < cfg.participation_rate]

        if len(present) < 2:
            continue

        # Ordre de soumission : biaisé par le skill + bruit aléatoire
        # Score = skill + bruit uniforme → trie croissant = 1er soumetteur
        submission_score = {
            u: skill[u] + rng.uniform(-0.4, 0.4)
            for u in present
        }
        ordered = sorted(present, key=lambda u: submission_score[u])
        days_data.append(ordered)

        # Mise à jour matrice de victoires
        for i, a in enumerate(ordered):
            for b in ordered[i + 1:]:
                wins[a][b] += 1

    # Détection des cycles de Condorcet (triplets A > B > C > A)
    n_cycles = 0
    for a, b, c in itertools.combinations(users, 3):
        ab = wins[a][b] > wins[b][a]
        bc = wins[b][c] > wins[c][b]
        ca = wins[c][a] > wins[a][c]
        # Cycle si A>B, B>C, C>A  ou  A<B, B<C, C<A
        if (ab and bc and ca) or (not ab and not bc and not ca):
            n_cycles += 1

    n_duels = sum(
        wins[a][b] + wins[b][a]
        for a, b in itertools.combinations(users, 2)
    )

    return SyntheticData(
        users=users,
        usernames=usernames,
        wins=wins,
        n_duels=n_duels,
        n_cycles=n_cycles,
        days_data=days_data,
    )


# ─────────────────────────────────────────────
# 2. SOLVER KEMENY-YOUNG (CP-SAT)
# ─────────────────────────────────────────────

@dataclass
class SolverResult:
    ordered_users: list[int]
    kemeny_score: int
    status: str          # OPTIMAL / FEASIBLE / TIMEOUT / FAILED
    solve_time_s: float
    n_variables: int
    n_constraints: int


def solve_kemeny(data: SyntheticData, timeout_s: float = 20.0) -> SolverResult:
    print("Solving ... ")
    users = data.users
    wins  = data.wins
    n     = len(users)
    idx   = {u: i for i, u in enumerate(users)}

    model = cp_model.CpModel()

    # Variables binaires x[i][j] = 1 si users[i] classé avant users[j]
    x = {}
    for i in range(n):
        for j in range(n):
            if i != j:
                x[i, j] = model.new_bool_var(f"x_{i}_{j}")

    # Antisymétrie
    for i in range(n):
        for j in range(i + 1, n):
            #model.add(x[i, j] + x[j, i] == 1)
            model.add_bool_xor([x[i, j] , x[j, i]])

    # Transitivité (élimine les cycles dans la solution)
    """for i in range(n):
        for j in range(n):
            for k in range(n):
                if i != j and j != k and i != k:
                    #model.add(x[i, j] + x[j, k] + x[k, i] >= 1)
                    model.add_bool_or([x[i, j] , x[j, k] , x[k, i]])"""
    for i in range(n):
        for j in range(i + 1, n):
            for k in range(j + 1, n):
                # Les 2 orientations de cycle possibles sur ce triplet
                model.add_bool_or([x[i, j], x[j, k], x[k, i]])  # i→j→k→i
                model.add_bool_or([x[k, j], x[j, i], x[i, k]])  # k→j→i→k

    # Objectif : maximiser le score de Kemeny
    terms = []
    for i in range(n):
        for j in range(n):
            if i != j:
                w = wins[users[i]][users[j]]
                if w > 0:
                    terms.append(w * x[i, j])

    model.maximize(sum(terms))

    n_vars        = len(x)
    n_constraints = n * (n - 1) // 2 + n * (n - 1) * (n - 2)  # antisym + transit

    solver = cp_model.CpSolver()
    solver.parameters.num_workers = 4
    solver.parameters.max_time_in_seconds = timeout_s
    solver.parameters.log_search_progress = False
    solver

    t0     = time.perf_counter()
    status = solver.solve(model)
    elapsed = time.perf_counter() - t0

    STATUS_MAP = {
        cp_model.OPTIMAL:   "OPTIMAL",
        cp_model.FEASIBLE:  "FEASIBLE",
        cp_model.INFEASIBLE:"INFEASIBLE",
        cp_model.UNKNOWN:   "TIMEOUT",
    }
    status_str = STATUS_MAP.get(status, "FAILED")

    if status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        scores = {
            users[i]: sum(solver.value(x[i, j]) for j in range(n) if j != i)
            for i in range(n)
        }
        ordered = sorted(users, key=lambda u: scores[u], reverse=True)
        kemeny_score = int(solver.objective_value)
    else:
        ordered = users
        kemeny_score = 0

    return SolverResult(
        ordered_users=ordered,
        kemeny_score=kemeny_score,
        status=status_str,
        solve_time_s=elapsed,
        n_variables=n_vars,
        n_constraints=n_constraints,
    )


# ─────────────────────────────────────────────
# 3. BENCHMARK
# ─────────────────────────────────────────────

@dataclass
class BenchmarkResult:
    cfg: BenchmarkConfig
    data: SyntheticData
    result: SolverResult


def run_benchmark(configs: list[BenchmarkConfig], n_runs: int = 3) -> list[BenchmarkResult]:
    results = []
    for cfg in configs:
        run_times = []
        last_result = None
        last_data   = None

        for run in range(n_runs):
            seed = (cfg.seed or 0) + run
            c    = BenchmarkConfig(
                n_candidates=cfg.n_candidates,
                n_days=cfg.n_days,
                #participation_rate=cfg.participation_rate,
                participation_rate=1.0,
                seed=seed,
                use_skill_model=cfg.use_skill_model,
            )
            data   = generate_data(c)
            result = solve_kemeny(data)
            print("Solver in ", result.solve_time_s, " c : ", cfg.n_candidates, " v", cfg.n_days)
            run_times.append(result.solve_time_s)
            last_result = result
            last_data   = data

        # Moyenne des temps sur n_runs
        avg_result = SolverResult(
            ordered_users=last_result.ordered_users,
            kemeny_score=last_result.kemeny_score,
            status=last_result.status,
            solve_time_s=statistics.mean(run_times),
            n_variables=last_result.n_variables,
            n_constraints=last_result.n_constraints,
        )
        results.append(BenchmarkResult(cfg=cfg, data=last_data, result=avg_result))

    return results


# ─────────────────────────────────────────────
# 4. AFFICHAGE
# ─────────────────────────────────────────────

def print_report(results: list[BenchmarkResult]):
    SEP = "─" * 90

    print(f"\n{'BENCHMARK KEMENY-YOUNG — CP-SAT (OR-Tools)':^90}")
    print(SEP)
    print(
        f"{'Joueurs':>8} {'Jours':>6} {'Particip.':>10} {'Duels':>8} "
        f"{'Cycles':>8} {'Vars':>8} {'Cstrs':>10} {'Temps (s)':>10} {'Statut':>10}"
    )
    print(SEP)

    for br in results:
        cfg = br.cfg
        d   = br.data
        r   = br.result
        print(
            f"{cfg.n_candidates:>8} {cfg.n_days:>6} {cfg.participation_rate:>9.0%} "
            f"{d.n_duels:>8} {d.n_cycles:>8} {r.n_variables:>8} {r.n_constraints:>10} "
            f"{r.solve_time_s:>10.4f} {r.status:>10}"
        )

    print(SEP)

    # Détail du top 5 pour le dernier benchmark
    last = results[-1]
    print(f"\nTop 10 — {last.cfg.n_candidates} joueurs, {last.cfg.n_days} jours "
          f"(participation {last.cfg.participation_rate:.0%})")
    print(f"  Score Kemeny : {last.result.kemeny_score}")
    print(f"  Statut       : {last.result.status}")
    print(f"  Cycles détectés dans les données : {last.data.n_cycles}")
    print()
    print(f"  {'Rang':>5}  {'Joueur':<15} {'Victoires en duels':>20}")
    print(f"  {'─'*5}  {'─'*15} {'─'*20}")

    wins = last.data.wins
    users = last.result.ordered_users
    for rank0, uid in enumerate(users[:10]):
        n_wins = sum(
            1 for v in users if v != uid
            and wins[uid][v] > wins[v][uid]
        )
        name = last.data.usernames[uid]
        print(f"  {rank0+1:>5}  {name:<15} {n_wins:>20}")

    print()


# ─────────────────────────────────────────────
# 5. POINT D'ENTRÉE
# ─────────────────────────────────────────────

if __name__ == "__main__":
    configs = [
        # (joueurs, jours, participation)
        BenchmarkConfig(n_candidates=5,   n_days=30,  participation_rate=0.8, seed=42),
        BenchmarkConfig(n_candidates=10,  n_days=30,  participation_rate=0.7, seed=42),
        BenchmarkConfig(n_candidates=15,  n_days=60,  participation_rate=0.6, seed=42),
        BenchmarkConfig(n_candidates=20,  n_days=60,  participation_rate=0.5, seed=42),
        BenchmarkConfig(n_candidates=30,  n_days=90,  participation_rate=0.5, seed=42),
        BenchmarkConfig(n_candidates=50,  n_days=90,  participation_rate=0.4, seed=42),
        BenchmarkConfig(n_candidates=75,  n_days=120, participation_rate=0.4, seed=42),
        BenchmarkConfig(n_candidates=100, n_days=180, participation_rate=0.3, seed=42),
    ]

    print("Génération des données et résolution en cours...")
    results = run_benchmark(configs, n_runs=3)
    print_report(results)
