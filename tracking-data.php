<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'Unauthorized.',
    ]);
    exit;
}

function respond(array $data, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1");
    $stmt->execute([':table' => $table]);
    return (bool) $stmt->fetchColumn();
}

function getTableColumns(PDO $pdo, string $table): array
{
    if (!tableExists($pdo, $table)) {
        return [];
    }

    $columns = [];
    $stmt = $pdo->query("PRAGMA table_info($table)");
    if ($stmt) {
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!empty($row['name'])) {
                $columns[] = (string) $row['name'];
            }
        }
    }

    return $columns;
}

function hasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function firstExistingColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (hasColumn($columns, $candidate)) {
            return $candidate;
        }
    }
    return null;
}

function firstExistingPair(array $columns, array $pairs): ?array
{
    foreach ($pairs as $pair) {
        if (count($pair) !== 2) {
            continue;
        }

        [$lat, $lng] = $pair;

        if (hasColumn($columns, $lat) && hasColumn($columns, $lng)) {
            return [$lat, $lng];
        }
    }

    return null;
}

function normalizeStatus(?string $status): string
{
    $status = strtolower(trim((string) $status));
    $status = str_replace(['_', '-'], ' ', $status);
    $status = preg_replace('/\s+/', ' ', $status) ?? $status;

    return match ($status) {
        'on the way' => 'Walker On The Way',
        'arrived' => 'Walker Arrived',
        'active', 'in progress' => 'Walk In Progress',
        'complete', 'completed' => 'Completed',
        'cancelled', 'canceled' => 'Cancelled',
        'pending' => 'Pending',
        default => $status !== '' ? ucwords($status) : 'Awaiting GPS',
    };
}

function numericOrNull(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    if (!is_numeric($value)) {
        return null;
    }

    return (float) $value;
}

function intOrNull(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    if (!is_numeric($value)) {
        return null;
    }

    return (int) $value;
}

function parseDurationFromService(?string $serviceType): int
{
    $serviceType = strtolower(trim((string) $serviceType));

    if ($serviceType === '') {
        return 30;
    }

    if (preg_match('/\b(15|20|30|45|60)\b/', $serviceType, $matches)) {
        return (int) $matches[1];
    }

    if (str_contains($serviceType, '15 minute')) {
        return 15;
    }
    if (str_contains($serviceType, '20 minute')) {
        return 20;
    }
    if (str_contains($serviceType, '30 minute')) {
        return 30;
    }
    if (str_contains($serviceType, '45 minute')) {
        return 45;
    }
    if (str_contains($serviceType, '60 minute')) {
        return 60;
    }

    return 30;
}

function parseSqlDateTime(?string $value): ?DateTimeImmutable
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($value);
    } catch (Throwable) {
        return null;
    }
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userEmail = strtolower(trim((string) ($_SESSION['email'] ?? '')));

$walkId = isset($_GET['walk_id']) ? (int) $_GET['walk_id'] : 0;
$bookingId = isset($_GET['booking_id']) ? (int) $_GET['booking_id'] : 0;

if ($walkId <= 0 && $bookingId <= 0) {
    respond([
        'ok' => false,
        'error' => 'Missing walk_id or booking_id.',
    ], 400);
}

