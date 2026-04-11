<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security-headers.php';

session_start();
require_once __DIR__ . '/db.php';

/*
|--------------------------------------------------------------------------
| Walker Start Job
|--------------------------------------------------------------------------
| PURPOSE
| - Worker-safe endpoint/page to start an assigned job
| - Only allows starting jobs assigned to the logged-in worker
| - Moves job into live / in-progress state
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
    $_SESSION['walker_flash_message'] = 'You do not have permission to start jobs.';
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
$possibleDurationColumns = ['duration_minutes', 'actual_duration_minutes', 'walk_duration_minutes', 'service_duration_minutes'];

$possibleStartColumns = [
    'walk_started_at',
    'started_at',
    'service_started_at',
    'job_started_at',
    'actual_start',
    'actual_start_at'
];

$startableStatuses = ['assigned', 'accepted', 'confirmed', 'scheduled'];
$trackableStatuses = ['in_progress', 'in progress', 'started', 'active'];
$completedStatuses = ['completed', 'done'];

$inProgressStatus = 'in_progress';

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
        return (bool) $stmt->fetchColumn();
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

function niceService(?string $service): string
{
    $service = trim((string) $service);
    if ($service === '') {
        return 'Service';
    }

    $service = str_replace(['_', '-'], ' ', strtolower($service));
    return ucwords($service);
}

function niceStatus(?string $status): string
{
    $status = trim((string) $status);
    if ($status === '') {
        return 'Pending';
    }

    $status = str_replace(['_', '-'], ' ', strtolower($status));
    return ucwords($status);
}

function niceText(?string $value, string $fallback = 'Not provided'): string
{
    $value = trim((string) $value);
    return $value !== '' ? $value : $fallback;
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

/* ==========================================================================
   LOAD JOB
   ========================================================================== */

$error = '';
$success = '';
$job = null;
$started = false;

