<?php
require_once __DIR__ . '/includes/member_config.php';

header('Content-Type: application/json');

$walkId = (int)($_GET['walk_id'] ?? 0);

if ($walkId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing walk_id']);
    exit;
}

if (!empty($_SESSION['member_id'])) {
    $member = currentMember($pdo);
    if (!$member) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid member session']);
        exit;
    }

    $walkStmt = $pdo->prepare("
        SELECT
            walks.*,
            dogs.dog_name
        FROM walks
        INNER JOIN dogs ON dogs.id = walks.dog_id
        WHERE walks.id = :walk_id
          AND walks.member_id = :member_id
        LIMIT 1
    ");
    $walkStmt->execute([
        ':walk_id' => $walkId,
        ':member_id' => $member['id']
    ]);
    $walk = $walkStmt->fetch(PDO::FETCH_ASSOC);
} elseif (!empty($_SESSION['walker_id'])) {
    $walker = currentWalker($pdo);
    if (!$walker) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid walker session']);
        exit;
    }

    $walkStmt = $pdo->prepare("
        SELECT
            walks.*,
            dogs.dog_name
        FROM walks
        INNER JOIN dogs ON dogs.id = walks.dog_id
        WHERE walks.id = :walk_id
          AND walks.walker_id = :walker_id
        LIMIT 1
    ");
    $walkStmt->execute([
        ':walk_id' => $walkId,
        ':walker_id' => $walker['id']
    ]);
    $walk = $walkStmt->fetch(PDO::FETCH_ASSOC);
} else {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

if (!$walk) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Walk not found']);
    exit;
}

$sessionStmt = $pdo->prepare("
    SELECT *
    FROM walk_sessions
    WHERE walk_id = :walk_id
    LIMIT 1
");
$sessionStmt->execute([':walk_id' => $walkId]);
$session = $sessionStmt->fetch(PDO::FETCH_ASSOC);

$routePoints = [];
if ($session && !empty($session['route_points'])) {
    $decoded = json_decode($session['route_points'], true);
    if (is_array($decoded)) {
        $routePoints = $decoded;
    }
}

echo json_encode([
    'ok' => true,
    'walk' => [
        'id' => (int)$walk['id'],
        'dog_name' => $walk['dog_name'],
        'walk_date' => $walk['walk_date'],
        'walk_time' => $walk['walk_time'],
        'duration_minutes' => (int)$walk['duration_minutes'],
        'walker_name' => $walk['walker_name'],
        'walker_phone' => $walk['walker_phone'],
        'status' => $walk['status']
    ],
    'session' => [
        'session_status' => $session['session_status'] ?? 'Walker Assigned',
        'eta_minutes' => isset($session['eta_minutes']) ? (int)$session['eta_minutes'] : null,
        'current_location' => $session['current_location'] ?? null,
        'current_lat' => isset($session['current_lat']) ? (float)$session['current_lat'] : null,
        'current_lng' => isset($session['current_lng']) ? (float)$session['current_lng'] : null,
        'last_gps_at' => $session['last_gps_at'] ?? null,
        'last_update' => $session['last_update'] ?? null,
        'bathroom_update' => $session['bathroom_update'] ?? null,
        'photo_note' => $session['photo_note'] ?? null,
        'route_note' => $session['route_note'] ?? null,
        'route_points' => $routePoints
    ]
]);