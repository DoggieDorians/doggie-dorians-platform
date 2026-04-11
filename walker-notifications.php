<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';

/*
|--------------------------------------------------------------------------
| Walker Notifications
|--------------------------------------------------------------------------
| PURPOSE
| - Worker-safe notification center
| - Supports 2 modes:
|   1) dedicated walker_notifications table
|   2) fallback generated from bookings activity
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
    $_SESSION['walker_flash_message'] = 'You do not have permission to access worker notifications.';
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

$NOTIFICATIONS_TABLE = 'walker_notifications';
$BOOKINGS_TABLE = 'bookings';

$notificationPossibleWorkerIdCols = ['walker_id', 'user_id', 'staff_id', 'employee_id'];
$notificationPossibleTitleCols = ['title', 'subject'];
$notificationPossibleMessageCols = ['message', 'body', 'content'];
$notificationPossibleTypeCols = ['type', 'category', 'notification_type'];
$notificationPossibleCreatedCols = ['created_at', 'created_on', 'timestamp'];
$notificationPossibleReadCols = ['is_read', 'read_flag', 'seen'];
$notificationPossibleLinkCols = ['link_url', 'url', 'action_url'];

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
$possibleCompletedColumns = [
    'walk_completed_at',
    'completed_at',
    'service_completed_at',
    'job_completed_at',
    'actual_end',
    'actual_end_at'
];

$openStatuses = ['pending', 'open', 'unassigned', 'approved'];
$startableStatuses = ['assigned', 'accepted', 'confirmed', 'scheduled'];
$trackableStatuses = ['in_progress', 'in progress', 'started', 'active'];
$completedStatuses = ['completed', 'done'];

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
        return 'Update';
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

function formatNotificationTime(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return 'Recent';
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return $value;
    }

    return date('M j, Y g:i A', $ts);
}

/* ==========================================================================
   DATA
   ========================================================================== */

$error = '';
$notifications = [];
$usedDedicatedTable = false;

$stats = [
    'total' => 0,
    'unread' => 0,
    'ready' => 0,
    'live' => 0,
    'completed' => 0,
    'open_queue' => 0,
];

/* ==========================================================================
   MODE A: DEDICATED WALKER NOTIFICATIONS TABLE
   ========================================================================== */

