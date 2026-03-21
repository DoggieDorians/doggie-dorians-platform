<?php
declare(strict_types=1);

function getDatabaseConnection(): PDO
{
    $dbPath = __DIR__ . '/data/members.sqlite';

    if (!file_exists($dbPath)) {
        throw new RuntimeException('Database file not found at: ' . $dbPath);
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON;');

    return $pdo;
}

$pdo = getDatabaseConnection();