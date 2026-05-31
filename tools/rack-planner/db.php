<?php

function rackPlannerConfig(): array
{
    static $config;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }

    return $config;
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = rackPlannerConfig();
    $db = $config['db'] ?? [];

    $host = $db['host'] ?? '127.0.0.1';
    $port = (int)($db['port'] ?? 3306);
    $database = $db['database'] ?? 'rack_planner';
    $username = $db['username'] ?? 'root';
    $password = $db['password'] ?? '';
    $charset = $db['charset'] ?? 'utf8mb4';

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);

    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
