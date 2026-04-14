<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';

/*
|--------------------------------------------------------------------------
| Admin Unassign Walker
|--------------------------------------------------------------------------
| PURPOSE
| - Admin-only page to remove a worker from a booking
| - Returns booking to open/unassigned queue
| - Keeps admin workflow separate from walker portal
|
| URL
| - admin-unassign-walker.php?id=123
|--------------------------------------------------------------------------
*/

/* ==========================================================================
   ACCESS CONTROL
   ========================================================================== */

$userId  = (int) ($_SESSION['user_id'] ?? 0);
$adminId = (int) ($_SESSION['admin_id'] ?? 0);
$roleRaw = (string) ($_SESSION['role'] ?? '');
$role    = strtolower(trim($roleRaw));
$isAdmin = !empty($_SESSION['is_admin']);

$allowedRoles = ['admin', 'superadmin', 'owner'];

$hasAdminAccess = (
    $isAdmin ||
    $adminId > 0 ||
    ($userId > 0 && in_array($role, $allowedRoles, true))
);

if (!$hasAdminAccess) {
    if ($userId <= 0 && $adminId <= 0 && !$isAdmin) {
        header('Location: admin-login.php');
        exit;
    }

    http_response_code(403);
    echo 'Access denied.';
    exit;
}

if (empty($_SESSION['admin_unassign_walker_csrf']) || !is_string($_SESSION['admin_unassign_walker_csrf'])) {
    $_SESSION['admin_unassign_walker_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['admin_unassign_walker_csrf'];

/* ==========================================================================
   CONFIG
   ========================================================================== */

$BOOKINGS_TABLE = 'bookings';
$BOOKING_ID_COL = 'id';

$possibleWorkerTables = ['workers', 'walkers', 'users'];
$possibleWorkerIdCols = ['id', 'worker_id', 'walker_id', 'user_id'];
$possibleWorkerNameCols = ['name', 'full_name', 'display_name', 'worker_name', 'walker_name'];
$possibleWorkerEmailCols = ['email'];
$possibleWorkerRoleCols = ['role'];

$possibleWalkerIdColumns = ['walker_id', 'staff_id', 'employee_id', 'worker_id', 'assigned_to', 'assigned_worker_id'];
$possibleServiceColumns  = ['service_type', 'booking_type', 'service', 'type'];
$possibleStatusColumns   = ['status', 'booking_status', 'walk_status'];
$possibleDateColumns     = ['scheduled_date', 'service_date', 'booking_date', 'start_date'];
$possibleTimeColumns     = ['scheduled_time', 'service_time', 'booking_time', 'start_time'];
$possibleCreatedColumns  = ['created_at', 'created_on'];
$possibleUpdatedColumns  = ['updated_at', 'modified_at'];
$possiblePetColumns      = ['pet_name', 'dog_name'];
$possibleNotesColumns    = ['notes', 'special_instructions', 'instructions', 'care_notes'];
$possibleAddressColumns  = ['address', 'service_address', 'location'];
$possibleClientColumns   = ['member_id', 'user_id', 'customer_id', 'client_id', 'owner_id'];

$openStatuses = ['pending', 'open', 'unassigned', 'approved'];
$assignedStatuses = ['assigned', 'accepted', 'confirmed', 'scheduled'];
$inProgressStatuses = ['in_progress', 'in progress', 'started', 'active'];
$completedStatuses = ['completed', 'done'];

$defaultUnassignStatus = 'open';

/* ==========================================================================
   HELPERS
   ========================================================================== */

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function quotedIdentifier(string $value): string
{
    return '"' . str_replace('"', '""', $value) . '"';
}

function tableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1");
        $stmt->execute([':table' => $table]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function chooseExistingTable(PDO $pdo, array $tables): ?string
{
    foreach ($tables as $table) {
        if (tableExists($pdo, $table)) {
            return $table;
        }
    }

    return null;
}

function getTableColumns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query('PRAGMA table_info(' . quotedIdentifier($table) . ')');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $cols = [];

        foreach ($rows as $row) {
            if (!empty($row['name'])) {
                $cols[] = (string) $row['name'];
            }
        }

        return $cols;
    } catch (Throwable $e) {
        return [];
    }
}

function firstExistingColumn(array $preferred, array $existing): ?string
{
    foreach ($preferred as $column) {
        if (in_array($column, $existing, true)) {
            return $column;
        }
    }
    return null;
}

function fetchOneSafe(PDO $pdo, string $sql, array $params = []): ?array
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

function safeExecute(PDOStatement $stmt, array $params = []): bool
{
    try {
        return $stmt->execute($params);
    } catch (Throwable $e) {
        return false;
    }
}

function niceService(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return 'Service';
    }
    $value = str_replace(['_', '-'], ' ', strtolower($value));
    return ucwords($value);
}

