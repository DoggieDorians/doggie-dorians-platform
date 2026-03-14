<?php
require_once __DIR__ . '/includes/member_config.php';

header('Content-Type: application/json');

if (empty($_SESSION['walker_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Walker not logged in']);
    exit;
}

$walker = currentWalker($pdo);
if (!$walker) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid walker session']);
    exit;
}

$walkId = (int)($_POST['walk_id'] ?? 0);
$lat = isset($_POST['lat']) ? (float)$_POST['lat'] : null;
$lng = isset($_POST['lng']) ? (float)$_POST['lng'] : null;
$currentLocation = trim($_POST['current_location'] ?? '');

if ($walkId <= 0 || $lat === null || $lng === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing required GPS fields']);
    exit;
}

$walkStmt = $pdo->prepare("
    SELECT *
    FROM walks
    WHERE id = :walk_id
      AND walker_id = :walker_id
    LIMIT 1
");
$walkStmt->execute([
    ':walk_id' => $walkId,
    ':walker_id' => $walker['id']
]);
$walk = $walkStmt->fetch(PDO::FETCH_ASSOC);

if (!$walk) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Walk not assigned to this walker']);
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

$routePoints[] = [
    'lat' => $lat,
    'lng' => $lng,
    'at' => date('Y-m-d H:i:s')
];

$routePoints = array_slice($routePoints, -500);
$routeJson = json_encode($routePoints);

$locationText = $currentLocation !== '' ? $currentLocation : 'GPS updated from walker device';
$lastGpsAt = date('Y-m-d H:i:s');

if ($session) {
    $update = $pdo->prepare("
        UPDATE walk_sessions
        SET current_lat = :current_lat,
            current_lng = :current_lng,
            current_location = :current_location,
            route_points = :route_points,
            last_gps_at = :last_gps_at,
            last_update = :last_update
        WHERE walk_id = :walk_id
    ");

    $update->execute([
        ':current_lat' => $lat,
        ':current_lng' => $lng,
        ':current_location' => $locationText,
        ':route_points' => $routeJson,
        ':last_gps_at' => $lastGpsAt,
        ':last_update' => 'Live GPS updated from walker device.',
        ':walk_id' => $walkId
    ]);
} else {
    $insert = $pdo->prepare("
        INSERT INTO walk_sessions (
            walk_id,
            session_status,
            current_lat,
            current_lng,
            current_location,
            route_points,
            last_gps_at,
            last_update
        ) VALUES (
            :walk_id,
            'On The Way',
            :current_lat,
            :current_lng,
            :current_location,
            :route_points,
            :last_gps_at,
            :last_update
        )
    ");

    $insert->execute([
        ':walk_id' => $walkId,
        ':current_lat' => $lat,
        ':current_lng' => $lng,
        ':current_location' => $locationText,
        ':route_points' => $routeJson,
        ':last_gps_at' => $lastGpsAt,
        ':last_update' => 'Live GPS updated from walker device.'
    ]);
}

echo json_encode([
    'ok' => true,
    'message' => 'GPS updated',
    'lat' => $lat,
    'lng' => $lng,
    'last_gps_at' => $lastGpsAt
]);