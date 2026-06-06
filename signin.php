<?php

declare(strict_types=1);

header("Content-Type: application/json; charset=utf-8");

require __DIR__ . "/db.php";

$username = trim($_POST["username"] ?? "");
$password = $_POST["password"] ?? "";

if ($username === "" || $password === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Pseudo ou mot de passe manquant.",
    ]);
    exit;
}

$stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
$stmt->execute([$username]);

if ($stmt->fetch()) {
    http_response_code(409);
    echo json_encode([
        "success" => false,
        "message" => "Ce pseudo existe deja.",
    ]);
    exit;
}

$passwordHash = password_hash($password, PASSWORD_ARGON2ID);
$token = bin2hex(random_bytes(32));

$stmt = $pdo->prepare(
    "INSERT INTO users (username, password_hash, api_token)
     VALUES (?, ?, ?)"
);
$stmt->execute([$username, $passwordHash, $token]);

echo json_encode([
    "success" => true,
    "token" => $token,
    "username" => $username,
]);
