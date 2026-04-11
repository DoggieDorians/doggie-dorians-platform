<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security-headers.php';

session_start();
require_once __DIR__ . '/db.php';

/**
 * Doggie Dorian's
 * live-tracking.php
 *
 * Full replacement
 * Premium schema-tolerant live walk tracking + timer page
 */

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection is not available.';
    exit;
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirectTo(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function currentUserRole(): string
{
    $role = (string) ($_SESSION['role'] ?? '');
    if ($role !== '') {
        return strtolower($role);
    }

    if (!empty($_SESSION['is_admin'])) {
        return 'admin';
    }

    if (!empty($_SESSION['walker_id'])) {
        return 'walker';
    }

    if (!empty($_SESSION['staff_id'])) {
        return 'staff';
    }

    if (!empty($_SESSION['employee_id'])) {
        return 'employee';
    }

    return 'member';
}

function isAdmin(): bool
{
    return currentUserRole() === 'admin';
}

function isWorker(): bool
{
    $role = currentUserRole();
    return in_array($role, ['walker', 'staff', 'employee'], true) || currentWorkerId() > 0;
}

function currentWorkerId(): int
{
    foreach (['walker_id', 'staff_id', 'employee_id', 'worker_id', 'user_id'] as $key) {
        if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) {
            return (int) $_SESSION[$key];
        }
    }
    return 0;
}

if (!isWorker() && !isAdmin()) {
    redirectTo('login.php');
}

function hasTable(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :name LIMIT 1");
        $stmt->execute([':name' => $table]);
        $cache[$table] = (bool) $stmt->fetchColumn();
        return $cache[$table];
    } catch (Throwable) {
        $cache[$table] = false;
        return false;
    }
}

function getTableColumns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    if (!hasTable($pdo, $table)) {
        $cache[$table] = [];
        return [];
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

        $cache[$table] = $columns;
        return $columns;
    } catch (Throwable) {
        $cache[$table] = [];
        return [];
    }
}

function hasColumn(PDO $pdo, string $table, string $column): bool
{
    return in_array($column, getTableColumns($pdo, $table), true);
}

function firstExistingColumn(PDO $pdo, string $table, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (hasColumn($pdo, $table, $candidate)) {
            return $candidate;
        }
    }
    return null;
}

function valueFromRow(array $row, array $candidates, mixed $default = null): mixed
{
    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $row) && $row[$candidate] !== null && $row[$candidate] !== '') {
            return $row[$candidate];
        }
    }
    return $default;
}

function safeExecute(PDOStatement $stmt, array $params = []): bool
{
    try {
        return $stmt->execute($params);
    } catch (Throwable) {
        return false;
    }
}

function normalizeStatus(?string $status): string
{
    $status = strtolower(trim((string) $status));

    return match ($status) {
        'new', 'open', 'unassigned' => 'available',
        'assigned', 'confirmed' => 'accepted',
        'active', 'walking', 'started', 'in progress' => 'in_progress',
        'done', 'finished', 'closed' => 'completed',
        'canceled', 'cancelled by client', 'cancelled by walker', 'void' => 'cancelled',
        'released back', 're-opened', 'reopened' => 'released',
        default => $status !== '' ? $status : 'pending',
    };
}

function normalizeServiceType(?string $type): string
{
    $type = strtolower(trim((string) $type));

    if ($type === '') {
        return 'service';
    }
    if (str_contains($type, 'walk')) {
        return 'walk';
    }
    if (str_contains($type, 'board')) {
        return 'boarding';
    }
    if (str_contains($type, 'daycare') || str_contains($type, 'day care')) {
        return 'daycare';
    }
    if (str_contains($type, 'sit')) {
        return 'sitting';
    }
    if (str_contains($type, 'drop')) {
        return 'drop-in';
    }

    return $type;
}

function bookingBaseTable(PDO $pdo): ?string
{
    foreach (['bookings', 'walks'] as $candidate) {
        if (hasTable($pdo, $candidate)) {
            return $candidate;
        }
    }
    return null;
}

function updateRowColumns(PDO $pdo, string $table, string $idCol, int $id, array $changes): bool
{
    if ($changes === []) {
        return true;
    }

    $sets = [];
    $params = [':id' => $id];

    foreach ($changes as $column => $value) {
        if (!hasColumn($pdo, $table, $column)) {
            continue;
        }
        $param = ':c_' . $column;
        $sets[] = "{$column} = {$param}";
        $params[$param] = $value;
    }

    if ($sets === []) {
        return true;
    }

    $sql = "UPDATE {$table} SET " . implode(', ', $sets) . " WHERE {$idCol} = :id";
    $stmt = $pdo->prepare($sql);
    return safeExecute($stmt, $params);
}

function loadPetNameById(PDO $pdo, int $petId): string
{
    if ($petId <= 0) {
        return '';
    }

    foreach (['pets', 'dogs'] as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $idCol = firstExistingColumn($pdo, $table, ['id', 'pet_id', 'dog_id']);
        $nameCol = firstExistingColumn($pdo, $table, ['name', 'pet_name', 'dog_name']);
        if ($idCol === null || $nameCol === null) {
            continue;
        }

        $stmt = $pdo->prepare("SELECT {$nameCol} FROM {$table} WHERE {$idCol} = :id LIMIT 1");
        if (!safeExecute($stmt, [':id' => $petId])) {
            continue;
        }

        $name = $stmt->fetchColumn();
        if ($name !== false && trim((string) $name) !== '') {
            return (string) $name;
        }
    }

    return '';
}

function loadClientName(PDO $pdo, int $userId): string
{
    if ($userId <= 0) {
        return '';
    }

    foreach (['users', 'members', 'client_profiles'] as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $idCol = firstExistingColumn($pdo, $table, ['id', 'user_id', 'member_id', 'client_id']);
        $nameCol = firstExistingColumn($pdo, $table, ['full_name', 'name', 'client_name', 'member_name']);
        if ($idCol === null || $nameCol === null) {
            continue;
        }

        $stmt = $pdo->prepare("SELECT {$nameCol} FROM {$table} WHERE {$idCol} = :id LIMIT 1");
        if (!safeExecute($stmt, [':id' => $userId])) {
            continue;
        }

        $name = $stmt->fetchColumn();
        if ($name !== false && trim((string) $name) !== '') {
            return (string) $name;
        }
    }

    return '';
}

