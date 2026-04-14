<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-auth.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection is not available.';
    exit;
}

function ddAdminBookingUpdateRedirectBack(string $query = ''): void
{
    header('Location: admin-bookings.php' . $query);
    exit;
}

function ddAdminBookingUpdateQuoteIdentifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function ddAdminBookingUpdateTableExists(PDO $pdo, string $table): bool
{
    static $cache = array();

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
        $stmt->execute(array(':table' => $table));
        $cache[$table] = (bool) $stmt->fetchColumn();
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = false;
        return false;
    }
}

function ddAdminBookingUpdateGetColumns(PDO $pdo, string $table): array
{
    static $cache = array();

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!ddAdminBookingUpdateTableExists($pdo, $table)) {
        $cache[$table] = array();
        return $cache[$table];
    }

    try {
        $sql = 'PRAGMA table_info(' . ddAdminBookingUpdateQuoteIdentifier($table) . ')';
        $stmt = $pdo->query($sql);
        if (!($stmt instanceof PDOStatement)) {
            $cache[$table] = array();
            return $cache[$table];
        }

        $columns = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            if (!empty($column['name'])) {
                $columns[] = (string) $column['name'];
            }
        }

        $cache[$table] = $columns;
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = array();
        return $cache[$table];
    }
}

function ddAdminBookingUpdateHasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function ddAdminBookingUpdateFirstExistingColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function ddAdminBookingUpdateSessionCsrfToken(): string
{
    $candidates = array(
        'admin_dashboard_csrf',
        'admin_csrf',
        'csrf_token',
    );

    foreach ($candidates as $key) {
        if (!empty($_SESSION[$key]) && is_string($_SESSION[$key])) {
            return $_SESSION[$key];
        }
    }

    return '';
}

function ddAdminBookingUpdateValidateCsrf(?string $submittedToken): bool
{
    $sessionToken = ddAdminBookingUpdateSessionCsrfToken();

    if ($sessionToken === '' || $submittedToken === null || $submittedToken === '') {
        return false;
    }

    return hash_equals($sessionToken, $submittedToken);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ddAdminBookingUpdateRedirectBack('?error=1');
}

