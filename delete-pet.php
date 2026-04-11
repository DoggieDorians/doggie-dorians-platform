<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$petId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($petId <= 0) {
    header('Location: manage-pets.php');
    exit;
}

function tableExists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare("
        SELECT name FROM sqlite_master 
        WHERE type='table' AND name=:name
    ");
    $stmt->execute([':name' => $tableName]);
    return (bool)$stmt->fetchColumn();
}

function getColumns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query("PRAGMA table_info($table)");
    return $stmt ? array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name') : [];
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database not available.');
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if (!tableExists($pdo, 'dogs')) {
        throw new RuntimeException('Dogs table missing.');
    }

    $columns = getColumns($pdo, 'dogs');

    $ownerCol = in_array('user_id', $columns, true)
        ? 'user_id'
        : (in_array('member_id', $columns, true) ? 'member_id' : null);

    if (!$ownerCol) {
        throw new RuntimeException('Owner column not found.');
    }

    // 🔒 Verify ownership before deleting
    $checkStmt = $pdo->prepare("
        SELECT id FROM dogs
        WHERE id = :id AND {$ownerCol} = :owner
        LIMIT 1
    ");
    $checkStmt->execute([
        ':id' => $petId,
        ':owner' => $userId
    ]);

    $exists = $checkStmt->fetch();

    if ($exists) {
        $deleteStmt = $pdo->prepare("
            DELETE FROM dogs
            WHERE id = :id AND {$ownerCol} = :owner
        ");
        $deleteStmt->execute([
            ':id' => $petId,
            ':owner' => $userId
        ]);
    }

} catch (Throwable $e) {
    // Silent fail (optional: log later)
}

header('Location: manage-pets.php');
exit;