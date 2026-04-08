<?php
declare(strict_types=1);

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$allowedOrigins = [
    'capacitor://localhost',
    'http://localhost',
    'https://localhost',
    'https://dorianspetcare.com',
];

if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'None',
]);

session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id']) || (int) $_SESSION['user_id'] <= 0) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Not logged in',
    ]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON payload',
    ]);
    exit;
}

$service = trim((string) ($data['service'] ?? ''));
$date = trim((string) ($data['date'] ?? ''));
$time = trim((string) ($data['time'] ?? ''));
$notes = trim((string) ($data['notes'] ?? ''));

if ($service === '' || $date === '' || $time === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Service, date, and time are required',
    ]);
    exit;
}

$userId = (int) $_SESSION['user_id'];

try {
    $petStmt = $pdo->prepare("
        SELECT id
        FROM pets
        WHERE user_id = :user_id
        ORDER BY id ASC
        LIMIT 1
    ");
    $petStmt->execute([
        ':user_id' => $userId,
    ]);

    $petId = (int) $petStmt->fetchColumn();

    if ($petId <= 0) {
        throw new RuntimeException('No pet found for this user');
    }

    $stmt = $pdo->prepare("
        INSERT INTO bookings (
            user_id,
            pet_id,
            service_type,
            service_date,
            service_time,
            duration_minutes,
            status,
            client_notes,
            price,
            is_instant_booking,
            pricing_type,
            unit_price,
            discount_label,
            quantity
        ) VALUES (
            :user_id,
            :pet_id,
            :service_type,
            :service_date,
            :service_time,
            :duration_minutes,
            :status,
            :client_notes,
            :price,
            :is_instant_booking,
            :pricing_type,
            :unit_price,
            :discount_label,
            :quantity
        )
    ");

    $stmt->execute([
        ':user_id' => $userId,
        ':pet_id' => $petId,
        ':service_type' => $service,
        ':service_date' => $date,
        ':service_time' => $time,
        ':duration_minutes' => 30,
        ':status' => 'pending',
        ':client_notes' => $notes,
        ':price' => 0,
        ':is_instant_booking' => 0,
        ':pricing_type' => 'member',
        ':unit_price' => 0,
        ':discount_label' => 'standard_member',
        ':quantity' => 1,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Booking request submitted successfully',
    ]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
    exit;
}