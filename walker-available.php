<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security-headers.php';

session_start();
require_once __DIR__ . '/db.php';

/*
|--------------------------------------------------------------------------
| Walker Available Jobs
|--------------------------------------------------------------------------
| PURPOSE
| - Worker-safe open jobs queue
| - Shows only open / unassigned jobs
| - Lets workers review and claim available work
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
    $_SESSION['walker_flash_message'] = 'You do not have permission to access available jobs.';
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

/* ==========================================================================
   DATA
   ========================================================================== */

$error = '';
$availableJobs = [];

$stats = [
    'open_total' => 0,
    'walks' => 0,
    'boarding' => 0,
    'other' => 0,
];

/* ==========================================================================
   LOAD AVAILABLE JOBS
   ========================================================================== */

if (!tableExists($pdo, $BOOKINGS_TABLE)) {
    $error = "The bookings table was not found. Update \$BOOKINGS_TABLE in walker-available.php if needed.";
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

        if ($walkerIdCol === null) {
            $error = 'No worker assignment column was found. Add one like walker_id, staff_id, or employee_id.';
        } elseif ($statusCol === null) {
            $error = 'No booking status column was found. Add status or booking_status.';
        } else {
            [$openPlaceholders, $openParams] = buildInClause($openStatuses, 'open');

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

            $orderCol = $dateCol ?? $createdCol ?? 'id';

            $sql = "
                SELECT
                    " . implode(",\n                    ", $selectParts) . "
                FROM $BOOKINGS_TABLE
                WHERE ($walkerIdCol IS NULL OR $walkerIdCol = 0 OR $walkerIdCol = '')
                  AND $statusCol IN (" . implode(', ', $openPlaceholders) . ")
                ORDER BY $orderCol ASC, id DESC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($openParams);
            $availableJobs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $stats['open_total'] = count($availableJobs);

            foreach ($availableJobs as $job) {
                $serviceRaw = strtolower(trim((string)($job['service_name'] ?? '')));

                if (str_contains($serviceRaw, 'walk')) {
                    $stats['walks']++;
                } elseif (str_contains($serviceRaw, 'board')) {
                    $stats['boarding']++;
                } else {
                    $stats['other']++;
                }
            }
        }
    } catch (Throwable $e) {
        $error = 'Available jobs error: ' . $e->getMessage();
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
    <title>Available Jobs | Doggie Dorian’s</title>
    <meta name="description" content="Available worker jobs page for Doggie Dorian’s.">
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
        .job-card {
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
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-top: 18px;
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
            background: rgba(217,180,107,0.12);
            color: var(--gold);
        }

        .job-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
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
            .hero-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .job-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 760px) {
            .container {
                width: min(100% - 18px, 1320px);
                padding-top: 18px;
            }

            .hero,
            .panel {
                padding: 18px;
            }

            .hero-grid,
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
                <h1 class="headline">Available Jobs</h1>
                <p class="subheadline">
                    Review open opportunities that have not been assigned yet and claim the ones that fit your schedule.
                </p>
            </div>

            <div class="top-actions">
                <a class="btn-secondary" href="walker-dashboard.php">Dashboard</a>
                <a class="btn-secondary" href="walker-jobs.php">My Jobs</a>
                <a class="btn-secondary" href="walker-profile.php">Profile</a>
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
            <div class="eyebrow">Open Worker Queue</div>
            <h2 style="margin:0; font-size:28px; letter-spacing:-0.03em;">Unassigned opportunities</h2>
            <p class="subheadline" style="margin-top:10px;">
                These jobs are currently open and can be reviewed before you claim them into your own workload.
            </p>

            <div class="hero-grid">
                <div class="stat">
                    <div class="stat-label">Open Total</div>
                    <div class="stat-value"><?= h((string)$stats['open_total']) ?></div>
                    <div class="stat-note">All currently available jobs</div>
                </div>

                <div class="stat">
                    <div class="stat-label">Walks</div>
                    <div class="stat-value"><?= h((string)$stats['walks']) ?></div>
                    <div class="stat-note">Open walk-related bookings</div>
                </div>

                <div class="stat">
                    <div class="stat-label">Boarding</div>
                    <div class="stat-value"><?= h((string)$stats['boarding']) ?></div>
                    <div class="stat-note">Open boarding-style bookings</div>
                </div>

                <div class="stat">
                    <div class="stat-label">Other Services</div>
                    <div class="stat-value"><?= h((string)$stats['other']) ?></div>
                    <div class="stat-note">Other open service types</div>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2 class="panel-title">Available queue</h2>
                    <p class="panel-subtitle">Only open and unassigned jobs are shown here</p>
                </div>
                <div class="badge"><?= h((string)$stats['open_total']) ?> Open</div>
            </div>

            <?php if (empty($availableJobs)): ?>
                <div class="empty-state">
                    No open jobs are available right now.
                </div>
            <?php else: ?>
                <div class="job-list">
                    <?php foreach ($availableJobs as $job): ?>
                        <?php $statusLabel = niceStatus((string)($job['status_name'] ?? 'Open')); ?>
                        <article class="job-card">
                            <div class="job-top">
                                <div>
                                    <h3 class="job-title"><?= h(niceService((string)($job['service_name'] ?? 'Service'))) ?> #<?= h((string)$job['id']) ?></h3>
                                    <div class="job-meta"><?= h(formatJobDate((string)($job['date_value'] ?? ''), (string)($job['time_value'] ?? ''))) ?></div>
                                </div>
                                <div class="status-pill"><?= h($statusLabel) ?></div>
                            </div>

                            <div class="job-grid">
                                <div class="job-item">
                                    <span>Pet</span>
                                    <strong><?= h(niceText((string)($job['pet_name_value'] ?? ''), 'Not listed')) ?></strong>
                                </div>

                                <div class="job-item">
                                    <span>Breed</span>
                                    <strong><?= h(niceText((string)($job['pet_breed_value'] ?? ''), 'Not listed')) ?></strong>
                                </div>

                                <div class="job-item">
                                    <span>Size</span>
                                    <strong><?= h(niceText((string)($job['pet_size_value'] ?? ''), 'Not listed')) ?></strong>
                                </div>

                                <div class="job-item">
                                    <span>Location</span>
                                    <strong><?= h(niceText((string)($job['address_value'] ?? ''), 'Not provided')) ?></strong>
                                </div>

                                <div class="job-item">
                                    <span>Client Ref</span>
                                    <strong><?= h(niceText((string)($job['client_value'] ?? ''), 'Private')) ?></strong>
                                </div>

                                <div class="job-item">
                                    <span>Duration</span>
                                    <strong><?= h(trim((string)($job['duration_value'] ?? '')) !== '' ? (string)$job['duration_value'] . ' min' : 'Not stored') ?></strong>
                                </div>

                                <div class="job-item" style="grid-column: span 2;">
                                    <span>Notes</span>
                                    <strong><?= h(niceText((string)($job['notes_value'] ?? ''), 'No special notes')) ?></strong>
                                </div>
                            </div>

                            <div class="job-actions">
                                <a class="mini-btn" href="walker-accept-job.php?id=<?= urlencode((string)$job['id']) ?>">Accept Job</a>
                                <a class="mini-btn-ghost" href="walker-job-view.php?id=<?= urlencode((string)$job['id']) ?>">View Details</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <div class="footer-note">
            Signed in as <?= h($workerEmail !== '' ? $workerEmail : $displayName) ?> · Worker-safe open queue enforced
        </div>
    </div>
</body>
</html>