<?php

$stmt = $pdo->prepare(
    "SELECT password_hash, api_token
     FROM users
     WHERE username = ?",
);

$stmt->execute([$username]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("Invalid credentials");
}

if (!password_verify($password, $user["password_hash"])) {
    die("Invalid credentials");
}

echo json_encode([
    "success" => true,
    "token" => $user["api_token"],
]);

?>
