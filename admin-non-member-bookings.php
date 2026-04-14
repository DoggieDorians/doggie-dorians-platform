<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';

date_default_timezone_set('America/New_York');

$pdoConnection = null;

if (isset($pdo) && $pdo instanceof PDO) {
    $pdoConnection = $pdo;
} elseif (isset($db) && $db instanceof PDO) {
    $pdoConnection = $db;
}

if (!$pdoConnection instanceof PDO) {
    http_response_code(500);
    exit('Database connection not available.');
}

$pdoConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdoConnection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Admin protection
|--------------------------------------------------------------------------
*/
function dd_nm_redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function dd_nm_is_admin_session(): bool
{
    if (!empty($_SESSION['is_admin'])) {
        return true;
    }

    if (!empty($_SESSION['admin_id'])) {
        return true;
    }

    $role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    return in_array($role, ['admin', 'superadmin', 'owner'], true);
}

if (empty($_SESSION['user_id']) && empty($_SESSION['admin_id']) && empty($_SESSION['is_admin'])) {
    dd_nm_redirect('admin-login.php');
}

if (!dd_nm_is_admin_session()) {
    dd_nm_redirect('admin-dashboard.php');
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['admin_nonmember_list_csrf']) || !is_string($_SESSION['admin_nonmember_list_csrf'])) {
    $_SESSION['admin_nonmember_list_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['admin_nonmember_list_csrf'];

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function dd_nm_e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function dd_nm_qi(string $value): string
{
    return '"' . str_replace('"', '""', $value) . '"';
}

function dd_nm_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
        $stmt->execute([':table' => $table]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function dd_nm_get_columns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query('PRAGMA table_info(' . dd_nm_qi($table) . ')');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $columns = [];

        foreach ($rows as $row) {
            if (!empty($row['name'])) {
                $columns[] = (string) $row['name'];
            }
        }

        return $columns;
    } catch (Throwable $e) {
        return [];
    }
}

function dd_nm_first_existing_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function dd_nm_safe_fetch_all(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

function dd_nm_safe_fetch_one(PDO $pdo, string $sql, array $params = []): ?array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function dd_nm_safe_execute(PDOStatement $stmt, array $params = []): bool
{
    try {
        return $stmt->execute($params);
    } catch (Throwable $e) {
        return false;
    }
}

function dd_nm_request_status_class(string $status): string
{
    return match (strtolower(trim($status))) {
        'reviewed', 'confirmed'  => 'status reviewed',
        'approved', 'scheduled'  => 'status approved',
        'converted', 'completed' => 'status converted',
        'cancelled', 'canceled'  => 'status cancelled',
        default                  => 'status pending',
    };
}

function dd_nm_normalize_status_label(string $status): string
{
    $normalized = strtolower(trim($status));

    return match ($normalized) {
        'pending'   => 'Pending',
        'reviewed'  => 'Reviewed',
        'approved'  => 'Approved',
        'converted' => 'Converted',
        'confirmed' => 'Confirmed',
        'scheduled' => 'Scheduled',
        'completed' => 'Completed',
        'cancelled', 'canceled' => 'Cancelled',
        default => trim($status) !== '' ? ucwords(str_replace(['_', '-'], ' ', $status)) : 'Pending',
    };
}

function dd_nm_format_date(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return dd_nm_e($value);
    }

    return date('F j, Y', $timestamp);
}

function dd_nm_format_datetime(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return dd_nm_e($value);
    }

    return date('F j, Y g:i A', $timestamp);
}

function dd_nm_format_money(mixed $amount): string
{
    if ($amount === null || $amount === '') {
        return '—';
    }

    if (!is_numeric($amount)) {
        return dd_nm_e((string) $amount);
    }

    return '$' . number_format((float) $amount, 2);
}

function dd_nm_first_non_empty(array $row, array $keys): string
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && $row[$key] !== null && trim((string) $row[$key]) !== '') {
            return (string) $row[$key];
        }
    }

    return '';
}

/*
|--------------------------------------------------------------------------
| Detect table
|--------------------------------------------------------------------------
*/
$tableName = null;

