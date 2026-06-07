from ortools.sat.python import cp_model
from datetime import datetime, timezone
import psycopg2
import os

def fetch_wins(conn):
    """Récupère la matrice de victoires depuis PostgreSQL."""
    with conn.cursor() as cur:
        cur.execute("""
            
            SELECT s.user_id, u.username, s.submitted_day
            FROM submissions s
            INNER JOIN users u ON u.id = s.user_id
            ORDER BY s.submitted_day ASC, s.submitted_at ASC, s.id ASC
        """)
        rows = cur.fetchall()

    days = {}
    usernames = {}
    for user_id, username, day in rows:
        days.setdefault(day, []).append(user_id)
        usernames[user_id] = username

    users = list(usernames.keys())
    wins = {u: {v: 0 for v in users} for u in users}

    for day_users in days.values():
        for i, a in enumerate(day_users):
            for b in day_users[i + 1:]:
                wins[a][b] += 1   # a soumis avant b ce jour

    return users, usernames, wins


def solve_kemeny(users: list, wins: dict) -> list:
    """
    ILP Kemeny-Young via CP-SAT.
    Retourne la liste ordonnée des user_id (meilleur → moins bon).
    """
    n = len(users)
    if n == 0:
        return []
    if n == 1:
        return users

    model = cp_model.CpModel()

    # Variables binaires x[i][j] = 1 si users[i] classé avant users[j]
    x = {}
    for i in range(n):
        for j in range(n):
            if i != j:
                x[i, j] = model.new_bool_var(f"x_{i}_{j}")

    # Contrainte 1 : antisymétrie — x[i][j] + x[j][i] = 1
    for i in range(n):
        for j in range(i + 1, n):
            model.add_bool_xor([x[i, j] , x[j, i]])
            #model.add(x[i, j] + x[j, i] == 1)

    # Contrainte 2 : transitivité — pas de cycle A > B > C > A
    for i in range(n):
        for j in range(n):
            for k in range(n):
                if i != j and j != k and i != k:
                    model.add_bool_or([x[i, j] , x[j, k] , x[k, i]])
                    #model.add(x[i, j] + x[j, k] + x[k, i] >= 1)

    # Objectif : maximiser le score de Kemeny
    objective_terms = []
    for i in range(n):
        for j in range(n):
            if i != j:
                w = wins[users[i]][users[j]]
                if w > 0:
                    objective_terms.append(w * x[i, j])

    model.maximize(sum(objective_terms))

    # Résolution
    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = 5.0  # timeout de sécurité
    status = solver.solve(model)

    if status not in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        # Fallback : ordre arbitraire si pas de solution
        return users

    # Reconstruit l'ordre à partir des variables x
    scores = {
        users[i]: sum(solver.value(x[i, j]) for j in range(n) if j != i)
        for i in range(n)
    }
    return sorted(users, key=lambda u: scores[u], reverse=True)


def upsert_rankings(conn, ordered_users: list, usernames: dict):
    with conn.cursor() as cur:
        cur.execute("""
            SELECT DISTINCT ON (user_id)
                user_id,
                id AS submission_id,
                submitted_at
            FROM submissions
            ORDER BY user_id, submitted_at DESC;
        """)

        rows = cur.fetchall()

        last_sub_map = {
            user_id: (submission_id, submitted_at)
            for user_id, submission_id, submitted_at in rows
        }
        cur.execute("""
            DELETE FROM kemeny_ranking_cache
            WHERE ranking_day = CURRENT_DATE;"""
        )
        for rank0, uid in enumerate(ordered_users):
            cur.execute("""
            INSERT INTO kemeny_ranking_cache (
                ranking_day,
                user_id,
                position,
                last_submission_at,
                last_submission_id,
                computed_at
            )
            VALUES (%s, %s, %s, %s, %s, %s);
        """, (
            datetime.now().date(),
            uid,
            rank0 + 1,
            last_sub_map[uid][1],#submitted_at,
            last_sub_map[uid][0],
            datetime.now(),
        ))
    conn.commit()


def recompute_ranking():
    conn = psycopg2.connect(
        dbname="cemantix_game",
        user="paul",
        password="CemantixThales",
        host="localhost",
        port=5432
    )
    #conn = psycopg2.connect(os.environ["DATABASE_URL"])
    try:
        users, usernames, wins = fetch_wins(conn)
        ordered = solve_kemeny(users, wins)
        upsert_rankings(conn, ordered, usernames)
    finally:
        conn.close()


if __name__ == "__main__":
    recompute_ranking()
