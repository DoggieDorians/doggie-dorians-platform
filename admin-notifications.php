<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';

/*
|--------------------------------------------------------------------------
| Admin Notifications
|--------------------------------------------------------------------------
| PURPOSE
| - Admin-only notifications page
| - Supports 2 modes:
|   1) dedicated admin_notifications table
|   2) fallback generated from bookings activity
|--------------------------------------------------------------------------
*/

/* ==========================================================================
   ACCESS CONTROL
   ========================================================================== */

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: admin-login.php');
    exit;
}

$currentRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
if ($currentRole !== 'admin') {
    header('Location: login.php');
    exit;
}

/* ==========================================================================
   FLASH
   ========================================================================== */

$flashType = $_SESSION['admin_flash_type'] ?? '';
$flashMessage = $_SESSION['admin_flash_message'] ?? '';
unset($_SESSION['admin_flash_type'], $_SESSION['admin_flash_message']);

/* ==========================================================================
   CONFIG
   ========================================================================== */

$ADMIN_NOTIFICATIONS_TABLE = 'admin_notifications';
$BOOKINGS_TABLE = 'bookings';
$USERS_TABLE = 'users';
$USER_ID_COL = 'id';

$notificationPossibleTitleCols = ['title', 'subject'];
$notificationPossibleMessageCols = ['message', 'body', 'content'];
$notificationPossibleTypeCols = ['type', 'category', 'notification_type'];
$notificationPossibleCreatedCols = ['created_at', 'created_on', 'timestamp'];
$notificationPossibleReadCols = ['is_read', 'read_flag', 'seen'];
$notificationPossibleLinkCols = ['link_url', 'url', 'action_url'];

$possibleUserNameCols = ['name', 'full_name', 'display_name'];
$possibleUserEmailCols = ['email'];

$possibleWalkerIdColumns = ['walker_id', 'staff_id', 'employee_id'];
$possibleServiceColumns  = ['service_type', 'booking_type', 'service'];
$possibleStatusColumns   = ['status', 'booking_status'];
$possibleDateColumns     = ['scheduled_date', 'service_date', 'booking_date', 'start_date'];
$possibleTimeColumns     = ['scheduled_time', 'service_time', 'booking_time'];
$possibleCreatedColumns  = ['created_at', 'created_on'];
$possiblePetColumns      = ['pet_name', 'dog_name'];
$possibleAddressColumns  = ['address', 'service_address', 'location'];
$possibleClientColumns   = ['member_id', 'user_id', 'customer_id'];

$openStatuses = ['pending', 'open', 'unassigned', 'approved'];
$assignedStatuses = ['assigned', 'accepted', 'confirmed', 'scheduled'];
$inProgressStatuses = ['in_progress', 'in progress', 'started', 'active'];
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
        $columns = [];

        foreach ($rows as $row) {
            if (!empty($row['name'])) {
                $columns[] = (string)$row['name'];
            }
        }

        return $columns;
    } catch (Throwable $e) {
        return [];
    }
}

