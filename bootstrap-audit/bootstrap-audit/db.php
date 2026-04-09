<?php
declare(strict_types=1);

function getDatabaseConnection(): PDO
{
    $dbPath = __DIR__ . '/data/members.sqlite';

    if (!is_file($dbPath)) {
        throw new RuntimeException('Database file not found: ' . $dbPath);
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec('PRAGMA busy_timeout = 5000;');

    return $pdo;
}

$pdo = getDatabaseConnection();