foreach (['non_member_bookings', 'public_booking_requests', 'public_booking_submissions'] as $candidateTable) {
    if (dd_nm_table_exists($pdoConnection, $candidateTable)) {
        $tableName = $candidateTable;
        break;
    }
}

if ($tableName === null) {
    http_response_code(500);
    exit('No non-member booking table was found.');
}

$requestColumns = dd_nm_get_columns($pdoConnection, $tableName);

$idCol = dd_nm_first_existing_column($requestColumns, ['id', 'request_id']);
$statusCol = dd_nm_first_existing_column($requestColumns, ['status']);
$createdAtCol = dd_nm_first_existing_column($requestColumns, ['created_at', 'submitted_at', 'created_on']);
$updatedAtCol = dd_nm_first_existing_column($requestColumns, ['updated_at', 'modified_at']);
$fullNameCol = dd_nm_first_existing_column($requestColumns, ['full_name', 'name', 'client_name']);
$emailCol = dd_nm_first_existing_column($requestColumns, ['email']);
$phoneCol = dd_nm_first_existing_column($requestColumns, ['phone', 'phone_number']);
$petNameCol = dd_nm_first_existing_column($requestColumns, ['pet_name', 'dog_name', 'name']);
$petBreedCol = dd_nm_first_existing_column($requestColumns, ['pet_breed', 'breed']);
$petSizeCol = dd_nm_first_existing_column($requestColumns, ['pet_size', 'dog_size', 'size']);
$serviceTypeCol = dd_nm_first_existing_column($requestColumns, ['service_type', 'service']);
$serviceDateCol = dd_nm_first_existing_column($requestColumns, ['service_date', 'date_start', 'booking_date']);
$serviceTimeCol = dd_nm_first_existing_column($requestColumns, ['service_time', 'preferred_walk_time', 'booking_time']);
$durationCol = dd_nm_first_existing_column($requestColumns, ['duration_minutes', 'walk_duration']);
$feedingScheduleCol = dd_nm_first_existing_column($requestColumns, ['feeding_schedule']);
$notesCol = dd_nm_first_existing_column($requestColumns, ['notes', 'note']);
$priceCol = dd_nm_first_existing_column($requestColumns, ['price', 'estimated_price', 'total_price']);
$bookingSourceCol = dd_nm_first_existing_column($requestColumns, ['booking_source']);

if ($idCol === null) {
    http_response_code(500);
    exit('The non-member booking table is missing an ID column.');
}

/*
|--------------------------------------------------------------------------
| Status system
|--------------------------------------------------------------------------
*/
$legacyStatuses = ['pending', 'reviewed', 'approved', 'converted', 'cancelled'];
$newStatuses = ['Pending', 'Confirmed', 'Scheduled', 'Completed', 'Cancelled'];

$usesLegacyWorkflow = in_array($tableName, ['public_booking_requests', 'public_booking_submissions'], true);

$allowedFilterStatuses = $usesLegacyWorkflow
    ? ['all', 'pending', 'reviewed', 'approved', 'converted', 'cancelled']
    : ['all', 'pending', 'confirmed', 'scheduled', 'completed', 'cancelled'];

$currentFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
if (!in_array($currentFilter, $allowedFilterStatuses, true)) {
    $currentFilter = 'all';
}

/*
|--------------------------------------------------------------------------
| Base WHERE clauses
|--------------------------------------------------------------------------
*/
$baseWhereParts = [];
$baseParams = [];

if ($bookingSourceCol !== null) {
    $baseWhereParts[] = "COALESCE(" . dd_nm_qi($bookingSourceCol) . ", 'non-member') = 'non-member'";
}

$baseWhereSql = '';
if (!empty($baseWhereParts)) {
    $baseWhereSql = ' WHERE ' . implode(' AND ', $baseWhereParts);
}

