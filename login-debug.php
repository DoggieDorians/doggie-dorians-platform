<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain');

echo "LOGIN DEBUG SAFE\n";
echo "================\n\n";

echo "__FILE__: " . __FILE__ . "\n";
echo "__DIR__: " . __DIR__ . "\n\n";

$dbPhp = __DIR__ . '/db.php';

echo "db.php exists: " . (is_file($dbPhp) ? 'YES' : 'NO') . "\n";
if (is_file($dbPhp)) {
    echo "db.php size: " . filesize($dbPhp) . "\n";
    echo "db.php mtime: " . date('Y-m-d H:i:s', filemtime($dbPhp)) . "\n";
}

echo "\nCandidates:\n";

$candidates = [
    '/homepages/39/d4299671946/private/data/members.sqlite',
    __DIR__ . '/data/members.sqlite',
    dirname(__DIR__) . '/data/members.sqlite',
    __DIR__ . '/members.sqlite',
];

foreach ($candidates as $candidate) {
    echo '- ' . $candidate . ' => ' . (is_file($candidate) ? 'FOUND' : 'missing') . "\n";
    if (is_file($candidate)) {
        echo '  size=' . filesize($candidate) . "\n";
        echo '  mtime=' . date('Y-m-d H:i:s', filemtime($candidate)) . "\n";
    }
}

echo "\nAbout to load db.php...\n";

try {
    require $dbPhp;
    echo "db.php loaded\n";

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        echo "pdo not created\n";
        exit;
    }

    echo "pdo created successfully\n";

    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
        ->fetchAll(PDO::FETCH_COLUMN);

    echo "\nTables:\n";
    foreach ($tables as $table) {
        echo '- ' . $table . "\n";
    }

    echo "\nusers table exists: " . (in_array('users', $tables, true) ? 'YES' : 'NO') . "\n";

    if (in_array('users', $tables, true)) {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        echo "users count: " . $count . "\n";

        $stmt = $pdo->query("SELECT id, full_name, email, role FROM users ORDER BY id DESC LIMIT 5");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo "\nRecent users:\n";
        foreach ($rows as $row) {
            echo '- #' . $row['id'] . ' | ' . $row['full_name'] . ' | ' . $row['email'] . ' | ' . $row['role'] . "\n";
        }
    }
} catch (Throwable $e) {
    echo "\nCAUGHT ERROR\n";
    echo "Type: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
}