function niceStatus(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return 'Pending';
    }
    $value = str_replace(['_', '-'], ' ', strtolower($value));
    return ucwords($value);
}

function formatJobDate(?string $date, ?string $time = null): string
{
    $date = trim((string) $date);
    $time = trim((string) $time);

    if ($date === '' && $time === '') {
        return 'Scheduling details pending';
    }

    $raw = trim($date . ' ' . $time);
    $ts = strtotime($raw);

    if ($ts !== false) {
        return date('M j, Y g:i A', $ts);
    }

    if ($date !== '' && $time !== '') {
        return $date . ' at ' . $time;
    }

    return $date !== '' ? $date : $time;
}

function redirectToManagement(): never
{
    header('Location: admin-walker-management.php');
    exit;
}

function loadWorkerRecord(PDO $pdo, ?string $workerTable, int $workerId, array $config): ?array
{
    if ($workerTable === null || $workerId <= 0) {
        return null;
    }

    $workerColumns = getTableColumns($pdo, $workerTable);
    if ($workerColumns === []) {
        return null;
    }

    $idCol = firstExistingColumn($config['possibleWorkerIdCols'], $workerColumns);
    if ($idCol === null) {
        return null;
    }

    $nameCol = firstExistingColumn($config['possibleWorkerNameCols'], $workerColumns);
    $emailCol = firstExistingColumn($config['possibleWorkerEmailCols'], $workerColumns);
    $roleCol = firstExistingColumn($config['possibleWorkerRoleCols'], $workerColumns);

    $selectParts = [
        quotedIdentifier($idCol) . ' AS worker_id',
        $nameCol !== null ? quotedIdentifier($nameCol) . ' AS worker_name' : "'' AS worker_name",
        $emailCol !== null ? quotedIdentifier($emailCol) . ' AS worker_email' : "'' AS worker_email",
        $roleCol !== null ? quotedIdentifier($roleCol) . ' AS worker_role' : "'' AS worker_role",
    ];

    $sql = "
        SELECT
            " . implode(",\n            ", $selectParts) . "
        FROM " . quotedIdentifier($workerTable) . "
        WHERE " . quotedIdentifier($idCol) . " = :worker_id
        LIMIT 1
    ";

    return fetchOneSafe($pdo, $sql, [':worker_id' => $workerId]);
}

function clearWalkerFromSessionTables(PDO $pdo, int $bookingId): void
{
    $sessionTables = ['walk_sessions', 'tracking_sessions'];

    foreach ($sessionTables as $table) {
        if (!tableExists($pdo, $table)) {
            continue;
        }

        $columns = getTableColumns($pdo, $table);
        $refCol = firstExistingColumn(['booking_id', 'walk_id'], $columns);
        $workerCol = firstExistingColumn(['walker_id', 'staff_id', 'employee_id', 'worker_id'], $columns);
        $updatedAtCol = firstExistingColumn(['updated_at', 'modified_at'], $columns);

        if ($refCol === null || $workerCol === null) {
            continue;
        }

        $sets = [quotedIdentifier($workerCol) . ' = NULL'];
        $params = [':booking_id' => $bookingId];

        if ($updatedAtCol !== null) {
            $sets[] = quotedIdentifier($updatedAtCol) . ' = :updated_at';
            $params[':updated_at'] = date('Y-m-d H:i:s');
        }

        $sql = "
            UPDATE " . quotedIdentifier($table) . "
            SET " . implode(', ', $sets) . "
            WHERE " . quotedIdentifier($refCol) . " = :booking_id
        ";

        $stmt = $pdo->prepare($sql);
        safeExecute($stmt, $params);
    }
}

/* ==========================================================================
   INPUT
   ========================================================================== */