/*
|--------------------------------------------------------------------------
| Inline status update
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $newStatus = trim((string) ($_POST['new_status'] ?? ''));

    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        header('Location: admin-non-member-bookings.php?error=1&status=' . urlencode($currentFilter));
        exit;
    }

    $validStatuses = $usesLegacyWorkflow ? $legacyStatuses : $newStatuses;

    if ($requestId <= 0 || !in_array($newStatus, $validStatuses, true)) {
        header('Location: admin-non-member-bookings.php?error=1&status=' . urlencode($currentFilter));
        exit;
    }

    if ($statusCol !== null) {
        $setParts = [
            dd_nm_qi($statusCol) . ' = :status',
        ];
        $params = [
            ':status' => $newStatus,
            ':id' => $requestId,
        ];

        if ($updatedAtCol !== null) {
            $setParts[] = dd_nm_qi($updatedAtCol) . ' = :updated_at';
            $params[':updated_at'] = date('Y-m-d H:i:s');
        }

        $stmt = $pdoConnection->prepare("
            UPDATE " . dd_nm_qi($tableName) . "
            SET " . implode(', ', $setParts) . "
            WHERE " . dd_nm_qi($idCol) . " = :id
        ");

        if (dd_nm_safe_execute($stmt, $params)) {
            header('Location: admin-non-member-bookings.php?updated=1&status=' . urlencode($currentFilter));
            exit;
        }
    }

    header('Location: admin-non-member-bookings.php?error=1&status=' . urlencode($currentFilter));
    exit;
}

/*
|--------------------------------------------------------------------------
| Flash messages
|--------------------------------------------------------------------------
*/
$flashMessage = '';
$flashType = 'success';

if (isset($_GET['updated'])) {
    $flashMessage = 'Non-member booking updated successfully.';
} elseif (isset($_GET['error'])) {
    $flashMessage = 'Something went wrong while updating the non-member booking.';
    $flashType = 'error';
}

/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/
$summary = [
    'total' => 0,
    'pending' => 0,
    'reviewed' => 0,
    'approved' => 0,
    'converted' => 0,
    'confirmed' => 0,
    'scheduled' => 0,
    'completed' => 0,
    'cancelled' => 0,
];

$totalSql = 'SELECT COUNT(*) AS total_count FROM ' . dd_nm_qi($tableName) . $baseWhereSql;
$totalRow = dd_nm_safe_fetch_one($pdoConnection, $totalSql, $baseParams);
$summary['total'] = (int) ($totalRow['total_count'] ?? 0);

