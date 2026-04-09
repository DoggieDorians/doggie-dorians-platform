<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/pricing.php';

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

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection is not available.',
    ]);
    exit;
}

function api_json_error(string $message, int $statusCode): never
{
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'message' => $message,
    ]);
    exit;
}

function api_json_success(array $payload = []): never
{
    echo json_encode(array_merge([
        'success' => true,
    ], $payload));
    exit;
}

function normalize_service_type(string $value): string
{
    $value = strtolower(trim($value));

    return match ($value) {
        'walk', 'walks' => 'walk',
        'daycare', 'day care' => 'daycare',
        'boarding', 'board' => 'boarding',
        default => '',
    };
}

function table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :name LIMIT 1");
        $stmt->execute([':name' => $table]);
        return $cache[$table] = (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        return $cache[$table] = false;
    }
}

function get_table_columns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!table_exists($pdo, $table)) {
        return $cache[$table] = [];
    }

    try {
        $safeTable = str_replace('"', '""', $table);
        $stmt = $pdo->query('PRAGMA table_info("' . $safeTable . '")');
        $columns = [];

        if ($stmt) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($row['name'])) {
                    $columns[] = (string) $row['name'];
                }
            }
        }

        return $cache[$table] = $columns;
    } catch (Throwable) {
        return $cache[$table] = [];
    }
}

function first_existing_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function booking_table(PDO $pdo): ?string
{
    foreach (['bookings', 'walks'] as $candidate) {
        if (table_exists($pdo, $candidate)) {
            return $candidate;
        }
    }

    return null;
}

function booking_table_map(PDO $pdo, string $table): array
{
    $columns = get_table_columns($pdo, $table);

    return [
        'user_id' => first_existing_column($columns, ['user_id', 'member_id', 'client_id']),
        'pet_id' => first_existing_column($columns, ['pet_id', 'dog_id']),
        'service_type' => first_existing_column($columns, ['service_type', 'type', 'booking_type', 'service']),
        'service_date' => first_existing_column($columns, ['service_date', 'booking_date', 'walk_date', 'date', 'scheduled_date', 'start_date']),
        'service_time' => first_existing_column($columns, ['service_time', 'booking_time', 'walk_time', 'time', 'scheduled_time', 'start_time']),
        'duration_minutes' => first_existing_column($columns, ['duration_minutes', 'duration', 'minutes']),
        'status' => first_existing_column($columns, ['status', 'booking_status', 'walk_status']),
        'notes' => first_existing_column($columns, ['client_notes', 'notes', 'special_instructions', 'instructions']),
        'price' => first_existing_column($columns, ['price', 'total_price', 'amount']),
        'is_instant_booking' => first_existing_column($columns, ['is_instant_booking']),
        'pricing_type' => first_existing_column($columns, ['pricing_type']),
        'unit_price' => first_existing_column($columns, ['unit_price']),
        'discount_label' => first_existing_column($columns, ['discount_label']),
        'quantity' => first_existing_column($columns, ['quantity']),
        'created_at' => first_existing_column($columns, ['created_at']),
        'updated_at' => first_existing_column($columns, ['updated_at']),
        'status_updated_by' => first_existing_column($columns, ['status_updated_by']),
        'status_updated_at' => first_existing_column($columns, ['status_updated_at']),
    ];
}

function get_first_pet_for_user(PDO $pdo, int $userId): int
{
    foreach (['pets', 'dogs'] as $table) {
        if (!table_exists($pdo, $table)) {
            continue;
        }

        $columns = get_table_columns($pdo, $table);
        $idCol = first_existing_column($columns, ['id', 'pet_id', 'dog_id']);
        $ownerCol = first_existing_column($columns, ['user_id', 'member_id', 'owner_id', 'client_id']);

        if ($idCol === null || $ownerCol === null) {
            continue;
        }

        $stmt = $pdo->prepare("
            SELECT {$idCol}
            FROM {$table}
            WHERE {$ownerCol} = :user_id
            ORDER BY {$idCol} ASC
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId]);

        $petId = (int) $stmt->fetchColumn();
        if ($petId > 0) {
            return $petId;
        }
    }

    return 0;
}

$userId = 0;
foreach (['member_id', 'user_id', 'id'] as $key) {
    if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) {
        $userId = (int) $_SESSION[$key];
        break;
    }
}

if ($userId <= 0) {
    api_json_error('Not logged in', 401);
}

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    api_json_error('Invalid JSON payload', 400);
}