$jobId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($jobId <= 0) {
    $_SESSION['admin_flash_type'] = 'error';
    $_SESSION['admin_flash_message'] = 'Invalid booking ID.';
    redirectToManagement();
}

/* ==========================================================================
   DATA CONTAINERS
   ========================================================================== */

$error = '';
$job = null;
$assignedWorker = null;

$schema = [
    'booking_walker_col' => null,
    'booking_service_col' => null,
    'booking_status_col' => null,
    'booking_date_col' => null,
    'booking_time_col' => null,
    'booking_created_col' => null,
    'booking_updated_col' => null,
    'booking_pet_col' => null,
    'booking_notes_col' => null,
    'booking_address_col' => null,
    'booking_client_col' => null,
];

/* ==========================================================================
   VALIDATE TABLES + COLUMNS
   ========================================================================== */

$workerTable = chooseExistingTable($pdo, $possibleWorkerTables);

try {
    if (!tableExists($pdo, $BOOKINGS_TABLE)) {
        $error = "The bookings table was not found. Update \$BOOKINGS_TABLE in admin-unassign-walker.php if needed.";
    } else {
        $bookingColumns = getTableColumns($pdo, $BOOKINGS_TABLE);

        if (!in_array($BOOKING_ID_COL, $bookingColumns, true)) {
            $error = 'Booking ID column not found in bookings table.';
        } else {
            $schema['booking_walker_col'] = firstExistingColumn($possibleWalkerIdColumns, $bookingColumns);
            $schema['booking_service_col'] = firstExistingColumn($possibleServiceColumns, $bookingColumns);
            $schema['booking_status_col'] = firstExistingColumn($possibleStatusColumns, $bookingColumns);
            $schema['booking_date_col'] = firstExistingColumn($possibleDateColumns, $bookingColumns);
            $schema['booking_time_col'] = firstExistingColumn($possibleTimeColumns, $bookingColumns);
            $schema['booking_created_col'] = firstExistingColumn($possibleCreatedColumns, $bookingColumns);
            $schema['booking_updated_col'] = firstExistingColumn($possibleUpdatedColumns, $bookingColumns);
            $schema['booking_pet_col'] = firstExistingColumn($possiblePetColumns, $bookingColumns);
            $schema['booking_notes_col'] = firstExistingColumn($possibleNotesColumns, $bookingColumns);
            $schema['booking_address_col'] = firstExistingColumn($possibleAddressColumns, $bookingColumns);
            $schema['booking_client_col'] = firstExistingColumn($possibleClientColumns, $bookingColumns);

            if ($schema['booking_walker_col'] === null) {
                $error = 'No worker assignment column was found in bookings. Add walker_id, staff_id, employee_id, worker_id, or assigned_worker_id.';
            } elseif ($schema['booking_status_col'] === null) {
                $error = 'No booking status column was found in bookings. Add status, booking_status, or walk_status.';
            }
        }
    }
} catch (Throwable $e) {
    $error = 'Setup error: ' . $e->getMessage();
}

/* ==========================================================================
   LOAD JOB
   ========================================================================== */