try {
    if (tableExists($pdo, $NOTIFICATIONS_TABLE)) {
        $notifColumns = getTableColumns($pdo, $NOTIFICATIONS_TABLE);

        $workerIdCol = firstExistingColumn($notificationPossibleWorkerIdCols, $notifColumns);
        $titleCol = firstExistingColumn($notificationPossibleTitleCols, $notifColumns);
        $messageCol = firstExistingColumn($notificationPossibleMessageCols, $notifColumns);
        $typeCol = firstExistingColumn($notificationPossibleTypeCols, $notifColumns);
        $createdCol = firstExistingColumn($notificationPossibleCreatedCols, $notifColumns);
        $readCol = firstExistingColumn($notificationPossibleReadCols, $notifColumns);
        $linkCol = firstExistingColumn($notificationPossibleLinkCols, $notifColumns);

        if ($workerIdCol !== null) {
            $selectParts = ['id'];
            $selectParts[] = $titleCol ? "$titleCol AS notif_title" : "'' AS notif_title";
            $selectParts[] = $messageCol ? "$messageCol AS notif_message" : "'' AS notif_message";
            $selectParts[] = $typeCol ? "$typeCol AS notif_type" : "'' AS notif_type";
            $selectParts[] = $createdCol ? "$createdCol AS notif_created" : "'' AS notif_created";
            $selectParts[] = $readCol ? "$readCol AS notif_read" : "0 AS notif_read";
            $selectParts[] = $linkCol ? "$linkCol AS notif_link" : "'' AS notif_link";

            $orderCol = $createdCol ?? 'id';

            $sql = "
                SELECT
                    " . implode(",\n                    ", $selectParts) . "
                FROM $NOTIFICATIONS_TABLE
                WHERE $workerIdCol = :worker_id
                ORDER BY $orderCol DESC, id DESC
                LIMIT 75
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([':worker_id' => $workerId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $row) {
                $isRead = (int)($row['notif_read'] ?? 0) === 1;

                $notifications[] = [
                    'title' => trim((string)($row['notif_title'] ?? 'Notification')),
                    'message' => trim((string)($row['notif_message'] ?? '')),
                    'type' => trim((string)($row['notif_type'] ?? 'update')),
                    'time' => trim((string)($row['notif_created'] ?? '')),
                    'is_read' => $isRead,
                    'link' => trim((string)($row['notif_link'] ?? '')),
                ];

                if (!$isRead) {
                    $stats['unread']++;
                }
            }

            $usedDedicatedTable = true;
        }
    }
} catch (Throwable $e) {
    $error = 'Notifications error: ' . $e->getMessage();
}

/* ==========================================================================
   MODE B: FALLBACK FROM BOOKINGS
   ========================================================================== */

if ($error === '' && !$usedDedicatedTable) {
    if (!tableExists($pdo, $BOOKINGS_TABLE)) {
        $error = "Neither a usable walker notifications table nor the bookings table could be loaded.";
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
            $completedCol = firstExistingColumn($possibleCompletedColumns, $columns);

            if ($walkerIdCol === null || $statusCol === null) {
                $error = 'Bookings table is missing required worker/status columns.';
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
                $selectParts[] = $completedCol ? "$completedCol AS completed_at_value" : "'' AS completed_at_value";

                $baseSelect = implode(",\n                    ", $selectParts);
                $orderCol = $createdCol ?? $dateCol ?? 'id';

                // Assigned to this worker
                $sqlMine = "
                    SELECT
                        $baseSelect
                    FROM $BOOKINGS_TABLE
                    WHERE $walkerIdCol = :worker_id
                    ORDER BY $orderCol DESC, id DESC
                    LIMIT 80
                ";
                $stmtMine = $pdo->prepare($sqlMine);
                $stmtMine->execute([':worker_id' => $workerId]);
                $myRows = $stmtMine->fetchAll(PDO::FETCH_ASSOC) ?: [];

                foreach ($myRows as $row) {
                    $statusRaw = strtolower(trim((string)($row['status_name'] ?? '')));
                    $service = niceService((string)($row['service_name'] ?? 'Service'));
                    $petName = trim((string)($row['pet_name_value'] ?? ''));
                    $clientRef = trim((string)($row['client_value'] ?? ''));
                    $schedule = formatJobDate(
                        (string)($row['date_value'] ?? ''),
                        (string)($row['time_value'] ?? '')
                    );

                    $title = $service . ' #' . (string)$row['id'];
                    $message = '';
                    $type = 'update';
                    $link = 'walker-job-view.php?id=' . urlencode((string)$row['id']);

                    if (in_array($statusRaw, $startableStatuses, true)) {
                        $title = 'Ready To Start: ' . $service . ' #' . (string)$row['id'];
                        $message = 'This job is assigned to you and ready to begin. Scheduled: ' . $schedule . '.';
                        $type = 'ready';
                        $stats['ready']++;
                        $stats['unread']++;
                        $link = 'walker-start-job.php?id=' . urlencode((string)$row['id']);
                    } elseif (in_array($statusRaw, $trackableStatuses, true)) {
                        $title = 'Live Now: ' . $service . ' #' . (string)$row['id'];
                        $message = 'This service is currently in progress. Started: ' . formatNotificationTime((string)($row['started_at_value'] ?? '')) . '.';
                        $type = 'live';
                        $stats['live']++;
                        $stats['unread']++;
                        $link = 'walker-track.php?id=' . urlencode((string)$row['id']);
                    } elseif (in_array($statusRaw, $completedStatuses, true)) {
                        $title = 'Completed: ' . $service . ' #' . (string)$row['id'];
                        $message = 'This job has been completed. Completed: ' . formatNotificationTime((string)($row['completed_at_value'] ?? '')) . '.';
                        $type = 'completed';
                        $stats['completed']++;
                        $link = 'walker-job-view.php?id=' . urlencode((string)$row['id']);
                    } else {
                        $message = 'Status: ' . niceStatus((string)($row['status_name'] ?? 'Update')) . '. Scheduled: ' . $schedule . '.';
                    }

                    if ($petName !== '') {
                        $message .= ' Pet: ' . $petName . '.';
                    }
                    if ($clientRef !== '') {
                        $message .= ' Client Ref: ' . $clientRef . '.';
                    }

                    $notifications[] = [
                        'title' => $title,
                        'message' => $message,
                        'type' => $type,
                        'time' => trim((string)($row['created_value'] ?? $row['date_value'] ?? '')),
                        'is_read' => false,
                        'link' => $link,
                    ];
                }

                // Open queue preview notifications
                [$openPlaceholders, $openParams] = buildInClause($openStatuses, 'open');
                $sqlOpen = "
                    SELECT
                        $baseSelect
                    FROM $BOOKINGS_TABLE
                    WHERE ($walkerIdCol IS NULL OR $walkerIdCol = 0 OR $walkerIdCol = '')
                      AND $statusCol IN (" . implode(', ', $openPlaceholders) . ")
                    ORDER BY $orderCol ASC, id DESC
                    LIMIT 8
                ";
                $stmtOpen = $pdo->prepare($sqlOpen);
                $stmtOpen->execute($openParams);
                $openRows = $stmtOpen->fetchAll(PDO::FETCH_ASSOC) ?: [];

                foreach ($openRows as $row) {
                    $service = niceService((string)($row['service_name'] ?? 'Service'));
                    $petName = trim((string)($row['pet_name_value'] ?? ''));
                    $schedule = formatJobDate(
                        (string)($row['date_value'] ?? ''),
                        (string)($row['time_value'] ?? '')
                    );

                    $message = 'This job is available to claim. Scheduled: ' . $schedule . '.';
                    if ($petName !== '') {
                        $message .= ' Pet: ' . $petName . '.';
                    }

                    $notifications[] = [
                        'title' => 'Open Opportunity: ' . $service . ' #' . (string)$row['id'],
                        'message' => $message,
                        'type' => 'open_queue',
                        'time' => trim((string)($row['created_value'] ?? $row['date_value'] ?? '')),
                        'is_read' => false,
                        'link' => 'walker-accept-job.php?id=' . urlencode((string)$row['id']),
                    ];

                    $stats['open_queue']++;
                }
            }
        } catch (Throwable $e) {
            $error = 'Fallback notification error: ' . $e->getMessage();
        }
    }
}

