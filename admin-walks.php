<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection not available.');
}

/* ==========================================================================
   ACCESS CONTROL
   ========================================================================== */

function dd_walks_redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function dd_walks_is_admin_session(): bool
{
    if (!empty($_SESSION['is_admin'])) {
        return true;
    }

    if (!empty($_SESSION['admin_id'])) {
        return true;
    }

    if (!empty($_SESSION['admin_logged_in'])) {
        return true;
    }

    $roleCandidates = [
        $_SESSION['role'] ?? null,
        $_SESSION['user_role'] ?? null,
        $_SESSION['account_role'] ?? null,
        $_SESSION['account_type'] ?? null,
    ];

    foreach ($roleCandidates as $role) {
        if (!is_string($role)) {
            continue;
        }

        $normalized = strtolower(trim($role));
        if (in_array($normalized, ['admin', 'superadmin', 'owner'], true)) {
            return true;
        }
    }

    return false;
}

if (empty($_SESSION['user_id']) && empty($_SESSION['admin_id']) && empty($_SESSION['is_admin']) && empty($_SESSION['admin_logged_in'])) {
    dd_walks_redirect('admin-login.php');
}

if (!dd_walks_is_admin_session()) {
    dd_walks_redirect('admin-dashboard.php');
}

/* ==========================================================================
   CSRF
   ========================================================================== */

if (empty($_SESSION['admin_walks_csrf']) || !is_string($_SESSION['admin_walks_csrf'])) {
    $_SESSION['admin_walks_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['admin_walks_csrf'];

/* ==========================================================================
   HELPERS
   ========================================================================== */

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function dd_walks_qi(string $value): string
{
    return '"' . str_replace('"', '""', $value) . '"';
}

function dd_walks_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1");
        $stmt->execute([':table' => $table]);
        $cache[$table] = (bool) $stmt->fetchColumn();
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = false;
        return false;
    }
}

function dd_walks_get_columns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    if (!dd_walks_table_exists($pdo, $table)) {
        $cache[$table] = [];
        return [];
    }

    try {
        $stmt = $pdo->query('PRAGMA table_info(' . dd_walks_qi($table) . ')');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $columns = [];

        foreach ($rows as $row) {
            if (!empty($row['name'])) {
                $columns[] = (string) $row['name'];
            }
        }

        $cache[$table] = $columns;
        return $columns;
    } catch (Throwable $e) {
        $cache[$table] = [];
        return [];
    }
}

function dd_walks_has_column(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function dd_walks_first_existing_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function dd_walks_safe_fetch_all(PDO $pdo, string $sql, array $params = []): array
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

function dd_walks_safe_execute(PDOStatement $stmt, array $params = []): bool
{
    try {
        return $stmt->execute($params);
    } catch (Throwable $e) {
        return false;
    }
}

function dd_walks_value_from_row(array $row, array $keys, mixed $default = null): mixed
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
            return $row[$key];
        }
    }

    return $default;
}

function dd_walks_build_name(array $row): string
{
    $full = trim((string) dd_walks_value_from_row($row, [
        'full_name',
        'name',
        'display_name',
        'client_name',
        'member_name',
        'worker_name',
        'walker_name',
        'pet_name',
        'dog_name',
        'username',
        'email',
    ], ''));

    if ($full !== '') {
        return $full;
    }

    $first = trim((string) ($row['first_name'] ?? ''));
    $last = trim((string) ($row['last_name'] ?? ''));

    $combined = trim($first . ' ' . $last);
    return $combined !== '' ? $combined : 'Unknown';
}

function dd_walks_normalize_status(string $status): string
{
    $normalized = strtolower(trim($status));
    $normalized = str_replace([' ', '-'], '_', $normalized);

    return match ($normalized) {
        '' => 'pending',
        'confirmed' => 'confirmed',
        'in_progress', 'inprogress', 'active', 'started' => 'in_progress',
        'completed', 'done' => 'completed',
        'cancelled', 'canceled' => 'cancelled',
        default => $normalized,
    };
}

function walkStatusClass(string $status): string
{
    return match (dd_walks_normalize_status($status)) {
        'confirmed'   => 'status confirmed',
        'in_progress' => 'status in-progress',
        'completed'   => 'status completed',
        'cancelled'   => 'status cancelled',
        default       => 'status pending',
    };
}

