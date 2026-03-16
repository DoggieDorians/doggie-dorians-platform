<?php
session_start();
header('Content-Type: application/json');

$walkId = isset($_GET['walk_id']) ? (int) $_GET['walk_id'] : 0;

if ($walkId <= 0) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid walk ID.'
    ]);
    exit;
}

function getPreferredColumn(PDO $db, string $table, array $preferredColumns): ?string
{
    $stmt = $db->query("PRAGMA table_info($table)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $available = array_map(fn($col) => $col['name'], $columns);

    foreach ($preferredColumns as $column) {
        if (in_array($column, $available, true)) {
            return $column;
        }
    }

    return null;
}

try {
    $db = new PDO('sqlite:' . __DIR__ . '/data/members.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $dogColumn = getPreferredColumn($db, 'dogs', [
        'name', 'dog_name', 'full_name', 'pet_name', 'first_name'
    ]);

    $memberColumn = getPreferredColumn($db, 'members', [
        'full_name', 'name', 'member_name', 'first_name', 'email'
    ]);

    $walkerColumn = getPreferredColumn($db, 'walkers', [
        'full_name', 'name', 'walker_name', 'first_name', 'email'
    ]);

    $dogSelect = $dogColumn ? "d.$dogColumn AS dog_name" : "'Dog' AS dog_name";
    $memberSelect = $memberColumn ? "m.$memberColumn AS member_name" : "'Member' AS member_name";
    $walkerSelect = $walkerColumn ? "wa.$walkerColumn AS walker_name" : "'Walker' AS walker_name";

    $sql = "
        SELECT
            w.id,
            w.member_id,
            w.walker_id,
            w.status,
            $dogSelect,
            $memberSelect,
            $walkerSelect
        FROM walks w
        LEFT JOIN dogs d ON d.id = w.dog_id
        LEFT JOIN members m ON m.id = w.member_id
        LEFT JOIN walkers wa ON wa.id = w.walker_id
        WHERE w.id = :walk_id
        LIMIT 1
    ";

    $walkStmt = $db->prepare($sql);
    $walkStmt->execute([':walk_id' => $walkId]);
    $walk = $walkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$walk) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Walk not found.'
        ]);
        exit;
    }

    $authorized = false;

    if (isset($_SESSION['member_id']) && (int)$_SESSION['member_id'] === (int)$walk['member_id']) {
        $authorized = true;
    }

    if (isset($_SESSION['walker_id']) && (int)$_SESSION['walker_id'] === (int)$walk['walker_id']) {
        $authorized = true;
    }

    if (!$authorized) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized access.'
        ]);
        exit;
    }

    $latestStmt = $db->prepare("
        SELECT latitude, longitude, accuracy, speed, heading, created_at
        FROM walk_tracking
        WHERE walk_id = :walk_id
        ORDER BY id DESC
        LIMIT 1
    ");
    $latestStmt->execute([':walk_id' => $walkId]);
    $latest = $latestStmt->fetch(PDO::FETCH_ASSOC);

    $pointsStmt = $db->prepare("
        SELECT latitude, longitude, created_at
        FROM walk_tracking
        WHERE walk_id = :walk_id
        ORDER BY id ASC
        LIMIT 500
    ");
    $pointsStmt->execute([':walk_id' => $walkId]);
    $points = $pointsStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'walk' => [
            'id' => (int)$walk['id'],
            'status' => $walk['status'],
            'dog_name' => $walk['dog_name'] ?? 'Dog',
            'member_name' => $walk['member_name'] ?? 'Member',
            'walker_name' => $walk['walker_name'] ?? 'Walker'
        ],
        'latest' => $latest ?: null,
        'points' => $points
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error.',
        'error' => $e->getMessage()
    ]);
}