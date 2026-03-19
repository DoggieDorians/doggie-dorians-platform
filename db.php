<?php
declare(strict_types=1);

function getDatabaseConnection(): PDO
{
    $possiblePaths = [
        __DIR__ . '/data/members.sqlite',
        __DIR__ . '/data/database.sqlite',
        __DIR__ . '/database.sqlite',
        __DIR__ . '/data/site.sqlite',
        __DIR__ . '/members.sqlite',
    ];

    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            $pdo = new PDO('sqlite:' . $path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            return $pdo;
        }
    }

    throw new RuntimeException(
        'Database file not found. Checked: ' . implode(' | ', $possiblePaths)
    );
}