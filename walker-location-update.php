<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

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

function normalizeIncomingStatus(?string $status): string
{
    $status = strtolower(trim((string) $status));
    $status = str_replace(['_', '-'], ' ', $status);
    $status = preg_replace('/\s+/', ' ', $status) ?? $status;

    return match ($status) {
        'on the way', 'en route' => 'on the way',
        'arrived' => 'arrived',
        'active', 'in progress', 'started', 'walking' => 'in progress',
        'complete', 'completed', 'finished' => 'completed',
        'cancelled', 'canceled' => 'cancelled',
        'pending' => 'pending',
        default => $status !== '' ? $status : 'in progress',
    };
}

function requestValue(string $key, mixed $default = null): mixed
{
    if (array_key_exists($key, $_POST)) {
        return $_POST[$key];
    }

    if (array_key_exists($key, $_GET)) {
        return $_GET[$key];
    }

    static $json = null;
    if ($json === null) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw ?: '', true);
        $json = is_array($decoded) ? $decoded : [];
    }

    return $json[$key] ?? $default;
}

/**
 * Session / auth resolution
 */
$sessionRole = strtolower((string) ($_SESSION['role'] ?? ''));
$isAdmin = ($sessionRole === 'admin');

$walkerId = 0;
if (isset($_SESSION['walker_id']) && is_numeric($_SESSION['walker_id'])) {
    $walkerId = (int) $_SESSION['walker_id'];
} elseif ($sessionRole === 'walker' && isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
    $walkerId = (int) $_SESSION['user_id'];
}

if (!$isAdmin && $walkerId <= 0) {
    respond([
        'ok' => false,
        'error' => 'Unauthorized.',
    ], 401);
}

$walkId = (int) requestValue('walk_id', 0);
$bookingId = (int) requestValue('booking_id', 0);
$latitude = numericOrNull(requestValue('latitude'));
$longitude = numericOrNull(requestValue('longitude'));
$accuracy = numericOrNull(requestValue('accuracy'));
$status = normalizeIncomingStatus((string) requestValue('status', 'in progress'));

if ($latitude === null || $longitude === null) {
    respond([
        'ok' => false,
        'error' => 'Latitude and longitude are required.',
    ], 400);
}

if ($latitude < -90 || $latitude > 90) {
    respond([
        'ok' => false,
        'error' => 'Latitude is out of range.',
    ], 400);
}

if ($longitude < -180 || $longitude > 180) {
    respond([
        'ok' => false,
        'error' => 'Longitude is out of range.',
    ], 400);
}