if ($statusCol !== null) {
    $statusSummarySql = '
        SELECT
            LOWER(COALESCE(' . dd_nm_qi($statusCol) . ", '')) AS status_key,
            COUNT(*) AS row_count
        FROM " . dd_nm_qi($tableName) .
        $baseWhereSql . '
        GROUP BY LOWER(COALESCE(' . dd_nm_qi($statusCol) . ", ''))
    ";

    $statusRows = dd_nm_safe_fetch_all($pdoConnection, $statusSummarySql, $baseParams);

    foreach ($statusRows as $statusRow) {
        $key = strtolower(trim((string) ($statusRow['status_key'] ?? '')));
        $count = (int) ($statusRow['row_count'] ?? 0);

        switch ($key) {
            case 'pending':
                $summary['pending'] = $count;
                break;
            case 'reviewed':
                $summary['reviewed'] = $count;
                break;
            case 'approved':
                $summary['approved'] = $count;
                break;
            case 'converted':
                $summary['converted'] = $count;
                break;
            case 'confirmed':
                $summary['confirmed'] = $count;
                break;
            case 'scheduled':
                $summary['scheduled'] = $count;
                break;
            case 'completed':
                $summary['completed'] = $count;
                break;
            case 'cancelled':
            case 'canceled':
                $summary['cancelled'] += $count;
                break;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/
$whereParts = $baseWhereParts;
$params = $baseParams;

if ($currentFilter !== 'all' && $statusCol !== null) {
    $whereParts[] = "LOWER(COALESCE(" . dd_nm_qi($statusCol) . ", '')) = :status_filter";
    $params[':status_filter'] = $currentFilter;
}

$whereSql = '';
if (!empty($whereParts)) {
    $whereSql = 'WHERE ' . implode(' AND ', $whereParts);
}

/*
|--------------------------------------------------------------------------
| Load rows
|--------------------------------------------------------------------------
*/
$selectParts = [
    dd_nm_qi($idCol) . ' AS id',
    ($fullNameCol !== null ? dd_nm_qi($fullNameCol) : "''") . ' AS full_name',
    ($emailCol !== null ? dd_nm_qi($emailCol) : "''") . ' AS email',
    ($phoneCol !== null ? dd_nm_qi($phoneCol) : "''") . ' AS phone',
    ($petNameCol !== null ? dd_nm_qi($petNameCol) : "''") . ' AS pet_name',
    ($petBreedCol !== null ? dd_nm_qi($petBreedCol) : "''") . ' AS pet_breed',
    ($petSizeCol !== null ? dd_nm_qi($petSizeCol) : "''") . ' AS pet_size',
    ($serviceTypeCol !== null ? dd_nm_qi($serviceTypeCol) : "''") . ' AS service_type',
    ($serviceDateCol !== null ? dd_nm_qi($serviceDateCol) : "''") . ' AS service_date',
    ($serviceTimeCol !== null ? dd_nm_qi($serviceTimeCol) : "''") . ' AS service_time',
    ($durationCol !== null ? dd_nm_qi($durationCol) : 'NULL') . ' AS duration_minutes',
    ($feedingScheduleCol !== null ? dd_nm_qi($feedingScheduleCol) : "''") . ' AS feeding_schedule',
    ($notesCol !== null ? dd_nm_qi($notesCol) : "''") . ' AS notes',
    ($priceCol !== null ? dd_nm_qi($priceCol) : 'NULL') . ' AS price',
    ($statusCol !== null ? dd_nm_qi($statusCol) : "'Pending'") . ' AS status',
    ($createdAtCol !== null ? dd_nm_qi($createdAtCol) : "''") . ' AS created_at',
];

$sql = "
    SELECT " . implode(",\n           ", $selectParts) . "
    FROM " . dd_nm_qi($tableName) . "
    {$whereSql}
    ORDER BY " . ($createdAtCol !== null ? dd_nm_qi($createdAtCol) . ' DESC, ' : '') . dd_nm_qi($idCol) . " DESC
";

$requests = dd_nm_safe_fetch_all($pdoConnection, $sql, $params);

$summaryCards = $usesLegacyWorkflow
    ? [
        ['label' => 'Total Requests', 'value' => $summary['total'], 'note' => 'All non-member requests received'],
        ['label' => 'Pending', 'value' => $summary['pending'], 'note' => 'New requests awaiting review'],
        ['label' => 'Reviewed', 'value' => $summary['reviewed'], 'note' => 'Checked by admin'],
        ['label' => 'Approved', 'value' => $summary['approved'], 'note' => 'Ready for next action'],
        ['label' => 'Converted', 'value' => $summary['converted'], 'note' => 'Moved into core workflow'],
        ['label' => 'Cancelled', 'value' => $summary['cancelled'], 'note' => 'Closed requests'],
    ]
    : [
        ['label' => 'Total Requests', 'value' => $summary['total'], 'note' => 'All non-member requests received'],
        ['label' => 'Pending', 'value' => $summary['pending'], 'note' => 'New requests awaiting review'],
        ['label' => 'Confirmed', 'value' => $summary['confirmed'], 'note' => 'Confirmed by admin'],
        ['label' => 'Scheduled', 'value' => $summary['scheduled'], 'note' => 'Ready for service'],
        ['label' => 'Completed', 'value' => $summary['completed'], 'note' => 'Service completed'],
        ['label' => 'Cancelled', 'value' => $summary['cancelled'], 'note' => 'Closed requests'],
    ];

$filterPills = $usesLegacyWorkflow
    ? [
        ['key' => 'all', 'label' => 'All'],
        ['key' => 'pending', 'label' => 'Pending'],
        ['key' => 'reviewed', 'label' => 'Reviewed'],
        ['key' => 'approved', 'label' => 'Approved'],
        ['key' => 'converted', 'label' => 'Converted'],
        ['key' => 'cancelled', 'label' => 'Cancelled'],
    ]
    : [
        ['key' => 'all', 'label' => 'All'],
        ['key' => 'pending', 'label' => 'Pending'],
        ['key' => 'confirmed', 'label' => 'Confirmed'],
        ['key' => 'scheduled', 'label' => 'Scheduled'],
        ['key' => 'completed', 'label' => 'Completed'],
        ['key' => 'cancelled', 'label' => 'Cancelled'],
    ];

$statusOptions = $usesLegacyWorkflow ? $legacyStatuses : $newStatuses;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Non-Member Bookings | Doggie Dorian’s Admin</title>
  <style>
    * { box-sizing: border-box; }

    :root {
      --bg: #070810;
      --panel: #11131b;
      --line: rgba(255,255,255,0.08);
      --text: #f7f4ee;
      --muted: rgba(247,244,238,0.68);
      --gold: #d4af37;
      --green: #5ed39a;
      --red: #ff9898;
      --blue: #66b3ff;
      --shadow: 0 20px 60px rgba(0,0,0,0.35);
    }

    body {
      margin: 0;
      background:
        radial-gradient(circle at top left, rgba(212,175,55,0.08), transparent 28%),
        linear-gradient(180deg, #090b13 0%, #05060b 100%);
      color: var(--text);
      font-family: Arial, Helvetica, sans-serif;
    }

    .wrap {
      max-width: 1460px;
      margin: 0 auto;
      padding: 34px 22px 60px;
    }

    .topbar {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 20px;
      flex-wrap: wrap;
      margin-bottom: 26px;
    }

    .eyebrow {
      color: var(--gold);
      letter-spacing: 0.14em;
      text-transform: uppercase;
      font-size: 12px;
      font-weight: 700;
      margin-bottom: 10px;
    }

    h1 {
      margin: 0;
      font-size: 42px;
      line-height: 1;
      letter-spacing: -0.03em;
    }

    .subtext {
      margin-top: 12px;
      color: var(--muted);
      font-size: 15px;
      max-width: 760px;
      line-height: 1.6;
    }

    .top-actions {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }

    .top-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 46px;
      padding: 0 18px;
      border-radius: 999px;
      text-decoration: none;
      font-weight: 700;
      border: 1px solid var(--line);
      background: rgba(255,255,255,0.02);
      color: var(--text);
    }

    .top-btn.primary {
      background: var(--gold);
      color: #0a0a0f;
      border-color: var(--gold);
    }

    .flash {
      margin-bottom: 22px;
      padding: 15px 18px;
      border-radius: 16px;
      font-weight: 700;
      box-shadow: var(--shadow);
    }

    .flash.success {
      background: rgba(94,211,154,0.12);
      border: 1px solid rgba(94,211,154,0.3);
      color: #c8ffe2;
    }

    .flash.error {
      background: rgba(255,140,140,0.12);
      border: 1px solid rgba(255,140,140,0.28);
      color: #ffd0d0;
    }

    .summary-grid {
      display: grid;
      grid-template-columns: repeat(6, minmax(0, 1fr));
      gap: 16px;
      margin-bottom: 24px;
    }

    .card {
      background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));
      border: 1px solid var(--line);
      border-radius: 20px;
      padding: 20px;
      box-shadow: var(--shadow);
    }

    .card-label {
      color: var(--muted);
      font-size: 12px;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      margin-bottom: 10px;
      font-weight: 700;
    }

    .card-value {
      font-size: 34px;
      font-weight: 800;
      letter-spacing: -0.03em;
    }

    .card-note {
      margin-top: 8px;
      color: var(--muted);
      font-size: 13px;
    }

    .panel {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 24px;
      box-shadow: var(--shadow);
      overflow: hidden;
    }

    .panel-head {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      padding: 22px 22px 18px;
      border-bottom: 1px solid var(--line);
      flex-wrap: wrap;
      background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.01));
    }

    .panel-title {
      font-size: 24px;
      font-weight: 800;
      margin: 0;
    }

    .panel-subtitle {
      color: var(--muted);
      font-size: 14px;
      margin-top: 6px;
    }

    .filters {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }

    .filter-pill {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 40px;
      padding: 0 16px;
      border-radius: 999px;
      text-decoration: none;
      color: var(--text);
      border: 1px solid var(--line);
      background: rgba(255,255,255,0.02);
      font-weight: 700;
      font-size: 14px;
    }

    .filter-pill.active {
      background: var(--gold);
      color: #0a0a0f;
      border-color: var(--gold);
    }

    .table-wrap {
      width: 100%;
      overflow-x: auto;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 1540px;
    }

    thead th {
      color: var(--muted);
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.12em;
      font-weight: 700;
      text-align: left;
      padding: 16px 18px;
      border-bottom: 1px solid var(--line);
      background: rgba(255,255,255,0.01);
    }

    tbody td {
      padding: 18px;
      border-bottom: 1px solid var(--line);
      vertical-align: top;
    }

    tbody tr:hover {
      background: rgba(255,255,255,0.015);
    }

    .primary-text {
      font-weight: 700;
      font-size: 15px;
      color: var(--text);
    }

    .secondary-text {
      margin-top: 6px;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.5;
      white-space: pre-wrap;
    }

    .status {
      display: inline-flex;
      align-items: center;
      border-radius: 999px;
      padding: 7px 12px;
      font-size: 12px;
      font-weight: 800;
      text-transform: capitalize;
      letter-spacing: 0.04em;
    }

    .status.pending {
      background: rgba(143,149,163,0.18);
      color: #e5e7ee;
    }

    .status.reviewed {
      background: rgba(102,179,255,0.18);
      color: #d8ecff;
    }

    .status.approved {
      background: rgba(94,211,154,0.18);
      color: #c8ffe2;
    }

    .status.converted {
      background: rgba(212,175,55,0.16);
      color: #f6de88;
    }

    .status.cancelled {
      background: rgba(255,140,140,0.15);
      color: #ffd0d0;
    }

    .actions {
      display: grid;
      gap: 8px;
      min-width: 180px;
    }

    .actions form {
      margin: 0;
    }

    .actions button,
    .actions a {
      width: 100%;
      min-height: 36px;
      border-radius: 10px;
      border: 1px solid var(--line);
      background: rgba(255,255,255,0.04);
      color: var(--text);
      font-weight: 800;
      cursor: pointer;
      font-size: 13px;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 0 12px;
    }

    .actions button:hover,
    .actions a:hover {
      border-color: rgba(212,175,55,0.26);
    }

    .empty-state {
      padding: 44px 22px;
      text-align: center;
      color: var(--muted);
    }

    .empty-state strong {
      display: block;
      color: var(--text);
      font-size: 18px;
      margin-bottom: 10px;
    }

    @media (max-width: 1200px) {
      .summary-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }
    }

    @media (max-width: 700px) {
      .summary-grid {
        grid-template-columns: 1fr;
      }

      h1 {
        font-size: 32px;
      }

      .wrap {
        padding: 24px 14px 46px;
      }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="topbar">
      <div>
        <div class="eyebrow">Doggie Dorian’s Admin</div>
        <h1>Non-Member Bookings</h1>
        <div class="subtext">
          Review public booking submissions, organize intake status, and keep non-member requests coordinated before conversion into live client workflows.
        </div>
      </div>

      <div class="top-actions">
        <a href="admin-dashboard.php" class="top-btn">Dashboard</a>
        <a href="admin-revenue.php" class="top-btn">Revenue</a>
        <a href="admin-bookings.php" class="top-btn">Main Bookings</a>
        <a href="admin-members.php" class="top-btn primary">Members</a>
      </div>
    </div>

    <?php if ($flashMessage !== ''): ?>
      <div class="flash <?php echo dd_nm_e($flashType); ?>">
        <?php echo dd_nm_e($flashMessage); ?>
      </div>
    <?php endif; ?>

    <div class="summary-grid">
      <?php foreach ($summaryCards as $card): ?>
        <div class="card">
          <div class="card-label"><?php echo dd_nm_e($card['label']); ?></div>
          <div class="card-value"><?php echo (int) $card['value']; ?></div>
          <div class="card-note"><?php echo dd_nm_e($card['note']); ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="panel">
      <div class="panel-head">
        <div>
          <h2 class="panel-title">Request Queue</h2>
          <div class="panel-subtitle">
            Review incoming public bookings and update their stage in the intake process.
          </div>
        </div>

        <div class="filters">
          <?php foreach ($filterPills as $pill): ?>
            <a
              class="filter-pill <?php echo $currentFilter === $pill['key'] ? 'active' : ''; ?>"
              href="admin-non-member-bookings.php?status=<?php echo urlencode($pill['key']); ?>"
            >
              <?php echo dd_nm_e($pill['label']); ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="table-wrap">
        <?php if (!$requests): ?>
          <div class="empty-state">
            <strong>No non-member bookings found</strong>
            There are no requests in this filter right now.
          </div>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Client</th>
                <th>Pet</th>
                <th>Service</th>
                <th>Schedule</th>
                <th>Price</th>
                <th>Notes</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($requests as $request): ?>
                <?php
                  $clientName = dd_nm_first_non_empty($request, ['full_name', 'name', 'client_name']);
                  $requestNotes = dd_nm_first_non_empty($request, ['notes', 'note']);
                  $requestStatus = dd_nm_normalize_status_label((string) ($request['status'] ?? 'Pending'));
                ?>
                <tr>
                  <td>
                    <div class="primary-text">#<?php echo (int) $request['id']; ?></div>
                    <div class="secondary-text"><?php echo dd_nm_format_datetime((string) ($request['created_at'] ?? '')); ?></div>
                  </td>

                  <td>
                    <div class="primary-text"><?php echo dd_nm_e($clientName !== '' ? $clientName : 'Unknown client'); ?></div>
                    <div class="secondary-text">
                      <?php echo dd_nm_e((string) ($request['email'] ?? '')); ?><br>
                      <?php echo dd_nm_e((string) ($request['phone'] ?? '')); ?>
                    </div>
                  </td>

                  <td>
                    <div class="primary-text"><?php echo dd_nm_e((string) ($request['pet_name'] ?? '')); ?></div>
                    <div class="secondary-text">
                      <?php echo dd_nm_e((string) ($request['pet_breed'] ?? '')); ?>
                      <?php if (!empty($request['pet_size'])): ?>
                        <br><?php echo dd_nm_e((string) $request['pet_size']); ?>
                      <?php endif; ?>
                    </div>
                  </td>

                  <td>
                    <div class="primary-text"><?php echo dd_nm_e(ucwords(str_replace(['_', '-'], ' ', (string) ($request['service_type'] ?? '')))); ?></div>
                    <div class="secondary-text">
                      <?php
                        $duration = $request['duration_minutes'] ?? null;
                        echo $duration ? dd_nm_e((string) $duration . ' min') : 'No duration';
                      ?>
                    </div>
                  </td>

                  <td>
                    <div class="primary-text"><?php echo dd_nm_format_date((string) ($request['service_date'] ?? '')); ?></div>
                    <div class="secondary-text"><?php echo dd_nm_e((string) ($request['service_time'] ?? '')); ?></div>
                  </td>

                  <td>
                    <div class="primary-text"><?php echo dd_nm_format_money($request['price'] ?? null); ?></div>
                  </td>

                  <td>
                    <div class="secondary-text"><?php echo dd_nm_e((string) ($request['feeding_schedule'] ?? '')); ?></div>
                    <?php if ($requestNotes !== ''): ?>
                      <div class="secondary-text" style="margin-top:8px;"><?php echo dd_nm_e($requestNotes); ?></div>
                    <?php endif; ?>
                  </td>

                  <td>
                    <span class="<?php echo dd_nm_e(dd_nm_request_status_class((string) ($request['status'] ?? 'pending'))); ?>">
                      <?php echo dd_nm_e($requestStatus); ?>
                    </span>
                  </td>

                  <td>
                    <div class="actions">
                      <a href="admin-non-member-bookings-view.php?id=<?php echo (int) $request['id']; ?>">View</a>

                      <?php foreach ($statusOptions as $statusOption): ?>
                        <?php if (strtolower(trim($statusOption)) === strtolower(trim((string) ($request['status'] ?? '')))) continue; ?>
                        <form method="post">
                          <input type="hidden" name="csrf_token" value="<?php echo dd_nm_e($csrfToken); ?>">
                          <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                          <input type="hidden" name="new_status" value="<?php echo dd_nm_e($statusOption); ?>">
                          <button type="submit"><?php echo dd_nm_e($statusOption); ?></button>
                        </form>
                      <?php endforeach; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>