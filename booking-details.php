<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';

/**
 * Doggie Dorian's
 * booking-details.php
 *
 * Full replacement
 * Premium schema-tolerant booking details page
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

function currentUserRole(): string
{
    $role = (string) ($_SESSION['role'] ?? '');
    if ($role !== '') {
        return strtolower($role);
    }

    if (!empty($_SESSION['is_admin'])) {
        return 'admin';
    }

    if (!empty($_SESSION['walker_id']) || !empty($_SESSION['staff_id'])) {
        return 'walker';
    }

    return 'member';
}

function currentUserId(): int
{
    foreach (['user_id', 'member_id', 'client_id', 'id'] as $key) {
        if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) {
            return (int) $_SESSION[$key];
        }
    }
    return 0;
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

function isAdmin(): bool
{
    return currentUserRole() === 'admin';
}

function isWorker(): bool
{
    $role = currentUserRole();
    return in_array($role, ['walker', 'staff', 'employee'], true) || currentWorkerId() > 0;
}

function isMember(): bool
{
    return currentUserRole() === 'member';
}

function normalizeStatus(?string $status): string
{
    $status = strtolower(trim((string) $status));

    return match ($status) {
        'new', 'open', 'unassigned' => 'available',
        'assigned', 'accepted', 'confirmed' => 'accepted',
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

function safeExecute(PDOStatement $stmt, array $params = []): bool
{
    try {
        return $stmt->execute($params);
    } catch (Throwable) {
        return false;
    }
}

function formatDateTimeDisplay(?string $date, ?string $time): string
{
    $date = trim((string) $date);
    $time = trim((string) $time);

    if ($date === '' && $time === '') {
        return 'Not scheduled';
    }

    if ($date !== '' && $time !== '') {
        $ts = strtotime($date . ' ' . $time);
        if ($ts !== false) {
            return date('F j, Y \a\t g:i A', $ts);
        }
        return $date . ' ' . $time;
    }

    if ($date !== '') {
        $ts = strtotime($date);
        return $ts !== false ? date('F j, Y', $ts) : $date;
    }

    $ts = strtotime($time);
    return $ts !== false ? date('g:i A', $ts) : $time;
}

function formatMoneyDisplay(mixed $value): string
{
    if ($value === null || $value === '') {
        return 'Not set';
    }

    if (is_numeric($value)) {
        return '$' . number_format((float) $value, 2);
    }

    return '$' . (string) $value;
}

function statusBadgeClass(string $status): string
{
    return match ($status) {
        'accepted' => 'badge-accepted',
        'in_progress' => 'badge-progress',
        'completed' => 'badge-complete',
        'cancelled' => 'badge-cancelled',
        'released' => 'badge-released',
        'available' => 'badge-available',
        default => 'badge-pending',
    };
}

function bookingBaseTable(PDO $pdo): ?string
{
    foreach (['bookings', 'walks', 'walk_sessions'] as $candidate) {
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

    $status = normalizeStatus((string) valueFromRow($row, ['status', 'booking_status', 'service_status', 'walk_status'], 'pending'));
    $serviceType = normalizeServiceType((string) valueFromRow($row, ['service_type', 'type', 'booking_type', 'category'], 'service'));

    $userId = (int) valueFromRow($row, ['user_id', 'member_id', 'client_id', 'owner_id'], 0);
    $workerId = (int) valueFromRow($row, ['walker_id', 'staff_id', 'employee_id', 'worker_id', 'assigned_to', 'assigned_worker_id'], 0);

    $dateValue = (string) valueFromRow($row, ['service_date', 'booking_date', 'walk_date', 'date', 'start_date', 'scheduled_date'], '');
    $timeValue = (string) valueFromRow($row, ['service_time', 'booking_time', 'walk_time', 'time', 'start_time', 'scheduled_time'], '');

    $petName = (string) valueFromRow($row, ['pet_name', 'dog_name', 'name'], '');
    $clientName = (string) valueFromRow($row, ['client_name', 'owner_name', 'member_name', 'customer_name'], '');
    $notes = (string) valueFromRow($row, ['notes', 'special_instructions', 'instructions', 'care_notes'], '');
    $duration = (string) valueFromRow($row, ['duration_minutes', 'duration', 'minutes'], '');
    $price = valueFromRow($row, ['price', 'total_price', 'amount'], '');

    return [
        '_table' => $table,
        '_id_col' => $idCol,
        '_raw' => $row,
        'id' => (int) valueFromRow($row, [$idCol], $bookingId),
        'status' => $status,
        'service_type' => $serviceType,
        'user_id' => $userId,
        'worker_id' => $workerId,
        'service_date' => $dateValue,
        'service_time' => $timeValue,
        'pet_name' => $petName,
        'client_name' => $clientName,
        'notes' => $notes,
        'duration' => $duration,
        'price' => $price,
    ];
}

function loadPetName(PDO $pdo, array $booking): string
{
    if ($booking['pet_name'] !== '') {
        return $booking['pet_name'];
    }

    $row = $booking['_raw'];
    $petId = (int) valueFromRow($row, ['pet_id', 'dog_id'], 0);
    if ($petId <= 0) {
        return '';
    }

    foreach (['pets', 'dogs'] as $petTable) {
        if (!hasTable($pdo, $petTable)) {
            continue;
        }

        $idCol = firstExistingColumn($pdo, $petTable, ['id', 'pet_id', 'dog_id']);
        $nameCol = firstExistingColumn($pdo, $petTable, ['name', 'pet_name', 'dog_name']);
        if ($idCol === null || $nameCol === null) {
            continue;
        }

        $stmt = $pdo->prepare("SELECT {$nameCol} FROM {$petTable} WHERE {$idCol} = :id LIMIT 1");
        if (!safeExecute($stmt, [':id' => $petId])) {
            continue;
        }

        $name = $stmt->fetchColumn();
        if ($name !== false && $name !== null && trim((string) $name) !== '') {
            return (string) $name;
        }
    }

    return '';
}

function loadClientName(PDO $pdo, array $booking): string
{
    if ($booking['client_name'] !== '') {
        return $booking['client_name'];
    }

    $userId = (int) $booking['user_id'];
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
        if ($name !== false && $name !== null && trim((string) $name) !== '') {
            return (string) $name;
        }
    }

    return '';
}

function loadWorkerName(PDO $pdo, int $workerId): string
{
    if ($workerId <= 0) {
        return '';
    }

    foreach (['walkers', 'staff', 'employees', 'users'] as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $idCol = firstExistingColumn($pdo, $table, ['id', 'walker_id', 'staff_id', 'employee_id', 'user_id']);
        $nameCol = firstExistingColumn($pdo, $table, ['full_name', 'name', 'walker_name', 'staff_name']);
        if ($idCol === null || $nameCol === null) {
            continue;
        }

        $stmt = $pdo->prepare("SELECT {$nameCol} FROM {$table} WHERE {$idCol} = :id LIMIT 1");
        if (!safeExecute($stmt, [':id' => $workerId])) {
            continue;
        }

        $name = $stmt->fetchColumn();
        if ($name !== false && $name !== null && trim((string) $name) !== '') {
            return (string) $name;
        }
    }

    return '';
}

function canUserViewBooking(array $booking): bool
{
    if (isAdmin()) {
        return true;
    }

    $currentUserId = currentUserId();
    $currentWorkerId = currentWorkerId();

    if (isMember() && $currentUserId > 0 && (int) $booking['user_id'] === $currentUserId) {
        return true;
    }

    if (isWorker()) {
        if ((int) $booking['worker_id'] === $currentWorkerId && $currentWorkerId > 0) {
            return true;
        }

        $status = normalizeStatus((string) $booking['status']);
        if (in_array($status, ['pending', 'available', 'released'], true)) {
            return true;
        }
    }

    return false;
}

function isWithinAdvanceWindow(array $booking, int $months = 1): bool
{
    $date = trim((string) $booking['service_date']);
    if ($date === '') {
        return true;
    }

    $serviceTs = strtotime($date . ' 23:59:59');
    if ($serviceTs === false) {
        return true;
    }

    $now = time();
    $limit = strtotime('+' . $months . ' month', $now);
    if ($limit === false) {
        return true;
    }

    return $serviceTs <= $limit;
}

function updateBookingColumns(PDO $pdo, string $table, string $idCol, int $bookingId, array $changes): bool
{
    if ($changes === []) {
        return true;
    }

    $sets = [];
    $params = [':id' => $bookingId];

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
        $data['user_id'] = $target === 'worker' ? (int) $booking['worker_id'] : (int) $booking['user_id'];
    }

    if (in_array('member_id', $columns, true) && $target !== 'worker') {
        $data['member_id'] = (int) $booking['user_id'];
    }

    if (in_array('walker_id', $columns, true) && $target === 'worker') {
        $data['walker_id'] = (int) $booking['worker_id'];
    }

    if (in_array('booking_id', $columns, true)) {
        $data['booking_id'] = (int) $booking['id'];
    }

    if (in_array('title', $columns, true)) {
        $data['title'] = 'Booking Update';
    }

    if (in_array('message', $columns, true)) {
        $data['message'] = $message;
    } elseif (in_array('content', $columns, true)) {
        $data['content'] = $message;
    } elseif (in_array('body', $columns, true)) {
        $data['body'] = $message;
    }

    if (in_array('type', $columns, true)) {
        $data['type'] = 'booking';
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

    $sql = 'INSERT INTO notifications (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);

    $params = [];
    foreach ($data as $key => $value) {
        $params[':' . $key] = $value;
    }

    safeExecute($stmt, $params);
}

function ensureWalkSessionStarted(PDO $pdo, array $booking): void
{
    if (!hasTable($pdo, 'walk_sessions')) {
        return;
    }

    $columns = getTableColumns($pdo, 'walk_sessions');
    if ($columns === []) {
        return;
    }

    $bookingIdCol = firstExistingColumn($pdo, 'walk_sessions', ['booking_id', 'walk_id']);
    if ($bookingIdCol === null) {
        return;
    }

    $checkIdCol = firstExistingColumn($pdo, 'walk_sessions', ['id']) ?? 'rowid';

    $check = $pdo->prepare("SELECT * FROM walk_sessions WHERE {$bookingIdCol} = :id ORDER BY {$checkIdCol} DESC LIMIT 1");
    if (!safeExecute($check, [':id' => (int) $booking['id']])) {
        return;
    }

    $existing = $check->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $existingId = (int) valueFromRow($existing, ['id'], 0);
        if ($existingId > 0) {
            $updates = [];
            if (hasColumn($pdo, 'walk_sessions', 'status')) {
                $updates['status'] = 'in_progress';
            }
            if (hasColumn($pdo, 'walk_sessions', 'started_at') && empty($existing['started_at'])) {
                $updates['started_at'] = date('Y-m-d H:i:s');
            }
            if (hasColumn($pdo, 'walk_sessions', 'walker_id') && empty($existing['walker_id']) && (int) $booking['worker_id'] > 0) {
                $updates['walker_id'] = (int) $booking['worker_id'];
            }
            if ($updates !== []) {
                updateBookingColumns($pdo, 'walk_sessions', 'id', $existingId, $updates);
            }
        }
        return;
    }

    $data = [];
    if (in_array($bookingIdCol, $columns, true)) {
        $data[$bookingIdCol] = (int) $booking['id'];
    }
    if (in_array('walker_id', $columns, true)) {
        $data['walker_id'] = (int) $booking['worker_id'];
    }
    if (in_array('status', $columns, true)) {
        $data['status'] = 'in_progress';
    }
    if (in_array('started_at', $columns, true)) {
        $data['started_at'] = date('Y-m-d H:i:s');
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

    $stmt = $pdo->prepare('INSERT INTO walk_sessions (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')');
    safeExecute($stmt, $params);
}

function endWalkSession(PDO $pdo, array $booking): void
{
    if (!hasTable($pdo, 'walk_sessions')) {
        return;
    }

    $bookingIdCol = firstExistingColumn($pdo, 'walk_sessions', ['booking_id', 'walk_id']);
    $idCol = firstExistingColumn($pdo, 'walk_sessions', ['id']);
    if ($bookingIdCol === null || $idCol === null) {
        return;
    }

    $stmt = $pdo->prepare("SELECT * FROM walk_sessions WHERE {$bookingIdCol} = :id ORDER BY {$idCol} DESC LIMIT 1");
    if (!safeExecute($stmt, [':id' => (int) $booking['id']])) {
        return;
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }

    $updates = [];
    if (hasColumn($pdo, 'walk_sessions', 'status')) {
        $updates['status'] = 'completed';
    }
    if (hasColumn($pdo, 'walk_sessions', 'ended_at')) {
        $updates['ended_at'] = date('Y-m-d H:i:s');
    }

    if ($updates !== []) {
        updateBookingColumns($pdo, 'walk_sessions', (string) $idCol, (int) $row[$idCol], $updates);
    }
}

function hasActiveTracking(array $booking): bool
{
    $status = normalizeStatus((string) $booking['status']);
    if ($booking['service_type'] !== 'walk') {
        return false;
    }

    return in_array($status, ['accepted', 'in_progress', 'completed'], true);
}

$bookingId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($bookingId <= 0) {
    redirectTo('dashboard.php');
}

$flash = $_SESSION['booking_details_flash'] ?? '';
unset($_SESSION['booking_details_flash']);

$booking = findBooking($pdo, $bookingId);
if (!$booking) {
    http_response_code(404);
    echo 'Booking not found.';
    exit;
}

if (!canUserViewBooking($booking)) {
    http_response_code(403);
    echo 'You do not have permission to view this booking.';
    exit;
}

$booking['pet_name'] = loadPetName($pdo, $booking);
$booking['client_name'] = loadClientName($pdo, $booking);
$workerName = loadWorkerName($pdo, (int) $booking['worker_id']);

$table = $booking['_table'];
$idCol = $booking['_id_col'];
$statusCol = firstExistingColumn($pdo, $table, ['status', 'booking_status', 'service_status', 'walk_status']);
$workerCol = firstExistingColumn($pdo, $table, ['walker_id', 'staff_id', 'employee_id', 'worker_id', 'assigned_to', 'assigned_worker_id']);
$updatedAtCol = firstExistingColumn($pdo, $table, ['updated_at', 'modified_at']);
$startedAtCol = firstExistingColumn($pdo, $table, ['started_at', 'walk_started_at', 'service_started_at']);
$completedAtCol = firstExistingColumn($pdo, $table, ['completed_at', 'ended_at', 'walk_completed_at', 'service_completed_at']);
$cancelReasonCol = firstExistingColumn($pdo, $table, ['cancel_reason', 'cancellation_reason', 'reason']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = strtolower(trim((string) ($_POST['action'] ?? '')));
    $notesInput = trim((string) ($_POST['action_note'] ?? ''));
    $currentStatus = normalizeStatus((string) $booking['status']);
    $workerId = currentWorkerId();
    $changes = [];
    $message = '';
    $success = false;

    try {
        if ($action === 'accept') {
            if (!isWorker() && !isAdmin()) {
                throw new RuntimeException('Only staff can accept services.');
            }
            if (!isWithinAdvanceWindow($booking, 1)) {
                throw new RuntimeException('This service is outside the 1 month acceptance window.');
            }
            if ($workerCol === null) {
                throw new RuntimeException('This booking cannot be assigned in the current schema.');
            }
            if (!in_array($currentStatus, ['pending', 'available', 'released'], true)) {
                throw new RuntimeException('This service is not currently available to accept.');
            }

            $assignedWorkerId = $workerId > 0 ? $workerId : (int) $booking['worker_id'];
            if ($assignedWorkerId <= 0 && !isAdmin()) {
                throw new RuntimeException('No worker session was found for this acceptance action.');
            }

            $changes[$workerCol] = $assignedWorkerId;
            if ($statusCol !== null) {
                $changes[$statusCol] = 'accepted';
            }
            if ($updatedAtCol !== null) {
                $changes[$updatedAtCol] = date('Y-m-d H:i:s');
            }

            $success = updateBookingColumns($pdo, $table, $idCol, $bookingId, $changes);
            if (!$success) {
                throw new RuntimeException('Failed to accept the service.');
            }

            $booking['worker_id'] = $assignedWorkerId;
            $booking['status'] = 'accepted';
            $workerName = loadWorkerName($pdo, $assignedWorkerId);

            $message = 'Service accepted successfully.';
            writeNotification($pdo, $booking, 'Your ' . $booking['service_type'] . ' service has been accepted by staff.', 'user');
            writeNotification($pdo, $booking, 'You accepted booking #' . $booking['id'] . '.', 'worker');
        } elseif ($action === 'release') {
            if (!isWorker() && !isAdmin()) {
                throw new RuntimeException('Only staff can release services.');
            }
            if (!in_array($currentStatus, ['accepted', 'assigned'], true)) {
                throw new RuntimeException('Only accepted services can be released.');
            }
            if (!isAdmin() && (int) $booking['worker_id'] !== $workerId) {
                throw new RuntimeException('You can only release services assigned to you.');
            }

            if ($workerCol !== null) {
                $changes[$workerCol] = null;
            }
            if ($statusCol !== null) {
                $changes[$statusCol] = 'released';
            }
            if ($updatedAtCol !== null) {
                $changes[$updatedAtCol] = date('Y-m-d H:i:s');
            }

            $success = updateBookingColumns($pdo, $table, $idCol, $bookingId, $changes);
            if (!$success) {
                throw new RuntimeException('Failed to release the service.');
            }

            $booking['worker_id'] = 0;
            $booking['status'] = 'released';
            $workerName = '';

            $message = 'Service released successfully.';
            writeNotification($pdo, $booking, 'Your ' . $booking['service_type'] . ' service is being reassigned.', 'user');
        } elseif ($action === 'cancel') {
            if (!isAdmin() && !isWorker() && !isMember()) {
                throw new RuntimeException('You do not have permission to cancel this service.');
            }

            if (in_array($currentStatus, ['completed', 'cancelled'], true)) {
                throw new RuntimeException('This service can no longer be cancelled.');
            }

            if (isMember() && (int) $booking['user_id'] !== currentUserId()) {
                throw new RuntimeException('You can only cancel your own service.');
            }

            if (isWorker() && !isAdmin() && (int) $booking['worker_id'] > 0 && (int) $booking['worker_id'] !== $workerId) {
                throw new RuntimeException('You can only cancel services assigned to you.');
            }

            if ($statusCol !== null) {
                $changes[$statusCol] = 'cancelled';
            }
            if ($updatedAtCol !== null) {
                $changes[$updatedAtCol] = date('Y-m-d H:i:s');
            }
            if ($cancelReasonCol !== null) {
                $changes[$cancelReasonCol] = $notesInput !== '' ? $notesInput : 'Cancelled from booking details.';
            }

            $success = updateBookingColumns($pdo, $table, $idCol, $bookingId, $changes);
            if (!$success) {
                throw new RuntimeException('Failed to cancel the service.');
            }

            $booking['status'] = 'cancelled';
            $message = 'Service cancelled successfully.';
            writeNotification($pdo, $booking, 'Your ' . $booking['service_type'] . ' service has been cancelled.', 'user');

            if ((int) $booking['worker_id'] > 0) {
                writeNotification($pdo, $booking, 'Booking #' . $booking['id'] . ' has been cancelled.', 'worker');
            }
        } elseif ($action === 'start_walk') {
            if ($booking['service_type'] !== 'walk') {
                throw new RuntimeException('Only walk services can be started here.');
            }
            if (!isWorker() && !isAdmin()) {
                throw new RuntimeException('Only staff can start walks.');
            }
            if (!isAdmin() && (int) $booking['worker_id'] !== $workerId) {
                throw new RuntimeException('Only the assigned walker can start this walk.');
            }
            if (!in_array($currentStatus, ['accepted', 'assigned'], true)) {
                throw new RuntimeException('This walk must be accepted before it can be started.');
            }

            if ($statusCol !== null) {
                $changes[$statusCol] = 'in_progress';
            }
            if ($startedAtCol !== null) {
                $changes[$startedAtCol] = date('Y-m-d H:i:s');
            }
            if ($updatedAtCol !== null) {
                $changes[$updatedAtCol] = date('Y-m-d H:i:s');
            }

            $success = updateBookingColumns($pdo, $table, $idCol, $bookingId, $changes);
            if (!$success) {
                throw new RuntimeException('Failed to start the walk.');
            }

            $booking['status'] = 'in_progress';
            ensureWalkSessionStarted($pdo, $booking);

            $message = 'Walk started successfully.';
            writeNotification($pdo, $booking, 'Your walk is now in progress. Live tracking is available.', 'user');
        } elseif ($action === 'complete_walk') {
            if ($booking['service_type'] !== 'walk') {
                throw new RuntimeException('Only walk services can be completed here.');
            }
            if (!isWorker() && !isAdmin()) {
                throw new RuntimeException('Only staff can complete walks.');
            }
            if (!isAdmin() && (int) $booking['worker_id'] !== $workerId) {
                throw new RuntimeException('Only the assigned walker can complete this walk.');
            }
            if (!in_array($currentStatus, ['in_progress', 'accepted', 'assigned'], true)) {
                throw new RuntimeException('This walk is not in a completable state.');
            }

            if ($statusCol !== null) {
                $changes[$statusCol] = 'completed';
            }
            if ($completedAtCol !== null) {
                $changes[$completedAtCol] = date('Y-m-d H:i:s');
            }
            if ($updatedAtCol !== null) {
                $changes[$updatedAtCol] = date('Y-m-d H:i:s');
            }

            $success = updateBookingColumns($pdo, $table, $idCol, $bookingId, $changes);
            if (!$success) {
                throw new RuntimeException('Failed to complete the walk.');
            }

            $booking['status'] = 'completed';
            endWalkSession($pdo, $booking);

            $message = 'Walk completed successfully.';
            writeNotification($pdo, $booking, 'Your walk has been completed.', 'user');
        } else {
            throw new RuntimeException('Unknown action.');
        }

        $_SESSION['booking_details_flash'] = $message;
        redirectTo('booking-details.php?id=' . $bookingId);
    } catch (Throwable $e) {
        $_SESSION['booking_details_flash'] = 'Error: ' . $e->getMessage();
        redirectTo('booking-details.php?id=' . $bookingId);
    }
}

$booking = findBooking($pdo, $bookingId) ?? $booking;
$booking['pet_name'] = loadPetName($pdo, $booking);
$booking['client_name'] = loadClientName($pdo, $booking);
$workerName = loadWorkerName($pdo, (int) $booking['worker_id']);

$status = normalizeStatus((string) $booking['status']);
$serviceType = normalizeServiceType((string) $booking['service_type']);
$canAccept = (isWorker() || isAdmin())
    && in_array($status, ['pending', 'available', 'released'], true)
    && isWithinAdvanceWindow($booking, 1);

$canRelease = (isWorker() || isAdmin())
    && in_array($status, ['accepted', 'assigned'], true)
    && (isAdmin() || (int) $booking['worker_id'] === currentWorkerId());

$canCancel = !in_array($status, ['completed', 'cancelled'], true)
    && (
        isAdmin()
        || (isWorker() && ((int) $booking['worker_id'] === currentWorkerId() || (int) $booking['worker_id'] === 0))
        || (isMember() && (int) $booking['user_id'] === currentUserId())
    );

$canStartWalk = $serviceType === 'walk'
    && (isWorker() || isAdmin())
    && in_array($status, ['accepted', 'assigned'], true)
    && (isAdmin() || (int) $booking['worker_id'] === currentWorkerId());

$canCompleteWalk = $serviceType === 'walk'
    && (isWorker() || isAdmin())
    && in_array($status, ['in_progress', 'accepted', 'assigned'], true)
    && (isAdmin() || (int) $booking['worker_id'] === currentWorkerId());

$showTrackForWorker = $serviceType === 'walk'
    && (isWorker() || isAdmin())
    && in_array($status, ['accepted', 'in_progress'], true)
    && ((isAdmin() && (int) $booking['worker_id'] > 0) || (int) $booking['worker_id'] === currentWorkerId());

$showTrackForClient = $serviceType === 'walk'
    && (isMember() || isAdmin())
    && hasActiveTracking($booking);

$backUrl = 'dashboard.php';
if (isAdmin()) {
    $backUrl = 'admin-bookings.php';
} elseif (isWorker()) {
    $backUrl = 'walker-dashboard.php';
} elseif (isMember()) {
    $backUrl = 'my-bookings.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Details | Doggie Dorian’s</title>
    <meta name="description" content="View and manage booking details for Doggie Dorian’s services.">
    <style>
        * { box-sizing: border-box; }

        :root {
            --bg: #0a0a0f;
            --panel: rgba(255,255,255,0.055);
            --panel-2: rgba(255,255,255,0.04);
            --line: rgba(255,255,255,0.08);
            --line-soft: rgba(255,255,255,0.06);
            --text: #f4f1ea;
            --muted: rgba(244,241,234,0.72);
            --muted-2: rgba(244,241,234,0.56);
            --gold-1: #e2c48d;
            --gold-2: #b9975b;
            --shadow: 0 18px 60px rgba(0,0,0,0.28);
        }

        body {
            margin: 0;
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top left, rgba(226,196,141,0.06), transparent 22%),
                linear-gradient(180deg, #0a0a0f 0%, #12121a 100%);
            color: var(--text);
        }

        a { color: inherit; text-decoration: none; }

        .page {
            max-width: 1220px;
            margin: 0 auto;
            padding: 28px 20px 80px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .brand {
            font-size: 1.5rem;
            font-weight: 900;
            letter-spacing: 0.04em;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.06);
            border: 1px solid var(--line);
            color: var(--text);
            padding: 10px 14px;
            border-radius: 999px;
            font-weight: 700;
            transition: .18s ease;
        }

        .back-link:hover {
            background: rgba(255,255,255,0.1);
            transform: translateY(-1px);
        }

        .hero {
            display: grid;
            grid-template-columns: 1.38fr .92fr;
            gap: 24px;
            margin-bottom: 24px;
        }

        .card {
            background: linear-gradient(180deg, rgba(255,255,255,0.065), rgba(255,255,255,0.035));
            border: 1px solid var(--line);
            border-radius: 26px;
            padding: 24px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(8px);
        }

        .eyebrow {
            color: #c6b28b;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            font-size: .76rem;
            font-weight: 800;
            margin-bottom: 10px;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 2rem;
            line-height: 1.08;
        }

        .sub {
            color: var(--muted);
            line-height: 1.65;
            font-size: 1rem;
            max-width: 720px;
        }

        .pill-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
        }

        .pill {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            border: 1px solid var(--line);
            font-size: .92rem;
            font-weight: 700;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 10px 14px;
            border-radius: 999px;
            font-size: .9rem;
            font-weight: 900;
            letter-spacing: .02em;
            width: max-content;
        }

        .badge-pending { background: rgba(255,255,255,0.08); color: #f5f3ef; }
        .badge-available { background: rgba(125,150,255,0.16); color: #cbd6ff; }
        .badge-accepted { background: rgba(215,183,120,0.18); color: #f3dfb1; }
        .badge-progress { background: rgba(109,174,255,0.18); color: #d0e4ff; }
        .badge-complete { background: rgba(125,206,141,0.18); color: #d7f1dd; }
        .badge-cancelled { background: rgba(214,123,123,0.18); color: #ffd5d5; }
        .badge-released { background: rgba(172,145,255,0.18); color: #e1d6ff; }

        .grid {
            display: grid;
            grid-template-columns: 1.12fr .88fr;
            gap: 24px;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            margin-top: 18px;
        }

        .detail {
            background: var(--panel-2);
            border: 1px solid var(--line-soft);
            border-radius: 18px;
            padding: 16px;
        }

        .detail-label {
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: var(--muted-2);
            margin-bottom: 8px;
            font-weight: 800;
        }

        .detail-value {
            font-size: 1rem;
            font-weight: 700;
            line-height: 1.55;
            color: #fffaf2;
            word-break: break-word;
        }

        .notes-box {
            margin-top: 16px;
            padding: 18px;
            border-radius: 18px;
            background: var(--panel-2);
            border: 1px solid var(--line-soft);
            color: rgba(244,241,234,0.88);
            line-height: 1.7;
            white-space: pre-wrap;
        }

        .actions {
            display: grid;
            gap: 14px;
        }

        .action-group {
            padding: 16px;
            border-radius: 18px;
            background: var(--panel-2);
            border: 1px solid var(--line-soft);
        }

        .action-title {
            font-size: 1rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .action-text {
            color: var(--muted);
            line-height: 1.6;
            font-size: .95rem;
            margin-bottom: 14px;
        }

        textarea {
            width: 100%;
            min-height: 100px;
            resize: vertical;
            border-radius: 16px;
            border: 1px solid rgba(255,255,255,0.12);
            background: rgba(0,0,0,0.28);
            color: #fff;
            padding: 14px;
            font: inherit;
            outline: none;
            margin-bottom: 12px;
            transition: .18s ease;
        }

        textarea:focus {
            border-color: rgba(226,196,141,0.6);
            box-shadow: 0 0 0 4px rgba(226,196,141,0.10);
        }

        .button-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        button, .button-link {
            border: none;
            cursor: pointer;
            border-radius: 16px;
            padding: 12px 16px;
            font-weight: 800;
            font-size: .95rem;
            transition: .18s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .primary {
            background: linear-gradient(135deg, var(--gold-1), var(--gold-2));
            color: #0a0a0f;
        }

        .primary:hover,
        .secondary:hover,
        .danger:hover,
        .success:hover,
        .button-link:hover {
            transform: translateY(-1px);
        }

        .secondary {
            background: rgba(255,255,255,0.08);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.1);
        }

        .danger {
            background: linear-gradient(135deg, #d67b7b, #ad4747);
            color: #fff;
        }

        .success {
            background: linear-gradient(135deg, #9ed19e, #4f9e66);
            color: #08110a;
        }

        .flash {
            margin-bottom: 18px;
            padding: 14px 18px;
            border-radius: 16px;
            background: rgba(198,178,139,0.14);
            border: 1px solid rgba(198,178,139,0.3);
            color: #f4e5bf;
            font-weight: 700;
        }

        .muted {
            color: var(--muted-2);
        }

        @media (max-width: 980px) {
            .hero, .grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .detail-grid {
                grid-template-columns: 1fr;
            }

            .page {
                padding: 20px 14px 64px;
            }

            h1 {
                font-size: 1.65rem;
            }

            .card {
                border-radius: 22px;
                padding: 18px;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="topbar">
            <div class="brand">Doggie Dorian’s</div>
            <a class="back-link" href="<?= h($backUrl) ?>">← Back</a>
        </div>

        <?php if ($flash !== ''): ?>
            <div class="flash"><?= h($flash) ?></div>
        <?php endif; ?>

        <section class="hero">
            <div class="card">
                <div class="eyebrow">Booking Overview</div>
                <h1><?= ucfirst(h($serviceType)) ?> Service #<?= (int) $booking['id'] ?></h1>
                <div class="sub">
                    Review service details, assigned team information, and next actions for this booking from one polished view.
                </div>

                <div class="pill-row">
                    <span class="badge <?= h(statusBadgeClass($status)) ?>">
                        <?= h(ucwords(str_replace('_', ' ', $status))) ?>
                    </span>
                    <div class="pill">Service: <?= h(ucfirst($serviceType)) ?></div>
                    <div class="pill">Scheduled: <?= h(formatDateTimeDisplay((string) $booking['service_date'], (string) $booking['service_time'])) ?></div>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Live Access</div>
                <h2 style="margin:0 0 12px;font-size:1.25rem;">Tracking & Service Flow</h2>
                <div class="sub" style="margin-bottom:16px;">
                    Live walk access appears when the service type and current booking status support it.
                </div>

                <div class="button-row">
                    <?php if ($showTrackForWorker): ?>
                        <a class="button-link primary" href="live-tracking.php?booking_id=<?= (int) $booking['id'] ?>">Open Live Tracking</a>
                    <?php endif; ?>

                    <?php if ($showTrackForClient): ?>
                        <a class="button-link secondary" href="client-map.php?booking_id=<?= (int) $booking['id'] ?>">View Client Tracking</a>
                    <?php endif; ?>

                    <?php if (!$showTrackForWorker && !$showTrackForClient): ?>
                        <div class="muted">Tracking is not currently available for this booking state.</div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="grid">
            <div class="card">
                <div class="eyebrow">Service Details</div>

                <div class="detail-grid">
                    <div class="detail">
                        <div class="detail-label">Client</div>
                        <div class="detail-value"><?= h($booking['client_name'] !== '' ? $booking['client_name'] : 'Not available') ?></div>
                    </div>

                    <div class="detail">
                        <div class="detail-label">Pet</div>
                        <div class="detail-value"><?= h($booking['pet_name'] !== '' ? $booking['pet_name'] : 'Not available') ?></div>
                    </div>

                    <div class="detail">
                        <div class="detail-label">Assigned Team</div>
                        <div class="detail-value"><?= h($workerName !== '' ? $workerName : 'Unassigned') ?></div>
                    </div>

                    <div class="detail">
                        <div class="detail-label">Duration</div>
                        <div class="detail-value">
                            <?= h((string) $booking['duration'] !== '' ? (string) $booking['duration'] . ' min' : 'Not specified') ?>
                        </div>
                    </div>

                    <div class="detail">
                        <div class="detail-label">Price</div>
                        <div class="detail-value"><?= h(formatMoneyDisplay($booking['price'])) ?></div>
                    </div>

                    <div class="detail">
                        <div class="detail-label">Booking ID</div>
                        <div class="detail-value">#<?= (int) $booking['id'] ?></div>
                    </div>
                </div>

                <div class="eyebrow" style="margin-top:22px;">Notes & Instructions</div>
                <div class="notes-box"><?= h($booking['notes'] !== '' ? $booking['notes'] : 'No notes were added for this booking.') ?></div>
            </div>

            <div class="card">
                <div class="eyebrow">Available Actions</div>

                <div class="actions">
                    <?php if ($canAccept): ?>
                        <form method="post" class="action-group">
                            <div class="action-title">Accept Service</div>
                            <div class="action-text">
                                Assign this service to the current staff session and move it into an accepted state.
                            </div>
                            <div class="button-row">
                                <button type="submit" name="action" value="accept" class="primary">Accept Service</button>
                            </div>
                        </form>
                    <?php endif; ?>

                    <?php if ($canRelease): ?>
                        <form method="post" class="action-group">
                            <div class="action-title">Release Service</div>
                            <div class="action-text">
                                Release this booking so it can be reassigned to another team member.
                            </div>
                            <div class="button-row">
                                <button type="submit" name="action" value="release" class="secondary">Release Service</button>
                            </div>
                        </form>
                    <?php endif; ?>

                    <?php if ($canStartWalk): ?>
                        <form method="post" class="action-group">
                            <div class="action-title">Start Walk</div>
                            <div class="action-text">
                                Start the walk and move this booking into an in-progress state.
                            </div>
                            <div class="button-row">
                                <button type="submit" name="action" value="start_walk" class="success">Start Walk</button>
                            </div>
                        </form>
                    <?php endif; ?>

                    <?php if ($canCompleteWalk): ?>
                        <form method="post" class="action-group">
                            <div class="action-title">Complete Walk</div>
                            <div class="action-text">
                                Mark this walk as completed and close the active session when supported.
                            </div>
                            <div class="button-row">
                                <button type="submit" name="action" value="complete_walk" class="primary">Complete Walk</button>
                            </div>
                        </form>
                    <?php endif; ?>

                    <?php if ($canCancel): ?>
                        <form method="post" class="action-group">
                            <div class="action-title">Cancel Service</div>
                            <div class="action-text">
                                Cancel this booking and include an optional note for the booking record.
                            </div>
                            <textarea name="action_note" placeholder="Optional cancellation note or reason..."></textarea>
                            <div class="button-row">
                                <button type="submit" name="action" value="cancel" class="danger">Cancel Service</button>
                            </div>
                        </form>
                    <?php endif; ?>

                    <?php if (!$canAccept && !$canRelease && !$canStartWalk && !$canCompleteWalk && !$canCancel): ?>
                        <div class="action-group">
                            <div class="action-title">No actions available</div>
                            <div class="action-text">
                                This booking is currently locked or complete for your access level.
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>
</body>
</html>