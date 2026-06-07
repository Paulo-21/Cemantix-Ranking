<?php

declare(strict_types=1);

header("Content-Type: application/json; charset=utf-8");

require __DIR__ . "/db.php";

// ── Helpers Cemantix ─────────────────────────────────────────────────────────

function getPuzzleNumber(): ?string
{
    $ch = curl_init("https://cemantix.certitudes.org/");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            "Host: cemantix.certitudes.org",
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/80.0.3987.149 Safari/537.36",
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && preg_match('/data-puzzle-number="(\d+)"/', $response, $matches)) {
        return $matches[1];
    }
    return null;
}

function checkWordAgainstCemantix(string $word, string $puzzleNumber): ?array
{
    $ch = curl_init("https://cemantix.certitudes.org/score?n={$puzzleNumber}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => "word=" . urlencode($word),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/x-www-form-urlencoded",
            "Host: cemantix.certitudes.org",
            "Origin: https://cemantix.certitudes.org",
            "Referer: https://cemantix.certitudes.org/",
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/80.0.3987.149 Safari/537.36",
        ],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

// ── Auth & validation ────────────────────────────────────────────────────────

function requestToken(): string
{
    $authorization = $_SERVER["HTTP_AUTHORIZATION"] ?? $_SERVER["REDIRECT_HTTP_AUTHORIZATION"] ?? "";
    if (preg_match('/Bearer\s+(\S+)/i', $authorization, $matches)) {
        return $matches[1];
    }
    return trim($_POST["api_token"] ?? "");
}

$token         = requestToken();
#$token = "5ed66f49908bdfcfef281a63207b2f6baf5f8a663976ead59bc7f12bed849257";
$submittedWord = trim($_POST["submitted_word"] ?? "");
#$submittedWord = "significatif";
if ($token === "") {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Token manquant. Connecte-toi avant d'envoyer ton mot."]);
    exit;
}

if ($submittedWord === "") {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Mot invalide."]);
    exit;
}

$submittedWord = mb_substr($submittedWord, 0, 120);

$stmt = $pdo->prepare("SELECT id, username FROM users WHERE api_token = ?");
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Token invalide. Reconnecte-toi."]);
    exit;
}

// ── Dates ────────────────────────────────────────────────────────────────────

$paris        = new DateTimeZone("Europe/Paris");
$utc          = new DateTimeZone("UTC");
$now          = new DateTimeImmutable("now", $paris);
$submittedDay = $now->format("Y-m-d");
$submittedAt  = $now->setTimezone($utc)->format("Y-m-d H:i:s");

// ── Vérification du mot ──────────────────────────────────────────────────────

// Quelqu'un a-t-il déjà soumis aujourd'hui ?
$stmt = $pdo->prepare("SELECT submitted_word FROM submissions WHERE submitted_day = ? LIMIT 1");
$stmt->execute([$submittedDay]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existing !== false) {
    // On compare avec le mot déjà validé en base
    
    if (mb_strtolower(trim($submittedWord)) !== mb_strtolower(trim($existing["submitted_word"]))) {
        http_response_code(422);
        echo json_encode(["success" => false, "message" => "Mot incorrect."]);
        exit;
    }
} else {
    // Premier à soumettre aujourd'hui : on interroge Cemantix
    $puzzleNumber = getPuzzleNumber();
    if ($puzzleNumber === null) {
        http_response_code(502);
        echo json_encode(["success" => false, "message" => "Impossible de récupérer le puzzle du jour."]);
        exit;
    }

    $result = checkWordAgainstCemantix($submittedWord, $puzzleNumber);
    #echo (mb_strtolower(trim($submittedWord)) + " "+mb_strtolower(trim($existing["submitted_word"])));
    if ($result === null || !isset($result["s"]) || (float) $result["s"] !== 1.0) {
        http_response_code(422);
        echo json_encode([
            "success" => false,
            "message" => "Mot incorrect.",
            "score"   => $result["v"] ?? null,
        ]);
        exit;
    }
}

// ── Insertion ────────────────────────────────────────────────────────────────

try {
    $stmt = $pdo->prepare(
        "INSERT INTO submissions (user_id, submitted_word, submitted_day, submitted_at)
         VALUES (?, ?, ?, ?)
         RETURNING id"
    );
    $stmt->execute([(int) $user["id"], $submittedWord, $submittedDay, $submittedAt]);
    $submissionId = (int) $stmt->fetchColumn();
} catch (PDOException $e) {
    if ($e->getCode() === "23505") {
        http_response_code(409);
        echo json_encode(["success" => false, "message" => "Tu as déjà soumis un mot aujourd'hui."]);
        exit;
    }
    throw $e;
}
exec("python3 kemeny.py");
echo json_encode([
    "success"       => true,
    "submission_id" => $submissionId,
    "username"      => $user["username"],
]);