function writeNotification(PDO $pdo, array $booking, string $message, string $target = 'user'): void
{
    if (!hasTable($pdo, 'notifications')) {
        return;
    }

    $columns = getTableColumns($pdo, 'notifications');
    if ($columns === []) {
        return;
    }

    $data = [];

    if (in_array('user_id', $columns, true)) {
        $data['user_id'] = $target === 'worker' ? (int) ($booking['worker_id'] ?? 0) : (int) ($booking['user_id'] ?? 0);
    }

    if (in_array('member_id', $columns, true) && $target !== 'worker') {
        $data['member_id'] = (int) ($booking['user_id'] ?? 0);
    }

    if (in_array('walker_id', $columns, true) && $target === 'worker') {
        $data['walker_id'] = (int) ($booking['worker_id'] ?? 0);
    }

    if (in_array('booking_id', $columns, true)) {
        $data['booking_id'] = (int) ($booking['id'] ?? 0);
    }

    if (in_array('title', $columns, true)) {
        $data['title'] = 'Walk Tracking Update';
    }

    if (in_array('message', $columns, true)) {
        $data['message'] = $message;
    } elseif (in_array('content', $columns, true)) {
        $data['content'] = $message;
    } elseif (in_array('body', $columns, true)) {
        $data['body'] = $message;
    }

    if (in_array('type', $columns, true)) {
        $data['type'] = 'tracking';
    }

    if (in_array('is_read', $columns, true)) {
        $data['is_read'] = 0;
    }

    if (in_array('created_at', $columns, true)) {
        $data['created_at'] = date('Y-m-d H:i:s');
    }

    if ($data === []) {
        return;
    }

    $fields = array_keys($data);
    $placeholders = array_map(static fn(string $key): string => ':' . $key, $fields);

    $params = [];
    foreach ($data as $key => $value) {
        $params[':' . $key] = $value;
    }

    $sql = 'INSERT INTO notifications (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    safeExecute($stmt, $params);
}

function getWalkSessionTable(PDO $pdo): ?string
{
    foreach (['walk_sessions', 'tracking_sessions'] as $candidate) {
        if (hasTable($pdo, $candidate)) {
            return $candidate;
        }
    }
    return null;
}

function getTrackingPointTable(PDO $pdo): ?string
{
    foreach (['tracking_points', 'walk_points', 'location_updates', 'gps_points', 'walk_tracking'] as $candidate) {
        if (hasTable($pdo, $candidate)) {
            return $candidate;
        }
    }
    return null;
}

function findBooking(PDO $pdo, int $bookingId): ?array
{
    $table = bookingBaseTable($pdo);
    if ($table === null) {
        return null;
    }

    $idCol = firstExistingColumn($pdo, $table, ['id', 'booking_id', 'walk_id']);
    if ($idCol === null) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE {$idCol} = :id LIMIT 1");
    if (!safeExecute($stmt, [':id' => $bookingId])) {
        return null;
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $petId = (int) valueFromRow($row, ['pet_id', 'dog_id'], 0);
    $userId = (int) valueFromRow($row, ['user_id', 'member_id', 'client_id', 'owner_id'], 0);
    $workerId = (int) valueFromRow($row, ['walker_id', 'staff_id', 'employee_id', 'worker_id', 'assigned_to', 'assigned_worker_id'], 0);

    return [
        '_table' => $table,
        '_id_col' => $idCol,
        '_raw' => $row,
        'id' => (int) valueFromRow($row, [$idCol], $bookingId),
        'user_id' => $userId,
        'worker_id' => $workerId,
        'pet_name' => (string) valueFromRow($row, ['pet_name', 'dog_name', 'name'], $petId > 0 ? loadPetNameById($pdo, $petId) : ''),
        'client_name' => (string) valueFromRow($row, ['client_name', 'owner_name', 'member_name', 'customer_name'], $userId > 0 ? loadClientName($pdo, $userId) : ''),
        'service_type' => normalizeServiceType((string) valueFromRow($row, ['service_type', 'type', 'booking_type', 'category'], 'service')),
        'status' => normalizeStatus((string) valueFromRow($row, ['status', 'booking_status', 'service_status', 'walk_status'], 'pending')),
        'service_date' => (string) valueFromRow($row, ['service_date', 'booking_date', 'walk_date', 'date', 'start_date', 'scheduled_date'], ''),
        'service_time' => (string) valueFromRow($row, ['service_time', 'booking_time', 'walk_time', 'time', 'start_time', 'scheduled_time'], ''),
        'duration' => (string) valueFromRow($row, ['duration_minutes', 'duration', 'minutes'], ''),
        'notes' => (string) valueFromRow($row, ['notes', 'special_instructions', 'instructions', 'care_notes'], ''),
    ];
}

function findOrCreateTrackingSession(PDO $pdo, array $booking): ?array
{
    $sessionTable = getWalkSessionTable($pdo);
    if ($sessionTable === null) {
        return null;
    }

    $sessionIdCol = firstExistingColumn($pdo, $sessionTable, ['id']);
    $bookingIdCol = firstExistingColumn($pdo, $sessionTable, ['booking_id', 'walk_id']);
    if ($sessionIdCol === null || $bookingIdCol === null) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM {$sessionTable} WHERE {$bookingIdCol} = :booking_id ORDER BY {$sessionIdCol} DESC LIMIT 1");
    if (!safeExecute($stmt, [':booking_id' => (int) $booking['id']])) {
        return null;
    }

    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        return $existing;
    }

    $columns = getTableColumns($pdo, $sessionTable);
    $data = [];

    if (in_array($bookingIdCol, $columns, true)) {
        $data[$bookingIdCol] = (int) $booking['id'];
    }
    if (in_array('walker_id', $columns, true)) {
        $data['walker_id'] = (int) $booking['worker_id'];
    }
    if (in_array('status', $columns, true)) {
        $data['status'] = 'accepted';
    }
    if (in_array('started_at', $columns, true)) {
        $data['started_at'] = null;
    }
    if (in_array('created_at', $columns, true)) {
        $data['created_at'] = date('Y-m-d H:i:s');
    }
    if (in_array('updated_at', $columns, true)) {
        $data['updated_at'] = date('Y-m-d H:i:s');
    }

    if ($data === []) {
        return null;
    }

    $fields = array_keys($data);
    $placeholders = array_map(static fn(string $key): string => ':' . $key, $fields);
    $params = [];

    foreach ($data as $key => $value) {
        $params[':' . $key] = $value;
    }

    $stmt = $pdo->prepare('INSERT INTO ' . $sessionTable . ' (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')');
    if (!safeExecute($stmt, $params)) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM {$sessionTable} WHERE {$bookingIdCol} = :booking_id ORDER BY {$sessionIdCol} DESC LIMIT 1");
    if (!safeExecute($stmt, [':booking_id' => (int) $booking['id']])) {
        return null;
    }

    $created = $stmt->fetch(PDO::FETCH_ASSOC);
    return $created ?: null;
}

