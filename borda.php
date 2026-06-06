<?php

declare(strict_types=1);

require __DIR__ . "/db.php";

/**
 * Recalcule le classement Borda global
 * et met à jour ranking_cache(scope='global')
 */

function recomputeBorda(PDO $pdo, string $scope = 'global'): void
{
    // 1. Charger toutes les soumissions
    $stmt = $pdo->query("
        SELECT s.submitted_word
        FROM submissions s
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $scores = [];

    // 2. Borda scoring
    foreach ($rows as $row) {
        $ranking = array_map('trim', explode(',', $row['submitted_word']));
        $n = count($ranking);

        foreach ($ranking as $i => $candidate) {
            $points = $n - $i - 1;

            if (!isset($scores[$candidate])) {
                $scores[$candidate] = 0;
            }

            $scores[$candidate] += $points;
        }
    }

    // 3. Tri décroissant
    arsort($scores);

    // 4. Format JSON
    $ranking = [];
    foreach ($scores as $candidate => $score) {
        $ranking[] = [
            'candidate' => $candidate,
            'score' => $score
        ];
    }

    // 5. UPSERT cache (remplace ancien ranking)
    $stmt = $pdo->prepare("
        INSERT INTO ranking_cache(scope, ranking, updated_at)
        VALUES (:scope, :ranking, NOW())
        ON CONFLICT (scope)
        DO UPDATE SET
            ranking = EXCLUDED.ranking,
            updated_at = NOW()
    ");

    $stmt->execute([
        ':scope' => $scope,
        ':ranking' => json_encode($ranking, JSON_UNESCAPED_UNICODE)
    ]);
}

// exécution directe si appelé seul
recomputeBorda($pdo);

echo json_encode([
    "success" => true,
    "message" => "Borda ranking updated"
]);