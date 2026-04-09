<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security-headers.php';

session_start();
require_once __DIR__ . '/db.php';

/*
|--------------------------------------------------------------------------
| Walker Track
|--------------------------------------------------------------------------
| PURPOSE
| - Worker-safe live tracking page
| - Only allows access to jobs assigned to the logged-in worker
| - Only for jobs already in progress
| - Allows worker to complete the job safely
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
    $_SESSION['walker_flash_message'] = 'You do not have permission to access live tracking.';
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
   INPUT
   ========================================================================== */

$jobId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($jobId <= 0) {
    $_SESSION['walker_flash_type'] = 'error';
    $_SESSION['walker_flash_message'] = 'Invalid job ID.';
    header('Location: walker-jobs.php');
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

$possibleWalkerIdColumns = ['walker_id', 'staff_id', 'employee_id'];
$possibleServiceColumns = ['service_type', 'booking_type', 'service'];
$possibleStatusColumns = ['status', 'booking_status'];
$possibleDateColumns = ['scheduled_date', 'service_date', 'booking_date', 'start_date'];
$possibleTimeColumns = ['scheduled_time', 'service_time', 'booking_time'];
$possibleCreatedColumns = ['created_at', 'created_on'];
$possiblePetColumns = ['pet_name', 'dog_name'];
$possibleBreedColumns = ['pet_breed', 'dog_breed', 'breed'];
$possibleSizeColumns = ['pet_size', 'dog_size', 'size'];
$possibleNotesColumns = ['notes', 'special_instructions'];
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

$possibleCompletedColumns = [
    'walk_completed_at',
    'completed_at',
    'service_completed_at',
    'job_completed_at',
    'actual_end',
    'actual_end_at'
];

$possibleDurationColumns = [
    'duration_minutes',
    'actual_duration_minutes',
    'walk_duration_minutes',
    'service_duration_minutes'
];

$trackableStatuses = ['in_progress', 'in progress', 'started', 'active'];
$completedStatuses = ['completed', 'done'];
$completedStatus = 'completed';

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

function niceText(?string $value, string $fallback = 'Not provided'): string
{
    $value = trim((string)$value);
    return $value !== '' ? $value : $fallback;
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

function formatDateTimeValue(?string $value, string $fallback = 'Not available'): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return $fallback;
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return $value;
    }

    return date('M j, Y g:i A', $ts);
}

function durationMinutesFromStrings(?string $start, ?string $end): ?int
{
    $start = trim((string)$start);
    $end = trim((string)$end);

    if ($start === '' || $end === '') {
        return null;
    }

    $startTs = strtotime($start);
    $endTs = strtotime($end);

    if ($startTs === false || $endTs === false || $endTs < $startTs) {
        return null;
    }

    return (int) floor(($endTs - $startTs) / 60);
}

/* ==========================================================================
   LOAD JOB
   ========================================================================== */

$error = '';
$success = '';
$job = null;
$completedNow = false;

$schema = [
    'walker_col' => null,
    'service_col' => null,
    'status_col' => null,
    'date_col' => null,
    'time_col' => null,
    'created_col' => null,
    'pet_col' => null,
    'breed_col' => null,
    'size_col' => null,
    'notes_col' => null,
    'address_col' => null,
    'client_col' => null,
    'start_col' => null,
    'completed_col' => null,
    'duration_col' => null,
];

