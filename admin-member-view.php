<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/db.php';

function safeRedirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function quotedIdentifier(string $value): string
{
    return '"' . str_replace('"', '""', $value) . '"';
}

function formatDate(?string $date): string
{
    $date = trim((string) $date);
    if ($date === '') {
        return 'N/A';
    }

    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return h($date);
    }

    return date('F j, Y', $timestamp);
}

function formatDateTime(?string $date): string
{
    $date = trim((string) $date);
    if ($date === '') {
        return 'N/A';
    }

    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return h($date);
    }

    return date('F j, Y \a\t g:i A', $timestamp);
}

function formatMoney(mixed $amount): string
{
    if ($amount === null || $amount === '') {
        return 'N/A';
    }

    if (!is_numeric($amount)) {
        return h((string) $amount);
    }

    return '$' . number_format((float) $amount, 2);
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

function getColumns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query('PRAGMA table_info(' . quotedIdentifier($table) . ')');
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

function pickExistingColumn(array $columns, array $choices): ?string
{
    foreach ($choices as $choice) {
        if (in_array($choice, $columns, true)) {
            return $choice;
        }
    }

    return null;
}

function buildSelectFragment(?string $column, string $alias, string $fallbackSql = 'NULL', string $tableAlias = ''): string
{
    if ($column === null) {
        return $fallbackSql . ' AS ' . quotedIdentifier($alias);
    }

    $prefix = $tableAlias !== '' ? $tableAlias . '.' : '';
    return $prefix . quotedIdentifier($column) . ' AS ' . quotedIdentifier($alias);
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

function fetchAllSafe(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function buildStatusBadgeClass(?string $status): string
{
    $normalized = strtolower(trim((string) $status));

    return match ($normalized) {
        'requested', 'pending' => 'status-requested',
        'confirmed' => 'status-confirmed',
        'in progress', 'in_progress' => 'status-in-progress',
        'completed' => 'status-completed',
        'cancelled', 'canceled' => 'status-cancelled',
        default => 'status-default',
    };
}

function normalizeStatusLabel(?string $status): string
{
    $normalized = strtolower(trim((string) $status));

    return match ($normalized) {
        '', 'pending', 'requested' => 'Requested',
        'confirmed' => 'Confirmed',
        'in progress', 'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'cancelled', 'canceled' => 'Cancelled',
        default => trim((string) $status) !== '' ? ucwords(str_replace(['_', '-'], ' ', trim((string) $status))) : 'Requested',
    };
}

function buildFullNameExpression(string $alias, ?string $nameCol, ?string $firstCol, ?string $lastCol, string $fallback = "'N/A'"): string
{
    if ($nameCol !== null) {
        return "COALESCE(NULLIF($alias." . quotedIdentifier($nameCol) . ", ''), $fallback)";
    }

    $first = $firstCol !== null ? "COALESCE($alias." . quotedIdentifier($firstCol) . ", '')" : "''";
    $last = $lastCol !== null ? "COALESCE($alias." . quotedIdentifier($lastCol) . ", '')" : "''";

    return "COALESCE(NULLIF(TRIM($first || ' ' || $last), ''), $fallback)";
}

if (empty($_SESSION['admin_member_view_csrf']) || !is_string($_SESSION['admin_member_view_csrf'])) {
    $_SESSION['admin_member_view_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['admin_member_view_csrf'];

$userId = (int) ($_GET['id'] ?? 0);

if ($userId <= 0) {
    safeRedirect('admin-members.php?status_type=error&status_message=' . urlencode('Invalid member ID'));
}

$allowedStatuses = [
    'Requested',
    'Confirmed',
    'In Progress',
    'Completed',
    'Cancelled',
];

$flashType = trim((string) ($_GET['status_type'] ?? ''));
$flashMessage = trim((string) ($_GET['status_message'] ?? ''));

$user = null;
$dogs = [];
$bookings = [];
$clientProfile = null;

$bookingHasAdminNotes = false;
$bookingHasStatusUpdatedAt = false;
$bookingHasStatusUpdatedBy = false;

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not available from db.php.');
    }

    if (!tableExists($pdo, 'users')) {
        throw new RuntimeException('The users table was not found.');
    }

    $userColumns = getColumns($pdo, 'users');

    $userIdCol = pickExistingColumn($userColumns, ['id', 'user_id']);
    $userNameCol = pickExistingColumn($userColumns, ['full_name', 'name', 'display_name', 'username']);
    $userFirstCol = pickExistingColumn($userColumns, ['first_name']);
    $userLastCol = pickExistingColumn($userColumns, ['last_name']);
    $userEmailCol = pickExistingColumn($userColumns, ['email']);
    $userPhoneCol = pickExistingColumn($userColumns, ['phone', 'phone_number', 'mobile']);
    $userStatusCol = pickExistingColumn($userColumns, ['status']);
    $userRoleCol = pickExistingColumn($userColumns, ['role']);
    $userCreatedCol = pickExistingColumn($userColumns, ['created_at', 'created_on', 'joined_at']);

    if ($userIdCol === null) {
        throw new RuntimeException('The users table is missing an ID column.');
    }

    $userSql = "
        SELECT
            " . buildSelectFragment($userIdCol, 'id', '0') . ",
            " . buildSelectFragment($userNameCol, 'full_name', "''") . ",
            " . buildSelectFragment($userFirstCol, 'first_name', "''") . ",
            " . buildSelectFragment($userLastCol, 'last_name', "''") . ",
            " . buildSelectFragment($userEmailCol, 'email', "''") . ",
            " . buildSelectFragment($userPhoneCol, 'phone', "''") . ",
            " . buildSelectFragment($userStatusCol, 'status', "''") . ",
            " . buildSelectFragment($userRoleCol, 'role', "'member'") . ",
            " . buildSelectFragment($userCreatedCol, 'created_at', "''") . "
        FROM " . quotedIdentifier('users') . "
        WHERE " . quotedIdentifier($userIdCol) . " = :user_id
        LIMIT 1
    ";

    $user = fetchOneSafe($pdo, $userSql, [':user_id' => $userId]);

    if (!$user) {
        safeRedirect('admin-members.php?status_type=error&status_message=' . urlencode('Member not found'));
    }

    $derivedFullName = trim((string) ($user['full_name'] ?? ''));
    if ($derivedFullName === '') {
        $derivedFullName = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
        if ($derivedFullName === '') {
            $derivedFullName = trim((string) ($user['email'] ?? ''));
        }
        if ($derivedFullName === '') {
            $derivedFullName = 'Member';
        }
        $user['full_name'] = $derivedFullName;
    }

    if (tableExists($pdo, 'dogs')) {
        $dogColumns = getColumns($pdo, 'dogs');

        $dogOwnerCol = pickExistingColumn($dogColumns, ['user_id', 'member_id', 'owner_id', 'client_id']);
        $dogNameCol = pickExistingColumn($dogColumns, ['name', 'pet_name', 'dog_name']);
        $dogBreedCol = pickExistingColumn($dogColumns, ['breed']);
        $dogAgeCol = pickExistingColumn($dogColumns, ['age', 'dog_age']);
        $dogNotesCol = pickExistingColumn($dogColumns, ['notes', 'care_notes']);
        $dogCreatedCol = pickExistingColumn($dogColumns, ['created_at', 'created_on']);

        if ($dogOwnerCol !== null) {
            $dogSelectParts = [
                quotedIdentifier('id'),
                $dogNameCol !== null ? quotedIdentifier($dogNameCol) . ' AS ' . quotedIdentifier('display_name') : "'Dog' AS " . quotedIdentifier('display_name'),
                $dogBreedCol !== null ? quotedIdentifier($dogBreedCol) . ' AS ' . quotedIdentifier('display_breed') : "NULL AS " . quotedIdentifier('display_breed'),
                $dogAgeCol !== null ? quotedIdentifier($dogAgeCol) . ' AS ' . quotedIdentifier('display_age') : "NULL AS " . quotedIdentifier('display_age'),
                $dogNotesCol !== null ? quotedIdentifier($dogNotesCol) . ' AS ' . quotedIdentifier('display_notes') : "NULL AS " . quotedIdentifier('display_notes'),
                $dogCreatedCol !== null ? quotedIdentifier($dogCreatedCol) . ' AS ' . quotedIdentifier('display_created') : "NULL AS " . quotedIdentifier('display_created'),
            ];

            $orderBy = $dogCreatedCol !== null ? quotedIdentifier($dogCreatedCol) . ' DESC' : quotedIdentifier('id') . ' DESC';

            $dogSql = "
                SELECT " . implode(', ', $dogSelectParts) . "
                FROM " . quotedIdentifier('dogs') . "
                WHERE " . quotedIdentifier($dogOwnerCol) . " = :user_id
                ORDER BY {$orderBy}
            ";

            $dogs = fetchAllSafe($pdo, $dogSql, [':user_id' => $userId]);
        }
    }

    if (tableExists($pdo, 'bookings')) {
        $bookingColumns = getColumns($pdo, 'bookings');

        $bookingUserCol = pickExistingColumn($bookingColumns, ['member_id', 'user_id', 'client_id']);
        $bookingServiceCol = pickExistingColumn($bookingColumns, ['service_type', 'service', 'booking_type', 'type']);
        $bookingDateCol = pickExistingColumn($bookingColumns, ['service_date', 'booking_date', 'created_at', 'created_on']);
        $bookingTimeCol = pickExistingColumn($bookingColumns, ['service_time', 'booking_time', 'time']);
        $bookingDurationCol = pickExistingColumn($bookingColumns, ['duration_minutes', 'duration']);
        $bookingStatusCol = pickExistingColumn($bookingColumns, ['status', 'booking_status', 'walk_status']);
        $bookingPriceCol = pickExistingColumn($bookingColumns, ['price', 'estimated_price', 'amount', 'total_price']);
        $bookingNotesCol = pickExistingColumn($bookingColumns, ['notes', 'client_notes', 'special_instructions']);
        $bookingAdminNotesCol = pickExistingColumn($bookingColumns, ['admin_notes']);
        $bookingStatusUpdatedAtCol = pickExistingColumn($bookingColumns, ['status_updated_at']);
        $bookingStatusUpdatedByCol = pickExistingColumn($bookingColumns, ['status_updated_by']);

        $bookingHasAdminNotes = $bookingAdminNotesCol !== null;
        $bookingHasStatusUpdatedAt = $bookingStatusUpdatedAtCol !== null;
        $bookingHasStatusUpdatedBy = $bookingStatusUpdatedByCol !== null;

        if ($bookingUserCol !== null) {
            $bookingSelectParts = [
                quotedIdentifier('id'),
                $bookingServiceCol !== null ? quotedIdentifier($bookingServiceCol) . ' AS ' . quotedIdentifier('display_service') : "'Service' AS " . quotedIdentifier('display_service'),
                $bookingDateCol !== null ? quotedIdentifier($bookingDateCol) . ' AS ' . quotedIdentifier('display_date') : "NULL AS " . quotedIdentifier('display_date'),
                $bookingTimeCol !== null ? quotedIdentifier($bookingTimeCol) . ' AS ' . quotedIdentifier('display_time') : "NULL AS " . quotedIdentifier('display_time'),
                $bookingDurationCol !== null ? quotedIdentifier($bookingDurationCol) . ' AS ' . quotedIdentifier('display_duration') : "NULL AS " . quotedIdentifier('display_duration'),
                $bookingStatusCol !== null ? quotedIdentifier($bookingStatusCol) . ' AS ' . quotedIdentifier('display_status') : "'Requested' AS " . quotedIdentifier('display_status'),
                $bookingPriceCol !== null ? quotedIdentifier($bookingPriceCol) . ' AS ' . quotedIdentifier('display_price') : "NULL AS " . quotedIdentifier('display_price'),
                $bookingNotesCol !== null ? quotedIdentifier($bookingNotesCol) . ' AS ' . quotedIdentifier('display_notes') : "NULL AS " . quotedIdentifier('display_notes'),
                $bookingAdminNotesCol !== null ? quotedIdentifier($bookingAdminNotesCol) . ' AS ' . quotedIdentifier('display_admin_notes') : "NULL AS " . quotedIdentifier('display_admin_notes'),
                $bookingStatusUpdatedAtCol !== null ? quotedIdentifier($bookingStatusUpdatedAtCol) . ' AS ' . quotedIdentifier('display_status_updated_at') : "NULL AS " . quotedIdentifier('display_status_updated_at'),
                $bookingStatusUpdatedByCol !== null ? quotedIdentifier($bookingStatusUpdatedByCol) . ' AS ' . quotedIdentifier('display_status_updated_by') : "NULL AS " . quotedIdentifier('display_status_updated_by'),
            ];

            $orderBy = $bookingDateCol !== null ? quotedIdentifier($bookingDateCol) . ' DESC' : quotedIdentifier('id') . ' DESC';

            $bookingSql = "
                SELECT " . implode(', ', $bookingSelectParts) . "
                FROM " . quotedIdentifier('bookings') . "
                WHERE " . quotedIdentifier($bookingUserCol) . " = :user_id
                ORDER BY {$orderBy}
                LIMIT 15
            ";

            $bookings = fetchAllSafe($pdo, $bookingSql, [':user_id' => $userId]);
        }
    }

    if (tableExists($pdo, 'client_profiles')) {
        $profileColumns = getColumns($pdo, 'client_profiles');
        $profileUserCol = pickExistingColumn($profileColumns, ['user_id', 'member_id', 'client_id']);

        if ($profileUserCol !== null) {
            $profileStmt = $pdo->prepare("
                SELECT *
                FROM " . quotedIdentifier('client_profiles') . "
                WHERE " . quotedIdentifier($profileUserCol) . " = :user_id
                LIMIT 1
            ");
            $profileStmt->execute([':user_id' => $userId]);
            $clientProfile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }
} catch (Throwable $e) {
    safeRedirect('admin-members.php?status_type=error&status_message=' . urlencode($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Profile | Doggie Dorian's Admin</title>
    <style>
        :root{
            --bg:#0a0a0f;
            --panel:rgba(255,255,255,0.06);
            --panel2:rgba(255,255,255,0.04);
            --border:rgba(212,175,55,0.22);
            --gold:#d4af37;
            --gold-soft:#f3df9b;
            --text:#f8f5ee;
            --muted:#b8b1a3;
            --shadow:0 20px 50px rgba(0,0,0,0.35);

            --requested-bg:rgba(212,175,55,0.14);
            --requested-text:#f4e1a1;

            --confirmed-bg:rgba(88,166,255,0.16);
            --confirmed-text:#cde4ff;

            --progress-bg:rgba(168,85,247,0.16);
            --progress-text:#ead5ff;

            --completed-bg:rgba(34,197,94,0.16);
            --completed-text:#d7ffe4;

            --cancelled-bg:rgba(239,68,68,0.16);
            --cancelled-text:#ffd1d1;

            --default-bg:rgba(255,255,255,0.10);
            --default-text:#f8f5ee;
        }

        *{box-sizing:border-box}

        body{
            margin:0;
            font-family:Inter, Arial, Helvetica, sans-serif;
            color:var(--text);
            background:
                radial-gradient(circle at top left, rgba(212,175,55,0.14), transparent 28%),
                radial-gradient(circle at top right, rgba(255,255,255,0.05), transparent 24%),
                linear-gradient(180deg, #08080c 0%, #111119 100%);
        }

        .container{
            max-width:1200px;
            margin:40px auto;
            padding:20px;
        }

        .topbar{
            display:flex;
            justify-content:space-between;
            align-items:flex-end;
            gap:16px;
            margin-bottom:24px;
            flex-wrap:wrap;
        }

        .topbar h1{
            margin:0 0 8px;
            font-size:40px;
            line-height:1;
            letter-spacing:-1px;
        }

        .sub{
            color:var(--muted);
            font-size:15px;
        }

        .actions{
            display:flex;
            gap:12px;
            flex-wrap:wrap;
        }

        .btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            padding:12px 16px;
            border-radius:14px;
            text-decoration:none;
            font-weight:800;
            border:none;
            cursor:pointer;
        }

        .btn-primary{
            color:#111;
            background:linear-gradient(180deg, #f0d77a, var(--gold));
            box-shadow:var(--shadow);
        }

        .btn-secondary{
            color:var(--text);
            background:rgba(255,255,255,0.05);
            border:1px solid var(--border);
        }

        .btn-update{
            color:#111;
            background:linear-gradient(180deg, #f0d77a, var(--gold));
            width:100%;
            margin-top:12px;
            font-size:14px;
        }

        .section{
            background:var(--panel);
            border:1px solid var(--border);
            border-radius:24px;
            padding:24px;
            margin-bottom:20px;
            box-shadow:var(--shadow);
        }

        .section h2{
            margin:0 0 14px;
            font-size:26px;
            letter-spacing:-0.4px;
        }

        .grid{
            display:grid;
            grid-template-columns:repeat(4, minmax(0,1fr));
            gap:14px;
        }

        .box{
            background:var(--panel2);
            border:1px solid rgba(255,255,255,0.08);
            border-radius:16px;
            padding:14px;
        }

        .label{
            color:var(--gold-soft);
            font-size:12px;
            text-transform:uppercase;
            letter-spacing:1px;
            margin-bottom:6px;
            font-weight:800;
        }

        .value{
            color:var(--text);
            font-size:15px;
            line-height:1.5;
        }

        .list{
            display:grid;
            gap:14px;
        }

        .item{
            background:var(--panel2);
            border:1px solid rgba(255,255,255,0.08);
            border-radius:18px;
            padding:18px;
        }

        .item-head{
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:14px;
            flex-wrap:wrap;
            margin-bottom:12px;
        }

        .item-title{
            font-size:18px;
            font-weight:800;
            margin-bottom:8px;
        }

        .item-meta{
            color:var(--muted);
            line-height:1.7;
            font-size:14px;
        }

        .status-badge{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            padding:8px 12px;
            border-radius:999px;
            font-size:12px;
            font-weight:800;
            letter-spacing:0.04em;
            text-transform:uppercase;
            border:1px solid rgba(255,255,255,0.08);
            white-space:nowrap;
        }

        .status-requested{
            background:var(--requested-bg);
            color:var(--requested-text);
        }

        .status-confirmed{
            background:var(--confirmed-bg);
            color:var(--confirmed-text);
        }

        .status-in-progress{
            background:var(--progress-bg);
            color:var(--progress-text);
        }

        .status-completed{
            background:var(--completed-bg);
            color:var(--completed-text);
        }

        .status-cancelled{
            background:var(--cancelled-bg);
            color:var(--cancelled-text);
        }

        .status-default{
            background:var(--default-bg);
            color:var(--default-text);
        }

        .booking-layout{
            display:grid;
            grid-template-columns:1.15fr 0.85fr;
            gap:18px;
            align-items:start;
        }

        .status-form{
            background:rgba(255,255,255,0.03);
            border:1px solid rgba(255,255,255,0.08);
            border-radius:16px;
            padding:14px;
        }

        .status-form label{
            display:block;
            margin-bottom:8px;
            font-size:12px;
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:0.08em;
            color:var(--gold-soft);
        }

        .status-form select,
        .status-form textarea{
            width:100%;
            border-radius:12px;
            border:1px solid rgba(255,255,255,0.10);
            background:rgba(0,0,0,0.28);
            color:var(--text);
            padding:12px 13px;
            font:inherit;
            outline:none;
        }

        .status-form textarea{
            min-height:100px;
            resize:vertical;
        }

        .status-history{
            margin-top:10px;
            color:var(--muted);
            font-size:13px;
            line-height:1.6;
        }

        .flash{
            margin-bottom:18px;
            padding:14px 16px;
            border-radius:16px;
            font-weight:700;
            border:1px solid rgba(255,255,255,0.08);
        }

        .flash-success{
            background:rgba(34,197,94,0.14);
            color:#d7ffe4;
        }

        .flash-error{
            background:rgba(239,68,68,0.14);
            color:#ffd1d1;
        }

        .empty{
            border:1px dashed rgba(255,255,255,0.14);
            border-radius:18px;
            padding:24px;
            text-align:center;
            color:var(--muted);
            background:rgba(255,255,255,0.03);
        }

        @media (max-width: 1100px){
            .grid{
                grid-template-columns:repeat(2, minmax(0,1fr));
            }

            .booking-layout{
                grid-template-columns:1fr;
            }
        }

        @media (max-width: 700px){
            .grid{
                grid-template-columns:1fr;
            }

            .topbar h1{
                font-size:32px;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="topbar">
        <div>
            <h1><?php echo h($user['full_name'] ?? 'Member'); ?></h1>
            <div class="sub">Full member profile, pets, and booking history.</div>
        </div>

        <div class="actions">
            <a href="admin-add-dog.php?user_id=<?php echo (int) $userId; ?>" class="btn btn-primary">+ Add Dog</a>
            <a href="admin-create-booking.php?user_id=<?php echo (int) $userId; ?>" class="btn btn-primary">+ Create Booking</a>
            <a href="admin-members.php" class="btn btn-secondary">← Back to Members</a>
        </div>
    </div>

    <?php if ($flashMessage !== ''): ?>
        <div class="flash <?php echo $flashType === 'success' ? 'flash-success' : 'flash-error'; ?>">
            <?php echo h($flashMessage); ?>
        </div>
    <?php endif; ?>

    <section class="section">
        <h2>Member Information</h2>
        <div class="grid">
            <div class="box">
                <div class="label">Full Name</div>
                <div class="value"><?php echo h($user['full_name'] ?? 'N/A'); ?></div>
            </div>

            <div class="box">
                <div class="label">Email</div>
                <div class="value"><?php echo h($user['email'] ?? 'N/A'); ?></div>
            </div>

            <div class="box">
                <div class="label">Phone</div>
                <div class="value"><?php echo h($user['phone'] ?? 'N/A'); ?></div>
            </div>

            <div class="box">
                <div class="label">Status</div>
                <div class="value"><?php echo h($user['status'] !== '' ? $user['status'] : 'N/A'); ?></div>
            </div>

            <div class="box">
                <div class="label">Role</div>
                <div class="value"><?php echo h($user['role'] ?? 'member'); ?></div>
            </div>

            <div class="box">
                <div class="label">Joined</div>
                <div class="value"><?php echo formatDate($user['created_at'] ?? ''); ?></div>
            </div>

            <div class="box">
                <div class="label">User ID</div>
                <div class="value"><?php echo h((string) ($user['id'] ?? 'N/A')); ?></div>
            </div>

            <div class="box">
                <div class="label">Client Profile</div>
                <div class="value"><?php echo $clientProfile ? 'Found' : 'Not found'; ?></div>
            </div>
        </div>
    </section>

    <?php if ($clientProfile): ?>
        <section class="section">
            <h2>Client Profile</h2>
            <div class="grid">
                <?php foreach ($clientProfile as $key => $value): ?>
                    <?php if (in_array((string) $key, ['id', 'user_id', 'member_id', 'client_id'], true)) continue; ?>
                    <div class="box">
                        <div class="label"><?php echo h(ucwords(str_replace('_', ' ', (string) $key))); ?></div>
                        <div class="value"><?php echo h((string) ($value !== null && $value !== '' ? $value : 'N/A')); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="section">
        <h2>Dogs</h2>

        <?php if (empty($dogs)): ?>
            <div class="empty">No dogs found for this member.</div>
        <?php else: ?>
            <div class="list">
                <?php foreach ($dogs as $dog): ?>
                    <div class="item">
                        <div class="item-title"><?php echo h($dog['display_name'] ?? 'Dog'); ?></div>
                        <div class="item-meta">
                            Breed: <?php echo h($dog['display_breed'] ?? 'N/A'); ?><br>
                            Age: <?php echo h((string) ($dog['display_age'] ?? 'N/A')); ?><br>
                            Notes: <?php echo h($dog['display_notes'] ?? 'N/A'); ?><br>
                            Added: <?php echo formatDateTime($dog['display_created'] ?? ''); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="section">
        <h2>Recent Bookings</h2>

        <?php if (empty($bookings)): ?>
            <div class="empty">No bookings found for this member.</div>
        <?php else: ?>
            <div class="list">
                <?php foreach ($bookings as $booking): ?>
                    <?php
                    $bookingId = (int) ($booking['id'] ?? 0);
                    $rawStatus = trim((string) ($booking['display_status'] ?? 'Requested'));
                    $currentStatus = normalizeStatusLabel($rawStatus);
                    $adminNotes = trim((string) ($booking['display_admin_notes'] ?? ''));
                    $statusUpdatedAt = trim((string) ($booking['display_status_updated_at'] ?? ''));
                    $statusUpdatedBy = trim((string) ($booking['display_status_updated_by'] ?? ''));
                    ?>
                    <div class="item">
                        <div class="booking-layout">
                            <div>
                                <div class="item-head">
                                    <div>
                                        <div class="item-title"><?php echo h($booking['display_service'] ?? 'Service'); ?></div>
                                    </div>
                                    <span class="status-badge <?php echo h(buildStatusBadgeClass($rawStatus)); ?>">
                                        <?php echo h($currentStatus); ?>
                                    </span>
                                </div>

                                <div class="item-meta">
                                    Date: <?php echo formatDate($booking['display_date'] ?? ''); ?><br>
                                    Time: <?php echo h($booking['display_time'] ?? 'N/A'); ?><br>
                                    Duration: <?php echo h((string) ($booking['display_duration'] ?? 'N/A')); ?><br>
                                    Price: <?php echo formatMoney($booking['display_price'] ?? null); ?><br>
                                    Client Notes: <?php echo h($booking['display_notes'] ?? 'N/A'); ?><br>
                                    Admin Notes:
                                    <?php
                                    if ($bookingHasAdminNotes) {
                                        echo h($adminNotes !== '' ? $adminNotes : 'None');
                                    } else {
                                        echo 'Run the booking status database upgrade first.';
                                    }
                                    ?>
                                </div>

                                <?php if ($bookingHasStatusUpdatedAt || $bookingHasStatusUpdatedBy): ?>
                                    <div class="status-history">
                                        <?php if ($bookingHasStatusUpdatedAt && $statusUpdatedAt !== ''): ?>
                                            Last updated: <?php echo formatDateTime($statusUpdatedAt); ?><br>
                                        <?php endif; ?>
                                        <?php if ($bookingHasStatusUpdatedBy && $statusUpdatedBy !== ''): ?>
                                            Updated by: <?php echo h($statusUpdatedBy); ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div>
                                <?php if ($bookingHasAdminNotes): ?>
                                    <form method="post" action="admin-update-booking-status.php" class="status-form">
                                        <input type="hidden" name="booking_id" value="<?php echo $bookingId; ?>">
                                        <input type="hidden" name="user_id" value="<?php echo (int) $userId; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">

                                        <label for="status_<?php echo $bookingId; ?>">Booking Status</label>
                                        <select name="status" id="status_<?php echo $bookingId; ?>" required>
                                            <?php foreach ($allowedStatuses as $statusOption): ?>
                                                <option value="<?php echo h($statusOption); ?>" <?php echo $currentStatus === $statusOption ? 'selected' : ''; ?>>
                                                    <?php echo h($statusOption); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <label for="admin_notes_<?php echo $bookingId; ?>" style="margin-top:12px;">Admin Notes</label>
                                        <textarea
                                            name="admin_notes"
                                            id="admin_notes_<?php echo $bookingId; ?>"
                                            placeholder="Add internal notes about this booking status update..."
                                        ><?php echo h($adminNotes); ?></textarea>

                                        <button type="submit" class="btn btn-update">Update Booking Status</button>
                                    </form>
                                <?php else: ?>
                                    <div class="empty">
                                        This booking table needs the new status fields before inline updates can work.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

</body>
</html>