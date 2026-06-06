<?php

declare(strict_types=1);

header("Content-Type: application/json; charset=utf-8");

require __DIR__ . "/db.php";

function requestToken(): string
{
    $authorization = $_SERVER["HTTP_AUTHORIZATION"] ?? $_SERVER["REDIRECT_HTTP_AUTHORIZATION"] ?? "";

    if (preg_match('/Bearer\s+(\S+)/i', $authorization, $matches)) {
        return $matches[1];
    }

    return trim($_POST["api_token"] ?? "");
}

$token = requestToken();
$attempts = filter_input(INPUT_POST, "attempts", FILTER_VALIDATE_INT);

if ($token === "") {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Token manquant. Connecte-toi avant d'envoyer ton score.",
    ]);
    exit;
}

if ($attempts === false || $attempts < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Score invalide.",
    ]);
    exit;
}

$stmt = $pdo->prepare("SELECT id, username FROM users WHERE api_token = ?");
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Token invalide. Reconnecte-toi.",
    ]);
    exit;
}

$paris = new DateTimeZone("Europe/Paris");
$utc = new DateTimeZone("UTC");
$now = new DateTimeImmutable("now", $paris);
$submittedDay = $now->format("Y-m-d");
$submittedAt = $now->setTimezone($utc)->format("Y-m-d H:i:s");

try {
    $stmt = $pdo->prepare(
        "INSERT INTO submissions (user_id, attempts, submitted_day, submitted_at)
         VALUES (?, ?, ?, ?)
         RETURNING id"
    );
    $stmt->execute([(int) $user["id"], $attempts, $submittedDay, $submittedAt]);
    $submissionId = (int) $stmt->fetchColumn();
} catch (PDOException $exception) {
    if ($exception->getCode() === "23505") {
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "message" => "Tu as deja soumis un score aujourd'hui.",
        ]);
        exit;
    }

    throw $exception;
}

echo json_encode([
    "success" => true,
    "submission_id" => $submissionId,
    "username" => $user["username"],
]);
