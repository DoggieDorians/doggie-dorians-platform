<?php
declare(strict_types=1);

function resolveDatabasePath(): string
{
    $candidates = [];

    $envPath = trim((string) getenv('DOGGIEDORIANS_DB_PATH'));
    if ($envPath !== '') {
        $candidates[] = $envPath;
    }

    $candidates[] = dirname(__DIR__) . '/private/data/members.sqlite';
    $candidates[] = __DIR__ . '/private/data/members.sqlite';
    $candidates[] = __DIR__ . '/data/members.sqlite';

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException('Database file not found.');
}

function getDatabaseConnection(): PDO
{
    $dbPath = resolveDatabasePath();

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec('PRAGMA busy_timeout = 5000;');

    return $pdo;
}

$pdo = getDatabaseConnection();