function firstExistingColumn(array $preferred, array $existing): ?string
{
    foreach ($preferred as $col) {
        if (in_array($col, $existing, true)) {
            return $col;
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

function workerDisplay(array $workerLookup, int $workerId): string
{
    $worker = $workerLookup[$workerId] ?? null;
    if (!$worker) {
        return 'Worker #' . $workerId;
    }

    $name = trim((string)($worker['worker_name'] ?? ''));
    $email = trim((string)($worker['worker_email'] ?? ''));

    return $name !== '' ? $name : ($email !== '' ? $email : 'Worker #' . $workerId);
}

/* ==========================================================================
   DATA LOAD
   ========================================================================== */

$error = '';
$notifications = [];
$usedDedicatedNotificationsTable = false;

$stats = [
    'total' => 0,
    'unread' => 0,
    'open_jobs' => 0,
    'active_jobs' => 0,
];

$workerLookup = [];

try {
    if (tableExists($pdo, $USERS_TABLE)) {
        $userColumns = getTableColumns($pdo, $USERS_TABLE);
        if (in_array($USER_ID_COL, $userColumns, true)) {
            $userNameCol = firstExistingColumn($possibleUserNameCols, $userColumns);
            $userEmailCol = firstExistingColumn($possibleUserEmailCols, $userColumns);

            $selectParts = [
                $USER_ID_COL . ' AS worker_id'
            ];
            $selectParts[] = $userNameCol ? "$userNameCol AS worker_name" : "'' AS worker_name";
            $selectParts[] = $userEmailCol ? "$userEmailCol AS worker_email" : "'' AS worker_email";

            $sqlUsers = "
                SELECT
                    " . implode(",\n                    ", $selectParts) . "
                FROM $USERS_TABLE
            ";

            $stmtUsers = $pdo->query($sqlUsers);
            $userRows = $stmtUsers ? ($stmtUsers->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

            foreach ($userRows as $row) {
                $workerLookup[(int)$row['worker_id']] = $row;
            }
        }
    }

    /* ----------------------------------------------------------------------
       MODE A: Dedicated admin_notifications table
       ---------------------------------------------------------------------- */
    if (tableExists($pdo, $ADMIN_NOTIFICATIONS_TABLE)) {
        $notifColumns = getTableColumns($pdo, $ADMIN_NOTIFICATIONS_TABLE);

        $titleCol = firstExistingColumn($notificationPossibleTitleCols, $notifColumns);
        $messageCol = firstExistingColumn($notificationPossibleMessageCols, $notifColumns);
        $typeCol = firstExistingColumn($notificationPossibleTypeCols, $notifColumns);
        $createdCol = firstExistingColumn($notificationPossibleCreatedCols, $notifColumns);
        $readCol = firstExistingColumn($notificationPossibleReadCols, $notifColumns);
        $linkCol = firstExistingColumn($notificationPossibleLinkCols, $notifColumns);

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
                " . implode(",\n                ", $selectParts) . "
            FROM $ADMIN_NOTIFICATIONS_TABLE
            ORDER BY $orderCol DESC, id DESC
            LIMIT 75
        ";

        $stmt = $pdo->query($sql);
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        foreach ($rows as $row) {
            $notifications[] = [
                'title' => trim((string)($row['notif_title'] ?? 'Notification')),
                'message' => trim((string)($row['notif_message'] ?? '')),
                'type' => trim((string)($row['notif_type'] ?? 'update')),
                'time' => trim((string)($row['notif_created'] ?? '')),
                'is_read' => (int)($row['notif_read'] ?? 0) === 1,
                'link' => trim((string)($row['notif_link'] ?? '')),
            ];
        }

        $usedDedicatedNotificationsTable = true;
    }

    /* ----------------------------------------------------------------------
       MODE B: Fallback from bookings
       ---------------------------------------------------------------------- */
    if (!$usedDedicatedNotificationsTable) {
        if (!tableExists($pdo, $BOOKINGS_TABLE)) {
            $error = 'Neither a usable admin notifications table nor the bookings table could be loaded.';
        } else {
            $bookingColumns = getTableColumns($pdo, $BOOKINGS_TABLE);

            $walkerIdCol = firstExistingColumn($possibleWalkerIdColumns, $bookingColumns);
            $serviceCol  = firstExistingColumn($possibleServiceColumns, $bookingColumns);
            $statusCol   = firstExistingColumn($possibleStatusColumns, $bookingColumns);
            $dateCol     = firstExistingColumn($possibleDateColumns, $bookingColumns);
            $timeCol     = firstExistingColumn($possibleTimeColumns, $bookingColumns);
            $createdCol  = firstExistingColumn($possibleCreatedColumns, $bookingColumns);
            $petCol      = firstExistingColumn($possiblePetColumns, $bookingColumns);
            $addressCol  = firstExistingColumn($possibleAddressColumns, $bookingColumns);
            $clientCol   = firstExistingColumn($possibleClientColumns, $bookingColumns);

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

                $baseSelect = implode(",\n                    ", $selectParts);
                $orderCol = $createdCol ?? $dateCol ?? 'id';

                $sql = "
                    SELECT
                        $baseSelect
                    FROM $BOOKINGS_TABLE
                    ORDER BY $orderCol DESC, id DESC
                    LIMIT 80
                ";

                $stmt = $pdo->query($sql);
                $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

                foreach ($rows as $row) {
                    $statusRaw = strtolower(trim((string)($row['status_name'] ?? '')));
                    $service = niceService((string)($row['service_name'] ?? 'Service'));
                    $petName = trim((string)($row['pet_name_value'] ?? ''));
                    $clientRef = trim((string)($row['client_value'] ?? ''));
                    $schedule = formatJobDate(
                        (string)($row['date_value'] ?? ''),
                        (string)($row['time_value'] ?? '')
                    );
                    $assignedWorkerId = (int)($row['assigned_worker_id'] ?? 0);
                    $workerName = $assignedWorkerId > 0 ? workerDisplay($workerLookup, $assignedWorkerId) : 'Unassigned';

                    $title = $service . ' #' . (string)$row['id'];
                    $message = '';
                    $type = 'update';
                    $link = 'admin-walker-management.php';

                    if (in_array($statusRaw, $openStatuses, true)) {
                        $title = 'Open Job: ' . $service . ' #' . (string)$row['id'];
                        $message = 'Needs assignment. ';
                        $message .= 'Scheduled: ' . $schedule . '. ';
                        $message .= 'Worker: Unassigned.';
                        $type = 'open_job';
                        $stats['open_jobs']++;
                        $link = 'admin-assign-walker.php?id=' . urlencode((string)$row['id']);
                    } elseif (in_array($statusRaw, $assignedStatuses, true)) {
                        $message = 'Assigned to ' . $workerName . '. ';
                        $message .= 'Scheduled: ' . $schedule . '.';
                        $type = 'assigned';
                        $link = 'admin-worker-view.php?id=' . urlencode((string)$assignedWorkerId);
                    } elseif (in_array($statusRaw, $inProgressStatuses, true)) {
                        $message = 'Currently in progress with ' . $workerName . '. ';
                        $message .= 'Scheduled: ' . $schedule . '.';
                        $type = 'in_progress';
                        $stats['active_jobs']++;
                        $link = 'admin-live-tracking.php';
                    } elseif (in_array($statusRaw, $completedStatuses, true)) {
                        $message = 'Completed by ' . $workerName . '. ';
                        $message .= 'Scheduled: ' . $schedule . '.';
                        $type = 'completed';
                        $link = 'admin-worker-view.php?id=' . urlencode((string)$assignedWorkerId);
                    } else {
                        $message = 'Status: ' . niceStatus((string)$row['status_name'] ?? 'Update') . '. ';
                        $message .= 'Scheduled: ' . $schedule . '. ';
                        $message .= 'Worker: ' . $workerName . '.';
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
            }
        }
    }

    $stats['total'] = count($notifications);

    foreach ($notifications as $notification) {
        if (empty($notification['is_read'])) {
            $stats['unread']++;
        }
    }
} catch (Throwable $e) {
    $error = 'Admin notifications error: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Notifications | Doggie Dorian’s</title>
    <meta name="description" content="Admin-only notifications page for Doggie Dorian’s.">
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
            --green: #8ae3b0;
            --blue: #8fc5ff;
            --red: #ffb0b0;
            --shadow: 0 24px 70px rgba(0,0,0,0.38);
            --radius-xl: 28px;
            --radius-lg: 22px;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "Segoe UI", sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(217,180,107,0.18), transparent 26%),
                radial-gradient(circle at top right, rgba(143,197,255,0.10), transparent 24%),
                linear-gradient(180deg, var(--bg-1), var(--bg-2));
        }

        a { color: inherit; text-decoration: none; }

        .container {
            width: min(1180px, calc(100% - 32px));
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
            max-width: 780px;
        }

        .top-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn,
        .btn-secondary {
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
            background: linear-gradient(135deg, var(--gold), var(--gold-strong));
            color: #17130e;
            box-shadow: 0 14px 30px rgba(191,143,55,0.28);
        }

        .btn-secondary {
            background: rgba(255,255,255,0.06);
            border: 1px solid var(--border);
            color: var(--text);
        }

        .btn:hover,
        .btn-secondary:hover {
            transform: translateY(-1px);
        }

        .success-box,
        .error-box {
            margin-bottom: 18px;
            border-radius: 18px;
            padding: 16px 18px;
            border: 1px solid rgba(255,255,255,0.10);
        }

        .success-box {
            background: rgba(80, 200, 120, 0.12);
            color: #98e6b3;
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
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-top: 20px;
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

        .pill-assigned { background: rgba(143,197,255,0.12); color: var(--blue); }
        .pill-open { background: rgba(217,180,107,0.12); color: var(--gold); }
        .pill-progress { background: rgba(138,227,176,0.12); color: var(--green); }
        .pill-completed { background: rgba(255,255,255,0.08); color: var(--text); }
        .pill-update { background: rgba(255,255,255,0.08); color: var(--text); }

        .notif-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 0 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 700;
            background: rgba(255,255,255,0.06);
            border: 1px solid var(--border);
        }

        .notif-link:hover {
            transform: translateY(-1px);
        }

        .empty-state {
            border: 1px dashed rgba(255,255,255,0.14);
            border-radius: 22px;
            padding: 26px;
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

        @media (max-width: 1080px) {
            .hero-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 760px) {
            .container {
                width: min(100% - 18px, 1180px);
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
            .notif-footer {
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
                <h1 class="headline">Admin Notifications</h1>
                <p class="subheadline">
                    This is the admin alert center for bookings, staffing, and active service activity across the platform.
                </p>
            </div>

            <div class="top-actions">
                <a class="btn-secondary" href="admin.php">Admin Dashboard</a>
                <a class="btn-secondary" href="admin-walker-management.php">Walker Management</a>
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
            <div class="eyebrow">Operations Alert Center</div>
            <h2 style="margin:0; font-size:28px; letter-spacing:-0.03em;">Platform activity snapshot</h2>
            <p class="subheadline" style="margin-top:10px;">
                Keep track of open jobs, live services, assignments, and overall activity from one admin-only feed.
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
                    <div class="stat-note">Fallback mode treats alerts as fresh</div>
                </div>

                <div class="stat">
                    <div class="stat-label">Open Jobs</div>
                    <div class="stat-value"><?= h((string)$stats['open_jobs']) ?></div>
                    <div class="stat-note">Jobs still needing assignment</div>
                </div>

                <div class="stat">
                    <div class="stat-label">Active Jobs</div>
                    <div class="stat-value"><?= h((string)$stats['active_jobs']) ?></div>
                    <div class="stat-note">Jobs currently in progress</div>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2 class="panel-title">Notification feed</h2>
                    <p class="panel-subtitle">
                        <?= $usedDedicatedNotificationsTable
                            ? 'Loaded from your dedicated admin notifications table.'
                            : 'Generated from booking activity because no dedicated admin notifications table was found.' ?>
                    </p>
                </div>
                <div class="badge"><?= h((string)$stats['total']) ?> Alerts</div>
            </div>

            <?php if (empty($notifications)): ?>
                <div class="empty-state">
                    No admin notifications are available right now.
                </div>
            <?php else: ?>
                <div class="notif-list">
                    <?php foreach ($notifications as $notification): ?>
                        <?php
                        $type = strtolower(trim((string)($notification['type'] ?? 'update')));
                        $pillClass = 'pill-update';

                        if ($type === 'assigned') {
                            $pillClass = 'pill-assigned';
                        } elseif ($type === 'open_job') {
                            $pillClass = 'pill-open';
                        } elseif ($type === 'in_progress') {
                            $pillClass = 'pill-progress';
                        } elseif ($type === 'completed') {
                            $pillClass = 'pill-completed';
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
            Admin-only notifications page · Separate from walker portal permissions
        </div>
    </div>
</body>
</html>