function dd_walks_human_status(string $status): string
{
    return match (dd_walks_normalize_status($status)) {
        'confirmed'   => 'Confirmed',
        'in_progress' => 'In Progress',
        'completed'   => 'Completed',
        'cancelled'   => 'Cancelled',
        default       => 'Pending',
    };
}

function dd_walks_format_date(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return e($value);
    }

    return date('F j, Y', $timestamp);
}

function dd_walks_format_datetime(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return e($value);
    }

    return date('F j, Y g:i A', $timestamp);
}

function dd_walks_format_money(mixed $amount): string
{
    if ($amount === null || $amount === '') {
        return '—';
    }

    if (!is_numeric($amount)) {
        return e((string) $amount);
    }

    return '$' . number_format((float) $amount, 2);
}

function dd_walks_is_walk_service(string $serviceType): bool
{
    $normalized = strtolower(trim($serviceType));
    if ($normalized === '') {
        return true;
    }

    return str_contains($normalized, 'walk');
}

/* ==========================================================================
   LOOKUPS
   ========================================================================== */

function dd_walks_build_lookup(PDO $pdo, array $sourceTables, array $idCandidates, array $nameCandidates, array $extraCandidates = []): array
{
    $lookup = [];

    foreach ($sourceTables as $table) {
        if (!dd_walks_table_exists($pdo, $table)) {
            continue;
        }

        $columns = dd_walks_get_columns($pdo, $table);
        if ($columns === []) {
            continue;
        }

        $idCol = dd_walks_first_existing_column($columns, $idCandidates);
        if ($idCol === null) {
            continue;
        }

        $orderCol = dd_walks_first_existing_column($columns, ['created_at', 'joined_at', 'date_created', $idCol]) ?? $idCol;
        $rows = dd_walks_safe_fetch_all(
            $pdo,
            'SELECT * FROM ' . dd_walks_qi($table) . ' ORDER BY ' . dd_walks_qi($orderCol) . ' DESC'
        );

        foreach ($rows as $row) {
            $id = (int) ($row[$idCol] ?? 0);
            if ($id <= 0) {
                continue;
            }

            if (isset($lookup[$id]) && trim((string) $lookup[$id]['label']) !== '') {
                continue;
            }

            $label = '';
            foreach ($nameCandidates as $candidate) {
                if (isset($row[$candidate]) && trim((string) $row[$candidate]) !== '') {
                    $label = trim((string) $row[$candidate]);
                    break;
                }
            }

            if ($label === '') {
                $label = dd_walks_build_name($row);
            }

            $extra = [];
            foreach ($extraCandidates as $extraKey => $candidateList) {
                $extra[$extraKey] = dd_walks_value_from_row($row, $candidateList, '');
            }

            $lookup[$id] = [
                'label' => $label !== '' ? $label : 'Unknown',
                'extra' => $extra,
            ];
        }
    }

    return $lookup;
}

function dd_walks_build_worker_lookup(PDO $pdo): array
{
    $lookup = [];

    foreach (['workers', 'walkers', 'users'] as $table) {
        if (!dd_walks_table_exists($pdo, $table)) {
            continue;
        }

        $columns = dd_walks_get_columns($pdo, $table);
        if ($columns === []) {
            continue;
        }

        $idCol = dd_walks_first_existing_column($columns, ['id', 'worker_id', 'walker_id', 'user_id']);
        if ($idCol === null) {
            continue;
        }

        $rows = dd_walks_safe_fetch_all(
            $pdo,
            'SELECT * FROM ' . dd_walks_qi($table) . ' ORDER BY ' . dd_walks_qi($idCol) . ' DESC'
        );

        foreach ($rows as $row) {
            if ($table === 'users') {
                $role = strtolower(trim((string) dd_walks_value_from_row($row, ['role', 'user_role', 'account_role', 'account_type'], '')));
                if (!in_array($role, ['walker', 'worker', 'staff', 'employee'], true)) {
                    continue;
                }
            }

            $id = (int) ($row[$idCol] ?? 0);
            if ($id <= 0 || isset($lookup[$id])) {
                continue;
            }

            $lookup[$id] = [
                'label' => dd_walks_build_name($row),
                'email' => (string) dd_walks_value_from_row($row, ['email'], ''),
            ];
        }
    }

    return $lookup;
}

