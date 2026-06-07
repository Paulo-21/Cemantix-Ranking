<?php

declare(strict_types=1);

header("Content-Type: application/json; charset=utf-8");

require __DIR__ . "/db.php";

$scope = $_GET["scope"] ?? "global";

if (!in_array($scope, ["global", "today"], true)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Classement inconnu.",
    ]);
    exit;
}

$params = [];
$where = "";
$leaderboard = "";
if ($scope === "today") {
    $paris = new DateTimeZone("Europe/Paris");
    $today = new DateTimeImmutable("today", $paris);

    $where = "WHERE s.submitted_day = ?";
    $params = [$today->format("Y-m-d")];
}
if ($scope == "today") {

    $stmt = $pdo->prepare(
        "SELECT u.username, s.submitted_word, s.submitted_at
        FROM submissions s
        INNER JOIN users u ON u.id = s.user_id
        {$where}
        ORDER BY s.submitted_at ASC, s.id ASC
        LIMIT 100"
    );
    $stmt->execute($params);

    $paris = new DateTimeZone("Europe/Paris");
    $leaderboard = array_map(static function (array $row) use ($paris): array {
        $submittedAt = new DateTimeImmutable($row["submitted_at"], new DateTimeZone("UTC"));
        $localTime = $submittedAt->setTimezone($paris);
        
        return [
            "player_name" => $row["username"],
            "submitted_word" => $row["submitted_word"],
            "submitted_time" => $localTime->format("H:i:s"),
            "submitted_date" => $localTime->format("d/m/Y"),
            ];
            }, $stmt->fetchAll());
}
else {  
    $stmt = $pdo->prepare(
        "SELECT
    c.position,
    u.id AS user_id,
    u.username,
    c.last_submission_at,
    s.submitted_word
FROM kemeny_ranking_cache c
JOIN users u ON u.id = c.user_id
JOIN submissions s ON s.id = c.last_submission_id
WHERE c.ranking_day = CURRENT_DATE
ORDER BY c.position ASC;"
);
$stmt->execute($params);

$paris = new DateTimeZone("Europe/Paris");
$leaderboard = array_map(static function (array $row) use ($paris): array {
    $submittedAt = new DateTimeImmutable($row["last_submission_at"], new DateTimeZone("UTC"));
    $localTime = $submittedAt->setTimezone($paris);
    
    return [
        "player_name" => $row["username"],
        "submitted_word" => $row["submitted_word"],
        "submitted_time" => $localTime->format("H:i:s"),
        "submitted_date" => $localTime->format("d/m/Y"),
        ];
        }, $stmt->fetchAll());
}
echo json_encode([
    "success" => true,
    "scope" => $scope,
    "leaderboard" => $leaderboard,
]);
