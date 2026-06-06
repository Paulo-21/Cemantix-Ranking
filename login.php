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

$stmt = $pdo->prepare(
    "SELECT password_hash, api_token
     FROM users
     WHERE username = ?"
);
$stmt->execute([$username]);

$user = $stmt->fetch();

if (!$user || !password_verify($password, $user["password_hash"])) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Identifiants invalides.",
    ]);
    exit;
}

echo json_encode([
    "success" => true,
    "token" => $user["api_token"],
    "username" => $username,
]);