$clientLookup = dd_walks_build_lookup(
    $pdo,
    ['users', 'members', 'client_profiles'],
    ['id', 'user_id', 'member_id', 'client_id'],
    ['full_name', 'name', 'client_name', 'member_name', 'username', 'email'],
    ['email' => ['email']]
);

$petLookup = dd_walks_build_lookup(
    $pdo,
    ['dogs', 'pets'],
    ['id', 'dog_id', 'pet_id'],
    ['dog_name', 'pet_name', 'name'],
    [
        'breed' => ['breed'],
        'size' => ['size', 'dog_size', 'pet_size'],
    ]
);

$workerLookup = dd_walks_build_worker_lookup($pdo);

/* ==========================================================================
   BOOKINGS SCHEMA
   ========================================================================== */

if (!dd_walks_table_exists($pdo, 'bookings')) {
    die('Bookings table not found.');
}

$bookingColumns = dd_walks_get_columns($pdo, 'bookings');

$idCol = dd_walks_first_existing_column($bookingColumns, ['id', 'booking_id']);
$statusCol = dd_walks_first_existing_column($bookingColumns, ['status', 'booking_status', 'walk_status']);
$serviceTypeCol = dd_walks_first_existing_column($bookingColumns, ['service_type', 'service', 'booking_type', 'type']);
$serviceDateCol = dd_walks_first_existing_column($bookingColumns, ['service_date', 'walk_date', 'booking_date', 'date']);
$serviceTimeCol = dd_walks_first_existing_column($bookingColumns, ['service_time', 'walk_time', 'booking_time', 'time']);
$durationCol = dd_walks_first_existing_column($bookingColumns, ['duration_minutes', 'walk_duration', 'duration']);
$priceCol = dd_walks_first_existing_column($bookingColumns, ['price', 'estimated_price', 'total_price', 'amount']);
$walkerNameCol = dd_walks_first_existing_column($bookingColumns, ['walker_name', 'assigned_walker_name', 'staff_name', 'employee_name']);
$walkerIdCol = dd_walks_first_existing_column($bookingColumns, ['assigned_walker_id', 'walker_id', 'staff_id', 'employee_id', 'worker_id', 'assigned_to']);
$clientIdCol = dd_walks_first_existing_column($bookingColumns, ['user_id', 'member_id', 'client_id', 'owner_id']);
$clientNameCol = dd_walks_first_existing_column($bookingColumns, ['client_name', 'member_name', 'owner_name', 'customer_name']);
$petIdCol = dd_walks_first_existing_column($bookingColumns, ['pet_id', 'dog_id']);
$petNameCol = dd_walks_first_existing_column($bookingColumns, ['pet_name', 'dog_name']);
$createdAtCol = dd_walks_first_existing_column($bookingColumns, ['created_at', 'created_on', 'date_created']);
$statusUpdatedByCol = dd_walks_first_existing_column($bookingColumns, ['status_updated_by']);
$statusUpdatedAtCol = dd_walks_first_existing_column($bookingColumns, ['status_updated_at']);
$updatedAtCol = dd_walks_first_existing_column($bookingColumns, ['updated_at', 'modified_at']);

if ($idCol === null) {
    die('Bookings table is missing an ID column.');
}

/* ==========================================================================
   FILTERS
   ========================================================================== */

$allowedFilters = ['all', 'pending', 'confirmed', 'in_progress', 'completed', 'cancelled', 'unassigned'];
$currentFilter = isset($_GET['status']) ? strtolower(trim((string) $_GET['status'])) : 'all';

if (!in_array($currentFilter, $allowedFilters, true)) {
    $currentFilter = 'all';
}

$flashMessage = '';
$flashType = 'success';

if (isset($_GET['updated'])) {
    $flashMessage = 'Walk status updated successfully.';
} elseif (isset($_GET['error'])) {
    $flashMessage = 'Something went wrong while updating the walk.';
    $flashType = 'error';
}

