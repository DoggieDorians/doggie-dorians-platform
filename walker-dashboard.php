<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';

/*
|--------------------------------------------------------------------------
| Walker Dashboard
|--------------------------------------------------------------------------
| PURPOSE
| - Main landing page for worker portal
| - Shows worker-only overview and quick actions
| - Blocks members/admins/guests
|--------------------------------------------------------------------------
*/

/* ==========================================================================
   ACCESS CONTROL
   ========================================================================== */

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    $_SESSION['walker_flash_type'] = 'error';
    $_SESSION['walker_flash_message'] = 'Please log in to access the worker portal.';
    header('Location: walker-login.php');
    exit;
}

$allowedWorkerRoles = ['walker', 'staff', 'employee'];
$currentRole = strtolower(trim((string)($_SESSION['role'] ?? '')));

if (!in_array($currentRole, $allowedWorkerRoles, true)) {
    $_SESSION['walker_flash_type'] = 'error';
    $_SESSION['walker_flash_message'] = 'You do not have permission to access the worker dashboard.';
    header('Location: login.php');
    exit;
}

$workerId = (int)($_SESSION['user_id'] ?? 0);
$workerName = (string)($_SESSION['name'] ?? 'Worker');
$workerEmail = (string)($_SESSION['email'] ?? '');

if ($workerId <= 0) {
    $_SESSION['walker_flash_type'] = 'error';
    $_SESSION['walker_flash_message'] = 'Invalid worker session.';
    header('Location: walker-login.php');
    exit;
}

/* ==========================================================================
   FLASH
   ========================================================================== */

$flashType = $_SESSION['walker_flash_type'] ?? '';
$flashMessage = $_SESSION['walker_flash_message'] ?? '';
unset($_SESSION['walker_flash_type'], $_SESSION['walker_flash_message']);

/* ==========================================================================
   CONFIG
   ========================================================================== */

$BOOKINGS_TABLE = 'bookings';
$NOTIFICATIONS_TABLE = 'walker_notifications';

$possibleWalkerIdColumns = ['walker_id', 'staff_id', 'employee_id'];
$possibleServiceColumns = ['service_type', 'booking_type', 'service'];
$possibleStatusColumns = ['status', 'booking_status'];
$possibleDateColumns = ['scheduled_date', 'service_date', 'booking_date', 'start_date'];
$possibleTimeColumns = ['scheduled_time', 'service_time', 'booking_time'];
$possibleCreatedColumns = ['created_at', 'created_on'];
$possiblePetColumns = ['pet_name', 'dog_name'];
$possibleAddressColumns = ['address', 'service_address', 'location'];
$possibleClientColumns = ['member_id', 'user_id', 'customer_id'];
$possibleStartColumns = [
    'walk_started_at',
    'started_at',
    'service_started_at',
    'job_started_at',
    'actual_start',
    'actual_start_at'
];

$notificationPossibleWalkerIdCols = ['walker_id', 'user_id', 'staff_id', 'employee_id'];
$notificationPossibleReadCols = ['is_read', 'read_flag', 'seen'];

$startableStatuses = ['assigned', 'accepted', 'confirmed', 'scheduled'];
$trackableStatuses = ['in_progress', 'in progress', 'started', 'active'];
$completedStatuses = ['completed', 'done'];
$openStatuses = ['pending', 'open', 'unassigned', 'approved'];