try {
    $walksExists = tableExists($pdo, 'walks');
    $walkersExists = tableExists($pdo, 'walkers');
    $bookingsExists = tableExists($pdo, 'bookings');
    $walkerLocationsExists = tableExists($pdo, 'walker_locations');
    $walkSessionsExists = tableExists($pdo, 'walk_sessions');

    if (!$walksExists) {
        respond([
            'ok' => false,
            'error' => 'Walks table not found.',
        ], 500);
    }

    $walkColumns = getTableColumns($pdo, 'walks');
    $bookingColumns = $bookingsExists ? getTableColumns($pdo, 'bookings') : [];
    $walkerColumns = $walkersExists ? getTableColumns($pdo, 'walkers') : [];
    $walkerLocationColumns = $walkerLocationsExists ? getTableColumns($pdo, 'walker_locations') : [];
    $walkSessionColumns = $walkSessionsExists ? getTableColumns($pdo, 'walk_sessions') : [];

    $resolvedWalk = null;
    $resolvedWalkId = 0;
    $resolvedBookingId = 0;
    $resolvedWalkerId = $walkerId;

    $selectParts = ['w.*'];
    $joins = '';

    if ($walkersExists && hasColumn($walkColumns, 'walker_id') && hasColumn($walkerColumns, 'id')) {
        if (hasColumn($walkerColumns, 'name')) {
            $selectParts[] = 'wk.name AS walker_name';
        } elseif (hasColumn($walkerColumns, 'full_name')) {
            $selectParts[] = 'wk.full_name AS walker_name';
        }
        $joins .= ' LEFT JOIN walkers wk ON wk.id = w.walker_id ';
    }

    /**
     * 1) Resolve exact walk_id first
     */
    if ($walkId > 0) {
        $whereParts = ['w.id = :walk_id'];
        $params = [':walk_id' => $walkId];

        if (!$isAdmin && hasColumn($walkColumns, 'walker_id')) {
            $whereParts[] = 'w.walker_id = :walker_id';
            $params[':walker_id'] = $walkerId;
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
        $resolvedWalk = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * 2) Resolve by booking_id
     */
    if (!$resolvedWalk && $bookingId > 0 && hasColumn($walkColumns, 'booking_id')) {
        $whereParts = ['w.booking_id = :booking_id'];
        $params = [':booking_id' => $bookingId];

        if (!$isAdmin && hasColumn($walkColumns, 'walker_id')) {
            $whereParts[] = 'w.walker_id = :walker_id';
            $params[':walker_id'] = $walkerId;
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
        $resolvedWalk = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * 3) Resolve current assigned walk for this walker
     */
    if (
        !$resolvedWalk &&
        !$isAdmin &&
        $walkerId > 0 &&
        hasColumn($walkColumns, 'walker_id')
    ) {
        $statusCol = firstExistingColumn($walkColumns, ['status', 'walk_status']);
        $dateCol = firstExistingColumn($walkColumns, ['walk_date', 'service_date', 'date']);
        $timeCol = firstExistingColumn($walkColumns, ['walk_time', 'service_time', 'time', 'start_time']);

        $statusFilter = '';
        if ($statusCol) {
            $statusFilter = "
                AND LOWER(COALESCE(w.$statusCol, '')) NOT IN ('completed', 'complete', 'cancelled', 'canceled')
            ";
        }

        $orderParts = [];
        if ($dateCol) {
            $orderParts[] = "w.$dateCol DESC";
        }
        if ($timeCol) {
            $orderParts[] = "w.$timeCol DESC";
        }
        $orderParts[] = 'w.id DESC';

        $sql = "
            SELECT " . implode(', ', $selectParts) . "
            FROM walks w
            $joins
            WHERE w.walker_id = :walker_id
            $statusFilter
            ORDER BY " . implode(', ', $orderParts) . "
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':walker_id' => $walkerId]);
        $resolvedWalk = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$resolvedWalk) {
        respond([
            'ok' => false,
            'error' => 'No matching walk found for this update.',
        ], 404);
    }

    $resolvedWalkId = (int) ($resolvedWalk['id'] ?? 0);

    if ($resolvedWalkId <= 0) {
        respond([
            'ok' => false,
            'error' => 'Resolved walk is invalid.',
        ], 500);
    }

    if (isset($resolvedWalk['booking_id']) && is_numeric($resolvedWalk['booking_id'])) {
        $resolvedBookingId = (int) $resolvedWalk['booking_id'];
    } elseif ($bookingId > 0) {
        $resolvedBookingId = $bookingId;
    }

    if (isset($resolvedWalk['walker_id']) && is_numeric($resolvedWalk['walker_id']) && (int) $resolvedWalk['walker_id'] > 0) {
        $resolvedWalkerId = (int) $resolvedWalk['walker_id'];
    }

    $now = date('Y-m-d H:i:s');

    $pdo->beginTransaction();

    /**
     * Save into walker_locations if available
     */
    if ($walkerLocationsExists) {
        $insertCols = [];
        $insertVals = [];
        $params = [];

        if (hasColumn($walkerLocationColumns, 'walker_id') && $resolvedWalkerId > 0) {
            $insertCols[] = 'walker_id';
            $insertVals[] = ':walker_id';
            $params[':walker_id'] = $resolvedWalkerId;
        }

        if (hasColumn($walkerLocationColumns, 'walk_id')) {
            $insertCols[] = 'walk_id';
            $insertVals[] = ':walk_id';
            $params[':walk_id'] = $resolvedWalkId;
        }

        if (hasColumn($walkerLocationColumns, 'booking_id') && $resolvedBookingId > 0) {
            $insertCols[] = 'booking_id';
            $insertVals[] = ':booking_id';
            $params[':booking_id'] = $resolvedBookingId;
        }

        if (hasColumn($walkerLocationColumns, 'latitude')) {
            $insertCols[] = 'latitude';
            $insertVals[] = ':latitude';
            $params[':latitude'] = $latitude;
        } elseif (hasColumn($walkerLocationColumns, 'lat')) {
            $insertCols[] = 'lat';
            $insertVals[] = ':latitude';
            $params[':latitude'] = $latitude;
        }

        if (hasColumn($walkerLocationColumns, 'longitude')) {
            $insertCols[] = 'longitude';
            $insertVals[] = ':longitude';
            $params[':longitude'] = $longitude;
        } elseif (hasColumn($walkerLocationColumns, 'lng')) {
            $insertCols[] = 'lng';
            $insertVals[] = ':longitude';
            $params[':longitude'] = $longitude;
        }

        if (hasColumn($walkerLocationColumns, 'accuracy') && $accuracy !== null) {
            $insertCols[] = 'accuracy';
            $insertVals[] = ':accuracy';
            $params[':accuracy'] = $accuracy;
        } elseif (hasColumn($walkerLocationColumns, 'accuracy_meters') && $accuracy !== null) {
            $insertCols[] = 'accuracy_meters';
            $insertVals[] = ':accuracy';
            $params[':accuracy'] = $accuracy;
        }

        if (hasColumn($walkerLocationColumns, 'status')) {
            $insertCols[] = 'status';
            $insertVals[] = ':status';
            $params[':status'] = $status;
        } elseif (hasColumn($walkerLocationColumns, 'walk_status')) {
            $insertCols[] = 'walk_status';
            $insertVals[] = ':status';
            $params[':status'] = $status;
        }

        if (hasColumn($walkerLocationColumns, 'updated_at')) {
            $insertCols[] = 'updated_at';
            $insertVals[] = ':updated_at';
            $params[':updated_at'] = $now;
        } elseif (hasColumn($walkerLocationColumns, 'created_at')) {
            $insertCols[] = 'created_at';
            $insertVals[] = ':created_at';
            $params[':created_at'] = $now;
        } elseif (hasColumn($walkerLocationColumns, 'timestamp')) {
            $insertCols[] = 'timestamp';
            $insertVals[] = ':timestamp';
            $params[':timestamp'] = $now;
        } elseif (hasColumn($walkerLocationColumns, 'recorded_at')) {
            $insertCols[] = 'recorded_at';
            $insertVals[] = ':recorded_at';
            $params[':recorded_at'] = $now;
        }

        if ($insertCols) {
            $sql = "
                INSERT INTO walker_locations (" . implode(', ', $insertCols) . ")
                VALUES (" . implode(', ', $insertVals) . ")
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }
    }

    /**
     * Save into walk_sessions if available
     */
    if ($walkSessionsExists) {
        $insertCols = [];
        $insertVals = [];
        $params = [];

        if (hasColumn($walkSessionColumns, 'walk_id')) {
            $insertCols[] = 'walk_id';
            $insertVals[] = ':walk_id';
            $params[':walk_id'] = $resolvedWalkId;
        }

        if (hasColumn($walkSessionColumns, 'walker_id') && $resolvedWalkerId > 0) {
            $insertCols[] = 'walker_id';
            $insertVals[] = ':walker_id';
            $params[':walker_id'] = $resolvedWalkerId;
        }

        if (hasColumn($walkSessionColumns, 'booking_id') && $resolvedBookingId > 0) {
            $insertCols[] = 'booking_id';
            $insertVals[] = ':booking_id';
            $params[':booking_id'] = $resolvedBookingId;
        }

        if (hasColumn($walkSessionColumns, 'latitude')) {
            $insertCols[] = 'latitude';
            $insertVals[] = ':latitude';
            $params[':latitude'] = $latitude;
        } elseif (hasColumn($walkSessionColumns, 'lat')) {
            $insertCols[] = 'lat';
            $insertVals[] = ':latitude';
            $params[':latitude'] = $latitude;
        } elseif (hasColumn($walkSessionColumns, 'current_latitude')) {
            $insertCols[] = 'current_latitude';
            $insertVals[] = ':latitude';
            $params[':latitude'] = $latitude;
        }

        if (hasColumn($walkSessionColumns, 'longitude')) {
            $insertCols[] = 'longitude';
            $insertVals[] = ':longitude';
            $params[':longitude'] = $longitude;
        } elseif (hasColumn($walkSessionColumns, 'lng')) {
            $insertCols[] = 'lng';
            $insertVals[] = ':longitude';
            $params[':longitude'] = $longitude;
        } elseif (hasColumn($walkSessionColumns, 'current_longitude')) {
            $insertCols[] = 'current_longitude';
            $insertVals[] = ':longitude';
            $params[':longitude'] = $longitude;
        }

        if (hasColumn($walkSessionColumns, 'accuracy') && $accuracy !== null) {
            $insertCols[] = 'accuracy';
            $insertVals[] = ':accuracy';
            $params[':accuracy'] = $accuracy;
        } elseif (hasColumn($walkSessionColumns, 'accuracy_meters') && $accuracy !== null) {
            $insertCols[] = 'accuracy_meters';
            $insertVals[] = ':accuracy';
            $params[':accuracy'] = $accuracy;
        }

        if (hasColumn($walkSessionColumns, 'status')) {
            $insertCols[] = 'status';
            $insertVals[] = ':status';
            $params[':status'] = $status;
        } elseif (hasColumn($walkSessionColumns, 'walk_status')) {
            $insertCols[] = 'walk_status';
            $insertVals[] = ':status';
            $params[':status'] = $status;
        }

        if (hasColumn($walkSessionColumns, 'updated_at')) {
            $insertCols[] = 'updated_at';
            $insertVals[] = ':updated_at';
            $params[':updated_at'] = $now;
        } elseif (hasColumn($walkSessionColumns, 'created_at')) {
            $insertCols[] = 'created_at';
            $insertVals[] = ':created_at';
            $params[':created_at'] = $now;
        } elseif (hasColumn($walkSessionColumns, 'timestamp')) {
            $insertCols[] = 'timestamp';
            $insertVals[] = ':timestamp';
            $params[':timestamp'] = $now;
        } elseif (hasColumn($walkSessionColumns, 'recorded_at')) {
            $insertCols[] = 'recorded_at';
            $insertVals[] = ':recorded_at';
            $params[':recorded_at'] = $now;
        }

        if ($insertCols) {
            $sql = "
                INSERT INTO walk_sessions (" . implode(', ', $insertCols) . ")
                VALUES (" . implode(', ', $insertVals) . ")
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }
    }

    /**
     * Update current GPS/status on walks
     */
    $updateParts = [];
    $params = [':id' => $resolvedWalkId];

    if (hasColumn($walkColumns, 'walker_id') && $resolvedWalkerId > 0 && empty($resolvedWalk['walker_id'])) {
        $updateParts[] = 'walker_id = :walker_id';
        $params[':walker_id'] = $resolvedWalkerId;
    }

    if (hasColumn($walkColumns, 'booking_id') && $resolvedBookingId > 0 && empty($resolvedWalk['booking_id'])) {
        $updateParts[] = 'booking_id = :booking_id';
        $params[':booking_id'] = $resolvedBookingId;
    }

    if (hasColumn($walkColumns, 'latitude')) {
        $updateParts[] = 'latitude = :latitude';
        $params[':latitude'] = $latitude;
    } elseif (hasColumn($walkColumns, 'lat')) {
        $updateParts[] = 'lat = :latitude';
        $params[':latitude'] = $latitude;
    } elseif (hasColumn($walkColumns, 'current_latitude')) {
        $updateParts[] = 'current_latitude = :latitude';
        $params[':latitude'] = $latitude;
    } elseif (hasColumn($walkColumns, 'last_latitude')) {
        $updateParts[] = 'last_latitude = :latitude';
        $params[':latitude'] = $latitude;
    }

    if (hasColumn($walkColumns, 'longitude')) {
        $updateParts[] = 'longitude = :longitude';
        $params[':longitude'] = $longitude;
    } elseif (hasColumn($walkColumns, 'lng')) {
        $updateParts[] = 'lng = :longitude';
        $params[':longitude'] = $longitude;
    } elseif (hasColumn($walkColumns, 'current_longitude')) {
        $updateParts[] = 'current_longitude = :longitude';
        $params[':longitude'] = $longitude;
    } elseif (hasColumn($walkColumns, 'last_longitude')) {
        $updateParts[] = 'last_longitude = :longitude';
        $params[':longitude'] = $longitude;
    }

    if (hasColumn($walkColumns, 'accuracy') && $accuracy !== null) {
        $updateParts[] = 'accuracy = :accuracy';
        $params[':accuracy'] = $accuracy;
    } elseif (hasColumn($walkColumns, 'accuracy_meters') && $accuracy !== null) {
        $updateParts[] = 'accuracy_meters = :accuracy';
        $params[':accuracy'] = $accuracy;
    }

    if (hasColumn($walkColumns, 'status')) {
        $updateParts[] = 'status = :status';
        $params[':status'] = $status;
    } elseif (hasColumn($walkColumns, 'walk_status')) {
        $updateParts[] = 'walk_status = :status';
        $params[':status'] = $status;
    }

    if (hasColumn($walkColumns, 'started_at') && $status === 'in progress' && empty($resolvedWalk['started_at'])) {
        $updateParts[] = 'started_at = :started_at';
        $params[':started_at'] = $now;
    }

    if (hasColumn($walkColumns, 'completed_at') && $status === 'completed') {
        $updateParts[] = 'completed_at = :completed_at';
        $params[':completed_at'] = $now;
    }

    if (hasColumn($walkColumns, 'updated_at')) {
        $updateParts[] = 'updated_at = :updated_at';
        $params[':updated_at'] = $now;
    } elseif (hasColumn($walkColumns, 'last_updated')) {
        $updateParts[] = 'last_updated = :updated_at';
        $params[':updated_at'] = $now;
    } elseif (hasColumn($walkColumns, 'gps_updated_at')) {
        $updateParts[] = 'gps_updated_at = :updated_at';
        $params[':updated_at'] = $now;
    }

    if ($updateParts) {
        $sql = "
            UPDATE walks
            SET " . implode(', ', $updateParts) . "
            WHERE id = :id
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Optional: mirror status into bookings
     */
    if ($bookingsExists && $resolvedBookingId > 0) {
        $bookingStatusCol = firstExistingColumn($bookingColumns, ['status']);
        if ($bookingStatusCol) {
            $stmt = $pdo->prepare("
                UPDATE bookings
                SET $bookingStatusCol = :status
                WHERE id = :booking_id
            ");
            $stmt->execute([
                ':status' => $status,
                ':booking_id' => $resolvedBookingId,
            ]);
        }
    }

    $pdo->commit();

    respond([
        'ok' => true,
        'message' => 'Location updated.',
        'walk_id' => $resolvedWalkId,
        'booking_id' => $resolvedBookingId > 0 ? $resolvedBookingId : null,
        'walker_id' => $resolvedWalkerId > 0 ? $resolvedWalkerId : null,
        'status' => $status,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'accuracy' => $accuracy,
        'updated_at' => $now,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}