$highlightId = isset($_GET['highlight']) ? (int) $_GET['highlight'] : 0;

/* ==========================================================================
   POST STATUS UPDATE
   ========================================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = (string) ($_POST['csrf_token'] ?? '');
    $bookingId = (int) ($_POST['booking_id'] ?? 0);
    $newStatus = trim((string) ($_POST['new_status'] ?? ''));

    if ($postedCsrf === '' || !hash_equals($csrfToken, $postedCsrf)) {
        header('Location: admin-walks.php?error=1&status=' . urlencode($currentFilter));
        exit;
    }

    if (
        $bookingId > 0 &&
        in_array($newStatus, ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled'], true) &&
        $statusCol !== null
    ) {
        $updateParts = [
            dd_walks_qi($statusCol) . ' = :status',
        ];

        $params = [
            ':status' => $newStatus,
            ':id' => $bookingId,
        ];

        if ($statusUpdatedByCol !== null) {
            $adminName = trim((string) (
                $_SESSION['admin_name']
                ?? $_SESSION['full_name']
                ?? $_SESSION['email']
                ?? 'admin'
            ));
            $updateParts[] = dd_walks_qi($statusUpdatedByCol) . ' = :status_updated_by';
            $params[':status_updated_by'] = $adminName !== '' ? $adminName : 'admin';
        }

        if ($statusUpdatedAtCol !== null) {
            $updateParts[] = dd_walks_qi($statusUpdatedAtCol) . ' = CURRENT_TIMESTAMP';
        }

        if ($updatedAtCol !== null) {
            $updateParts[] = dd_walks_qi($updatedAtCol) . ' = CURRENT_TIMESTAMP';
        }

        $stmt = $pdo->prepare(
            'UPDATE ' . dd_walks_qi('bookings') . '
             SET ' . implode(', ', $updateParts) . '
             WHERE ' . dd_walks_qi($idCol) . ' = :id'
        );

        if (dd_walks_safe_execute($stmt, $params)) {
            header('Location: admin-walks.php?updated=1&status=' . urlencode($currentFilter) . '&highlight=' . $bookingId);
            exit;
        }
    }

    header('Location: admin-walks.php?error=1&status=' . urlencode($currentFilter));
    exit;
}

/* ==========================================================================
   LOAD BOOKINGS
   ========================================================================== */

$orderCol = $serviceDateCol ?? $createdAtCol ?? $idCol;

$rawBookings = dd_walks_safe_fetch_all(
    $pdo,
    'SELECT * FROM ' . dd_walks_qi('bookings') . '
     ORDER BY ' . dd_walks_qi($orderCol) . ' DESC, ' . dd_walks_qi($idCol) . ' DESC'
);

$walks = [];
$summary = [
    'total' => 0,
    'unassigned' => 0,
    'pending' => 0,
    'confirmed' => 0,
    'in_progress' => 0,
    'completed' => 0,
];

