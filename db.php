<?php

declare(strict_types=1);

$dbHost = getenv("DB_HOST") ?: "localhost";
$dbPort = getenv("DB_PORT") ?: "5432";
$dbName = getenv("DB_NAME") ?: "cemantix_ranking";
$dbUser = getenv("DB_USER") ?: "postgres";
$dbPass = getenv("DB_PASSWORD") ?: "";

try {
    $pdo = new PDO(
        "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $exception) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Connexion PostgreSQL impossible: " . $exception->getMessage(),
    ]);
    exit;
}