function loadTrackingSession(PDO $pdo, array $booking): ?array
{
    $sessionTable = getWalkSessionTable($pdo);
    if ($sessionTable === null) {
        return null;
    }

    $sessionIdCol = firstExistingColumn($pdo, $sessionTable, ['id']);
    $bookingIdCol = firstExistingColumn($pdo, $sessionTable, ['booking_id', 'walk_id']);
    if ($sessionIdCol === null || $bookingIdCol === null) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM {$sessionTable} WHERE {$bookingIdCol} = :booking_id ORDER BY {$sessionIdCol} DESC LIMIT 1");
    if (!safeExecute($stmt, [':booking_id' => (int) $booking['id']])) {
        return null;
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function updateBookingForWalkStart(PDO $pdo, array $booking): void
{
    $table = (string) $booking['_table'];
    $idCol = (string) $booking['_id_col'];

    $statusCol = firstExistingColumn($pdo, $table, ['status', 'booking_status', 'service_status', 'walk_status']);
    $startedAtCol = firstExistingColumn($pdo, $table, ['started_at', 'walk_started_at', 'service_started_at']);
    $updatedAtCol = firstExistingColumn($pdo, $table, ['updated_at', 'modified_at']);

    $changes = [];
    if ($statusCol !== null) {
        $changes[$statusCol] = 'in_progress';
    }
    if ($startedAtCol !== null) {
        $changes[$startedAtCol] = date('Y-m-d H:i:s');
    }
    if ($updatedAtCol !== null) {
        $changes[$updatedAtCol] = date('Y-m-d H:i:s');
    }

    updateRowColumns($pdo, $table, $idCol, (int) $booking['id'], $changes);
}

function updateBookingForWalkComplete(PDO $pdo, array $booking): void
{
    $table = (string) $booking['_table'];
    $idCol = (string) $booking['_id_col'];

    $statusCol = firstExistingColumn($pdo, $table, ['status', 'booking_status', 'service_status', 'walk_status']);
    $completedAtCol = firstExistingColumn($pdo, $table, ['completed_at', 'ended_at', 'walk_completed_at', 'service_completed_at']);
    $updatedAtCol = firstExistingColumn($pdo, $table, ['updated_at', 'modified_at']);

    $changes = [];
    if ($statusCol !== null) {
        $changes[$statusCol] = 'completed';
    }
    if ($completedAtCol !== null) {
        $changes[$completedAtCol] = date('Y-m-d H:i:s');
    }
    if ($updatedAtCol !== null) {
        $changes[$updatedAtCol] = date('Y-m-d H:i:s');
    }

    updateRowColumns($pdo, $table, $idCol, (int) $booking['id'], $changes);
}

function startTrackingSession(PDO $pdo, array $booking): array
{
    $sessionTable = getWalkSessionTable($pdo);
    if ($sessionTable === null) {
        return ['ok' => false, 'message' => 'No walk session table is available.'];
    }

    $session = findOrCreateTrackingSession($pdo, $booking);
    if (!$session) {
        return ['ok' => false, 'message' => 'Could not create or load the tracking session.'];
    }

    $sessionIdCol = firstExistingColumn($pdo, $sessionTable, ['id']);
    if ($sessionIdCol === null) {
        return ['ok' => false, 'message' => 'Session ID column is missing.'];
    }

    $changes = [];
    if (hasColumn($pdo, $sessionTable, 'status')) {
        $changes['status'] = 'in_progress';
    }
    if (hasColumn($pdo, $sessionTable, 'started_at') && empty($session['started_at'])) {
        $changes['started_at'] = date('Y-m-d H:i:s');
    }
    if (hasColumn($pdo, $sessionTable, 'walker_id') && (int) valueFromRow($session, ['walker_id'], 0) <= 0) {
        $changes['walker_id'] = (int) $booking['worker_id'];
    }
    if (hasColumn($pdo, $sessionTable, 'updated_at')) {
        $changes['updated_at'] = date('Y-m-d H:i:s');
    }

    if (!updateRowColumns($pdo, $sessionTable, $sessionIdCol, (int) $session[$sessionIdCol], $changes)) {
        return ['ok' => false, 'message' => 'Failed to start the tracking session.'];
    }

    updateBookingForWalkStart($pdo, $booking);
    writeNotification($pdo, $booking, 'Your walk has started and live tracking is now active.', 'user');

    return ['ok' => true, 'message' => 'Walk started successfully.'];
}

function completeTrackingSession(PDO $pdo, array $booking): array
{
    $sessionTable = getWalkSessionTable($pdo);
    if ($sessionTable === null) {
        return ['ok' => false, 'message' => 'No walk session table is available.'];
    }

    $session = findOrCreateTrackingSession($pdo, $booking);
    if (!$session) {
        return ['ok' => false, 'message' => 'Could not load the tracking session.'];
    }

    $sessionIdCol = firstExistingColumn($pdo, $sessionTable, ['id']);
    if ($sessionIdCol === null) {
        return ['ok' => false, 'message' => 'Session ID column is missing.'];
    }

    $changes = [];
    if (hasColumn($pdo, $sessionTable, 'status')) {
        $changes['status'] = 'completed';
    }
    if (hasColumn($pdo, $sessionTable, 'ended_at')) {
        $changes['ended_at'] = date('Y-m-d H:i:s');
    }
    if (hasColumn($pdo, $sessionTable, 'updated_at')) {
        $changes['updated_at'] = date('Y-m-d H:i:s');
    }

    if (!updateRowColumns($pdo, $sessionTable, $sessionIdCol, (int) $session[$sessionIdCol], $changes)) {
        return ['ok' => false, 'message' => 'Failed to complete the tracking session.'];
    }

    updateBookingForWalkComplete($pdo, $booking);
    writeNotification($pdo, $booking, 'Your walk has been completed.', 'user');

    return ['ok' => true, 'message' => 'Walk completed successfully.'];
}

function writeTrackingPoint(PDO $pdo, array $booking, float $lat, float $lng, ?float $accuracy = null): void
{
    $pointTable = getTrackingPointTable($pdo);
    if ($pointTable === null) {
        return;
    }

    $columns = getTableColumns($pdo, $pointTable);
    if ($columns === []) {
        return;
    }

    $data = [];
    $bookingIdCol = firstExistingColumn($pdo, $pointTable, ['booking_id', 'walk_id']);
    $sessionIdCol = firstExistingColumn($pdo, $pointTable, ['session_id', 'walk_session_id', 'tracking_session_id']);
    $latCol = firstExistingColumn($pdo, $pointTable, ['latitude', 'lat']);
    $lngCol = firstExistingColumn($pdo, $pointTable, ['longitude', 'lng', 'lon']);
    $accuracyCol = firstExistingColumn($pdo, $pointTable, ['accuracy', 'gps_accuracy']);
    $createdAtCol = firstExistingColumn($pdo, $pointTable, ['created_at', 'recorded_at', 'timestamp']);

    if ($bookingIdCol !== null) {
        $data[$bookingIdCol] = (int) $booking['id'];
    }

    $session = loadTrackingSession($pdo, $booking);
    if ($session && $sessionIdCol !== null) {
        $sourceSessionIdCol = firstExistingColumn($pdo, getWalkSessionTable($pdo) ?? '', ['id']);
        if ($sourceSessionIdCol !== null && isset($session[$sourceSessionIdCol])) {
            $data[$sessionIdCol] = (int) $session[$sourceSessionIdCol];
        }
    }

    if ($latCol !== null) {
        $data[$latCol] = $lat;
    }
    if ($lngCol !== null) {
        $data[$lngCol] = $lng;
    }
    if ($accuracyCol !== null) {
        $data[$accuracyCol] = $accuracy;
    }
    if ($createdAtCol !== null) {
        $data[$createdAtCol] = date('Y-m-d H:i:s');
    }

    if ($data === []) {
        return;
    }

    $fields = array_keys($data);
    $placeholders = array_map(static fn(string $key): string => ':' . $key, $fields);

    $params = [];
    foreach ($data as $key => $value) {
        $params[':' . $key] = $value;
    }

    $stmt = $pdo->prepare('INSERT INTO ' . $pointTable . ' (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')');
    safeExecute($stmt, $params);
}

function updateTrackingSessionLocation(PDO $pdo, array $booking, float $lat, float $lng, ?float $accuracy = null): void
{
    $sessionTable = getWalkSessionTable($pdo);
    if ($sessionTable === null) {
        return;
    }

    $session = findOrCreateTrackingSession($pdo, $booking);
    if (!$session) {
        return;
    }

    $sessionIdCol = firstExistingColumn($pdo, $sessionTable, ['id']);
    if ($sessionIdCol === null) {
        return;
    }

    $changes = [];
    $latCol = firstExistingColumn($pdo, $sessionTable, ['latitude', 'lat', 'current_latitude', 'current_lat']);
    $lngCol = firstExistingColumn($pdo, $sessionTable, ['longitude', 'lng', 'lon', 'current_longitude', 'current_lng']);
    $accuracyCol = firstExistingColumn($pdo, $sessionTable, ['accuracy', 'gps_accuracy']);
    $updatedAtCol = firstExistingColumn($pdo, $sessionTable, ['updated_at', 'last_ping_at', 'last_location_at']);

    if ($latCol !== null) {
        $changes[$latCol] = $lat;
    }
    if ($lngCol !== null) {
        $changes[$lngCol] = $lng;
    }
    if ($accuracyCol !== null) {
        $changes[$accuracyCol] = $accuracy;
    }
    if ($updatedAtCol !== null) {
        $changes[$updatedAtCol] = date('Y-m-d H:i:s');
    }
    if (hasColumn($pdo, $sessionTable, 'status')) {
        $changes['status'] = 'in_progress';
    }
    if (hasColumn($pdo, $sessionTable, 'started_at') && empty($session['started_at'])) {
        $changes['started_at'] = date('Y-m-d H:i:s');
    }

    updateRowColumns($pdo, $sessionTable, $sessionIdCol, (int) $session[$sessionIdCol], $changes);
    writeTrackingPoint($pdo, $booking, $lat, $lng, $accuracy);
}

function getLatestTrackingSnapshot(PDO $pdo, array $booking): array
{
    $sessionTable = getWalkSessionTable($pdo);
    $snapshot = [
        'session_status' => null,
        'started_at' => null,
        'ended_at' => null,
        'latitude' => null,
        'longitude' => null,
        'accuracy' => null,
        'last_ping_at' => null,
    ];

    if ($sessionTable === null) {
        return $snapshot;
    }

    $session = loadTrackingSession($pdo, $booking);
    if (!$session) {
        return $snapshot;
    }

    $snapshot['session_status'] = normalizeStatus((string) valueFromRow($session, ['status', 'walk_status', 'service_status'], ''));
    $snapshot['started_at'] = (string) valueFromRow($session, ['started_at'], '');
    $snapshot['ended_at'] = (string) valueFromRow($session, ['ended_at'], '');
    $snapshot['latitude'] = valueFromRow($session, ['latitude', 'lat', 'current_latitude', 'current_lat'], null);
    $snapshot['longitude'] = valueFromRow($session, ['longitude', 'lng', 'lon', 'current_longitude', 'current_lng'], null);
    $snapshot['accuracy'] = valueFromRow($session, ['accuracy', 'gps_accuracy'], null);
    $snapshot['last_ping_at'] = (string) valueFromRow($session, ['updated_at', 'last_ping_at', 'last_location_at'], '');

    return $snapshot;
}

function formatDisplayDateTime(string $date, string $time): string
{
    $date = trim($date);
    $time = trim($time);

    if ($date === '' && $time === '') {
        return 'Not scheduled';
    }

    if ($date !== '' && $time !== '') {
        $ts = strtotime($date . ' ' . $time);
        return $ts !== false ? date('F j, Y \a\t g:i A', $ts) : ($date . ' ' . $time);
    }

    if ($date !== '') {
        $ts = strtotime($date);
        return $ts !== false ? date('F j, Y', $ts) : $date;
    }

    $ts = strtotime($time);
    return $ts !== false ? date('g:i A', $ts) : $time;
}

$bookingId = isset($_GET['booking_id']) ? (int) $_GET['booking_id'] : (int) ($_POST['booking_id'] ?? 0);
if ($bookingId <= 0) {
    redirectTo('walker-dashboard.php');
}

$booking = findBooking($pdo, $bookingId);
if (!$booking) {
    http_response_code(404);
    echo 'Walk booking not found.';
    exit;
}

if ($booking['service_type'] !== 'walk') {
    http_response_code(400);
    echo 'Live tracking is only available for walk services.';
    exit;
}

$sessionWorkerId = currentWorkerId();
if (!isAdmin() && (int) $booking['worker_id'] > 0 && (int) $booking['worker_id'] !== $sessionWorkerId) {
    http_response_code(403);
    echo 'You do not have permission to access this live tracking session.';
    exit;
}

if (!isAdmin() && (int) $booking['worker_id'] <= 0 && $sessionWorkerId <= 0) {
    http_response_code(403);
    echo 'No worker session is available for this walk.';
    exit;
}

if ((int) $booking['worker_id'] <= 0 && $sessionWorkerId > 0) {
    $workerCol = firstExistingColumn($pdo, $booking['_table'], ['walker_id', 'staff_id', 'employee_id', 'worker_id', 'assigned_to', 'assigned_worker_id']);
    if ($workerCol !== null) {
        updateRowColumns($pdo, $booking['_table'], $booking['_id_col'], (int) $booking['id'], [$workerCol => $sessionWorkerId]);
        $booking = findBooking($pdo, $bookingId) ?? $booking;
    }
}

$apiAction = strtolower(trim((string) ($_GET['api'] ?? $_POST['api'] ?? '')));
if ($apiAction !== '') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $apiAction !== 'status') {
        jsonResponse(['ok' => false, 'message' => 'Invalid request method.'], 405);
    }

    if ($apiAction === 'start') {
        $result = startTrackingSession($pdo, $booking);
        jsonResponse(array_merge($result, [
            'snapshot' => getLatestTrackingSnapshot($pdo, $booking),
            'booking_status' => normalizeStatus((string) (findBooking($pdo, $bookingId)['status'] ?? $booking['status'])),
        ]), $result['ok'] ? 200 : 400);
    }

    if ($apiAction === 'ping') {
        $lat = isset($_POST['latitude']) ? (float) $_POST['latitude'] : 0.0;
        $lng = isset($_POST['longitude']) ? (float) $_POST['longitude'] : 0.0;
        $accuracy = isset($_POST['accuracy']) && $_POST['accuracy'] !== '' ? (float) $_POST['accuracy'] : null;

        if ($lat === 0.0 && $lng === 0.0) {
            jsonResponse(['ok' => false, 'message' => 'Latitude and longitude are required.'], 400);
        }

        updateTrackingSessionLocation($pdo, $booking, $lat, $lng, $accuracy);

        $freshBooking = findBooking($pdo, $bookingId) ?? $booking;
        jsonResponse([
            'ok' => true,
            'message' => 'Location updated.',
            'booking_status' => normalizeStatus((string) $freshBooking['status']),
            'snapshot' => getLatestTrackingSnapshot($pdo, $freshBooking),
        ]);
    }

    if ($apiAction === 'complete') {
        $result = completeTrackingSession($pdo, $booking);
        jsonResponse(array_merge($result, [
            'snapshot' => getLatestTrackingSnapshot($pdo, $booking),
            'booking_status' => normalizeStatus((string) (findBooking($pdo, $bookingId)['status'] ?? $booking['status'])),
        ]), $result['ok'] ? 200 : 400);
    }

    if ($apiAction === 'status') {
        $freshBooking = findBooking($pdo, $bookingId) ?? $booking;
        jsonResponse([
            'ok' => true,
            'booking_status' => normalizeStatus((string) $freshBooking['status']),
            'snapshot' => getLatestTrackingSnapshot($pdo, $freshBooking),
        ]);
    }

    jsonResponse(['ok' => false, 'message' => 'Unknown API action.'], 400);
}

