<?php
require_once __DIR__ . '/data/config/db.php';

try {
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
    $tables = $stmt->fetchAll();

    echo "<h1>Database Connected Successfully</h1>";
    echo "<h2>Tables:</h2>";
    echo "<ul>";

    foreach ($tables as $table) {
        echo "<li>" . htmlspecialchars($table['name']) . "</li>";
    }

    echo "</ul>";
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}