try {
    $bookingsExists = tableExists($pdo, 'bookings');
    $walksExists = tableExists($pdo, 'walks');
    $walkSessionsExists = tableExists($pdo, 'walk_sessions');
    $walkerLocationsExists = tableExists($pdo, 'walker_locations');
    $walkersExists = tableExists($pdo, 'walkers');
    $petsExists = tableExists($pdo, 'pets');

    $bookingColumns = $bookingsExists ? getTableColumns($pdo, 'bookings') : [];
    $walkColumns = $walksExists ? getTableColumns($pdo, 'walks') : [];
    $walkSessionColumns = $walkSessionsExists ? getTableColumns($pdo, 'walk_sessions') : [];
    $walkerLocationColumns = $walkerLocationsExists ? getTableColumns($pdo, 'walker_locations') : [];
    $walkerColumns = $walkersExists ? getTableColumns($pdo, 'walkers') : [];
    $petColumns = $petsExists ? getTableColumns($pdo, 'pets') : [];

    if (!$bookingsExists && !$walksExists) {
        respond([
            'ok' => false,
            'error' => 'Tracking tables are missing.',
        ], 500);
    }

    $booking = null;
    $walk = null;
    $resolvedWalkId = 0;
    $resolvedBookingId = 0;
    $walkerName = '';
    $status = 'Awaiting GPS';
    $petName = '';
    $serviceType = '';
    $serviceDate = '';
    $serviceTime = '';
    $durationMinutes = 30;
    $startedAt = null;
    $completedAt = null;

    /**
     * Step 1: Resolve booking ownership if booking_id provided.
     */
    if ($bookingId > 0 && $bookingsExists) {
        $bookingUserCol = firstExistingColumn($bookingColumns, ['user_id', 'member_id']);
        $bookingEmailCol = firstExistingColumn($bookingColumns, ['email', 'customer_email']);
        $bookingStatusCol = firstExistingColumn($bookingColumns, ['status']);
        $serviceTypeCol = firstExistingColumn($bookingColumns, ['service_type', 'booking_type', 'service', 'type']);
        $serviceDateCol = firstExistingColumn($bookingColumns, ['service_date', 'booking_date', 'date']);
        $serviceTimeCol = firstExistingColumn($bookingColumns, ['service_time', 'booking_time', 'time']);
        $durationCol = firstExistingColumn($bookingColumns, ['duration_minutes', 'duration']);
        $petIdCol = firstExistingColumn($bookingColumns, ['pet_id']);
        $petNameCol = firstExistingColumn($bookingColumns, ['pet_name', 'dog_name', 'name']);

        $selectParts = ['b.*'];
        $joins = '';

        if ($petIdCol && $petsExists && hasColumn($petColumns, 'id')) {
            $joins .= ' LEFT JOIN pets p ON p.id = b.' . $petIdCol . ' ';
            if (hasColumn($petColumns, 'name')) {
                $selectParts[] = 'p.name AS joined_pet_name';
            }
        }

        $whereParts = ['b.id = :booking_id'];
        $params = [':booking_id' => $bookingId];

        if ($bookingUserCol) {
            $whereParts[] = "b.$bookingUserCol = :user_id";
            $params[':user_id'] = $userId;
        }

        if ($bookingEmailCol && $userEmail !== '') {
            $whereParts[] = "LOWER(b.$bookingEmailCol) = :email";
            $params[':email'] = $userEmail;
        }

        $sql = "
            SELECT " . implode(', ', $selectParts) . "
            FROM bookings b
            $joins
            WHERE (" . implode(' OR ', $whereParts) . ")
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$booking) {
            respond([
                'ok' => false,
                'error' => 'Booking not found for this account.',
            ], 404);
        }

        $resolvedBookingId = (int) ($booking['id'] ?? 0);

        if ($bookingStatusCol && !empty($booking[$bookingStatusCol])) {
            $status = (string) $booking[$bookingStatusCol];
        }
        if ($serviceTypeCol && !empty($booking[$serviceTypeCol])) {
            $serviceType = (string) $booking[$serviceTypeCol];
        }
        if ($serviceDateCol && !empty($booking[$serviceDateCol])) {
            $serviceDate = (string) $booking[$serviceDateCol];
        }
        if ($serviceTimeCol && !empty($booking[$serviceTimeCol])) {
            $serviceTime = (string) $booking[$serviceTimeCol];
        }

        $petName = (string) ($booking['joined_pet_name'] ?? ($petNameCol && !empty($booking[$petNameCol]) ? $booking[$petNameCol] : ''));

        if ($durationCol && !empty($booking[$durationCol]) && is_numeric($booking[$durationCol])) {
            $durationMinutes = max(1, (int) $booking[$durationCol]);
        } else {
            $durationMinutes = parseDurationFromService($serviceType);
        }
    }

    /**
     * Step 2: Resolve walk record.
     */
    if ($walksExists) {
        $selectParts = ['w.*'];
        $joins = '';

        if ($walkersExists && hasColumn($walkColumns, 'walker_id') && hasColumn($walkerColumns, 'id')) {
            if (hasColumn($walkerColumns, 'name')) {
                $selectParts[] = 'wk.name AS walker_name';
            } elseif (hasColumn($walkerColumns, 'full_name')) {
                $selectParts[] = 'wk.full_name AS walker_name';
            }
            $joins = ' LEFT JOIN walkers wk ON wk.id = w.walker_id ';
        }

        if ($walkId > 0) {
            $walkUserCol = firstExistingColumn($walkColumns, ['user_id', 'member_id']);
            $walkBookingCol = firstExistingColumn($walkColumns, ['booking_id']);

            $whereParts = ['w.id = :walk_id'];
            $params = [':walk_id' => $walkId];

            if ($walkUserCol) {
                $whereParts[] = "w.$walkUserCol = :user_id";
                $params[':user_id'] = $userId;
            }

            if ($resolvedBookingId > 0 && $walkBookingCol) {
                $whereParts[] = "w.$walkBookingCol = :booking_id";
                $params[':booking_id'] = $resolvedBookingId;
            }

            $sql = "
                SELECT " . implode(', ', $selectParts) . "
                FROM walks w
                $joins
                WHERE (" . implode(' OR ', $whereParts) . ")
                ORDER BY w.id DESC
                LIMIT 1
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $walk = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if (!$walk && $resolvedBookingId > 0 && hasColumn($walkColumns, 'booking_id')) {
            $sql = "
                SELECT " . implode(', ', $selectParts) . "
                FROM walks w
                $joins
                WHERE w.booking_id = :booking_id
                ORDER BY w.id DESC
                LIMIT 1
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([':booking_id' => $resolvedBookingId]);
            $walk = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if (!$walk && $booking && hasColumn($walkColumns, 'user_id')) {
            $walkDateCol = firstExistingColumn($walkColumns, ['walk_date', 'service_date', 'date']);
            $bookingDateCol = firstExistingColumn($bookingColumns, ['service_date', 'booking_date', 'date']);

            if ($walkDateCol && $bookingDateCol && !empty($booking[$bookingDateCol])) {
                $sql = "
                    SELECT " . implode(', ', $selectParts) . "
                    FROM walks w
                    $joins
                    WHERE w.user_id = :user_id
                      AND w.$walkDateCol = :walk_date
                    ORDER BY w.id DESC
                    LIMIT 1
                ";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':user_id' => $userId,
                    ':walk_date' => (string) $booking[$bookingDateCol],
                ]);
                $walk = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }

        if ($walk) {
            $resolvedWalkId = (int) ($walk['id'] ?? 0);

            if ($resolvedBookingId <= 0 && isset($walk['booking_id']) && (int) $walk['booking_id'] > 0) {
                $resolvedBookingId = (int) $walk['booking_id'];
            }

            if (!empty($walk['walker_name'])) {
                $walkerName = (string) $walk['walker_name'];
            }

            if (!empty($walk['status'])) {
                $status = (string) $walk['status'];
            } elseif (!empty($walk['walk_status'])) {
                $status = (string) $walk['walk_status'];
            }

            $startedAtCol = firstExistingColumn($walkColumns, ['started_at']);
            $completedAtCol = firstExistingColumn($walkColumns, ['completed_at']);
            $walkDurationCol = firstExistingColumn($walkColumns, ['duration_minutes', 'duration']);
            $walkDateCol = firstExistingColumn($walkColumns, ['walk_date', 'service_date', 'date']);
            $walkTimeCol = firstExistingColumn($walkColumns, ['walk_time', 'service_time', 'time', 'start_time']);

            if ($startedAtCol && !empty($walk[$startedAtCol])) {
                $startedAt = (string) $walk[$startedAtCol];
            }

            if ($completedAtCol && !empty($walk[$completedAtCol])) {
                $completedAt = (string) $walk[$completedAtCol];
            }

            if ($walkDurationCol && !empty($walk[$walkDurationCol]) && is_numeric($walk[$walkDurationCol])) {
                $durationMinutes = max(1, (int) $walk[$walkDurationCol]);
            }

            if ($serviceDate === '' && $walkDateCol && !empty($walk[$walkDateCol])) {
                $serviceDate = (string) $walk[$walkDateCol];
            }

            if ($serviceTime === '' && $walkTimeCol && !empty($walk[$walkTimeCol])) {
                $serviceTime = (string) $walk[$walkTimeCol];
            }
        }
    }

    if ($walkId > 0 && !$walk) {
        respond([
            'ok' => false,
            'error' => 'Walk not found for this account.',
        ], 404);
    }

    /**
     * Step 3: GPS resolution priority
     * 1) walk_sessions latest point
     * 2) walker_locations latest point
     * 3) direct columns on walks
     */
    $latitude = null;
    $longitude = null;
    $updatedAt = null;
    $accuracy = null;

    if ($resolvedWalkId > 0 && $walkSessionsExists) {
        $latLngPair = firstExistingPair($walkSessionColumns, [
            ['latitude', 'longitude'],
            ['lat', 'lng'],
            ['current_latitude', 'current_longitude'],
        ]);

        $walkRefCol = firstExistingColumn($walkSessionColumns, ['walk_id']);
        $updatedCol = firstExistingColumn($walkSessionColumns, ['updated_at', 'created_at', 'recorded_at', 'timestamp']);
        $accuracyCol = firstExistingColumn($walkSessionColumns, ['accuracy', 'accuracy_meters']);

        if ($latLngPair && $walkRefCol) {
            [$latCol, $lngCol] = $latLngPair;
            $orderCol = $updatedCol ?: 'id';

            $selectParts = [
                "$latCol AS latitude",
                "$lngCol AS longitude",
                $updatedCol ? "$updatedCol AS updated_at" : "NULL AS updated_at",
                $accuracyCol ? "$accuracyCol AS accuracy" : "NULL AS accuracy",
            ];

            $sql = "
                SELECT " . implode(', ', $selectParts) . "
                FROM walk_sessions
                WHERE $walkRefCol = :walk_id
                ORDER BY $orderCol DESC, id DESC
                LIMIT 1
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([':walk_id' => $resolvedWalkId]);
            $gps = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($gps) {
                $latitude = numericOrNull($gps['latitude'] ?? null);
                $longitude = numericOrNull($gps['longitude'] ?? null);
                $updatedAt = $gps['updated_at'] ?? null;
                $accuracy = numericOrNull($gps['accuracy'] ?? null);
            }
        }
    }

    if (($latitude === null || $longitude === null) && $resolvedWalkId > 0 && $walkerLocationsExists) {
        $latLngPair = firstExistingPair($walkerLocationColumns, [
            ['latitude', 'longitude'],
            ['lat', 'lng'],
            ['current_latitude', 'current_longitude'],
        ]);

        $walkRefCol = firstExistingColumn($walkerLocationColumns, ['walk_id']);
        $walkerRefCol = firstExistingColumn($walkerLocationColumns, ['walker_id']);
        $updatedCol = firstExistingColumn($walkerLocationColumns, ['updated_at', 'created_at', 'recorded_at', 'timestamp']);
        $accuracyCol = firstExistingColumn($walkerLocationColumns, ['accuracy', 'accuracy_meters']);

        if ($latLngPair) {
            [$latCol, $lngCol] = $latLngPair;
            $orderCol = $updatedCol ?: 'id';
            $selectParts = [
                "$latCol AS latitude",
                "$lngCol AS longitude",
                $updatedCol ? "$updatedCol AS updated_at" : "NULL AS updated_at",
                $accuracyCol ? "$accuracyCol AS accuracy" : "NULL AS accuracy",
            ];

            $gps = null;

            if ($walkRefCol) {
                $sql = "
                    SELECT " . implode(', ', $selectParts) . "
                    FROM walker_locations
                    WHERE $walkRefCol = :walk_id
                    ORDER BY $orderCol DESC, id DESC
                    LIMIT 1
                ";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([':walk_id' => $resolvedWalkId]);
                $gps = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            if (
                !$gps &&
                $walkerRefCol &&
                $walk &&
                isset($walk['walker_id']) &&
                (int) $walk['walker_id'] > 0
            ) {
                $sql = "
                    SELECT " . implode(', ', $selectParts) . "
                    FROM walker_locations
                    WHERE $walkerRefCol = :walker_id
                    ORDER BY $orderCol DESC, id DESC
                    LIMIT 1
                ";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([':walker_id' => (int) $walk['walker_id']]);
                $gps = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            if ($gps) {
                $latitude = numericOrNull($gps['latitude'] ?? null);
                $longitude = numericOrNull($gps['longitude'] ?? null);
                $updatedAt = $gps['updated_at'] ?? null;
                $accuracy = numericOrNull($gps['accuracy'] ?? null);
            }
        }
    }

    if (($latitude === null || $longitude === null) && $walk) {
        $latLngPair = firstExistingPair($walkColumns, [
            ['latitude', 'longitude'],
            ['lat', 'lng'],
            ['current_latitude', 'current_longitude'],
            ['last_latitude', 'last_longitude'],
        ]);

        $updatedCol = firstExistingColumn($walkColumns, ['updated_at', 'last_updated', 'gps_updated_at', 'created_at']);
        $accuracyCol = firstExistingColumn($walkColumns, ['accuracy', 'accuracy_meters']);

        if ($latLngPair) {
            [$latCol, $lngCol] = $latLngPair;

            $latitude = numericOrNull($walk[$latCol] ?? null);
            $longitude = numericOrNull($walk[$lngCol] ?? null);
            $updatedAt = $updatedCol ? ($walk[$updatedCol] ?? null) : null;
            $accuracy = $accuracyCol ? numericOrNull($walk[$accuracyCol] ?? null) : null;
        }
    }

    /**
     * Step 4: Timer calculation
     */
    $timerStarted = false;
    $timerCompleted = false;
    $elapsedSeconds = 0;
    $remainingSeconds = max(60, $durationMinutes * 60);
    $targetEndAt = null;

    $startedDate = parseSqlDateTime($startedAt);
    $completedDate = parseSqlDateTime($completedAt);
    $now = new DateTimeImmutable('now');

    if ($startedDate) {
        $timerStarted = true;

        $endBase = $completedDate ?: $now;
        $elapsedSeconds = max(0, $endBase->getTimestamp() - $startedDate->getTimestamp());
        $remainingSeconds = max(0, ($durationMinutes * 60) - $elapsedSeconds);
        $targetEndAt = $startedDate->modify('+' . $durationMinutes . ' minutes')->format('Y-m-d H:i:s');
    }

    if ($completedDate) {
        $timerCompleted = true;
    }

    $normalizedStatus = normalizeStatus($status);

    respond([
        'ok' => true,
        'walk_id' => $resolvedWalkId > 0 ? $resolvedWalkId : null,
        'booking_id' => $resolvedBookingId > 0 ? $resolvedBookingId : null,
        'walker_name' => $walkerName !== '' ? $walkerName : null,
        'pet_name' => $petName !== '' ? $petName : null,
        'service_type' => $serviceType !== '' ? $serviceType : null,
        'service_date' => $serviceDate !== '' ? $serviceDate : null,
        'service_time' => $serviceTime !== '' ? $serviceTime : null,
        'status' => $normalizedStatus,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'accuracy' => $accuracy,
        'updated_at' => $updatedAt,
        'has_live_gps' => ($latitude !== null && $longitude !== null),

        'duration_minutes' => $durationMinutes,
        'started_at' => $startedAt,
        'completed_at' => $completedAt,
        'timer_started' => $timerStarted,
        'timer_completed' => $timerCompleted,
        'elapsed_seconds' => $elapsedSeconds,
        'remaining_seconds' => $remainingSeconds,
        'target_end_at' => $targetEndAt,
    ]);
} catch (Throwable $e) {
    respond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}