$flash = $_SESSION['live_tracking_flash'] ?? '';
unset($_SESSION['live_tracking_flash']);

$snapshot = getLatestTrackingSnapshot($pdo, $booking);
$bookingStatus = normalizeStatus((string) $booking['status']);
$sessionStatus = normalizeStatus((string) ($snapshot['session_status'] ?? ''));
$isStarted = in_array($sessionStatus, ['in_progress', 'completed'], true) || in_array($bookingStatus, ['in_progress', 'completed'], true);
$isCompleted = in_array($sessionStatus, ['completed'], true) || $bookingStatus === 'completed';

$startTimestamp = '';
if (!empty($snapshot['started_at'])) {
    $ts = strtotime((string) $snapshot['started_at']);
    if ($ts !== false) {
        $startTimestamp = date('c', $ts);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Tracking | Doggie Dorian’s</title>
    <meta name="description" content="Live walk tracking and timer for Doggie Dorian’s staff.">
    <style>
        * { box-sizing: border-box; }

        :root {
            --bg: #09090d;
            --panel: rgba(255,255,255,0.055);
            --panel-2: rgba(255,255,255,0.04);
            --line: rgba(255,255,255,0.08);
            --line-soft: rgba(255,255,255,0.06);
            --text: #f4f1ea;
            --muted: rgba(244,241,234,0.72);
            --muted-2: rgba(244,241,234,0.56);
            --gold-1: #e2c48d;
            --gold-2: #b9975b;
            --blue-1: #7fb5ff;
            --blue-2: #5d90ff;
            --green-1: #9ed19e;
            --green-2: #4d9a63;
            --red-1: #d67b7b;
            --red-2: #a84646;
            --shadow: 0 20px 60px rgba(0,0,0,0.30);
        }

        body {
            margin: 0;
            background:
                radial-gradient(circle at top left, rgba(226,196,141,0.06), transparent 22%),
                linear-gradient(180deg, #09090d 0%, #111118 100%);
            color: var(--text);
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        a { color: inherit; text-decoration: none; }

        .page {
            max-width: 1380px;
            margin: 0 auto;
            padding: 28px 18px 80px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .brand {
            font-size: 1.55rem;
            font-weight: 900;
            letter-spacing: .04em;
        }

        .top-links {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .top-link {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            font-weight: 700;
            transition: .18s ease;
        }

        .top-link:hover {
            background: rgba(255,255,255,0.1);
            transform: translateY(-1px);
        }

        .hero {
            display: grid;
            grid-template-columns: 1.18fr .92fr;
            gap: 20px;
            margin-bottom: 22px;
        }

        .card {
            background: linear-gradient(180deg, rgba(255,255,255,0.065), rgba(255,255,255,0.03));
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 28px;
            padding: 24px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(8px);
        }

        .eyebrow {
            color: #c6b28b;
            text-transform: uppercase;
            letter-spacing: .14em;
            font-size: .75rem;
            font-weight: 800;
            margin-bottom: 10px;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 2.04rem;
            line-height: 1.08;
        }

        .sub {
            color: var(--muted);
            line-height: 1.65;
        }

        .status-pill-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
        }

        .pill {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            font-size: .9rem;
            font-weight: 800;
        }

        .highlight-pill {
            background: rgba(127,181,255,0.12);
            border-color: rgba(127,181,255,0.3);
            color: #dce9ff;
        }

        .timer-grid {
            display: grid;
            grid-template-columns: 1.1fr 1fr 1fr;
            gap: 14px;
            margin-top: 18px;
        }

        .timer-box {
            padding: 18px;
            border-radius: 20px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .timer-label {
            font-size: .75rem;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: rgba(244,241,234,0.58);
            font-weight: 800;
            margin-bottom: 8px;
        }

        .timer-value {
            font-size: 1.8rem;
            font-weight: 900;
        }

        .timer-value.smallish {
            font-size: 1.12rem;
        }

        .grid {
            display: grid;
            grid-template-columns: 1.02fr .98fr;
            gap: 20px;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .detail {
            padding: 16px;
            border-radius: 20px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .detail-label {
            font-size: .75rem;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: rgba(244,241,234,0.58);
            font-weight: 800;
            margin-bottom: 8px;
        }

        .detail-value {
            font-size: 1rem;
            font-weight: 700;
            line-height: 1.55;
        }

        .map-shell {
            margin-top: 16px;
            border-radius: 22px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.08);
            background: linear-gradient(135deg, rgba(127,181,255,0.08), rgba(226,196,141,0.08));
            min-height: 320px;
            position: relative;
        }

        .map-stage {
            position: absolute;
            inset: 0;
            display: grid;
            place-items: center;
            padding: 22px;
            text-align: center;
        }

        .map-title {
            font-size: 1.05rem;
            font-weight: 900;
            margin-bottom: 8px;
        }

        .map-copy {
            color: var(--muted);
            line-height: 1.6;
            max-width: 420px;
        }

        .map-coords {
            margin-top: 16px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: .92rem;
            color: #dfe9ff;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            padding: 10px 12px;
            border-radius: 14px;
        }

        .action-stack {
            display: grid;
            gap: 14px;
        }

        .action-box {
            padding: 18px;
            border-radius: 20px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .action-title {
            font-size: 1rem;
            font-weight: 900;
            margin-bottom: 8px;
        }

        .action-text {
            color: rgba(244,241,234,0.72);
            line-height: 1.6;
            margin-bottom: 14px;
        }

        button {
            border: none;
            cursor: pointer;
            border-radius: 14px;
            padding: 12px 16px;
            font-weight: 800;
            font-size: .95rem;
            transition: .18s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        button:disabled {
            opacity: .55;
            cursor: not-allowed;
            transform: none !important;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--gold-1), var(--gold-2));
            color: #0b0b10;
        }

        .btn-success {
            background: linear-gradient(135deg, var(--green-1), var(--green-2));
            color: #09110a;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--red-1), var(--red-2));
            color: #fff;
        }

        .btn-secondary {
            background: rgba(255,255,255,0.08);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.1);
        }

        .btn-tracking {
            background: linear-gradient(135deg, var(--blue-1), var(--blue-2));
            color: #08111f;
        }

        .btn-primary:hover,
        .btn-success:hover,
        .btn-danger:hover,
        .btn-secondary:hover,
        .btn-tracking:hover {
            transform: translateY(-1px);
        }

        .flash, .notice {
            margin-bottom: 18px;
            padding: 14px 18px;
            border-radius: 16px;
            font-weight: 700;
        }

        .flash {
            background: rgba(198,178,139,0.14);
            border: 1px solid rgba(198,178,139,0.3);
            color: #f3e5c2;
        }

        .notice {
            background: rgba(109,174,255,0.14);
            border: 1px solid rgba(109,174,255,0.3);
            color: #d8e8ff;
        }

        .error {
            background: rgba(214,123,123,0.14);
            border: 1px solid rgba(214,123,123,0.3);
            color: #ffd9d9;
        }

        .success {
            background: rgba(125,206,141,0.14);
            border: 1px solid rgba(125,206,141,0.3);
            color: #dff6e3;
        }

        .muted {
            color: rgba(244,241,234,0.58);
        }

        .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }

        .hidden {
            display: none !important;
        }

        @media (max-width: 1120px) {
            .hero, .grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 760px) {
            .timer-grid, .detail-grid {
                grid-template-columns: 1fr;
            }

            h1 {
                font-size: 1.64rem;
            }

            .page {
                padding: 20px 12px 60px;
            }

            .card {
                padding: 18px;
                border-radius: 22px;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="topbar">
            <div class="brand">Doggie Dorian’s</div>
            <div class="top-links">
                <a class="top-link" href="walker-dashboard.php">Walker Dashboard</a>
                <?php if (isAdmin()): ?>
                    <a class="top-link" href="admin-bookings.php">Admin Bookings</a>
                <?php endif; ?>
                <a class="top-link" href="booking-details.php?id=<?= (int) $booking['id'] ?>">Booking Details</a>
            </div>
        </div>

        <?php if ($flash !== ''): ?>
            <div class="flash"><?= h($flash) ?></div>
        <?php endif; ?>

        <div id="noticeBox" class="notice hidden"></div>

        <section class="hero">
            <div class="card">
                <div class="eyebrow">Live Walk Control</div>
                <h1>Live Tracking #<?= (int) $booking['id'] ?></h1>
                <div class="sub">
                    Control the active walk session, monitor timing and GPS updates, and keep the client-facing tracking flow synced from start to completion.
                </div>

                <div class="status-pill-row">
                    <div class="pill highlight-pill">Booking Status: <span id="bookingStatusLabel"><?= h(ucwords(str_replace('_', ' ', $bookingStatus))) ?></span></div>
                    <div class="pill">Session Status: <span id="sessionStatusLabel"><?= h(ucwords(str_replace('_', ' ', $sessionStatus !== '' ? $sessionStatus : 'not_started'))) ?></span></div>
                    <div class="pill">Client: <?= h($booking['client_name'] !== '' ? $booking['client_name'] : 'Unknown client') ?></div>
                    <div class="pill">Pet: <?= h($booking['pet_name'] !== '' ? $booking['pet_name'] : 'Unknown pet') ?></div>
                </div>

                <div class="timer-grid">
                    <div class="timer-box">
                        <div class="timer-label">Elapsed Time</div>
                        <div class="timer-value mono" id="elapsedTimer">00:00:00</div>
                    </div>

                    <div class="timer-box">
                        <div class="timer-label">Last GPS Ping</div>
                        <div class="timer-value smallish mono" id="lastPingLabel">
                            <?= h($snapshot['last_ping_at'] !== null && $snapshot['last_ping_at'] !== '' ? (string) $snapshot['last_ping_at'] : 'Waiting') ?>
                        </div>
                    </div>

                    <div class="timer-box">
                        <div class="timer-label">GPS Accuracy</div>
                        <div class="timer-value smallish mono" id="accuracyLabel">
                            <?= h($snapshot['accuracy'] !== null && $snapshot['accuracy'] !== '' ? (string) $snapshot['accuracy'] . ' m' : '—') ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Session Snapshot</div>

                <div class="detail-grid">
                    <div class="detail">
                        <div class="detail-label">Scheduled For</div>
                        <div class="detail-value"><?= h(formatDisplayDateTime((string) $booking['service_date'], (string) $booking['service_time'])) ?></div>
                    </div>

                    <div class="detail">
                        <div class="detail-label">Duration</div>
                        <div class="detail-value"><?= h((string) $booking['duration'] !== '' ? (string) $booking['duration'] . ' min' : 'Not specified') ?></div>
                    </div>

                    <div class="detail">
                        <div class="detail-label">Started At</div>
                        <div class="detail-value mono" id="startedAtLabel"><?= h($snapshot['started_at'] !== null && $snapshot['started_at'] !== '' ? (string) $snapshot['started_at'] : 'Not started') ?></div>
                    </div>

                    <div class="detail">
                        <div class="detail-label">Ended At</div>
                        <div class="detail-value mono" id="endedAtLabel"><?= h($snapshot['ended_at'] !== null && $snapshot['ended_at'] !== '' ? (string) $snapshot['ended_at'] : 'Not completed') ?></div>
                    </div>

                    <div class="detail">
                        <div class="detail-label">Latitude</div>
                        <div class="detail-value mono" id="latLabel"><?= h($snapshot['latitude'] !== null ? (string) $snapshot['latitude'] : '—') ?></div>
                    </div>

                    <div class="detail">
                        <div class="detail-label">Longitude</div>
                        <div class="detail-value mono" id="lngLabel"><?= h($snapshot['longitude'] !== null ? (string) $snapshot['longitude'] : '—') ?></div>
                    </div>
                </div>

                <div class="map-shell">
                    <div class="map-stage">
                        <div>
                            <div class="map-title">Live route preview</div>
                            <div class="map-copy">
                                This control page is keeping location, timing, and walk state synced. Use the client tracking page for the customer-facing map experience.
                            </div>
                            <div class="map-coords" id="mapCoordsLabel">
                                LAT: <?= h($snapshot['latitude'] !== null ? (string) $snapshot['latitude'] : '—') ?>
                                &nbsp;•&nbsp;
                                LNG: <?= h($snapshot['longitude'] !== null ? (string) $snapshot['longitude'] : '—') ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="grid">
            <div class="card">
                <div class="eyebrow">Walk Information</div>
                <div class="detail-grid">
                    <div class="detail">
                        <div class="detail-label">Booking ID</div>
                        <div class="detail-value">#<?= (int) $booking['id'] ?></div>
                    </div>

                    <div class="detail">
                        <div class="detail-label">Service Type</div>
                        <div class="detail-value"><?= h(ucfirst((string) $booking['service_type'])) ?></div>
                    </div>

                    <div class="detail">
                        <div class="detail-label">Client</div>
                        <div class="detail-value"><?= h($booking['client_name'] !== '' ? $booking['client_name'] : 'Unknown client') ?></div>
                    </div>

                    <div class="detail">
                        <div class="detail-label">Pet</div>
                        <div class="detail-value"><?= h($booking['pet_name'] !== '' ? $booking['pet_name'] : 'Unknown pet') ?></div>
                    </div>
                </div>

                <div class="detail" style="margin-top:14px;">
                    <div class="detail-label">Care Notes</div>
                    <div class="detail-value" style="font-weight:600;"><?= h($booking['notes'] !== '' ? (string) $booking['notes'] : 'No care notes were provided for this walk.') ?></div>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Tracking Actions</div>

                <div class="action-stack">
                    <div class="action-box">
                        <div class="action-title">Start Walk</div>
                        <div class="action-text">
                            Start the active session, launch the timer, and move the walk into an in-progress state for the tracking flow.
                        </div>
                        <button id="startWalkBtn" class="btn-success" <?= $isStarted ? 'disabled' : '' ?>>Start Walk</button>
                    </div>

                    <div class="action-box">
                        <div class="action-title">Enable Live GPS</div>
                        <div class="action-text">
                            Grant location access so this page can send live GPS pings while the walk is active.
                        </div>
                        <button id="gpsBtn" class="btn-tracking" <?= !$isStarted || $isCompleted ? 'disabled' : '' ?>>Enable Live GPS</button>
                    </div>

                    <div class="action-box">
                        <div class="action-title">Complete Walk</div>
                        <div class="action-text">
                            End the session, stop the timer, and mark the service completed for the client-facing experience.
                        </div>
                        <button id="completeWalkBtn" class="btn-primary" <?= !$isStarted || $isCompleted ? 'disabled' : '' ?>>Complete Walk</button>
                    </div>

                    <div class="action-box">
                        <div class="action-title">Client Tracking View</div>
                        <div class="action-text">
                            Open the client-facing map page tied to this same booking.
                        </div>
                        <a class="top-link" href="client-map.php?booking_id=<?= (int) $booking['id'] ?>">Open Client Map</a>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <script>
        const bookingId = <?= (int) $booking['id'] ?>;
        let walkStarted = <?= $isStarted ? 'true' : 'false' ?>;
        let walkCompleted = <?= $isCompleted ? 'true' : 'false' ?>;
        let startIso = <?= json_encode($startTimestamp) ?>;
        let gpsEnabled = false;
        let gpsWatchId = null;
        let pingIntervalId = null;
        let statusIntervalId = null;
        let latestPosition = null;

        const noticeBox = document.getElementById('noticeBox');
        const elapsedTimer = document.getElementById('elapsedTimer');
        const bookingStatusLabel = document.getElementById('bookingStatusLabel');
        const sessionStatusLabel = document.getElementById('sessionStatusLabel');
        const lastPingLabel = document.getElementById('lastPingLabel');
        const accuracyLabel = document.getElementById('accuracyLabel');
        const latLabel = document.getElementById('latLabel');
        const lngLabel = document.getElementById('lngLabel');
        const startedAtLabel = document.getElementById('startedAtLabel');
        const endedAtLabel = document.getElementById('endedAtLabel');
        const mapCoordsLabel = document.getElementById('mapCoordsLabel');

        const startWalkBtn = document.getElementById('startWalkBtn');
        const gpsBtn = document.getElementById('gpsBtn');
        const completeWalkBtn = document.getElementById('completeWalkBtn');

        function showNotice(message, type = 'info') {
            noticeBox.className = 'notice';
            if (type === 'error') {
                noticeBox.classList.add('error');
            }
            if (type === 'success') {
                noticeBox.classList.add('success');
            }
            noticeBox.textContent = message;
            noticeBox.classList.remove('hidden');
        }

        function formatElapsed(seconds) {
            const hrs = String(Math.floor(seconds / 3600)).padStart(2, '0');
            const mins = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0');
            const secs = String(seconds % 60).padStart(2, '0');
            return `${hrs}:${mins}:${secs}`;
        }

        function updateTimer() {
            if (!walkStarted || !startIso) {
                elapsedTimer.textContent = '00:00:00';
                return;
            }

            const startedMs = new Date(startIso).getTime();
            if (Number.isNaN(startedMs)) {
                elapsedTimer.textContent = '00:00:00';
                return;
            }

            const endMs = walkCompleted && endedAtLabel.textContent && endedAtLabel.textContent !== 'Not completed'
                ? new Date(endedAtLabel.textContent).getTime()
                : Date.now();

            const baseMs = Number.isNaN(endMs) ? Date.now() : endMs;
            const diffSeconds = Math.max(0, Math.floor((baseMs - startedMs) / 1000));
            elapsedTimer.textContent = formatElapsed(diffSeconds);
        }

        function titleCaseStatus(value) {
            return String(value || '')
                .replaceAll('_', ' ')
                .replace(/\b\w/g, c => c.toUpperCase());
        }

        function setButtons() {
            startWalkBtn.disabled = walkStarted;
            gpsBtn.disabled = !walkStarted || walkCompleted;
            completeWalkBtn.disabled = !walkStarted || walkCompleted;
            gpsBtn.textContent = gpsEnabled ? 'Live GPS Enabled' : 'Enable Live GPS';
        }

        async function postApi(api, payload = {}) {
            const form = new FormData();
            form.append('api', api);
            form.append('booking_id', String(bookingId));

            Object.entries(payload).forEach(([key, value]) => {
                if (value !== null && value !== undefined) {
                    form.append(key, String(value));
                }
            });

            const response = await fetch(`live-tracking.php?booking_id=${bookingId}`, {
                method: 'POST',
                body: form,
                credentials: 'same-origin'
            });

            return response.json();
        }

        function applySnapshot(data) {
            if (!data) return;

            if (data.booking_status) {
                bookingStatusLabel.textContent = titleCaseStatus(data.booking_status);
                walkCompleted = data.booking_status === 'completed';
                if (data.booking_status === 'in_progress' || data.booking_status === 'completed') {
                    walkStarted = true;
                }
            }

            if (data.snapshot) {
                const snap = data.snapshot;

                if (snap.session_status) {
                    sessionStatusLabel.textContent = titleCaseStatus(snap.session_status);
                    if (snap.session_status === 'in_progress' || snap.session_status === 'completed') {
                        walkStarted = true;
                    }
                    if (snap.session_status === 'completed') {
                        walkCompleted = true;
                    }
                }

                if (snap.started_at) {
                    startedAtLabel.textContent = snap.started_at;
                    const parsed = new Date(snap.started_at);
                    if (!Number.isNaN(parsed.getTime())) {
                        startIso = parsed.toISOString();
                    }
                }

                if (snap.ended_at) {
                    endedAtLabel.textContent = snap.ended_at;
                }

                if (snap.latitude !== null && snap.latitude !== '') {
                    latLabel.textContent = String(snap.latitude);
                }

                if (snap.longitude !== null && snap.longitude !== '') {
                    lngLabel.textContent = String(snap.longitude);
                }

                if (snap.latitude !== null || snap.longitude !== null) {
                    mapCoordsLabel.textContent = `LAT: ${snap.latitude ?? '—'} • LNG: ${snap.longitude ?? '—'}`;
                }

                if (snap.accuracy !== null && snap.accuracy !== '') {
                    accuracyLabel.textContent = `${snap.accuracy} m`;
                }

                if (snap.last_ping_at) {
                    lastPingLabel.textContent = snap.last_ping_at;
                }
            }

            setButtons();
            updateTimer();
        }

        async function refreshStatus() {
            try {
                const response = await fetch(`live-tracking.php?booking_id=${bookingId}&api=status`, {
                    credentials: 'same-origin'
                });
                const data = await response.json();
                if (data.ok) {
                    applySnapshot(data);
                }
            } catch (error) {
                console.error(error);
            }
        }

        async function startWalk() {
            try {
                const data = await postApi('start');
                if (!data.ok) {
                    showNotice(data.message || 'Could not start the walk.', 'error');
                    return;
                }

                walkStarted = true;
                showNotice(data.message || 'Walk started.', 'success');
                applySnapshot(data);
            } catch (error) {
                showNotice('Could not start the walk.', 'error');
            }
        }

        async function completeWalk() {
            try {
                const data = await postApi('complete');
                if (!data.ok) {
                    showNotice(data.message || 'Could not complete the walk.', 'error');
                    return;
                }

                walkCompleted = true;
                gpsEnabled = false;
                stopGpsWatch();
                stopPingLoop();
                showNotice(data.message || 'Walk completed.', 'success');
                applySnapshot(data);
            } catch (error) {
                showNotice('Could not complete the walk.', 'error');
            }
        }

        async function sendPing() {
            if (!latestPosition || !walkStarted || walkCompleted) {
                return;
            }

            const coords = latestPosition.coords || {};
            try {
                const data = await postApi('ping', {
                    latitude: coords.latitude,
                    longitude: coords.longitude,
                    accuracy: coords.accuracy ?? ''
                });

                if (data.ok) {
                    applySnapshot(data);
                }
            } catch (error) {
                console.error(error);
            }
        }

        function stopGpsWatch() {
            if (gpsWatchId !== null && navigator.geolocation) {
                navigator.geolocation.clearWatch(gpsWatchId);
                gpsWatchId = null;
            }
        }

        function stopPingLoop() {
            if (pingIntervalId !== null) {
                clearInterval(pingIntervalId);
                pingIntervalId = null;
            }
        }

        function startPingLoop() {
            stopPingLoop();
            pingIntervalId = setInterval(() => {
                void sendPing();
            }, 15000);
        }

        function enableGps() {
            if (!navigator.geolocation) {
                showNotice('This browser does not support geolocation.', 'error');
                return;
            }

            gpsEnabled = true;
            setButtons();

            gpsWatchId = navigator.geolocation.watchPosition(
                (position) => {
                    latestPosition = position;
                    latLabel.textContent = String(position.coords.latitude);
                    lngLabel.textContent = String(position.coords.longitude);
                    accuracyLabel.textContent = `${position.coords.accuracy} m`;
                    mapCoordsLabel.textContent = `LAT: ${position.coords.latitude} • LNG: ${position.coords.longitude}`;
                    void sendPing();
                },
                (error) => {
                    gpsEnabled = false;
                    setButtons();
                    showNotice('Location permission is required for live GPS updates.', 'error');
                    console.error(error);
                },
                {
                    enableHighAccuracy: true,
                    maximumAge: 5000,
                    timeout: 12000
                }
            );

            startPingLoop();
            showNotice('Live GPS updates are enabled.', 'success');
        }

        startWalkBtn.addEventListener('click', () => {
            void startWalk();
        });

        completeWalkBtn.addEventListener('click', () => {
            void completeWalk();
        });

        gpsBtn.addEventListener('click', () => {
            if (!walkStarted || walkCompleted) return;
            if (!gpsEnabled) {
                enableGps();
            }
        });

        setInterval(updateTimer, 1000);
        updateTimer();
        setButtons();

        statusIntervalId = setInterval(() => {
            void refreshStatus();
        }, 10000);

        void refreshStatus();
    </script>
</body>
</html>