/* ==========================================================================
   HELPERS
   ========================================================================== */

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1");
        $stmt->execute([':table' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function getTableColumns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query("PRAGMA table_info($table)");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $cols = [];

        foreach ($rows as $row) {
            if (!empty($row['name'])) {
                $cols[] = (string)$row['name'];
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

function buildInClause(array $values, string $prefix = 'p'): array
{
    $placeholders = [];
    $params = [];

    foreach (array_values($values) as $i => $value) {
        $key = ':' . $prefix . $i;
        $placeholders[] = $key;
        $params[$key] = $value;
    }

    return [$placeholders, $params];
}

function niceService(?string $service): string
{
    $service = trim((string)$service);

    if ($service === '') {
        return 'Service';
    }

    $service = str_replace(['_', '-'], ' ', strtolower($service));
    return ucwords($service);
}

function niceStatus(?string $status): string
{
    $status = trim((string)$status);

    if ($status === '') {
        return 'Pending';
    }

    $status = str_replace(['_', '-'], ' ', strtolower($status));
    return ucwords($status);
}

function formatJobDate(?string $date, ?string $time = null): string
{
    $date = trim((string)$date);
    $time = trim((string)$time);

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

function formatElapsedShort(?string $start): string
{
    $start = trim((string)$start);
    if ($start === '') {
        return 'Timer unavailable';
    }

    $startTs = strtotime($start);
    if ($startTs === false) {
        return 'Timer unavailable';
    }

    $elapsed = time() - $startTs;
    if ($elapsed < 0) {
        $elapsed = 0;
    }

    $hours = floor($elapsed / 3600);
    $minutes = floor(($elapsed % 3600) / 60);

    return sprintf('%02dh %02dm', $hours, $minutes);
}

/* ==========================================================================
   DEFAULT DATA
   ========================================================================== */

$error = '';

$stats = [
    'assigned' => 0,
    'ready_to_start' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'available_jobs' => 0,
    'notifications' => 0,
];

$nextAssignedJobs = [];
$liveJobs = [];
$availableJobsPreview = [];

/* ==========================================================================
   LOAD BOOKING DATA
   ========================================================================== */

if (!tableExists($pdo, $BOOKINGS_TABLE)) {
    $error = "The bookings table was not found. Update \$BOOKINGS_TABLE in walker-dashboard.php if needed.";
} else {
    try {
        $columns = getTableColumns($pdo, $BOOKINGS_TABLE);

        $walkerIdCol = firstExistingColumn($possibleWalkerIdColumns, $columns);
        $serviceCol = firstExistingColumn($possibleServiceColumns, $columns);
        $statusCol = firstExistingColumn($possibleStatusColumns, $columns);
        $dateCol = firstExistingColumn($possibleDateColumns, $columns);
        $timeCol = firstExistingColumn($possibleTimeColumns, $columns);
        $createdCol = firstExistingColumn($possibleCreatedColumns, $columns);
        $petCol = firstExistingColumn($possiblePetColumns, $columns);
        $addressCol = firstExistingColumn($possibleAddressColumns, $columns);
        $clientCol = firstExistingColumn($possibleClientColumns, $columns);
        $startCol = firstExistingColumn($possibleStartColumns, $columns);

        if ($walkerIdCol === null) {
            $error = 'No worker assignment column was found. Add one like walker_id, staff_id, or employee_id.';
        } elseif ($statusCol === null) {
            $error = 'No booking status column was found. Add status or booking_status.';
        } else {
            $selectParts = ['id'];
            $selectParts[] = "$walkerIdCol AS assigned_worker_id";
            $selectParts[] = "$statusCol AS status_name";
            $selectParts[] = $serviceCol ? "$serviceCol AS service_name" : "'' AS service_name";
            $selectParts[] = $dateCol ? "$dateCol AS date_value" : "'' AS date_value";
            $selectParts[] = $timeCol ? "$timeCol AS time_value" : "'' AS time_value";
            $selectParts[] = $createdCol ? "$createdCol AS created_value" : "'' AS created_value";
            $selectParts[] = $petCol ? "$petCol AS pet_name_value" : "'' AS pet_name_value";
            $selectParts[] = $addressCol ? "$addressCol AS address_value" : "'' AS address_value";
            $selectParts[] = $clientCol ? "$clientCol AS client_value" : "'' AS client_value";
            $selectParts[] = $startCol ? "$startCol AS started_at_value" : "'' AS started_at_value";

            $baseSelect = implode(",\n                    ", $selectParts);
            $orderCol = $dateCol ?? $createdCol ?? 'id';

            // All jobs assigned to this worker
            $sqlAssigned = "
                SELECT
                    $baseSelect
                FROM $BOOKINGS_TABLE
                WHERE $walkerIdCol = :worker_id
                ORDER BY
                    CASE
                        WHEN LOWER(COALESCE($statusCol, '')) IN ('in_progress', 'in progress', 'started', 'active') THEN 0
                        WHEN LOWER(COALESCE($statusCol, '')) IN ('assigned', 'accepted', 'confirmed', 'scheduled') THEN 1
                        WHEN LOWER(COALESCE($statusCol, '')) IN ('completed', 'done') THEN 2
                        ELSE 3
                    END,
                    $orderCol ASC,
                    id DESC
            ";
            $stmtAssigned = $pdo->prepare($sqlAssigned);
            $stmtAssigned->execute([':worker_id' => $workerId]);
            $assignedRows = $stmtAssigned->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($assignedRows as $row) {
                $statusRaw = strtolower(trim((string)($row['status_name'] ?? '')));
                $stats['assigned']++;

                if (in_array($statusRaw, $startableStatuses, true)) {
                    $stats['ready_to_start']++;
                    if (count($nextAssignedJobs) < 4) {
                        $nextAssignedJobs[] = $row;
                    }
                }

                if (in_array($statusRaw, $trackableStatuses, true)) {
                    $stats['in_progress']++;
                    if (count($liveJobs) < 3) {
                        $liveJobs[] = $row;
                    }
                }

                if (in_array($statusRaw, $completedStatuses, true)) {
                    $stats['completed']++;
                }
            }

            // Available jobs preview
            [$openPlaceholders, $openParams] = buildInClause($openStatuses, 'open');
            $sqlOpen = "
                SELECT
                    $baseSelect
                FROM $BOOKINGS_TABLE
                WHERE ($walkerIdCol IS NULL OR $walkerIdCol = 0 OR $walkerIdCol = '')
                  AND $statusCol IN (" . implode(', ', $openPlaceholders) . ")
                ORDER BY $orderCol ASC, id DESC
                LIMIT 4
            ";
            $stmtOpen = $pdo->prepare($sqlOpen);
            $stmtOpen->execute($openParams);
            $availableJobsPreview = $stmtOpen->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $stats['available_jobs'] = count($availableJobsPreview);

            // Count all available jobs
            $sqlOpenCount = "
                SELECT COUNT(*)
                FROM $BOOKINGS_TABLE
                WHERE ($walkerIdCol IS NULL OR $walkerIdCol = 0 OR $walkerIdCol = '')
                  AND $statusCol IN (" . implode(', ', $openPlaceholders) . ")
            ";
            $stmtOpenCount = $pdo->prepare($sqlOpenCount);
            $stmtOpenCount->execute($openParams);
            $stats['available_jobs'] = (int)$stmtOpenCount->fetchColumn();
        }
    } catch (Throwable $e) {
        $error = 'Dashboard error: ' . $e->getMessage();
    }
}

/* ==========================================================================
   LOAD NOTIFICATION COUNT
   ========================================================================== */

try {
    if (tableExists($pdo, $NOTIFICATIONS_TABLE)) {
        $notifCols = getTableColumns($pdo, $NOTIFICATIONS_TABLE);
        $notifWorkerIdCol = firstExistingColumn($notificationPossibleWalkerIdCols, $notifCols);
        $notifReadCol = firstExistingColumn($notificationPossibleReadCols, $notifCols);

        if ($notifWorkerIdCol !== null) {
            if ($notifReadCol !== null) {
                $sqlNotif = "
                    SELECT COUNT(*)
                    FROM $NOTIFICATIONS_TABLE
                    WHERE $notifWorkerIdCol = :worker_id
                      AND ($notifReadCol = 0 OR $notifReadCol IS NULL OR $notifReadCol = '')
                ";
            } else {
                $sqlNotif = "
                    SELECT COUNT(*)
                    FROM $NOTIFICATIONS_TABLE
                    WHERE $notifWorkerIdCol = :worker_id
                ";
            }

            $stmtNotif = $pdo->prepare($sqlNotif);
            $stmtNotif->execute([':worker_id' => $workerId]);
            $stats['notifications'] = (int)$stmtNotif->fetchColumn();
        }
    } else {
        $stats['notifications'] = $stats['ready_to_start'] + $stats['in_progress'];
    }
} catch (Throwable $e) {
    // Non-fatal
}

$displayName = trim($workerName) !== '' ? $workerName : 'Worker';
$firstName = explode(' ', $displayName)[0] ?: 'Worker';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Walker Dashboard | Doggie Dorian’s</title>
    <meta name="description" content="Worker dashboard for Doggie Dorian’s.">
    <style>
        * { box-sizing: border-box; }

        :root {
            --bg-1: #090b10;
            --bg-2: #12141b;
            --card: rgba(255,255,255,0.08);
            --card-strong: rgba(255,255,255,0.11);
            --border: rgba(255,255,255,0.12);
            --text: #f8f5ee;
            --muted: #b9b09f;
            --gold: #d9b46b;
            --gold-strong: #bf8f37;
            --green: #8ae3b0;
            --blue: #8fc5ff;
            --red: #ffb0b0;
            --shadow: 0 30px 80px rgba(0,0,0,0.42);
            --radius-xl: 30px;
            --radius-lg: 22px;
            --radius-md: 16px;
        }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top left, rgba(217,180,107,0.18), transparent 28%),
                radial-gradient(circle at top right, rgba(143,197,255,0.10), transparent 24%),
                linear-gradient(180deg, var(--bg-1), var(--bg-2));
        }

        a { color: inherit; text-decoration: none; }

        .container {
            width: min(1320px, calc(100% - 32px));
            margin: 0 auto;
            padding: 28px 0 44px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .eyebrow {
            display: inline-block;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.18em;
            color: var(--gold);
            margin-bottom: 8px;
        }

        .headline {
            margin: 0;
            font-size: clamp(30px, 5vw, 52px);
            line-height: 0.98;
            letter-spacing: -0.05em;
        }

        .subheadline {
            margin: 12px 0 0;
            font-size: 15px;
            line-height: 1.75;
            color: var(--muted);
            max-width: 760px;
        }

        .top-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn,
        .btn-secondary,
        .mini-btn,
        .mini-btn-ghost {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-weight: 700;
            transition: transform .16s ease, background .16s ease, box-shadow .16s ease;
        }

        .btn,
        .btn-secondary {
            min-height: 48px;
            padding: 0 18px;
            border-radius: 999px;
        }

        .btn {
            background: linear-gradient(135deg, var(--gold), var(--gold-strong));
            color: #17130e;
            box-shadow: 0 16px 34px rgba(191,143,55,0.28);
        }

        .btn-secondary {
            background: rgba(255,255,255,0.06);
            border: 1px solid var(--border);
            color: var(--text);
        }

        .mini-btn,
        .mini-btn-ghost {
            min-height: 40px;
            padding: 0 14px;
            border-radius: 999px;
            font-size: 13px;
        }

        .mini-btn {
            background: linear-gradient(135deg, var(--gold), var(--gold-strong));
            color: #17130e;
        }

        .mini-btn-ghost {
            background: rgba(255,255,255,0.06);
            border: 1px solid var(--border);
            color: var(--text);
        }

        .btn:hover,
        .btn-secondary:hover,
        .mini-btn:hover,
        .mini-btn-ghost:hover {
            transform: translateY(-1px);
        }

        .success-box,
        .error-box {
            margin-bottom: 18px;
            border-radius: 18px;
            padding: 14px 16px;
            border: 1px solid rgba(255,255,255,0.10);
            font-size: 14px;
            line-height: 1.6;
        }

        .success-box {
            background: rgba(80, 200, 120, 0.12);
            color: #9ce7b7;
        }

        .error-box {
            background: rgba(255, 80, 80, 0.10);
            color: var(--red);
        }

        .hero,
        .panel,
        .stat,
        .job-card,
        .quick-card {
            background: var(--card);
            border: 1px solid var(--border);
            backdrop-filter: blur(16px);
            box-shadow: var(--shadow);
        }

        .hero {
            border-radius: var(--radius-xl);
            padding: 24px;
            margin-bottom: 18px;
        }

        .hero-grid {
            display: grid;
            grid-template-columns: 1.2fr .8fr;
            gap: 18px;
            align-items: stretch;
        }

        .hero-card {
            background: var(--card-strong);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 24px;
            padding: 22px;
        }

        .hero-card h2 {
            margin: 0 0 10px;
            font-size: 26px;
            letter-spacing: -0.03em;
        }

        .hero-copy {
            color: var(--muted);
            font-size: 15px;
            line-height: 1.7;
        }

        .hero-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .worker-badge {
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
            background: rgba(143,197,255,0.12);
            color: var(--blue);
            margin-bottom: 12px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 14px;
            margin: 18px 0;
        }

        .stat {
            border-radius: 22px;
            padding: 18px;
            background: var(--card-strong);
        }

        .stat-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            color: var(--muted);
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 30px;
            font-weight: 800;
            letter-spacing: -0.03em;
        }

        .stat-note {
            margin-top: 6px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.5;
        }

        .quick-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 18px;
        }

        .quick-card {
            border-radius: 22px;
            padding: 18px;
            background: var(--card-strong);
        }

        .quick-title {
            margin: 0 0 8px;
            font-size: 16px;
            letter-spacing: -0.02em;
        }

        .quick-copy {
            margin: 0 0 14px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.65;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .panel {
            border-radius: var(--radius-xl);
            padding: 22px;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .panel-title {
            margin: 0;
            font-size: 22px;
            letter-spacing: -0.03em;
        }

        .panel-subtitle {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 14px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
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

        .job-list {
            display: grid;
            gap: 14px;
        }

        .job-card {
            border-radius: 22px;
            padding: 18px;
        }

        .job-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }

        .job-title {
            margin: 0;
            font-size: 18px;
            letter-spacing: -0.02em;
        }

        .job-meta {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }

        .status-pill {
            white-space: nowrap;
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            border: 1px solid rgba(255,255,255,0.12);
        }

        .status-assigned { background: rgba(143,197,255,0.12); color: var(--blue); }
        .status-progress { background: rgba(138,227,176,0.12); color: var(--green); }
        .status-open { background: rgba(217,180,107,0.12); color: var(--gold); }
        .status-neutral { background: rgba(255,255,255,0.08); color: var(--text); }

        .job-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px 14px;
            margin: 14px 0 16px;
        }

        .job-item span {
            display: block;
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 4px;
        }

        .job-item strong {
            font-size: 14px;
            line-height: 1.5;
        }

        .job-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .empty-state {
            border: 1px dashed rgba(255,255,255,0.14);
            border-radius: 22px;
            padding: 24px;
            color: var(--muted);
            text-align: center;
            background: rgba(255,255,255,0.03);
        }

        .footer-note {
            margin-top: 20px;
            text-align: center;
            color: var(--muted);
            font-size: 13px;
        }

        @media (max-width: 1180px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }

            .quick-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 980px) {
            .hero-grid,
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 760px) {
            .container {
                width: min(100% - 18px, 1320px);
                padding-top: 18px;
            }

            .hero,
            .panel,
            .quick-card {
                padding: 18px;
            }

            .stats-grid,
            .quick-grid,
            .job-grid {
                grid-template-columns: 1fr;
            }

            .topbar,
            .job-top,
            .panel-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .stat-value {
                font-size: 26px;
            }
        }
    </style>
</head>
<body>
    <div class="container">

        <div class="topbar">
            <div>
                <div class="eyebrow">Doggie Dorian’s Worker Portal</div>
                <h1 class="headline">Welcome back, <?= h($firstName) ?></h1>
                <p class="subheadline">
                    This dashboard gives you a clean worker-only view of your assignments, active services, opportunities, and account tools.
                </p>
            </div>

            <div class="top-actions">
                <a class="btn-secondary" href="walker-profile.php">Profile</a>
                <a class="btn-secondary" href="walker-notifications.php">Notifications</a>
                <a class="btn-secondary" href="walker-logout.php">Log Out</a>
            </div>
        </div>

        <?php if ($flashMessage !== ''): ?>
            <div class="<?= $flashType === 'success' ? 'success-box' : 'error-box' ?>">
                <?= h($flashMessage) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="error-box"><?= h($error) ?></div>
        <?php endif; ?>

        <section class="hero">
            <div class="hero-grid">
                <div class="hero-card">
                    <div class="worker-badge"><?= h(niceStatus($currentRole)) ?> Access</div>
                    <h2>Your workday, organized.</h2>
                    <div class="hero-copy">
                        Start assigned services, continue live work, review available jobs, and stay synced with your worker notifications—all without exposing admin or member tools.
                    </div>

                    <div class="hero-actions">
                        <a class="btn" href="walker-jobs.php">My Jobs</a>
                        <a class="btn-secondary" href="walker-available.php">Available Jobs</a>
                        <a class="btn-secondary" href="walker-notifications.php">Alerts</a>
                    </div>
                </div>

                <div class="hero-card">
                    <h2>Worker account</h2>
                    <div class="info-stack">
                        <div class="info-card">
                            <span class="job-item"><span>Name</span><strong><?= h($displayName) ?></strong></span>
                        </div>
                        <div class="info-card">
                            <span class="job-item"><span>Email</span><strong><?= h($workerEmail !== '' ? $workerEmail : 'Not available') ?></strong></span>
                        </div>
                        <div class="info-card">
                            <span class="job-item"><span>Portal</span><strong>Walker-only access enforced</strong></span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="stats-grid">
            <div class="stat">
                <div class="stat-label">Assigned Jobs</div>
                <div class="stat-value"><?= h((string)$stats['assigned']) ?></div>
                <div class="stat-note">All jobs currently linked to your account</div>
            </div>

            <div class="stat">
                <div class="stat-label">Ready To Start</div>
                <div class="stat-value"><?= h((string)$stats['ready_to_start']) ?></div>
                <div class="stat-note">Assigned services that can begin now</div>
            </div>

            <div class="stat">
                <div class="stat-label">In Progress</div>
                <div class="stat-value"><?= h((string)$stats['in_progress']) ?></div>
                <div class="stat-note">Active services already underway</div>
            </div>

            <div class="stat">
                <div class="stat-label">Completed</div>
                <div class="stat-value"><?= h((string)$stats['completed']) ?></div>
                <div class="stat-note">Finished jobs in your current visible set</div>
            </div>

            <div class="stat">
                <div class="stat-label">Open Opportunities</div>
                <div class="stat-value"><?= h((string)$stats['available_jobs']) ?></div>
                <div class="stat-note">Jobs still available to claim</div>
            </div>

            <div class="stat">
                <div class="stat-label">Notifications</div>
                <div class="stat-value"><?= h((string)$stats['notifications']) ?></div>
                <div class="stat-note">Unread or fresh worker alerts</div>
            </div>
        </section>

        <section class="quick-grid">
            <article class="quick-card">
                <h3 class="quick-title">My Assigned Jobs</h3>
                <p class="quick-copy">See everything currently assigned to your account in one clean worker view.</p>
                <a class="mini-btn" href="walker-jobs.php">Open Jobs</a>
            </article>

            <article class="quick-card">
                <h3 class="quick-title">Available Queue</h3>
                <p class="quick-copy">Review open opportunities that are still unassigned and available to claim.</p>
                <a class="mini-btn" href="walker-available.php">Open Queue</a>
            </article>

            <article class="quick-card">
                <h3 class="quick-title">Notifications</h3>
                <p class="quick-copy">Check worker alerts for assignments, in-progress work, and new opportunities.</p>
                <a class="mini-btn" href="walker-notifications.php">Open Alerts</a>
            </article>

            <article class="quick-card">
                <h3 class="quick-title">Profile</h3>
                <p class="quick-copy">Keep your contact details, bio, and availability aligned with operations.</p>
                <a class="mini-btn" href="walker-profile.php">Open Profile</a>
            </article>
        </section>

        <div class="content-grid">
            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2 class="panel-title">Ready to start</h2>
                        <p class="panel-subtitle">Your next assigned services that can be started now</p>
                    </div>
                    <div class="badge"><?= h((string)$stats['ready_to_start']) ?> Ready</div>
                </div>

                <?php if (empty($nextAssignedJobs)): ?>
                    <div class="empty-state">
                        No assigned jobs are currently waiting to be started.
                    </div>
                <?php else: ?>
                    <div class="job-list">
                        <?php foreach ($nextAssignedJobs as $job): ?>
                            <?php $statusLabel = niceStatus((string)($job['status_name'] ?? 'Assigned')); ?>
                            <article class="job-card">
                                <div class="job-top">
                                    <div>
                                        <h3 class="job-title"><?= h(niceService((string)($job['service_name'] ?? 'Service'))) ?> #<?= h((string)$job['id']) ?></h3>
                                        <div class="job-meta"><?= h(formatJobDate((string)($job['date_value'] ?? ''), (string)($job['time_value'] ?? ''))) ?></div>
                                    </div>
                                    <div class="status-pill status-assigned"><?= h($statusLabel) ?></div>
                                </div>

                                <div class="job-grid">
                                    <div class="job-item">
                                        <span>Pet</span>
                                        <strong><?= h(trim((string)($job['pet_name_value'] ?? '')) !== '' ? (string)$job['pet_name_value'] : 'Not listed') ?></strong>
                                    </div>
                                    <div class="job-item">
                                        <span>Client Ref</span>
                                        <strong><?= h(trim((string)($job['client_value'] ?? '')) !== '' ? (string)$job['client_value'] : 'Private') ?></strong>
                                    </div>
                                    <div class="job-item">
                                        <span>Location</span>
                                        <strong><?= h(trim((string)($job['address_value'] ?? '')) !== '' ? (string)$job['address_value'] : 'Not provided yet') ?></strong>
                                    </div>
                                    <div class="job-item">
                                        <span>Status</span>
                                        <strong><?= h($statusLabel) ?></strong>
                                    </div>
                                </div>

                                <div class="job-actions">
                                    <a class="mini-btn" href="walker-start-job.php?id=<?= urlencode((string)$job['id']) ?>">Start Job</a>
                                    <a class="mini-btn-ghost" href="walker-job-view.php?id=<?= urlencode((string)$job['id']) ?>">View Details</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2 class="panel-title">Live work</h2>
                        <p class="panel-subtitle">Services already in progress and ready to continue tracking</p>
                    </div>
                    <div class="badge"><?= h((string)$stats['in_progress']) ?> Active</div>
                </div>

                <?php if (empty($liveJobs)): ?>
                    <div class="empty-state">
                        No jobs are currently in progress.
                    </div>
                <?php else: ?>
                    <div class="job-list">
                        <?php foreach ($liveJobs as $job): ?>
                            <?php
                            $statusLabel = niceStatus((string)($job['status_name'] ?? 'In Progress'));
                            $elapsed = formatElapsedShort((string)($job['started_at_value'] ?? ''));
                            ?>
                            <article class="job-card">
                                <div class="job-top">
                                    <div>
                                        <h3 class="job-title"><?= h(niceService((string)($job['service_name'] ?? 'Service'))) ?> #<?= h((string)$job['id']) ?></h3>
                                        <div class="job-meta"><?= h(formatJobDate((string)($job['date_value'] ?? ''), (string)($job['time_value'] ?? ''))) ?></div>
                                    </div>
                                    <div class="status-pill status-progress"><?= h($statusLabel) ?></div>
                                </div>

                                <div class="job-grid">
                                    <div class="job-item">
                                        <span>Pet</span>
                                        <strong><?= h(trim((string)($job['pet_name_value'] ?? '')) !== '' ? (string)$job['pet_name_value'] : 'Not listed') ?></strong>
                                    </div>
                                    <div class="job-item">
                                        <span>Location</span>
                                        <strong><?= h(trim((string)($job['address_value'] ?? '')) !== '' ? (string)$job['address_value'] : 'Not provided yet') ?></strong>
                                    </div>
                                    <div class="job-item">
                                        <span>Elapsed</span>
                                        <strong><?= h($elapsed) ?></strong>
                                    </div>
                                    <div class="job-item">
                                        <span>Status</span>
                                        <strong><?= h($statusLabel) ?></strong>
                                    </div>
                                </div>

                                <div class="job-actions">
                                    <a class="mini-btn" href="walker-track.php?id=<?= urlencode((string)$job['id']) ?>">Continue Tracking</a>
                                    <a class="mini-btn-ghost" href="walker-job-view.php?id=<?= urlencode((string)$job['id']) ?>">View Details</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <section class="panel" style="margin-top:18px;">
            <div class="panel-header">
                <div>
                    <h2 class="panel-title">Available jobs preview</h2>
                    <p class="panel-subtitle">Open opportunities still available to claim</p>
                </div>
                <div class="badge"><?= h((string)$stats['available_jobs']) ?> Open</div>
            </div>

            <?php if (empty($availableJobsPreview)): ?>
                <div class="empty-state">
                    No open jobs are available right now.
                </div>
            <?php else: ?>
                <div class="job-list">
                    <?php foreach ($availableJobsPreview as $job): ?>
                        <article class="job-card">
                            <div class="job-top">
                                <div>
                                    <h3 class="job-title"><?= h(niceService((string)($job['service_name'] ?? 'Service'))) ?> #<?= h((string)$job['id']) ?></h3>
                                    <div class="job-meta"><?= h(formatJobDate((string)($job['date_value'] ?? ''), (string)($job['time_value'] ?? ''))) ?></div>
                                </div>
                                <div class="status-pill status-open"><?= h(niceStatus((string)($job['status_name'] ?? 'Open'))) ?></div>
                            </div>

                            <div class="job-grid">
                                <div class="job-item">
                                    <span>Pet</span>
                                    <strong><?= h(trim((string)($job['pet_name_value'] ?? '')) !== '' ? (string)$job['pet_name_value'] : 'Not listed') ?></strong>
                                </div>
                                <div class="job-item">
                                    <span>Client Ref</span>
                                    <strong><?= h(trim((string)($job['client_value'] ?? '')) !== '' ? (string)$job['client_value'] : 'Private') ?></strong>
                                </div>
                                <div class="job-item">
                                    <span>Location</span>
                                    <strong><?= h(trim((string)($job['address_value'] ?? '')) !== '' ? (string)$job['address_value'] : 'Not provided yet') ?></strong>
                                </div>
                                <div class="job-item">
                                    <span>Status</span>
                                    <strong><?= h(niceStatus((string)($job['status_name'] ?? 'Open'))) ?></strong>
                                </div>
                            </div>

                            <div class="job-actions">
                                <a class="mini-btn" href="walker-accept-job.php?id=<?= urlencode((string)$job['id']) ?>">Accept Job</a>
                                <a class="mini-btn-ghost" href="walker-job-view.php?id=<?= urlencode((string)$job['id']) ?>">View Details</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top:14px;">
                    <a class="mini-btn-ghost" href="walker-available.php">See Full Available Queue</a>
                </div>
            <?php endif; ?>
        </section>

        <div class="footer-note">
            Signed in as <?= h($workerEmail !== '' ? $workerEmail : $displayName) ?> · Worker-only dashboard enforced
        </div>
    </div>
</body>
</html>