<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/analytics.php';

header('Content-Type: text/plain; charset=UTF-8');

echo "Doggie Dorian's analytics schema check\n";
echo "=====================================\n\n";

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('PDO connection is not available.');
    }

    $dbPath = '(unknown)';
    $dbListStmt = $pdo->query('PRAGMA database_list');
    if ($dbListStmt) {
        $dbRows = $dbListStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($dbRows as $dbRow) {
            if (($dbRow['name'] ?? '') === 'main') {
                $dbPath = (string) ($dbRow['file'] ?? '(unknown)');
                break;
            }
        }
    }

    echo "Resolved DB path:\n" . $dbPath . "\n\n";

    dd_analytics_ensure_schema($pdo);

    $tables = [];
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'analytics_%' ORDER BY name ASC");
    if ($stmt) {
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    echo "Analytics tables found:\n";
    if (!$tables) {
        echo "(none)\n";
    } else {
        foreach ($tables as $table) {
            echo "- " . $table . "\n";
        }
    }

    echo "\nStatus: OK\n";
} catch (Throwable $e) {
    echo "Status: ERROR\n";
    echo "Message: " . $e->getMessage() . "\n";
}