foreach ($rawBookings as $row) {
    $serviceType = (string) dd_walks_value_from_row($row, [$serviceTypeCol ?? ''], '');
    if ($serviceTypeCol !== null && !dd_walks_is_walk_service($serviceType)) {
        continue;
    }

    $bookingId = (int) ($row[$idCol] ?? 0);
    $statusValue = (string) dd_walks_value_from_row($row, [$statusCol ?? ''], 'pending');
    $normalizedStatus = dd_walks_normalize_status($statusValue);

    $assignedWalkerId = (int) dd_walks_value_from_row($row, [$walkerIdCol ?? ''], 0);
    $walkerName = trim((string) dd_walks_value_from_row($row, [$walkerNameCol ?? ''], ''));

    if ($walkerName === '' && $assignedWalkerId > 0 && isset($workerLookup[$assignedWalkerId])) {
        $walkerName = trim((string) ($workerLookup[$assignedWalkerId]['label'] ?? ''));
    }
    if ($walkerName === '') {
        $walkerName = 'Not assigned';
    }

    $clientName = trim((string) dd_walks_value_from_row($row, [$clientNameCol ?? ''], ''));
    $clientId = (int) dd_walks_value_from_row($row, [$clientIdCol ?? ''], 0);

    if ($clientName === '' && $clientId > 0 && isset($clientLookup[$clientId])) {
        $clientName = trim((string) ($clientLookup[$clientId]['label'] ?? ''));
    }
    if ($clientName === '') {
        $clientName = 'Unknown client';
    }

    $petName = trim((string) dd_walks_value_from_row($row, [$petNameCol ?? ''], ''));
    $petId = (int) dd_walks_value_from_row($row, [$petIdCol ?? ''], 0);
    $petBreed = '';
    $petSize = '';

    if ($petName === '' && $petId > 0 && isset($petLookup[$petId])) {
        $petName = trim((string) ($petLookup[$petId]['label'] ?? ''));
        $petBreed = trim((string) ($petLookup[$petId]['extra']['breed'] ?? ''));
        $petSize = trim((string) ($petLookup[$petId]['extra']['size'] ?? ''));
    }

    if ($petName === '') {
        $petName = 'Pet not found';
    }

    $isUnassigned = ($assignedWalkerId <= 0 && trim($walkerName) === 'Not assigned');

    $normalizedRow = [
        'id' => $bookingId,
        'client_label' => $clientName,
        'user_id' => $clientId,
        'pet_name' => $petName,
        'pet_breed' => $petBreed,
        'pet_size' => $petSize,
        'pet_id' => $petId,
        'service_type' => $serviceType !== '' ? $serviceType : 'walk',
        'service_date' => (string) dd_walks_value_from_row($row, [$serviceDateCol ?? ''], ''),
        'service_time' => (string) dd_walks_value_from_row($row, [$serviceTimeCol ?? ''], ''),
        'duration_minutes' => dd_walks_value_from_row($row, [$durationCol ?? ''], null),
        'status' => $normalizedStatus,
        'price' => dd_walks_value_from_row($row, [$priceCol ?? ''], 0),
        'walker_name' => $walkerName,
        'assigned_walker_id' => $assignedWalkerId,
        'created_at' => (string) dd_walks_value_from_row($row, [$createdAtCol ?? ''], ''),
        'is_unassigned' => $isUnassigned,
    ];

    $summary['total']++;

    if ($isUnassigned) {
        $summary['unassigned']++;
    }
    if ($normalizedStatus === 'pending') {
        $summary['pending']++;
    }
    if ($normalizedStatus === 'confirmed') {
        $summary['confirmed']++;
    }
    if ($normalizedStatus === 'in_progress') {
        $summary['in_progress']++;
    }
    if ($normalizedStatus === 'completed') {
        $summary['completed']++;
    }

    $include = true;
    if ($currentFilter === 'unassigned') {
        $include = $isUnassigned;
    } elseif ($currentFilter !== 'all') {
        $include = ($normalizedStatus === $currentFilter);
    }

    if ($include) {
        $walks[] = $normalizedRow;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Walk Operations | Doggie Dorian’s Admin</title>
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
      --blue: #66b3ff;
      --red: #ff9898;
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
      max-width: 1480px;
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
      max-width: 780px;
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
      border: 1px solid rgba(94,211,154,0.30);
      color: #c8ffe2;
    }

    .flash.error {
      background: rgba(255,152,152,0.12);
      border: 1px solid rgba(255,152,152,0.28);
      color: #ffd7d7;
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
      min-width: 1520px;
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

    tbody tr.highlight-row {
      background: rgba(212,175,55,0.08);
      box-shadow: inset 4px 0 0 var(--gold);
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

    .status.confirmed {
      background: rgba(94,211,154,0.18);
      color: #c8ffe2;
    }

    .status.in-progress {
      background: rgba(102,179,255,0.18);
      color: #d8ecff;
    }

    .status.completed {
      background: rgba(212,175,55,0.16);
      color: #f6de88;
    }

    .status.cancelled {
      background: rgba(255,152,152,0.15);
      color: #ffd7d7;
    }

    .actions {
      display: grid;
      gap: 8px;
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

    .actions a.track {
      background: rgba(102,179,255,0.12);
      border-color: rgba(102,179,255,0.24);
      color: #d8ecff;
    }

    .actions a.client-track {
      background: rgba(94,211,154,0.12);
      border-color: rgba(94,211,154,0.24);
      color: #c8ffe2;
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
        <h1>Walk Operations</h1>
        <div class="subtext">
          Manage scheduled walks, track assignment status, move services through the live workflow, and launch GPS tracking directly from this page.
        </div>
      </div>

      <div class="top-actions">
        <a href="admin-dashboard.php" class="top-btn">Admin Home</a>
        <a href="admin-revenue.php" class="top-btn">Revenue</a>
        <a href="admin-bookings.php" class="top-btn primary">Main Bookings</a>
      </div>
    </div>

    <?php if ($flashMessage !== ''): ?>
      <div class="flash <?php echo e($flashType); ?>">
        <?php echo e($flashMessage); ?>
      </div>
    <?php endif; ?>

    <div class="summary-grid">
      <div class="card">
        <div class="card-label">Total Walks</div>
        <div class="card-value"><?php echo $summary['total']; ?></div>
        <div class="card-note">All walk bookings in system</div>
      </div>

      <div class="card">
        <div class="card-label">Unassigned</div>
        <div class="card-value"><?php echo $summary['unassigned']; ?></div>
        <div class="card-note">Walks with no walker assigned</div>
      </div>

      <div class="card">
        <div class="card-label">Pending</div>
        <div class="card-value"><?php echo $summary['pending']; ?></div>
        <div class="card-note">Awaiting confirmation</div>
      </div>

      <div class="card">
        <div class="card-label">Confirmed</div>
        <div class="card-value"><?php echo $summary['confirmed']; ?></div>
        <div class="card-note">Ready to begin</div>
      </div>

      <div class="card">
        <div class="card-label">In Progress</div>
        <div class="card-value"><?php echo $summary['in_progress']; ?></div>
        <div class="card-note">Currently active walks</div>
      </div>

      <div class="card">
        <div class="card-label">Completed</div>
        <div class="card-value"><?php echo $summary['completed']; ?></div>
        <div class="card-note">Finished walk services</div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <div>
          <h2 class="panel-title">Walk Queue</h2>
          <div class="panel-subtitle">
            Keep your walking operation coordinated and launch live tracking directly when needed.
          </div>
        </div>

        <div class="filters">
          <a class="filter-pill <?php echo $currentFilter === 'all' ? 'active' : ''; ?>" href="admin-walks.php?status=all">All</a>
          <a class="filter-pill <?php echo $currentFilter === 'unassigned' ? 'active' : ''; ?>" href="admin-walks.php?status=unassigned">Unassigned</a>
          <a class="filter-pill <?php echo $currentFilter === 'pending' ? 'active' : ''; ?>" href="admin-walks.php?status=pending">Pending</a>
          <a class="filter-pill <?php echo $currentFilter === 'confirmed' ? 'active' : ''; ?>" href="admin-walks.php?status=confirmed">Confirmed</a>
          <a class="filter-pill <?php echo $currentFilter === 'in_progress' ? 'active' : ''; ?>" href="admin-walks.php?status=in_progress">In Progress</a>
          <a class="filter-pill <?php echo $currentFilter === 'completed' ? 'active' : ''; ?>" href="admin-walks.php?status=completed">Completed</a>
          <a class="filter-pill <?php echo $currentFilter === 'cancelled' ? 'active' : ''; ?>" href="admin-walks.php?status=cancelled">Cancelled</a>
        </div>
      </div>

      <div class="table-wrap">
        <?php if (!$walks): ?>
          <div class="empty-state">
            <strong>No walks found</strong>
            There are no walk bookings in this filter right now.
          </div>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Client</th>
                <th>Pet</th>
                <th>Schedule</th>
                <th>Status</th>
                <th>Walker</th>
                <th>Price</th>
                <th>Tracking</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($walks as $walk): ?>
                <tr class="<?php echo ((int) ($walk['id'] ?? 0) === $highlightId) ? 'highlight-row' : ''; ?>">
                  <td>
                    <div class="primary-text">#<?php echo (int) ($walk['id'] ?? 0); ?></div>
                  </td>

                  <td>
                    <div class="primary-text"><?php echo e($walk['client_label'] ?? 'Unknown client'); ?></div>
                    <div class="secondary-text">User ID: <?php echo (int) ($walk['user_id'] ?? 0); ?></div>
                  </td>

                  <td>
                    <div class="primary-text"><?php echo e($walk['pet_name'] ?? 'Pet not found'); ?></div>
                    <div class="secondary-text">
                      <?php
                        $petBits = [];
                        if (!empty($walk['pet_breed'])) {
                            $petBits[] = $walk['pet_breed'];
                        }
                        if (!empty($walk['pet_size'])) {
                            $petBits[] = $walk['pet_size'];
                        }
                        echo e($petBits ? implode(' • ', $petBits) : 'Pet ID: ' . (int) ($walk['pet_id'] ?? 0));
                      ?>
                    </div>
                  </td>

                  <td>
                    <div class="primary-text"><?php echo e(dd_walks_format_date((string) ($walk['service_date'] ?? ''))); ?></div>
                    <div class="secondary-text">
                      <?php
                        $sched = [];
                        if (!empty($walk['service_time'])) {
                            $sched[] = $walk['service_time'];
                        }
                        if (isset($walk['duration_minutes']) && $walk['duration_minutes'] !== null && $walk['duration_minutes'] !== '') {
                            $sched[] = (int) $walk['duration_minutes'] . ' min';
                        }
                        echo e($sched ? implode(' • ', $sched) : 'No schedule details');
                      ?>
                    </div>
                  </td>

                  <td>
                    <span class="<?php echo e(walkStatusClass((string) ($walk['status'] ?? 'pending'))); ?>">
                      <?php echo e(dd_walks_human_status((string) ($walk['status'] ?? 'pending'))); ?>
                    </span>
                  </td>

                  <td>
                    <div class="primary-text"><?php echo e($walk['walker_name'] ?? 'Not assigned'); ?></div>
                    <div class="secondary-text">
                      <?php if (!empty($walk['assigned_walker_id'])): ?>
                        Walker ID: <?php echo (int) $walk['assigned_walker_id']; ?>
                      <?php else: ?>
                        Assignment needed
                      <?php endif; ?>
                    </div>
                  </td>

                  <td>
                    <div class="primary-text"><?php echo e(dd_walks_format_money($walk['price'] ?? null)); ?></div>
                  </td>

                  <td>
                    <div class="actions">
                      <a class="track" href="live-tracking.php?booking_id=<?php echo (int) ($walk['id'] ?? 0); ?>" target="_blank" rel="noopener noreferrer">
                        Open Walker Track
                      </a>
                      <a class="client-track" href="client-map.php?booking_id=<?php echo (int) ($walk['id'] ?? 0); ?>" target="_blank" rel="noopener noreferrer">
                        Open Client Map
                      </a>
                    </div>
                  </td>

                  <td>
                    <div class="actions">
                      <a href="admin-assign-walker.php?id=<?php echo (int) ($walk['id'] ?? 0); ?>">Assign Walker</a>
                      <a href="admin-edit-booking.php?id=<?php echo (int) ($walk['id'] ?? 0); ?>">Edit Walk</a>

                      <?php if (($walk['status'] ?? '') !== 'confirmed'): ?>
                        <form method="post">
                          <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                          <input type="hidden" name="booking_id" value="<?php echo (int) ($walk['id'] ?? 0); ?>">
                          <input type="hidden" name="new_status" value="confirmed">
                          <button type="submit">Confirm</button>
                        </form>
                      <?php endif; ?>

                      <?php if (($walk['status'] ?? '') !== 'in_progress'): ?>
                        <form method="post">
                          <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                          <input type="hidden" name="booking_id" value="<?php echo (int) ($walk['id'] ?? 0); ?>">
                          <input type="hidden" name="new_status" value="in_progress">
                          <button type="submit">Start Walk</button>
                        </form>
                      <?php endif; ?>

                      <?php if (($walk['status'] ?? '') !== 'completed'): ?>
                        <form method="post">
                          <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                          <input type="hidden" name="booking_id" value="<?php echo (int) ($walk['id'] ?? 0); ?>">
                          <input type="hidden" name="new_status" value="completed">
                          <button type="submit">Complete Walk</button>
                        </form>
                      <?php endif; ?>
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