if (!tableExists($pdo, $BOOKINGS_TABLE)) {
    $error = "The bookings table was not found. Update \$BOOKINGS_TABLE in walker-start-job.php if needed.";
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
        $breedCol = firstExistingColumn($possibleBreedColumns, $columns);
        $sizeCol = firstExistingColumn($possibleSizeColumns, $columns);
        $notesCol = firstExistingColumn($possibleNotesColumns, $columns);
        $addressCol = firstExistingColumn($possibleAddressColumns, $columns);
        $clientCol = firstExistingColumn($possibleClientColumns, $columns);
        $durationCol = firstExistingColumn($possibleDurationColumns, $columns);
        $startCol = firstExistingColumn($possibleStartColumns, $columns);

        if ($walkerIdCol === null) {
            $error = 'No worker assignment column was found. Add one like walker_id, staff_id, or employee_id.';
        } elseif ($statusCol === null) {
            $error = 'No booking status column was found. Add status or booking_status.';
        } else {
            $selectParts = [
                'id',
                "$walkerIdCol AS assigned_worker_id",
                "$statusCol AS status_name"
            ];
            $selectParts[] = $serviceCol ? "$serviceCol AS service_name" : "'' AS service_name";
            $selectParts[] = $dateCol ? "$dateCol AS date_value" : "'' AS date_value";
            $selectParts[] = $timeCol ? "$timeCol AS time_value" : "'' AS time_value";
            $selectParts[] = $createdCol ? "$createdCol AS created_value" : "'' AS created_value";
            $selectParts[] = $petCol ? "$petCol AS pet_name_value" : "'' AS pet_name_value";
            $selectParts[] = $breedCol ? "$breedCol AS pet_breed_value" : "'' AS pet_breed_value";
            $selectParts[] = $sizeCol ? "$sizeCol AS pet_size_value" : "'' AS pet_size_value";
            $selectParts[] = $notesCol ? "$notesCol AS notes_value" : "'' AS notes_value";
            $selectParts[] = $addressCol ? "$addressCol AS address_value" : "'' AS address_value";
            $selectParts[] = $clientCol ? "$clientCol AS client_value" : "'' AS client_value";
            $selectParts[] = $durationCol ? "$durationCol AS duration_value" : "'' AS duration_value";
            $selectParts[] = $startCol ? "$startCol AS started_at_value" : "'' AS started_at_value";

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
                    $error = 'This job is not assigned to you.';
                } elseif (in_array($statusRaw, $completedStatuses, true)) {
                    $error = 'This job has already been completed.';
                } elseif (in_array($statusRaw, $trackableStatuses, true)) {
                    $error = 'This job is already in progress.';
                } elseif (!in_array($statusRaw, $startableStatuses, true)) {
                    $error = 'This job is not currently in a startable status.';
                } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $pdo->beginTransaction();

                    $sqlRecheck = "
                        SELECT
                            id,
                            $walkerIdCol AS assigned_worker_id,
                            $statusCol AS status_name
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
                        } elseif (in_array($currentStatusRaw, $trackableStatuses, true)) {
                            $pdo->rollBack();
                            $error = 'This job has already been started.';
                        } elseif (in_array($currentStatusRaw, $completedStatuses, true)) {
                            $pdo->rollBack();
                            $error = 'This job has already been completed.';
                        } elseif (!in_array($currentStatusRaw, $startableStatuses, true)) {
                            $pdo->rollBack();
                            $error = 'This job is no longer in a startable state.';
                        } else {
                            $updateParts = [
                                "$statusCol = :new_status"
                            ];

                            $params = [
                                ':new_status' => $inProgressStatus,
                                ':job_id' => $jobId,
                            ];

                            if ($startCol !== null) {
                                $updateParts[] = "$startCol = :started_at";
                                $params[':started_at'] = date('Y-m-d H:i:s');
                            }

                            $sqlUpdate = "
                                UPDATE $BOOKINGS_TABLE
                                SET " . implode(", ", $updateParts) . "
                                WHERE id = :job_id
                            ";

                            $stmtUpdate = $pdo->prepare($sqlUpdate);
                            $stmtUpdate->execute($params);

                            $pdo->commit();

                            $job['status_name'] = $inProgressStatus;
                            if ($startCol !== null) {
                                $job['started_at_value'] = $params[':started_at'];
                            }

                            $started = true;
                            $success = 'Job started successfully. You can now continue in live tracking.';

                            $_SESSION['walker_flash_type'] = 'success';
                            $_SESSION['walker_flash_message'] = 'Job started successfully.';
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Start job error: ' . $e->getMessage();
    }
}