$service = normalize_service_type((string) ($data['service'] ?? ''));
$date = trim((string) ($data['date'] ?? ''));
$time = trim((string) ($data['time'] ?? ''));
$notes = trim((string) ($data['notes'] ?? ''));
$durationMinutes = (int) ($data['duration_minutes'] ?? 30);

if ($service === '' || $date === '' || $time === '') {
    api_json_error('Service, date, and time are required', 422);
}

if ($service === 'walk' && !in_array($durationMinutes, [15, 20, 30, 45, 60], true)) {
    api_json_error('Invalid walk duration', 422);
}

if ($service === 'daycare') {
    $durationMinutes = (int) (dd_pricing_matrix()['daycare']['member']['hours'] ?? 6) * 60;
}

if ($service === 'boarding') {
    $durationMinutes = 1440;
}

try {
    $petId = get_first_pet_for_user($pdo, $userId);

    if ($petId <= 0) {
        throw new RuntimeException('No pet found for this user');
    }

    $pricing = match ($service) {
        'walk' => dd_get_walk_pricing($durationMinutes, true),
        'daycare' => dd_get_daycare_pricing(true, false, 0),
        'boarding' => [
            'pricing_type' => 'member',
            'unit_price' => 0.00,
            'total_price' => 0.00,
            'discount_label' => 'standard_member',
            'quantity' => 1,
        ],
        default => throw new RuntimeException('Invalid service type'),
    };

    $table = booking_table($pdo);

    if ($table === null) {
        throw new RuntimeException('No booking table found');
    }

    $map = booking_table_map($pdo, $table);
    $insertData = [];

    if ($map['user_id'] !== null) {
        $insertData[$map['user_id']] = $userId;
    }
    if ($map['pet_id'] !== null) {
        $insertData[$map['pet_id']] = $petId;
    }
    if ($map['service_type'] !== null) {
        $insertData[$map['service_type']] = $service;
    }
    if ($map['service_date'] !== null) {
        $insertData[$map['service_date']] = $date;
    }
    if ($map['service_time'] !== null) {
        $insertData[$map['service_time']] = $time;
    }
    if ($map['duration_minutes'] !== null) {
        $insertData[$map['duration_minutes']] = $durationMinutes;
    }
    if ($map['status'] !== null) {
        $insertData[$map['status']] = 'pending';
    }
    if ($map['notes'] !== null) {
        $insertData[$map['notes']] = $notes;
    }
    if ($map['price'] !== null) {
        $insertData[$map['price']] = (float) ($pricing['total_price'] ?? 0);
    }
    if ($map['is_instant_booking'] !== null) {
        $insertData[$map['is_instant_booking']] = 0;
    }
    if ($map['pricing_type'] !== null) {
        $insertData[$map['pricing_type']] = (string) ($pricing['pricing_type'] ?? 'member');
    }
    if ($map['unit_price'] !== null) {
        $insertData[$map['unit_price']] = (float) ($pricing['unit_price'] ?? 0);
    }
    if ($map['discount_label'] !== null) {
        $insertData[$map['discount_label']] = (string) ($pricing['discount_label'] ?? 'standard_member');
    }
    if ($map['quantity'] !== null) {
        $insertData[$map['quantity']] = (int) ($pricing['quantity'] ?? 1);
    }
    if ($map['created_at'] !== null) {
        $insertData[$map['created_at']] = date('Y-m-d H:i:s');
    }
    if ($map['updated_at'] !== null) {
        $insertData[$map['updated_at']] = date('Y-m-d H:i:s');
    }
    if ($map['status_updated_by'] !== null) {
        $insertData[$map['status_updated_by']] = 'member';
    }
    if ($map['status_updated_at'] !== null) {
        $insertData[$map['status_updated_at']] = date('Y-m-d H:i:s');
    }

    if (empty($insertData)) {
        throw new RuntimeException('Could not map booking fields safely');
    }

    $fields = array_keys($insertData);
    $placeholders = array_map(static fn(string $field): string => ':' . $field, $fields);
    $params = [];

    foreach ($insertData as $field => $value) {
        $params[':' . $field] = $value;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO ' . $table . ' (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')'
    );
    $stmt->execute($params);

    api_json_success([
        'message' => 'Booking request submitted successfully',
    ]);
} catch (Throwable $e) {
    api_json_error($e->getMessage(), 500);
}