if ($error === '') {
    try {
        $jobSelectParts = [
            quotedIdentifier($BOOKING_ID_COL) . ' AS booking_id',
            quotedIdentifier($schema['booking_walker_col']) . ' AS assigned_worker_id',
            quotedIdentifier($schema['booking_status_col']) . ' AS status_name',
        ];

        $jobSelectParts[] = $schema['booking_service_col'] ? quotedIdentifier($schema['booking_service_col']) . " AS service_name" : "'' AS service_name";
        $jobSelectParts[] = $schema['booking_date_col'] ? quotedIdentifier($schema['booking_date_col']) . " AS date_value" : "'' AS date_value";
        $jobSelectParts[] = $schema['booking_time_col'] ? quotedIdentifier($schema['booking_time_col']) . " AS time_value" : "'' AS time_value";
        $jobSelectParts[] = $schema['booking_created_col'] ? quotedIdentifier($schema['booking_created_col']) . " AS created_value" : "'' AS created_value";
        $jobSelectParts[] = $schema['booking_pet_col'] ? quotedIdentifier($schema['booking_pet_col']) . " AS pet_name_value" : "'' AS pet_name_value";
        $jobSelectParts[] = $schema['booking_notes_col'] ? quotedIdentifier($schema['booking_notes_col']) . " AS notes_value" : "'' AS notes_value";
        $jobSelectParts[] = $schema['booking_address_col'] ? quotedIdentifier($schema['booking_address_col']) . " AS address_value" : "'' AS address_value";
        $jobSelectParts[] = $schema['booking_client_col'] ? quotedIdentifier($schema['booking_client_col']) . " AS client_value" : "'' AS client_value";

        $sqlJob = "
            SELECT
                " . implode(",\n                ", $jobSelectParts) . "
            FROM " . quotedIdentifier($BOOKINGS_TABLE) . "
            WHERE " . quotedIdentifier($BOOKING_ID_COL) . " = :job_id
            LIMIT 1
        ";

        $job = fetchOneSafe($pdo, $sqlJob, [':job_id' => $jobId]);

        if (!$job) {
            $error = 'Booking not found.';
        } else {
            $assignedWorkerId = (int) ($job['assigned_worker_id'] ?? 0);

            if ($assignedWorkerId > 0) {
                $assignedWorker = loadWorkerRecord(
                    $pdo,
                    $workerTable,
                    $assignedWorkerId,
                    [
                        'possibleWorkerIdCols' => $possibleWorkerIdCols,
                        'possibleWorkerNameCols' => $possibleWorkerNameCols,
                        'possibleWorkerEmailCols' => $possibleWorkerEmailCols,
                        'possibleWorkerRoleCols' => $possibleWorkerRoleCols,
                    ]
                );
            }
        }
    } catch (Throwable $e) {
        $error = 'Unable to load booking: ' . $e->getMessage();
    }
}

/* ==========================================================================
   SAVE UNASSIGN
   ========================================================================== */