if (!ddAdminBookingUpdateValidateCsrf(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
    ddAdminBookingUpdateRedirectBack('?error=csrf');
}

if (!ddAdminBookingUpdateTableExists($pdo, 'bookings')) {
    ddAdminBookingUpdateRedirectBack('?error=1');
}

$bookingColumns = ddAdminBookingUpdateGetColumns($pdo, 'bookings');
if (empty($bookingColumns)) {
    ddAdminBookingUpdateRedirectBack('?error=1');
}

$idColumn = ddAdminBookingUpdateFirstExistingColumn($bookingColumns, array('id', 'booking_id'));
if ($idColumn === null) {
    ddAdminBookingUpdateRedirectBack('?error=1');
}

$bookingId = (int) ($_POST['booking_id'] ?? 0);
if ($bookingId <= 0) {
    ddAdminBookingUpdateRedirectBack('?error=1');
}

$allowedStatuses = array('pending', 'confirmed', 'in_progress', 'completed', 'cancelled');

$status = strtolower(trim((string) ($_POST['status'] ?? '')));
$serviceType = trim((string) ($_POST['service_type'] ?? ''));
$serviceDate = trim((string) ($_POST['service_date'] ?? ''));
$serviceTime = trim((string) ($_POST['service_time'] ?? ''));
$durationMinutes = trim((string) ($_POST['duration_minutes'] ?? ''));
$price = trim((string) ($_POST['price'] ?? ''));
$adminNotes = trim((string) ($_POST['admin_notes'] ?? ''));
$assignedWalkerIdRaw = trim((string) ($_POST['assigned_walker_id'] ?? ''));
$walkerName = trim((string) ($_POST['walker_name'] ?? ''));

$updateParts = array();
$params = array(':booking_id' => $bookingId);

$addUpdate = function (string $column, string $placeholder, $value) use (&$updateParts, &$params): void {
    $updateParts[] = ddAdminBookingUpdateQuoteIdentifier($column) . ' = ' . $placeholder;
    $params[$placeholder] = $value;
};

$statusColumn = ddAdminBookingUpdateFirstExistingColumn($bookingColumns, array('status'));
$serviceTypeColumn = ddAdminBookingUpdateFirstExistingColumn($bookingColumns, array('service_type', 'service'));
$serviceDateColumn = ddAdminBookingUpdateFirstExistingColumn($bookingColumns, array('service_date', 'booking_date', 'date'));
$serviceTimeColumn = ddAdminBookingUpdateFirstExistingColumn($bookingColumns, array('service_time', 'booking_time', 'time', 'start_time'));
$durationColumn = ddAdminBookingUpdateFirstExistingColumn($bookingColumns, array('duration_minutes', 'duration'));
$priceColumn = ddAdminBookingUpdateFirstExistingColumn($bookingColumns, array('price', 'amount', 'total_price', 'total'));
$adminNotesColumn = ddAdminBookingUpdateFirstExistingColumn($bookingColumns, array('admin_notes', 'notes'));
$assignedWalkerIdColumn = ddAdminBookingUpdateFirstExistingColumn($bookingColumns, array('assigned_walker_id', 'walker_id', 'worker_id', 'assigned_worker_id'));
$walkerNameColumn = ddAdminBookingUpdateFirstExistingColumn($bookingColumns, array('walker_name', 'worker_name', 'assigned_walker_name', 'assigned_worker_name'));
$statusUpdatedByColumn = ddAdminBookingUpdateFirstExistingColumn($bookingColumns, array('status_updated_by'));
$statusUpdatedAtColumn = ddAdminBookingUpdateFirstExistingColumn($bookingColumns, array('status_updated_at', 'updated_at'));

if ($status !== '' && in_array($status, $allowedStatuses, true) && $statusColumn !== null) {
    $addUpdate($statusColumn, ':status', $status);
}

if ($serviceType !== '' && $serviceTypeColumn !== null) {
    $addUpdate($serviceTypeColumn, ':service_type', $serviceType);
}

if ($serviceDate !== '' && $serviceDateColumn !== null) {
    $addUpdate($serviceDateColumn, ':service_date', $serviceDate);
}

if ($serviceTime !== '' && $serviceTimeColumn !== null) {
    $addUpdate($serviceTimeColumn, ':service_time', $serviceTime);
}

if ($durationMinutes !== '' && $durationColumn !== null) {
    $durationValue = (int) $durationMinutes;
    if ($durationValue > 0) {
        $addUpdate($durationColumn, ':duration_minutes', $durationValue);
    }
}

if ($price !== '' && $priceColumn !== null) {
    $priceValue = (float) $price;
    if ($priceValue >= 0) {
        $addUpdate($priceColumn, ':price', $priceValue);
    }
}

if ($adminNotesColumn !== null) {
    $addUpdate($adminNotesColumn, ':admin_notes', $adminNotes !== '' ? $adminNotes : null);
}

if ($assignedWalkerIdColumn !== null) {
    if ($assignedWalkerIdRaw !== '') {
        $assignedWalkerId = (int) $assignedWalkerIdRaw;
        $addUpdate($assignedWalkerIdColumn, ':assigned_walker_id', $assignedWalkerId > 0 ? $assignedWalkerId : null);
    } elseif (array_key_exists('assigned_walker_id', $_POST)) {
        $addUpdate($assignedWalkerIdColumn, ':assigned_walker_id', null);
    }
}

if ($walkerNameColumn !== null) {
    if ($walkerName !== '') {
        $addUpdate($walkerNameColumn, ':walker_name', $walkerName);
    } elseif (array_key_exists('walker_name', $_POST)) {
        $addUpdate($walkerNameColumn, ':walker_name', null);
    }
}

if ($statusUpdatedByColumn !== null) {
    $addUpdate($statusUpdatedByColumn, ':status_updated_by', 'admin');
}

if ($statusUpdatedAtColumn !== null) {
    $updateParts[] = ddAdminBookingUpdateQuoteIdentifier($statusUpdatedAtColumn) . ' = CURRENT_TIMESTAMP';
}

if (empty($updateParts)) {
    ddAdminBookingUpdateRedirectBack('?error=1');
}

$sql = '
    UPDATE ' . ddAdminBookingUpdateQuoteIdentifier('bookings') . '
    SET ' . implode(', ', $updateParts) . '
    WHERE ' . ddAdminBookingUpdateQuoteIdentifier($idColumn) . ' = :booking_id
';

try {
    $stmt = $pdo->prepare($sql);

    foreach ($params as $placeholder => $value) {
        if (is_int($value)) {
            $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
        } elseif (is_float($value)) {
            $stmt->bindValue($placeholder, (string) $value, PDO::PARAM_STR);
        } elseif ($value === null) {
            $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue($placeholder, $value, PDO::PARAM_STR);
        }
    }

    $stmt->execute();
} catch (Throwable $e) {
    ddAdminBookingUpdateRedirectBack('?error=1');
}

ddAdminBookingUpdateRedirectBack('?updated=1&highlight=' . $bookingId);