if (!tableExists($pdo, $BOOKINGS_TABLE)) {
    $error = "The bookings table was not found. Update \$BOOKINGS_TABLE in walker-track.php if needed.";
} else {
    try {
        $columns = getTableColumns($pdo, $BOOKINGS_TABLE);

        $schema['walker_col'] = firstExistingColumn($possibleWalkerIdColumns, $columns);
        $schema['service_col'] = firstExistingColumn($possibleServiceColumns, $columns);
        $schema['status_col'] = firstExistingColumn($possibleStatusColumns, $columns);
        $schema['date_col'] = firstExistingColumn($possibleDateColumns, $columns);
        $schema['time_col'] = firstExistingColumn($possibleTimeColumns, $columns);
        $schema['created_col'] = firstExistingColumn($possibleCreatedColumns, $columns);
        $schema['pet_col'] = firstExistingColumn($possiblePetColumns, $columns);
        $schema['breed_col'] = firstExistingColumn($possibleBreedColumns, $columns);
        $schema['size_col'] = firstExistingColumn($possibleSizeColumns, $columns);
        $schema['notes_col'] = firstExistingColumn($possibleNotesColumns, $columns);
        $schema['address_col'] = firstExistingColumn($possibleAddressColumns, $columns);
        $schema['client_col'] = firstExistingColumn($possibleClientColumns, $columns);
        $schema['start_col'] = firstExistingColumn($possibleStartColumns, $columns);
        $schema['completed_col'] = firstExistingColumn($possibleCompletedColumns, $columns);
        $schema['duration_col'] = firstExistingColumn($possibleDurationColumns, $columns);

        if ($schema['walker_col'] === null) {
            $error = 'No worker assignment column was found. Add one like walker_id, staff_id, or employee_id.';
        } elseif ($schema['status_col'] === null) {
            $error = 'No booking status column was found. Add status or booking_status.';
        } else {
            $selectParts = [
                'id',
                "{$schema['walker_col']} AS assigned_worker_id",
                "{$schema['status_col']} AS status_name"
            ];
            $selectParts[] = $schema['service_col'] ? "{$schema['service_col']} AS service_name" : "'' AS service_name";
            $selectParts[] = $schema['date_col'] ? "{$schema['date_col']} AS date_value" : "'' AS date_value";
            $selectParts[] = $schema['time_col'] ? "{$schema['time_col']} AS time_value" : "'' AS time_value";
            $selectParts[] = $schema['created_col'] ? "{$schema['created_col']} AS created_value" : "'' AS created_value";
            $selectParts[] = $schema['pet_col'] ? "{$schema['pet_col']} AS pet_name_value" : "'' AS pet_name_value";
            $selectParts[] = $schema['breed_col'] ? "{$schema['breed_col']} AS pet_breed_value" : "'' AS pet_breed_value";
            $selectParts[] = $schema['size_col'] ? "{$schema['size_col']} AS pet_size_value" : "'' AS pet_size_value";
            $selectParts[] = $schema['notes_col'] ? "{$schema['notes_col']} AS notes_value" : "'' AS notes_value";
            $selectParts[] = $schema['address_col'] ? "{$schema['address_col']} AS address_value" : "'' AS address_value";
            $selectParts[] = $schema['client_col'] ? "{$schema['client_col']} AS client_value" : "'' AS client_value";
            $selectParts[] = $schema['start_col'] ? "{$schema['start_col']} AS started_at_value" : "'' AS started_at_value";
            $selectParts[] = $schema['completed_col'] ? "{$schema['completed_col']} AS completed_at_value" : "'' AS completed_at_value";
            $selectParts[] = $schema['duration_col'] ? "{$schema['duration_col']} AS duration_value" : "'' AS duration_value";

            $sql = "
                SELECT
                    " . implode(",\n                    ", $selectParts) . "
                FROM $BOOKINGS_TABLE
                WHERE id = :job_id
                LIMIT 1
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([':job_id' => $jobId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if (!$job) {
                $error = 'Job not found.';
            } else {
                $assignedWorkerId = (int)($job['assigned_worker_id'] ?? 0);
                $statusRaw = strtolower(trim((string)($job['status_name'] ?? '')));

                if ($assignedWorkerId !== $workerId) {
                    $error = 'This live job is not assigned to you.';
                } elseif (in_array($statusRaw, $completedStatuses, true)) {
                    $error = 'This job has already been completed.';
                } elseif (!in_array($statusRaw, $trackableStatuses, true)) {
                    $error = 'This job is not currently in progress.';
                } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'complete_job')) {
                    $pdo->beginTransaction();

                    $sqlRecheck = "
                        SELECT
                            id,
                            {$schema['walker_col']} AS assigned_worker_id,
                            {$schema['status_col']} AS status_name
                            " . ($schema['start_col'] ? ", {$schema['start_col']} AS started_at_value" : ", '' AS started_at_value") . "
                        FROM $BOOKINGS_TABLE
                        WHERE id = :job_id
                        LIMIT 1
                    ";
                    $stmtRecheck = $pdo->prepare($sqlRecheck);
                    $stmtRecheck->execute([':job_id' => $jobId]);
                    $currentJob = $stmtRecheck->fetch(PDO::FETCH_ASSOC);

                    if (!$currentJob) {
                        $pdo->rollBack();
                        $error = 'This job no longer exists.';
                    } else {
                        $currentAssignedWorkerId = (int)($currentJob['assigned_worker_id'] ?? 0);
                        $currentStatusRaw = strtolower(trim((string)($currentJob['status_name'] ?? '')));

                        if ($currentAssignedWorkerId !== $workerId) {
                            $pdo->rollBack();
                            $error = 'This job is no longer assigned to you.';
                        } elseif (in_array($currentStatusRaw, $completedStatuses, true)) {
                            $pdo->rollBack();
                            $error = 'This job has already been completed.';
                        } elseif (!in_array($currentStatusRaw, $trackableStatuses, true)) {
                            $pdo->rollBack();
                            $error = 'This job is no longer in a trackable state.';
                        } else {
                            $now = date('Y-m-d H:i:s');
                            $updateParts = [
                                "{$schema['status_col']} = :completed_status"
                            ];
                            $params = [
                                ':completed_status' => $completedStatus,
                                ':job_id' => $jobId,
                            ];

                            if ($schema['completed_col'] !== null) {
                                $updateParts[] = "{$schema['completed_col']} = :completed_at";
                                $params[':completed_at'] = $now;
                            }

                            if ($schema['duration_col'] !== null) {
                                $minutes = durationMinutesFromStrings(
                                    (string)($currentJob['started_at_value'] ?? ''),
                                    $now
                                );
                                if ($minutes !== null) {
                                    $updateParts[] = "{$schema['duration_col']} = :duration_minutes";
                                    $params[':duration_minutes'] = $minutes;
                                }
                            }

                            $sqlUpdate = "
                                UPDATE $BOOKINGS_TABLE
                                SET " . implode(", ", $updateParts) . "
                                WHERE id = :job_id
                            ";

                            $stmtUpdate = $pdo->prepare($sqlUpdate);
                            $stmtUpdate->execute($params);

                            $pdo->commit();

                            $job['status_name'] = $completedStatus;
                            if ($schema['completed_col'] !== null) {
                                $job['completed_at_value'] = $now;
                            }
                            if ($schema['duration_col'] !== null && isset($params[':duration_minutes'])) {
                                $job['duration_value'] = (string)$params[':duration_minutes'];
                            }

                            $completedNow = true;
                            $success = 'Job completed successfully.';

                            $_SESSION['walker_flash_type'] = 'success';
                            $_SESSION['walker_flash_message'] = 'Job completed successfully.';
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Tracking error: ' . $e->getMessage();
    }
}

$displayName = trim($workerName) !== '' ? $workerName : 'Worker';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Tracking | Doggie Dorian’s</title>
    <meta name="description" content="Worker live tracking page for Doggie Dorian’s.">
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
        }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top left, rgba(217,180,107,0.16), transparent 28%),
                radial-gradient(circle at top right, rgba(138,227,176,0.12), transparent 24%),
                linear-gradient(180deg, var(--bg-1), var(--bg-2));
        }

        a { color: inherit; text-decoration: none; }
        button { font: inherit; }

        .container {
            width: min(1220px, calc(100% - 32px));
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
            font-size: clamp(30px, 5vw, 50px);
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
        .btn-danger,
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
        .btn-secondary,
        .btn-danger {
            min-height: 48px;
            padding: 0 18px;
            border-radius: 999px;
        }

        .btn {
            border: none;
            cursor: pointer;
            background: linear-gradient(135deg, var(--gold), var(--gold-strong));
            color: #17130e;
            box-shadow: 0 16px 34px rgba(191,143,55,0.28);
        }

        .btn-danger {
            border: none;
            cursor: pointer;
            background: linear-gradient(135deg, rgba(255,122,122,0.95), rgba(220,70,70,0.95));
            color: #fff;
            box-shadow: 0 16px 34px rgba(220,70,70,0.24);
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
        .btn-danger:hover,
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
        .timer-card,
        .detail-card,
        .action-card {
            background: var(--card);
            border: 1px solid var(--border);
            backdrop-filter: blur(16px);
            box-shadow: var(--shadow);
        }

        .hero,
        .panel {
            border-radius: var(--radius-xl);
            padding: 24px;
        }

        .hero {
            margin-bottom: 18px;
        }

        .hero-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
        }

        .hero-title {
            margin: 0;
            font-size: 30px;
            letter-spacing: -0.04em;
        }

        .hero-meta {
            margin-top: 10px;
            color: var(--muted);
            font-size: 15px;
            line-height: 1.75;
        }

        .status-pill {
            white-space: nowrap;
            border-radius: 999px;
            padding: 10px 14px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            border: 1px solid rgba(255,255,255,0.12);
        }

        .status-progress { background: rgba(138,227,176,0.12); color: var(--green); }
        .status-completed { background: rgba(255,255,255,0.08); color: var(--text); }

        .hero-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .timer-band {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 18px;
            margin-top: 18px;
        }

        .timer-card {
            border-radius: 24px;
            padding: 20px;
            background: var(--card-strong);
        }

        .timer-label {
            display: block;
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 10px;
        }

        .timer-value {
            font-size: 44px;
            line-height: 1;
            letter-spacing: -0.05em;
            font-weight: 800;
        }

        .timer-sub {
            margin-top: 10px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
        }

        .timer-meta-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .detail-card,
        .action-card {
            border-radius: 22px;
            padding: 18px;
            background: var(--card-strong);
        }

        .detail-label {
            display: block;
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 6px;
        }

        .detail-value {
            font-size: 15px;
            line-height: 1.65;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-top: 18px;
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

        .details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .notes-card {
            margin-top: 14px;
        }

        .action-title {
            margin: 0 0 8px;
            font-size: 18px;
            letter-spacing: -0.02em;
        }

        .action-copy {
            margin: 0 0 14px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
        }

        .action-stack {
            display: grid;
            gap: 14px;
        }

        .footer-note {
            margin-top: 20px;
            text-align: center;
            color: var(--muted);
            font-size: 13px;
        }

        @media (max-width: 1080px) {
            .timer-band,
            .grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 760px) {
            .container {
                width: min(100% - 18px, 1220px);
                padding-top: 18px;
            }

            .hero,
            .panel {
                padding: 18px;
            }

            .details-grid,
            .timer-meta-grid {
                grid-template-columns: 1fr;
            }

            .topbar,
            .hero-top,
            .panel-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .hero-title {
                font-size: 26px;
            }

            .timer-value {
                font-size: 34px;
            }
        }
    </style>
</head>
<body>
    <div class="container">

        <div class="topbar">
            <div>
                <div class="eyebrow">Doggie Dorian’s Worker Portal</div>
                <h1 class="headline">Live Tracking</h1>
                <p class="subheadline">
                    Continue your active service here, monitor elapsed time, and complete the job when finished.
                </p>
            </div>

            <div class="top-actions">
                <a class="btn-secondary" href="walker-dashboard.php">Dashboard</a>
                <a class="btn-secondary" href="walker-jobs.php">My Jobs</a>
                <a class="btn-secondary" href="walker-job-view.php?id=<?= urlencode((string)$jobId) ?>">Job Details</a>
            </div>
        </div>

        <?php if ($flashMessage !== ''): ?>
            <div class="<?= $flashType === 'success' ? 'success-box' : 'error-box' ?>">
                <?= h($flashMessage) ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="success-box"><?= h($success) ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="error-box"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($job !== null): ?>
            <?php
            $serviceLabel = niceService((string)($job['service_name'] ?? 'Service'));
            $statusRaw = strtolower(trim((string)($job['status_name'] ?? 'In Progress')));
            $statusLabel = niceStatus((string)($job['status_name'] ?? 'In Progress'));
            $scheduledLabel = formatJobDate((string)($job['date_value'] ?? ''), (string)($job['time_value'] ?? ''));
            $statusClass = in_array($statusRaw, $completedStatuses, true) ? 'status-completed' : 'status-progress';

            $startIso = '';
            $startedAtRaw = trim((string)($job['started_at_value'] ?? ''));
            if ($startedAtRaw !== '') {
                $startedTs = strtotime($startedAtRaw);
                if ($startedTs !== false) {
                    $startIso = date('c', $startedTs);
                }
            }
            ?>
            <section class="hero">
                <div class="hero-top">
                    <div>
                        <div class="eyebrow"><?= $completedNow ? 'Job Completed' : 'Active Service' ?></div>
                        <h2 class="hero-title"><?= h($serviceLabel) ?> #<?= h((string)$job['id']) ?></h2>
                        <div class="hero-meta">
                            Scheduled: <?= h($scheduledLabel) ?><br>
                            Worker: <?= h($workerEmail !== '' ? $workerEmail : $displayName) ?>
                        </div>
                    </div>
                    <div class="status-pill <?= h($statusClass) ?>"><?= h($statusLabel) ?></div>
                </div>

                <div class="timer-band">
                    <div class="timer-card">
                        <span class="timer-label">Elapsed Time</span>
                        <div class="timer-value" id="liveTimer" data-start="<?= h($startIso) ?>">00:00:00</div>
                        <div class="timer-sub">
                            Started: <?= h(formatDateTimeValue((string)($job['started_at_value'] ?? ''), 'Not stored')) ?><br>
                            Completed: <?= h(formatDateTimeValue((string)($job['completed_at_value'] ?? ''), 'Not completed')) ?>
                        </div>
                    </div>

                    <div class="timer-meta-grid">
                        <div class="detail-card">
                            <span class="detail-label">Pet</span>
                            <div class="detail-value"><?= h(niceText((string)($job['pet_name_value'] ?? ''), 'Not listed')) ?></div>
                        </div>
                        <div class="detail-card">
                            <span class="detail-label">Location</span>
                            <div class="detail-value"><?= h(niceText((string)($job['address_value'] ?? ''), 'Not provided')) ?></div>
                        </div>
                        <div class="detail-card">
                            <span class="detail-label">Client Ref</span>
                            <div class="detail-value"><?= h(niceText((string)($job['client_value'] ?? ''), 'Private')) ?></div>
                        </div>
                        <div class="detail-card">
                            <span class="detail-label">Stored Duration</span>
                            <div class="detail-value">
                                <?= h(trim((string)($job['duration_value'] ?? '')) !== '' ? (string)$job['duration_value'] . ' min' : 'Not stored') ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="hero-actions">
                    <?php if (!$completedNow && !in_array($statusRaw, $completedStatuses, true) && $error === ''): ?>
                        <form method="post" action="walker-track.php?id=<?= urlencode((string)$job['id']) ?>" style="display:inline;">
                            <input type="hidden" name="action" value="complete_job">
                            <button type="submit" class="btn-danger" onclick="return confirm('Complete this job now?');">
                                Complete Job
                            </button>
                        </form>
                    <?php endif; ?>

                    <a class="btn-secondary" href="walker-job-view.php?id=<?= urlencode((string)$job['id']) ?>">Job Details</a>
                    <a class="btn-secondary" href="walker-jobs.php">Back To My Jobs</a>
                </div>
            </section>

            <div class="grid">
                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <h2 class="panel-title">Service details</h2>
                            <p class="panel-subtitle">Live job information for the active worker flow</p>
                        </div>
                        <div class="badge">Job #<?= h((string)$job['id']) ?></div>
                    </div>

                    <div class="details-grid">
                        <div class="detail-card">
                            <span class="detail-label">Service</span>
                            <div class="detail-value"><?= h($serviceLabel) ?></div>
                        </div>

                        <div class="detail-card">
                            <span class="detail-label">Status</span>
                            <div class="detail-value"><?= h($statusLabel) ?></div>
                        </div>

                        <div class="detail-card">
                            <span class="detail-label">Scheduled</span>
                            <div class="detail-value"><?= h($scheduledLabel) ?></div>
                        </div>

                        <div class="detail-card">
                            <span class="detail-label">Started At</span>
                            <div class="detail-value"><?= h(formatDateTimeValue((string)($job['started_at_value'] ?? ''), 'Not stored')) ?></div>
                        </div>

                        <div class="detail-card">
                            <span class="detail-label">Pet</span>
                            <div class="detail-value"><?= h(niceText((string)($job['pet_name_value'] ?? ''), 'Not listed')) ?></div>
                        </div>

                        <div class="detail-card">
                            <span class="detail-label">Breed</span>
                            <div class="detail-value"><?= h(niceText((string)($job['pet_breed_value'] ?? ''), 'Not listed')) ?></div>
                        </div>

                        <div class="detail-card">
                            <span class="detail-label">Size</span>
                            <div class="detail-value"><?= h(niceText((string)($job['pet_size_value'] ?? ''), 'Not listed')) ?></div>
                        </div>

                        <div class="detail-card">
                            <span class="detail-label">Location</span>
                            <div class="detail-value"><?= h(niceText((string)($job['address_value'] ?? ''), 'Not provided')) ?></div>
                        </div>

                        <div class="detail-card">
                            <span class="detail-label">Completed At</span>
                            <div class="detail-value"><?= h(formatDateTimeValue((string)($job['completed_at_value'] ?? ''), 'Not completed')) ?></div>
                        </div>

                        <div class="detail-card">
                            <span class="detail-label">Duration</span>
                            <div class="detail-value">
                                <?= h(trim((string)($job['duration_value'] ?? '')) !== '' ? (string)$job['duration_value'] . ' min' : 'Not stored') ?>
                            </div>
                        </div>
                    </div>

                    <div class="detail-card notes-card">
                        <span class="detail-label">Notes</span>
                        <div class="detail-value"><?= h(niceText((string)($job['notes_value'] ?? ''), 'No special notes')) ?></div>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <h2 class="panel-title">Tracking actions</h2>
                            <p class="panel-subtitle">Complete the live flow when the service is done</p>
                        </div>
                    </div>

                    <div class="action-stack">
                        <div class="action-card">
                            <h3 class="action-title">Live timer</h3>
                            <p class="action-copy">
                                This page uses the stored start time to display live elapsed time while the service is active.
                            </p>
                        </div>

                        <div class="action-card">
                            <h3 class="action-title">Completion update</h3>
                            <p class="action-copy">
                                Completing the job moves it into a completed state and stores completion data if your schema supports it.
                            </p>
                        </div>

                        <div class="action-card">
                            <h3 class="action-title">Finish service</h3>
                            <p class="action-copy">
                                Use this once the walk or service is fully complete and ready to leave the live worker flow.
                            </p>

                            <?php if (!$completedNow && !in_array($statusRaw, $completedStatuses, true) && $error === ''): ?>
                                <form method="post" action="walker-track.php?id=<?= urlencode((string)$job['id']) ?>">
                                    <input type="hidden" name="action" value="complete_job">
                                    <button type="submit" class="mini-btn" onclick="return confirm('Complete this job now?');">
                                        Confirm Completion
                                    </button>
                                </form>
                            <?php else: ?>
                                <a class="mini-btn-ghost" href="walker-jobs.php">Return To My Jobs</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            </div>
        <?php endif; ?>

        <div class="footer-note">
            Signed in as <?= h($workerEmail !== '' ? $workerEmail : $displayName) ?> · Worker-safe live tracking enforced
        </div>
    </div>

    <script>
        (function () {
            const timerEl = document.getElementById('liveTimer');
            if (!timerEl) return;

            const startIso = timerEl.getAttribute('data-start') || '';

            function pad(n) {
                return String(n).padStart(2, '0');
            }

            function render(seconds) {
                const safe = Math.max(0, seconds);
                const hours = Math.floor(safe / 3600);
                const minutes = Math.floor((safe % 3600) / 60);
                const secs = safe % 60;
                timerEl.textContent = pad(hours) + ':' + pad(minutes) + ':' + pad(secs);
            }

            if (!startIso) {
                render(0);
                return;
            }

            const startMs = new Date(startIso).getTime();
            if (Number.isNaN(startMs)) {
                render(0);
                return;
            }

            function tick() {
                const nowMs = Date.now();
                const elapsed = Math.floor((nowMs - startMs) / 1000);
                render(elapsed);
            }

            tick();
            setInterval(tick, 1000);
        })();
    </script>
</body>
</html>