$stats['total'] = count($notifications);
$displayName = trim($workerName) !== '' ? $workerName : 'Worker';
$firstName = explode(' ', $displayName)[0] ?: 'Worker';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Worker Notifications | Doggie Dorian’s</title>
    <meta name="description" content="Worker notifications page for Doggie Dorian’s.">
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

        .btn-secondary,
        .notif-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 48px;
            padding: 0 18px;
            border-radius: 999px;
            font-weight: 700;
            transition: transform .16s ease, background .16s ease, box-shadow .16s ease;
        }

        .btn-secondary,
        .notif-link {
            background: rgba(255,255,255,0.06);
            border: 1px solid var(--border);
            color: var(--text);
        }

        .btn-secondary:hover,
        .notif-link:hover {
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
        .notif-card {
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
            grid-template-columns: repeat(5, 1fr);
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

        .notif-list {
            display: grid;
            gap: 14px;
        }

        .notif-card {
            border-radius: 22px;
            padding: 18px;
        }

        .notif-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }

        .notif-title {
            margin: 0;
            font-size: 18px;
            letter-spacing: -0.02em;
        }

        .notif-time {
            color: var(--muted);
            font-size: 13px;
            white-space: nowrap;
        }

        .notif-message {
            color: var(--text);
            font-size: 15px;
            line-height: 1.7;
            margin-bottom: 14px;
        }

        .notif-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            min-height: 32px;
            padding: 0 11px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            border: 1px solid rgba(255,255,255,0.12);
        }

        .pill-ready { background: rgba(143,197,255,0.12); color: var(--blue); }
        .pill-live { background: rgba(138,227,176,0.12); color: var(--green); }
        .pill-completed { background: rgba(255,255,255,0.08); color: var(--text); }
        .pill-open { background: rgba(217,180,107,0.12); color: var(--gold); }
        .pill-update { background: rgba(255,255,255,0.08); color: var(--text); }

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

            .hero-grid {
                grid-template-columns: 1fr;
            }

            .topbar,
            .notif-top,
            .notif-footer,
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
                <h1 class="headline"><?= h($firstName) ?>’s Notifications</h1>
                <p class="subheadline">
                    This is your worker alert center for assigned services, live work, completed updates, and open opportunities.
                </p>
            </div>

            <div class="top-actions">
                <a class="btn-secondary" href="walker-dashboard.php">Dashboard</a>
                <a class="btn-secondary" href="walker-jobs.php">My Jobs</a>
                <a class="btn-secondary" href="walker-available.php">Available Jobs</a>
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
            <div class="eyebrow">Worker Alert Center</div>
            <h2 style="margin:0; font-size:28px; letter-spacing:-0.03em;">Your activity snapshot</h2>
            <p class="subheadline" style="margin-top:10px;">
                See what needs action now, what is live, what has been completed, and what is still open in the queue.
            </p>

            <div class="hero-grid">
                <div class="stat">
                    <div class="stat-label">Total Alerts</div>
                    <div class="stat-value"><?= h((string)$stats['total']) ?></div>
                    <div class="stat-note">Notifications currently shown</div>
                </div>

                <div class="stat">
                    <div class="stat-label">Unread Style</div>
                    <div class="stat-value"><?= h((string)$stats['unread']) ?></div>
                    <div class="stat-note">Fallback mode treats fresh items as unread-style alerts</div>
                </div>

                <div class="stat">
                    <div class="stat-label">Ready</div>
                    <div class="stat-value"><?= h((string)$stats['ready']) ?></div>
                    <div class="stat-note">Assigned jobs ready to begin</div>
                </div>

                <div class="stat">
                    <div class="stat-label">Live</div>
                    <div class="stat-value"><?= h((string)$stats['live']) ?></div>
                    <div class="stat-note">Active jobs in progress</div>
                </div>

                <div class="stat">
                    <div class="stat-label">Open Queue</div>
                    <div class="stat-value"><?= h((string)$stats['open_queue']) ?></div>
                    <div class="stat-note">Available jobs still open to claim</div>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2 class="panel-title">Notification feed</h2>
                    <p class="panel-subtitle">
                        <?= $usedDedicatedTable
                            ? 'Loaded from your dedicated walker notifications table.'
                            : 'Generated from your jobs and the open queue because no dedicated walker notifications table was found.' ?>
                    </p>
                </div>
                <div class="badge"><?= h((string)$stats['total']) ?> Alerts</div>
            </div>

            <?php if (empty($notifications)): ?>
                <div class="empty-state">
                    No worker notifications are available right now.
                </div>
            <?php else: ?>
                <div class="notif-list">
                    <?php foreach ($notifications as $notification): ?>
                        <?php
                        $type = strtolower(trim((string)($notification['type'] ?? 'update')));
                        $pillClass = 'pill-update';

                        if ($type === 'ready') {
                            $pillClass = 'pill-ready';
                        } elseif ($type === 'live') {
                            $pillClass = 'pill-live';
                        } elseif ($type === 'completed') {
                            $pillClass = 'pill-completed';
                        } elseif ($type === 'open_queue') {
                            $pillClass = 'pill-open';
                        }
                        ?>
                        <article class="notif-card">
                            <div class="notif-top">
                                <div>
                                    <h3 class="notif-title"><?= h((string)($notification['title'] ?? 'Notification')) ?></h3>
                                </div>
                                <div class="notif-time"><?= h(formatNotificationTime((string)($notification['time'] ?? ''))) ?></div>
                            </div>

                            <div class="notif-message">
                                <?= h((string)($notification['message'] ?? '')) ?>
                            </div>

                            <div class="notif-footer">
                                <div class="pill <?= h($pillClass) ?>">
                                    <?= h(niceStatus((string)($notification['type'] ?? 'update'))) ?>
                                </div>

                                <?php if (trim((string)($notification['link'] ?? '')) !== ''): ?>
                                    <a class="notif-link" href="<?= h((string)$notification['link']) ?>">Open</a>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <div class="footer-note">
            Signed in as <?= h($workerEmail !== '' ? $workerEmail : $displayName) ?> · Worker-only notifications enforced
        </div>
    </div>
</body>
</html>