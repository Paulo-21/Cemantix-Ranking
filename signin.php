<?php

$pdo = new PDO(
    "mysql:host=localhost;dbname=mydb;charset=utf8mb4",
    "user",
    "password",
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ],
);

$username = trim($_POST["username"] ?? "");
$password = $_POST["password"] ?? "";

if ($username === "" || $password === "") {
    http_response_code(400);
    die("Missing username or password");
}

// Vérifie si l'utilisateur existe déjà
$stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
$stmt->execute([$username]);

if ($stmt->fetch()) {
    http_response_code(409);
    die("Username already exists");
}

// Hash Argon2id
$passwordHash = password_hash($password, PASSWORD_ARGON2ID);

// Token aléatoire 256 bits
$token = bin2hex(random_bytes(32));

// Insertion
$stmt = $pdo->prepare(
    "INSERT INTO users (username, password_hash, api_token)
     VALUES (?, ?, ?)",
);

$stmt->execute([$username, $passwordHash, $token]);

echo json_encode([
    "success" => true,
    "token" => $token,
]);
