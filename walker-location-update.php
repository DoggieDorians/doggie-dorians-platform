<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['walker_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Walker not logged in.'
    ]);
    exit;
}

$walkerId = (int) $_SESSION['walker_id'];

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request body.'
    ]);
    exit;
}

$walkId = isset($input['walk_id']) ? (int) $input['walk_id'] : 0;
$latitude = isset($input['latitude']) ? (float) $input['latitude'] : null;
$longitude = isset($input['longitude']) ? (float) $input['longitude'] : null;
$accuracy = isset($input['accuracy']) ? (float) $input['accuracy'] : null;
$speed = isset($input['speed']) && $input['speed'] !== null ? (float) $input['speed'] : null;
$heading = isset($input['heading']) && $input['heading'] !== null ? (float) $input['heading'] : null;

if ($walkId <= 0 || $latitude === null || $longitude === null) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Missing required GPS fields.'
    ]);
    exit;
}

try {
    $db = new PDO('sqlite:' . __DIR__ . '/data/members.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $checkStmt = $db->prepare("
        SELECT id, walker_id, status
        FROM walks
        WHERE id = :walk_id
        LIMIT 1
    ");
    $checkStmt->execute([':walk_id' => $walkId]);
    $walk = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$walk) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Walk not found.'
        ]);
        exit;
    }

    if ((int)$walk['walker_id'] !== $walkerId) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'You are not assigned to this walk.'
        ]);
        exit;
    }

    $insertStmt = $db->prepare("
        INSERT INTO walk_tracking (
            walk_id,
            walker_id,
            latitude,
            longitude,
            accuracy,
            speed,
            heading
        ) VALUES (
            :walk_id,
            :walker_id,
            :latitude,
            :longitude,
            :accuracy,
            :speed,
            :heading
        )
    ");

    $insertStmt->execute([
        ':walk_id' => $walkId,
        ':walker_id' => $walkerId,
        ':latitude' => $latitude,
        ':longitude' => $longitude,
        ':accuracy' => $accuracy,
        ':speed' => $speed,
        ':heading' => $heading
    ]);

    $updateWalkStatus = $db->prepare("
        UPDATE walks
        SET status = 'In Progress'
        WHERE id = :walk_id
          AND (status = 'Assigned' OR status = 'Requested' OR status = 'Accepted')
    ");
    $updateWalkStatus->execute([':walk_id' => $walkId]);

    echo json_encode([
        'success' => true,
        'message' => 'Location saved.'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error.',
        'error' => $e->getMessage()
    ]);
}