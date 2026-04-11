<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/database/setup.php';
require_once __DIR__ . '/admin-auth.php';

function redirectBack(string $query = ''): void
{
    header('Location: admin-bookings.php' . $query);
    exit;
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1");
    $stmt->execute(['table' => $table]);
    return (bool) $stmt->fetchColumn();
}

function getColumns(PDO $pdo, string $table): array
{
    $columns = [];
    $stmt = $pdo->query("PRAGMA table_info($table)");
    if ($stmt) {
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[] = $column['name'];
        }
    }
    return $columns;
}

function hasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectBack('?error=1');
}

if (!tableExists($pdo, 'bookings')) {
    redirectBack('?error=1');
}

$bookingColumns = getColumns($pdo, 'bookings');

$bookingId = (int) ($_POST['booking_id'] ?? 0);
if ($bookingId <= 0) {
    redirectBack('?error=1');
}

$allowedStatuses = ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled'];
$status = trim((string) ($_POST['status'] ?? ''));
$serviceType = trim((string) ($_POST['service_type'] ?? ''));
$serviceDate = trim((string) ($_POST['service_date'] ?? ''));
$serviceTime = trim((string) ($_POST['service_time'] ?? ''));
$durationMinutes = trim((string) ($_POST['duration_minutes'] ?? ''));
$price = trim((string) ($_POST['price'] ?? ''));
$adminNotes = trim((string) ($_POST['admin_notes'] ?? ''));
$assignedWalkerIdRaw = trim((string) ($_POST['assigned_walker_id'] ?? ''));
$walkerName = trim((string) ($_POST['walker_name'] ?? ''));

$updateParts = [];
$params = ['id' => $bookingId];

$addUpdate = function (string $column, string $placeholder, $value) use (&$updateParts, &$params): void {
    $updateParts[] = $column . ' = ' . $placeholder;
    $params[ltrim($placeholder, ':')] = $value;
};

if ($status !== '' && in_array($status, $allowedStatuses, true) && hasColumn($bookingColumns, 'status')) {
    $addUpdate('status', ':status', $status);
}

if ($serviceType !== '' && hasColumn($bookingColumns, 'service_type')) {
    $addUpdate('service_type', ':service_type', $serviceType);
}

if ($serviceDate !== '' && hasColumn($bookingColumns, 'service_date')) {
    $addUpdate('service_date', ':service_date', $serviceDate);
}

if ($serviceTime !== '' && hasColumn($bookingColumns, 'service_time')) {
    $addUpdate('service_time', ':service_time', $serviceTime);
}

if ($durationMinutes !== '' && hasColumn($bookingColumns, 'duration_minutes')) {
    $durationValue = (int) $durationMinutes;
    if ($durationValue > 0) {
        $addUpdate('duration_minutes', ':duration_minutes', $durationValue);
    }
}

if ($price !== '' && hasColumn($bookingColumns, 'price')) {
    $priceValue = (float) $price;
    if ($priceValue >= 0) {
        $addUpdate('price', ':price', $priceValue);
    }
}

if (hasColumn($bookingColumns, 'admin_notes')) {
    $addUpdate('admin_notes', ':admin_notes', $adminNotes !== '' ? $adminNotes : null);
}

if (hasColumn($bookingColumns, 'assigned_walker_id')) {
    if ($assignedWalkerIdRaw !== '') {
        $assignedWalkerId = (int) $assignedWalkerIdRaw;
        $addUpdate('assigned_walker_id', ':assigned_walker_id', $assignedWalkerId > 0 ? $assignedWalkerId : null);
    } elseif (isset($_POST['assigned_walker_id'])) {
        $addUpdate('assigned_walker_id', ':assigned_walker_id', null);
    }
}

if (hasColumn($bookingColumns, 'walker_name')) {
    if ($walkerName !== '') {
        $addUpdate('walker_name', ':walker_name', $walkerName);
    } elseif (isset($_POST['walker_name'])) {
        $addUpdate('walker_name', ':walker_name', null);
    }
}

if (hasColumn($bookingColumns, 'status_updated_by')) {
    $addUpdate('status_updated_by', ':status_updated_by', 'admin');
}

if (hasColumn($bookingColumns, 'status_updated_at')) {
    $updateParts[] = 'status_updated_at = CURRENT_TIMESTAMP';
}

if (!$updateParts) {
    redirectBack('?error=1');
}

$sql = "
    UPDATE bookings
    SET " . implode(', ', $updateParts) . "
    WHERE id = :id
";

$stmt = $pdo->prepare($sql);

foreach ($params as $key => $value) {
    $placeholder = ':' . $key;

    if (is_int($value)) {
        $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
    } elseif ($value === null) {
        $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue($placeholder, $value);
    }
}

$stmt->execute();

redirectBack('?updated=1&highlight=' . $bookingId);