$displayName = trim($workerName) !== '' ? $workerName : 'Worker';
$firstName = explode(' ', $displayName)[0] ?: 'Worker';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Start Job | Doggie Dorian’s</title>
    <meta name="description" content="Worker-safe job start page for Doggie Dorian’s.">
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
                radial-gradient(circle at top left, rgba(217,180,107,0.18), transparent 28%),
                radial-gradient(circle at top right, rgba(138,227,176,0.10), transparent 24%),
                linear-gradient(180deg, var(--bg-1), var(--bg-2));
        }

        a { color: inherit; text-decoration: none; }
        button { font: inherit; }

        .container {
            width: min(1120px, calc(100% - 32px));
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
            font-size: clamp(30px, 5vw, 48px);
            line-height: 0.98;
            letter-spacing: -0.05em;
        }

        .subheadline {
            margin: 12px 0 0;
            font-size: 15px;
            line-height: 1.75;
            color: var(--muted);
            max-width: 720px;
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
            border: none;
            cursor: pointer;
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

        .status-assigned { background: rgba(143,197,255,0.12); color: var(--blue); }
        .status-progress { background: rgba(138,227,176,0.12); color: var(--green); }
        .status-neutral { background: rgba(255,255,255,0.08); color: var(--text); }

        .hero-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
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

        @media (max-width: 980px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 760px) {
            .container {
                width: min(100% - 18px, 1120px);
                padding-top: 18px;
            }

            .hero,
            .panel {
                padding: 18px;
            }

            .details-grid {
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
        }
    </style>
</head>
<body>
    <div class="container">

        <div class="topbar">
            <div>
                <div class="eyebrow">Doggie Dorian’s Worker Portal</div>
                <h1 class="headline">Start Job</h1>
                <p class="subheadline">
                    Review this assigned service and begin live work when you are ready.
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
            $statusRaw = strtolower(trim((string)($job['status_name'] ?? '')));
            $statusLabel = niceStatus((string)($job['status_name'] ?? 'Assigned'));
            $scheduledLabel = formatJobDate((string)($job['date_value'] ?? ''), (string)($job['time_value'] ?? ''));
            $statusClass = in_array($statusRaw, $trackableStatuses, true) ? 'status-progress' : 'status-assigned';
            ?>
            <section class="hero">
                <div class="hero-top">
                    <div>
                        <div class="eyebrow"><?= $started ? 'Job Started' : 'Assigned To You' ?></div>
                        <h2 class="hero-title"><?= h($serviceLabel) ?> #<?= h((string)$job['id']) ?></h2>
                        <div class="hero-meta">
                            Scheduled: <?= h($scheduledLabel) ?><br>
                            Worker: <?= h($workerEmail !== '' ? $workerEmail : $displayName) ?>
                        </div>
                    </div>
                    <div class="status-pill <?= h($statusClass) ?>"><?= h($statusLabel) ?></div>
                </div>

                <div class="hero-actions">
                    <?php if (!$started && $error === ''): ?>
                        <form method="post" action="walker-start-job.php?id=<?= urlencode((string)$job['id']) ?>" style="display:inline;">
                            <button type="submit" class="btn" onclick="return confirm('Start this job now?');">
                                Start Job
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($started): ?>
                        <a class="btn" href="walker-track.php?id=<?= urlencode((string)$job['id']) ?>">Go To Live Tracking</a>
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
                            <p class="panel-subtitle">Review the assigned booking before going live</p>
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
                            <span class="detail-label">Client Ref</span>
                            <div class="detail-value"><?= h(niceText((string)($job['client_value'] ?? ''), 'Private')) ?></div>
                        </div>

                        <div class="detail-card">
                            <span class="detail-label">Location</span>
                            <div class="detail-value"><?= h(niceText((string)($job['address_value'] ?? ''), 'Not provided')) ?></div>
                        </div>

                        <div class="detail-card">
                            <span class="detail-label">Stored Duration</span>
                            <div class="detail-value">
                                <?= h(trim((string)($job['duration_value'] ?? '')) !== '' ? (string)$job['duration_value'] . ' min' : 'Not stored') ?>
                            </div>
                        </div>

                        <div class="detail-card">
                            <span class="detail-label">Started At</span>
                            <div class="detail-value"><?= h(formatDateTimeValue((string)($job['started_at_value'] ?? ''), 'Not started')) ?></div>
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
                            <h2 class="panel-title">Start flow</h2>
                            <p class="panel-subtitle">What happens when you begin this service</p>
                        </div>
                    </div>

                    <div class="action-stack">
                        <div class="action-card">
                            <h3 class="action-title">Status moves live</h3>
                            <p class="action-copy">
                                Starting this job moves it from an assigned state into an in-progress state so it can appear in your live worker flow.
                            </p>
                        </div>

                        <div class="action-card">
                            <h3 class="action-title">Start time is recorded</h3>
                            <p class="action-copy">
                                If your bookings table supports a start timestamp column, this action stores the exact time you began the service.
                            </p>
                        </div>

                        <div class="action-card">
                            <h3 class="action-title">Next step</h3>
                            <p class="action-copy">
                                After the job starts, continue in the live tracking page to manage timing and progress.
                            </p>

                            <?php if (!$started && $error === ''): ?>
                                <form method="post" action="walker-start-job.php?id=<?= urlencode((string)$job['id']) ?>">
                                    <button type="submit" class="mini-btn" onclick="return confirm('Start this job now?');">
                                        Confirm Start
                                    </button>
                                </form>
                            <?php else: ?>
                                <a class="mini-btn-ghost" href="walker-track.php?id=<?= urlencode((string)$job['id']) ?>">Open Live Tracking</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            </div>
        <?php endif; ?>

        <div class="footer-note">
            Signed in as <?= h($workerEmail !== '' ? $workerEmail : $displayName) ?> · Worker-safe start flow enforced
        </div>
    </div>
</body>
</html>