if ($error === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    $postedStatus = trim((string) ($_POST['booking_status'] ?? $defaultUnassignStatus));
    $clearStatusToDefault = isset($_POST['force_open_status']) ? '1' : '0';

    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        $error = 'Session expired. Please refresh and try again.';
    }

    try {
        if ($error === '') {
            $pdo->beginTransaction();

            $stmtLockJob = $pdo->prepare("
                SELECT
                    " . quotedIdentifier($BOOKING_ID_COL) . " AS booking_id,
                    " . quotedIdentifier($schema['booking_walker_col']) . " AS assigned_worker_id,
                    " . quotedIdentifier($schema['booking_status_col']) . " AS status_name
                FROM " . quotedIdentifier($BOOKINGS_TABLE) . "
                WHERE " . quotedIdentifier($BOOKING_ID_COL) . " = :job_id
                LIMIT 1
            ");
            $stmtLockJob->execute([':job_id' => $jobId]);
            $currentJob = $stmtLockJob->fetch(PDO::FETCH_ASSOC);

            if (!$currentJob) {
                $pdo->rollBack();
                $error = 'Booking no longer exists.';
            } else {
                $currentAssignedWorkerId = (int) ($currentJob['assigned_worker_id'] ?? 0);

                if ($currentAssignedWorkerId <= 0) {
                    $pdo->rollBack();
                    $_SESSION['admin_flash_type'] = 'error';
                    $_SESSION['admin_flash_message'] = 'This booking is already unassigned.';
                    redirectToManagement();
                }

                $newStatus = $clearStatusToDefault === '1' ? $defaultUnassignStatus : $postedStatus;
                if ($newStatus === '') {
                    $newStatus = $defaultUnassignStatus;
                }

                $updateSets = [
                    quotedIdentifier($schema['booking_walker_col']) . ' = NULL',
                    quotedIdentifier($schema['booking_status_col']) . ' = :booking_status',
                ];

                $params = [
                    ':booking_status' => $newStatus,
                    ':job_id' => $jobId,
                ];

                if ($schema['booking_updated_col'] !== null) {
                    $updateSets[] = quotedIdentifier($schema['booking_updated_col']) . ' = :updated_at';
                    $params[':updated_at'] = date('Y-m-d H:i:s');
                }

                $sqlUpdate = "
                    UPDATE " . quotedIdentifier($BOOKINGS_TABLE) . "
                    SET " . implode(', ', $updateSets) . "
                    WHERE " . quotedIdentifier($BOOKING_ID_COL) . " = :job_id
                ";

                $stmtUpdate = $pdo->prepare($sqlUpdate);
                $stmtUpdate->execute($params);

                clearWalkerFromSessionTables($pdo, $jobId);

                $pdo->commit();

                $workerDisplay = 'assigned worker';
                if ($assignedWorker) {
                    $workerName = trim((string) ($assignedWorker['worker_name'] ?? ''));
                    $workerEmail = trim((string) ($assignedWorker['worker_email'] ?? ''));
                    $workerDisplay = $workerName !== '' ? $workerName : ($workerEmail !== '' ? $workerEmail : 'assigned worker');
                }

                $_SESSION['admin_flash_type'] = 'success';
                $_SESSION['admin_flash_message'] = 'Booking #' . $jobId . ' has been unassigned from ' . $workerDisplay . '.';

                redirectToManagement();
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Unassign error: ' . $e->getMessage();
    }
}

$currentStatusLabel = $job ? niceStatus((string) ($job['status_name'] ?? 'Pending')) : 'Pending';
$serviceLabel = $job ? niceService((string) ($job['service_name'] ?? 'Service')) : 'Service';
$scheduledLabel = $job ? formatJobDate((string) ($job['date_value'] ?? ''), (string) ($job['time_value'] ?? '')) : 'Scheduling details pending';

$workerDisplayName = 'No worker assigned';
$workerDisplayEmail = 'Not available';
$workerDisplayRole = 'Worker';

if ($assignedWorker) {
    $workerDisplayName = trim((string) ($assignedWorker['worker_name'] ?? '')) !== ''
        ? (string) $assignedWorker['worker_name']
        : ('Worker #' . (string) ($assignedWorker['worker_id'] ?? ''));
    $workerDisplayEmail = trim((string) ($assignedWorker['worker_email'] ?? '')) !== ''
        ? (string) $assignedWorker['worker_email']
        : 'Not available';
    $workerDisplayRole = trim((string) ($assignedWorker['worker_role'] ?? '')) !== ''
        ? niceStatus((string) $assignedWorker['worker_role'])
        : 'Worker';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unassign Walker | Doggie Dorian’s</title>
    <meta name="description" content="Admin-only booking unassignment page for Doggie Dorian’s.">
    <style>
        * { box-sizing: border-box; }

        :root {
            --bg-1: #0a0b0f;
            --bg-2: #12141a;
            --card: rgba(255,255,255,0.08);
            --card-strong: rgba(255,255,255,0.11);
            --border: rgba(255,255,255,0.12);
            --text: #f8f5ee;
            --muted: #bdb3a3;
            --gold: #d9b46b;
            --gold-strong: #bf8f37;
            --red: #ffb0b0;
            --red-strong: #ff7a7a;
            --shadow: 0 24px 70px rgba(0,0,0,0.38);
            --radius-xl: 28px;
            --radius-lg: 22px;
            --radius-md: 16px;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "Segoe UI", sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(217,180,107,0.14), transparent 26%),
                radial-gradient(circle at top right, rgba(255,122,122,0.08), transparent 24%),
                linear-gradient(180deg, var(--bg-1), var(--bg-2));
        }

        a { color: inherit; text-decoration: none; }

        .container {
            width: min(1120px, calc(100% - 32px));
            margin: 0 auto;
            padding: 28px 0 44px;
        }

        .topbar {
            display: flex;
            gap: 16px;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }

        .eyebrow {
            display: inline-block;
            font-size: 12px;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: 8px;
            font-weight: 700;
        }

        .headline {
            font-size: clamp(28px, 4vw, 44px);
            line-height: 1.03;
            letter-spacing: -0.04em;
            margin: 0;
        }

        .subheadline {
            margin: 10px 0 0;
            color: var(--muted);
            font-size: 15px;
            line-height: 1.6;
            max-width: 760px;
        }

        .top-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn,
        .btn-secondary,
        .btn-danger {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 48px;
            padding: 0 18px;
            border-radius: 999px;
            font-weight: 700;
            transition: transform .16s ease, background .16s ease, box-shadow .16s ease;
        }

        .btn {
            border: none;
            cursor: pointer;
            background: linear-gradient(135deg, var(--gold), var(--gold-strong));
            color: #17130e;
            box-shadow: 0 14px 30px rgba(191,143,55,0.28);
        }

        .btn-secondary {
            background: rgba(255,255,255,0.06);
            border: 1px solid var(--border);
            color: var(--text);
        }

        .btn-danger {
            border: none;
            cursor: pointer;
            background: linear-gradient(135deg, rgba(255,122,122,0.95), rgba(220,70,70,0.95));
            color: #fff;
            box-shadow: 0 14px 30px rgba(220,70,70,0.24);
        }

        .btn:hover,
        .btn-secondary:hover,
        .btn-danger:hover {
            transform: translateY(-1px);
        }

        .error-box {
            margin-bottom: 18px;
            border-radius: 18px;
            padding: 16px 18px;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(255, 80, 80, 0.10);
            color: var(--red);
        }

        .grid {
            display: grid;
            grid-template-columns: .95fr 1.05fr;
            gap: 18px;
        }

        .panel,
        .hero-card,
        .warning-card {
            background: var(--card);
            border: 1px solid var(--border);
            backdrop-filter: blur(16px);
            box-shadow: var(--shadow);
            border-radius: var(--radius-xl);
            padding: 24px;
        }

        .hero-card h2,
        .panel h2,
        .warning-card h2 {
            margin: 0 0 14px;
            font-size: 24px;
            letter-spacing: -0.03em;
        }

        .hero-copy {
            color: var(--muted);
            font-size: 15px;
            line-height: 1.7;
            margin-bottom: 18px;
        }

        .info-stack {
            display: grid;
            gap: 12px;
        }

        .info-card {
            background: var(--card-strong);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 18px;
            padding: 16px;
        }

        .info-label {
            display: block;
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 6px;
        }

        .info-value {
            font-size: 15px;
            line-height: 1.6;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            min-height: 34px;
            padding: 0 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            border: 1px solid rgba(255,255,255,0.12);
            background: rgba(255,255,255,0.06);
        }

        form {
            display: grid;
            gap: 16px;
        }

        .field {
            display: grid;
            gap: 8px;
        }

        label {
            font-size: 14px;
            font-weight: 700;
            color: var(--text);
        }

        select {
            width: 100%;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.06);
            color: var(--text);
            border-radius: 16px;
            padding: 14px 15px;
            font-size: 15px;
            outline: none;
            transition: border-color .16s ease, background .16s ease, transform .16s ease;
        }

        select:focus {
            border-color: rgba(217,180,107,0.65);
            background: rgba(255,255,255,0.08);
            transform: translateY(-1px);
        }

        .checkline {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--muted);
            font-size: 14px;
        }

        .checkline input {
            accent-color: #ff7a7a;
        }

        .helper {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 6px;
        }

        .warning-card {
            border-color: rgba(255,122,122,0.18);
            background: linear-gradient(180deg, rgba(255,122,122,0.05), rgba(255,255,255,0.03));
        }

        .warning-text {
            color: var(--muted);
            line-height: 1.7;
            font-size: 15px;
        }

        .footer-note {
            margin-top: 20px;
            text-align: center;
            color: var(--muted);
            font-size: 13px;
        }

        @media (max-width: 920px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 760px) {
            .container {
                width: min(100% - 18px, 1120px);
                padding-top: 18px;
            }

            .hero-card,
            .panel,
            .warning-card {
                padding: 18px;
            }

            .topbar {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="container">

        <div class="topbar">
            <div>
                <div class="eyebrow">Doggie Dorian’s Admin Operations</div>
                <h1 class="headline">Unassign Walker</h1>
                <p class="subheadline">
                    Remove the current worker from this booking and return the service to the available queue from the admin side.
                </p>
            </div>

            <div class="top-actions">
                <a class="btn-secondary" href="admin-walker-management.php">Walker Management</a>
                <a class="btn-secondary" href="admin-bookings.php">Admin Bookings</a>
            </div>
        </div>

        <?php if ($error !== ''): ?>
            <div class="error-box"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($job !== null): ?>
            <div class="grid">
                <section class="hero-card">
                    <h2>Booking overview</h2>
                    <div class="hero-copy">
                        Review the service and current assignment before unassigning the worker.
                    </div>

                    <div class="info-stack">
                        <div class="info-card">
                            <span class="info-label">Booking</span>
                            <div class="info-value"><?= h($serviceLabel) ?> #<?= h((string) $job['booking_id']) ?></div>
                        </div>

                        <div class="info-card">
                            <span class="info-label">Current Status</span>
                            <div class="info-value">
                                <span class="status-pill"><?= h($currentStatusLabel) ?></span>
                            </div>
                        </div>

                        <div class="info-card">
                            <span class="info-label">Scheduled</span>
                            <div class="info-value"><?= h($scheduledLabel) ?></div>
                        </div>

                        <div class="info-card">
                            <span class="info-label">Pet</span>
                            <div class="info-value"><?= h(trim((string) ($job['pet_name_value'] ?? '')) !== '' ? (string) $job['pet_name_value'] : 'Not listed') ?></div>
                        </div>

                        <div class="info-card">
                            <span class="info-label">Client Ref</span>
                            <div class="info-value"><?= h(trim((string) ($job['client_value'] ?? '')) !== '' ? (string) $job['client_value'] : 'Private') ?></div>
                        </div>

                        <div class="info-card">
                            <span class="info-label">Location</span>
                            <div class="info-value"><?= h(trim((string) ($job['address_value'] ?? '')) !== '' ? (string) $job['address_value'] : 'Not provided') ?></div>
                        </div>

                        <div class="info-card">
                            <span class="info-label">Notes</span>
                            <div class="info-value"><?= h(trim((string) ($job['notes_value'] ?? '')) !== '' ? (string) $job['notes_value'] : 'No notes') ?></div>
                        </div>
                    </div>
                </section>

                <section class="panel">
                    <h2>Current assigned worker</h2>

                    <div class="info-stack" style="margin-bottom:18px;">
                        <div class="info-card">
                            <span class="info-label">Worker Name</span>
                            <div class="info-value"><?= h($workerDisplayName) ?></div>
                        </div>

                        <div class="info-card">
                            <span class="info-label">Email</span>
                            <div class="info-value"><?= h($workerDisplayEmail) ?></div>
                        </div>

                        <div class="info-card">
                            <span class="info-label">Role</span>
                            <div class="info-value"><?= h($workerDisplayRole) ?></div>
                        </div>
                    </div>

                    <form method="post" action="admin-unassign-walker.php?id=<?= urlencode((string) $jobId) ?>">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

                        <div class="field">
                            <label for="booking_status">Booking status after unassigning</label>
                            <select id="booking_status" name="booking_status">
                                <?php
                                $statusOptions = array_unique(array_merge(
                                    $openStatuses,
                                    $assignedStatuses,
                                    $inProgressStatuses,
                                    $completedStatuses,
                                    [$defaultUnassignStatus]
                                ));
                                foreach ($statusOptions as $statusOpt):
                                ?>
                                    <option value="<?= h($statusOpt) ?>" <?= strtolower($defaultUnassignStatus) === strtolower($statusOpt) ? 'selected' : '' ?>>
                                        <?= h(niceStatus($statusOpt)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="helper">
                                Recommended reset status is “Open” or “Unassigned”.
                            </div>
                        </div>

                        <label class="checkline">
                            <input type="checkbox" name="force_open_status" value="1" checked>
                            Force status to “<?= h(niceStatus($defaultUnassignStatus)) ?>” when saving
                        </label>

                        <div class="actions">
                            <button
                                type="submit"
                                class="btn-danger"
                                onclick="return confirm('Unassign this worker from booking #<?= h((string) $job['booking_id']) ?>?');"
                            >
                                Unassign Worker
                            </button>
                            <a href="admin-walker-management.php" class="btn-secondary">Cancel</a>
                        </div>
                    </form>
                </section>
            </div>

            <section class="warning-card" style="margin-top:18px;">
                <h2>What happens next</h2>
                <div class="warning-text">
                    Unassigning removes the current worker from this booking and places the service back into the admin-controlled open workflow.
                    From there, you can assign a different worker through the admin side.
                </div>
            </section>
        <?php endif; ?>

        <div class="footer-note">
            Admin-only worker unassignment page · Centralized reassignment flow
        </div>
    </div>
</body>
</html>