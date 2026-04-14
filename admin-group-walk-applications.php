<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-auth.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection is not available.';
    exit;
}

function ddAdminGroupWalksH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ddAdminGroupWalksRedirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function ddAdminGroupWalksQuoteIdentifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function ddAdminGroupWalksSafeExecute(PDO $pdo, string $sql, array $params = array()): bool
{
    try {
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    } catch (Throwable $e) {
        return false;
    }
}

function ddAdminGroupWalksSafeFetchAll(PDO $pdo, string $sql, array $params = array()): array
{
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt->execute($params)) {
            return array();
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    } catch (Throwable $e) {
        return array();
    }
}

function ddAdminGroupWalksSafeFetchOne(PDO $pdo, string $sql, array $params = array()): ?array
{
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt->execute($params)) {
            return null;
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function ddAdminGroupWalksTableExists(PDO $pdo, string $table): bool
{
    static $cache = array();

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
        $stmt->execute(array(':table' => $table));
        $cache[$table] = (bool) $stmt->fetchColumn();
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = false;
        return false;
    }
}

function ddAdminGroupWalksGetColumns(PDO $pdo, string $table): array
{
    static $cache = array();

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!ddAdminGroupWalksTableExists($pdo, $table)) {
        $cache[$table] = array();
        return $cache[$table];
    }

    try {
        $stmt = $pdo->query('PRAGMA table_info(' . ddAdminGroupWalksQuoteIdentifier($table) . ')');
        if (!($stmt instanceof PDOStatement)) {
            $cache[$table] = array();
            return $cache[$table];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $columns = array();

        foreach ($rows as $row) {
            if (isset($row['name']) && $row['name'] !== '') {
                $columns[] = (string) $row['name'];
            }
        }

        $cache[$table] = $columns;
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = array();
        return $cache[$table];
    }
}

function ddAdminGroupWalksColumnExists(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function ddAdminGroupWalksNormalizeStatus($status): string
{
    $status = strtolower(trim((string) $status));
    $allowed = array('new', 'reviewed', 'approved', 'declined');

    if (in_array($status, $allowed, true)) {
        return $status;
    }

    return 'new';
}

function ddAdminGroupWalksFormatDateTimeDisplay($value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return $value;
    }

    return date('F j, Y \a\t g:i A', $ts);
}

function ddAdminGroupWalksStatusBadgeClass(string $status): string
{
    $status = ddAdminGroupWalksNormalizeStatus($status);

    if ($status === 'approved') {
        return 'badge-approved';
    }
    if ($status === 'declined') {
        return 'badge-declined';
    }
    if ($status === 'reviewed') {
        return 'badge-reviewed';
    }

    return 'badge-new';
}

function ddAdminGroupWalksCsrfToken(): string
{
    if (empty($_SESSION['admin_group_walks_csrf']) || !is_string($_SESSION['admin_group_walks_csrf'])) {
        $_SESSION['admin_group_walks_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['admin_group_walks_csrf'];
}

function ddAdminGroupWalksValidateCsrf(?string $submittedToken): bool
{
    $sessionToken = $_SESSION['admin_group_walks_csrf'] ?? '';

    if (!is_string($sessionToken) || $sessionToken === '' || $submittedToken === null || $submittedToken === '') {
        return false;
    }

    return hash_equals($sessionToken, $submittedToken);
}

function ddAdminGroupWalksCreateTableIfNeeded(PDO $pdo): bool
{
    if (ddAdminGroupWalksTableExists($pdo, 'group_walk_applications')) {
        return true;
    }

    $sql = '
        CREATE TABLE IF NOT EXISTS group_walk_applications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_name TEXT NOT NULL,
            email TEXT NOT NULL,
            phone TEXT NOT NULL,
            neighborhood TEXT NOT NULL,
            dog_name TEXT NOT NULL,
            breed TEXT NOT NULL,
            size TEXT NOT NULL,
            age TEXT NOT NULL,
            temperament TEXT NOT NULL,
            leash_behavior TEXT NOT NULL,
            preferred_days TEXT NOT NULL,
            preferred_time TEXT NOT NULL,
            prior_group_experience TEXT NOT NULL,
            notes TEXT DEFAULT \'\',
            admin_notes TEXT DEFAULT \'\',
            status TEXT NOT NULL DEFAULT \'new\',
            created_at TEXT NOT NULL
        )
    ';

    return ddAdminGroupWalksSafeExecute($pdo, $sql);
}

function ddAdminGroupWalksEnsureOptionalColumns(PDO $pdo): void
{
    if (!ddAdminGroupWalksTableExists($pdo, 'group_walk_applications')) {
        return;
    }

    $columns = ddAdminGroupWalksGetColumns($pdo, 'group_walk_applications');

    $optionalColumns = array(
        'admin_notes' => 'ALTER TABLE group_walk_applications ADD COLUMN admin_notes TEXT DEFAULT \'\'',
        'reviewed_at' => 'ALTER TABLE group_walk_applications ADD COLUMN reviewed_at TEXT DEFAULT NULL',
        'updated_at' => 'ALTER TABLE group_walk_applications ADD COLUMN updated_at TEXT DEFAULT NULL',
    );

    foreach ($optionalColumns as $column => $sql) {
        if (ddAdminGroupWalksColumnExists($columns, $column)) {
            continue;
        }

        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
        }
    }
}

function ddAdminGroupWalksCreateNotificationIfPossible(PDO $pdo, string $title, string $message): void
{
    if (!ddAdminGroupWalksTableExists($pdo, 'notifications')) {
        return;
    }

    $columns = ddAdminGroupWalksGetColumns($pdo, 'notifications');
    if (empty($columns)) {
        return;
    }

    $now = date('Y-m-d H:i:s');
    $data = array(
        'title' => $title,
        'message' => $message,
        'type' => 'group_walk_application',
        'is_read' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    );

    $insertCols = array();
    $placeholders = array();
    $params = array();

    foreach ($data as $column => $value) {
        if (!ddAdminGroupWalksColumnExists($columns, $column)) {
            continue;
        }

        $insertCols[] = ddAdminGroupWalksQuoteIdentifier($column);
        $placeholders[] = ':' . $column;
        $params[':' . $column] = $value;
    }

    if (empty($insertCols)) {
        return;
    }

    $sql = 'INSERT INTO ' . ddAdminGroupWalksQuoteIdentifier('notifications')
        . ' (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $placeholders) . ')';

    ddAdminGroupWalksSafeExecute($pdo, $sql, $params);
}

if (!ddAdminGroupWalksCreateTableIfNeeded($pdo)) {
    http_response_code(500);
    echo 'Could not prepare the group walk applications table.';
    exit;
}

ddAdminGroupWalksEnsureOptionalColumns($pdo);

$flash = isset($_SESSION['admin_group_walks_flash']) ? (string) $_SESSION['admin_group_walks_flash'] : '';
$flashType = isset($_SESSION['admin_group_walks_flash_type']) ? (string) $_SESSION['admin_group_walks_flash_type'] : '';
unset($_SESSION['admin_group_walks_flash'], $_SESSION['admin_group_walks_flash_type']);

$applicationColumns = ddAdminGroupWalksGetColumns($pdo, 'group_walk_applications');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ddAdminGroupWalksValidateCsrf(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
        $_SESSION['admin_group_walks_flash_type'] = 'error';
        $_SESSION['admin_group_walks_flash'] = 'Security check failed. Please refresh the page and try again.';
        ddAdminGroupWalksRedirect('admin-group-walk-applications.php');
    }

    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    if ($action === 'update_application') {
        $applicationId = (int) (isset($_POST['application_id']) ? $_POST['application_id'] : 0);
        $newStatus = ddAdminGroupWalksNormalizeStatus(isset($_POST['status']) ? $_POST['status'] : 'new');
        $adminNotes = trim((string) (isset($_POST['admin_notes']) ? $_POST['admin_notes'] : ''));

        if ($applicationId <= 0) {
            $_SESSION['admin_group_walks_flash_type'] = 'error';
            $_SESSION['admin_group_walks_flash'] = 'Invalid application selected.';
            ddAdminGroupWalksRedirect('admin-group-walk-applications.php');
        }

        $existing = ddAdminGroupWalksSafeFetchOne(
            $pdo,
            'SELECT * FROM ' . ddAdminGroupWalksQuoteIdentifier('group_walk_applications')
            . ' WHERE ' . ddAdminGroupWalksQuoteIdentifier('id') . ' = :id LIMIT 1',
            array(':id' => $applicationId)
        );

        if ($existing === null) {
            $_SESSION['admin_group_walks_flash_type'] = 'error';
            $_SESSION['admin_group_walks_flash'] = 'Application not found.';
            ddAdminGroupWalksRedirect('admin-group-walk-applications.php');
        }

        $updateParts = array(
            ddAdminGroupWalksQuoteIdentifier('status') . ' = :status',
        );
        $params = array(
            ':status' => $newStatus,
            ':id' => $applicationId,
        );

        if (ddAdminGroupWalksColumnExists($applicationColumns, 'admin_notes')) {
            $updateParts[] = ddAdminGroupWalksQuoteIdentifier('admin_notes') . ' = :admin_notes';
            $params[':admin_notes'] = $adminNotes;
        }

        if (ddAdminGroupWalksColumnExists($applicationColumns, 'reviewed_at')) {
            $updateParts[] = ddAdminGroupWalksQuoteIdentifier('reviewed_at') . ' = :reviewed_at';
            $params[':reviewed_at'] = date('Y-m-d H:i:s');
        }

        if (ddAdminGroupWalksColumnExists($applicationColumns, 'updated_at')) {
            $updateParts[] = ddAdminGroupWalksQuoteIdentifier('updated_at') . ' = :updated_at';
            $params[':updated_at'] = date('Y-m-d H:i:s');
        }

        $updated = ddAdminGroupWalksSafeExecute(
            $pdo,
            'UPDATE ' . ddAdminGroupWalksQuoteIdentifier('group_walk_applications')
            . ' SET ' . implode(', ', $updateParts)
            . ' WHERE ' . ddAdminGroupWalksQuoteIdentifier('id') . ' = :id',
            $params
        );

        if ($updated) {
            ddAdminGroupWalksCreateNotificationIfPossible(
                $pdo,
                'Group Walk Application Updated',
                'Application #' . $applicationId . ' was updated to status: ' . strtoupper($newStatus) . '.'
            );

            $_SESSION['admin_group_walks_flash_type'] = 'success';
            $_SESSION['admin_group_walks_flash'] = 'Application updated successfully.';
        } else {
            $_SESSION['admin_group_walks_flash_type'] = 'error';
            $_SESSION['admin_group_walks_flash'] = 'Could not update the application.';
        }

        ddAdminGroupWalksRedirect('admin-group-walk-applications.php');
    }
}

$statusFilterRaw = isset($_GET['status']) ? (string) $_GET['status'] : 'new';
$showAll = $statusFilterRaw === 'all';
$statusFilter = $showAll ? 'all' : ddAdminGroupWalksNormalizeStatus($statusFilterRaw);

if ($showAll) {
    $applications = ddAdminGroupWalksSafeFetchAll(
        $pdo,
        'SELECT * FROM ' . ddAdminGroupWalksQuoteIdentifier('group_walk_applications')
        . ' ORDER BY ' . ddAdminGroupWalksQuoteIdentifier('created_at') . ' DESC, '
        . ddAdminGroupWalksQuoteIdentifier('id') . ' DESC'
    );
} else {
    $applications = ddAdminGroupWalksSafeFetchAll(
        $pdo,
        'SELECT * FROM ' . ddAdminGroupWalksQuoteIdentifier('group_walk_applications')
        . ' WHERE ' . ddAdminGroupWalksQuoteIdentifier('status') . ' = :status'
        . ' ORDER BY ' . ddAdminGroupWalksQuoteIdentifier('created_at') . ' DESC, '
        . ddAdminGroupWalksQuoteIdentifier('id') . ' DESC',
        array(':status' => $statusFilter)
    );
}

$countsRow = ddAdminGroupWalksSafeFetchOne(
    $pdo,
    'SELECT
        COUNT(*) AS total_count,
        SUM(CASE WHEN status = \'new\' THEN 1 ELSE 0 END) AS new_count,
        SUM(CASE WHEN status = \'reviewed\' THEN 1 ELSE 0 END) AS reviewed_count,
        SUM(CASE WHEN status = \'approved\' THEN 1 ELSE 0 END) AS approved_count,
        SUM(CASE WHEN status = \'declined\' THEN 1 ELSE 0 END) AS declined_count
     FROM ' . ddAdminGroupWalksQuoteIdentifier('group_walk_applications')
);

$totalCount = (int) ($countsRow['total_count'] ?? 0);
$newCount = (int) ($countsRow['new_count'] ?? 0);
$reviewedCount = (int) ($countsRow['reviewed_count'] ?? 0);
$approvedCount = (int) ($countsRow['approved_count'] ?? 0);
$declinedCount = (int) ($countsRow['declined_count'] ?? 0);

$csrfToken = ddAdminGroupWalksCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Group Walk Applications | Doggie Dorian’s</title>
    <meta name="description" content="Review and manage group walk applications.">
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #09090d;
            color: #f4f1ea;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .page {
            max-width: 1400px;
            margin: 0 auto;
            padding: 28px 18px 80px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .brand {
            font-size: 1.45rem;
            font-weight: 900;
            letter-spacing: .04em;
        }

        .top-links {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .top-link {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            font-weight: 700;
        }

        .hero {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 20px;
            margin-bottom: 22px;
        }

        .card {
            background: linear-gradient(180deg, rgba(255,255,255,0.065), rgba(255,255,255,0.03));
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 24px;
            padding: 22px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.28);
        }

        .hero-primary {
            background: linear-gradient(135deg, rgba(198,178,139,0.18), rgba(255,255,255,0.04));
        }

        .eyebrow {
            color: #c6b28b;
            text-transform: uppercase;
            letter-spacing: .14em;
            font-size: .75rem;
            font-weight: 800;
            margin-bottom: 10px;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 2rem;
            line-height: 1.08;
        }

        h2 {
            margin: 0 0 10px;
            font-size: 1.25rem;
        }

        .sub {
            color: rgba(244,241,234,0.72);
            line-height: 1.6;
        }

        .flash-error,
        .flash-success {
            margin-bottom: 18px;
            padding: 14px 18px;
            border-radius: 16px;
            font-weight: 700;
        }

        .flash-error {
            background: rgba(214,123,123,0.14);
            border: 1px solid rgba(214,123,123,0.3);
            color: #ffd5d5;
        }

        .flash-success {
            background: rgba(125,206,141,0.14);
            border: 1px solid rgba(125,206,141,0.3);
            color: #d7f1dd;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 12px;
            margin-top: 18px;
        }

        .stat {
            padding: 16px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .stat-label {
            color: rgba(244,241,234,0.56);
            text-transform: uppercase;
            letter-spacing: .12em;
            font-size: .73rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 1.45rem;
            font-weight: 900;
        }

        .filter-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .filter-pill {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            font-size: .9rem;
            font-weight: 700;
        }

        .filter-pill.active {
            background: rgba(198,178,139,0.16);
            border-color: rgba(198,178,139,0.32);
            color: #f3e5c7;
        }

        .application-list {
            display: grid;
            gap: 16px;
            margin-top: 22px;
        }

        .application-card {
            display: grid;
            gap: 16px;
        }

        .app-top {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        .app-title {
            font-size: 1.1rem;
            font-weight: 900;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 8px 11px;
            border-radius: 999px;
            font-size: .82rem;
            font-weight: 900;
            letter-spacing: .02em;
            text-transform: capitalize;
        }

        .badge-new {
            background: rgba(255,255,255,0.08);
            color: #f5f3ef;
        }

        .badge-reviewed {
            background: rgba(109,174,255,0.18);
            color: #d0e4ff;
        }

        .badge-approved {
            background: rgba(125,206,141,0.18);
            color: #d7f1dd;
        }

        .badge-declined {
            background: rgba(214,123,123,0.18);
            color: #ffd5d5;
        }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
        }

        .meta-box {
            padding: 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .meta-label {
            color: rgba(244,241,234,0.56);
            text-transform: uppercase;
            letter-spacing: .12em;
            font-size: .73rem;
            font-weight: 800;
            margin-bottom: 6px;
        }

        .meta-value {
            font-size: .97rem;
            font-weight: 700;
            line-height: 1.5;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .detail-box {
            padding: 16px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .detail-box strong {
            display: block;
            margin-bottom: 8px;
            color: #f3e5c7;
        }

        .detail-copy {
            color: rgba(244,241,234,0.76);
            line-height: 1.65;
            white-space: pre-wrap;
        }

        form {
            display: grid;
            gap: 14px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 220px 1fr;
            gap: 14px;
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: 800;
            margin-bottom: 8px;
            color: rgba(244,241,234,0.78);
        }

        select,
        textarea {
            width: 100%;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(0,0,0,0.26);
            color: #fff;
            padding: 13px 14px;
            font: inherit;
            outline: none;
        }

        textarea {
            min-height: 120px;
            resize: vertical;
        }

        .btn-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 46px;
            padding: 12px 18px;
            border: none;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
            transition: transform 0.15s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-gold {
            background: linear-gradient(135deg, #e2c48d, #b9975b);
            color: #0b0b10;
        }

        .btn-light {
            background: rgba(255,255,255,0.06);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.12);
        }

        .empty {
            padding: 20px;
            border-radius: 18px;
            background: rgba(255,255,255,0.03);
            border: 1px dashed rgba(255,255,255,0.12);
            color: rgba(244,241,234,0.64);
        }

        @media (max-width: 1180px) {
            .hero,
            .meta-grid,
            .detail-grid,
            .stats {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 860px) {
            .hero,
            .meta-grid,
            .detail-grid,
            .stats,
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .page {
                padding: 20px 12px 60px;
            }

            h1 {
                font-size: 1.65rem;
            }

            .card {
                padding: 18px;
                border-radius: 22px;
            }

            .btn-row {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="topbar">
            <div class="brand">Doggie Dorian’s Admin</div>

            <div class="top-links">
                <a class="top-link" href="admin-dashboard.php">Dashboard</a>
                <a class="top-link" href="admin-nav.php">Admin Nav</a>
                <a class="top-link" href="admin-bookings.php">Bookings</a>
                <a class="top-link" href="admin-group-walk-applications.php">Group Walk Applications</a>
                <a class="top-link" href="logout.php">Logout</a>
            </div>
        </div>

        <?php if ($flash !== ''): ?>
            <div class="<?php echo $flashType === 'success' ? 'flash-success' : 'flash-error'; ?>">
                <?php echo ddAdminGroupWalksH($flash); ?>
            </div>
        <?php endif; ?>

        <section class="hero">
            <div class="card hero-primary">
                <div class="eyebrow">Admin Review</div>
                <h1>Group Walk Applications</h1>
                <div class="sub">
                    Review applicants, evaluate fit, update status, and keep your group walk intake organized from one place.
                </div>

                <div class="stats">
                    <div class="stat">
                        <div class="stat-label">Total</div>
                        <div class="stat-value"><?php echo (int) $totalCount; ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">New</div>
                        <div class="stat-value"><?php echo (int) $newCount; ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Reviewed</div>
                        <div class="stat-value"><?php echo (int) $reviewedCount; ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Approved</div>
                        <div class="stat-value"><?php echo (int) $approvedCount; ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Declined</div>
                        <div class="stat-value"><?php echo (int) $declinedCount; ?></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Filter View</div>
                <h2>Quick status filters</h2>
                <div class="sub">
                    Narrow your review list by current application status.
                </div>

                <div class="filter-row">
                    <a class="filter-pill <?php echo $showAll ? 'active' : ''; ?>" href="admin-group-walk-applications.php?status=all">All</a>
                    <a class="filter-pill <?php echo (!$showAll && $statusFilter === 'new') ? 'active' : ''; ?>" href="admin-group-walk-applications.php?status=new">New</a>
                    <a class="filter-pill <?php echo (!$showAll && $statusFilter === 'reviewed') ? 'active' : ''; ?>" href="admin-group-walk-applications.php?status=reviewed">Reviewed</a>
                    <a class="filter-pill <?php echo (!$showAll && $statusFilter === 'approved') ? 'active' : ''; ?>" href="admin-group-walk-applications.php?status=approved">Approved</a>
                    <a class="filter-pill <?php echo (!$showAll && $statusFilter === 'declined') ? 'active' : ''; ?>" href="admin-group-walk-applications.php?status=declined">Declined</a>
                </div>
            </div>
        </section>

        <section class="application-list">
            <?php if (empty($applications)): ?>
                <div class="card">
                    <div class="empty">No group walk applications match this filter yet.</div>
                </div>
            <?php else: ?>
                <?php foreach ($applications as $app): ?>
                    <?php
                    $appId = (int) ($app['id'] ?? 0);
                    $status = ddAdminGroupWalksNormalizeStatus($app['status'] ?? 'new');
                    ?>
                    <div class="card application-card">
                        <div class="app-top">
                            <div class="app-title">
                                #<?php echo $appId; ?> · <?php echo ddAdminGroupWalksH($app['owner_name'] ?? 'Applicant'); ?> · <?php echo ddAdminGroupWalksH($app['dog_name'] ?? 'Dog'); ?>
                            </div>
                            <span class="badge <?php echo ddAdminGroupWalksH(ddAdminGroupWalksStatusBadgeClass($status)); ?>">
                                <?php echo ddAdminGroupWalksH($status); ?>
                            </span>
                        </div>

                        <div class="meta-grid">
                            <div class="meta-box">
                                <div class="meta-label">Email</div>
                                <div class="meta-value"><?php echo ddAdminGroupWalksH($app['email'] ?? '—'); ?></div>
                            </div>
                            <div class="meta-box">
                                <div class="meta-label">Phone</div>
                                <div class="meta-value"><?php echo ddAdminGroupWalksH($app['phone'] ?? '—'); ?></div>
                            </div>
                            <div class="meta-box">
                                <div class="meta-label">Neighborhood</div>
                                <div class="meta-value"><?php echo ddAdminGroupWalksH($app['neighborhood'] ?? '—'); ?></div>
                            </div>
                            <div class="meta-box">
                                <div class="meta-label">Submitted</div>
                                <div class="meta-value"><?php echo ddAdminGroupWalksH(ddAdminGroupWalksFormatDateTimeDisplay($app['created_at'] ?? '')); ?></div>
                            </div>

                            <div class="meta-box">
                                <div class="meta-label">Breed</div>
                                <div class="meta-value"><?php echo ddAdminGroupWalksH($app['breed'] ?? '—'); ?></div>
                            </div>
                            <div class="meta-box">
                                <div class="meta-label">Size</div>
                                <div class="meta-value"><?php echo ddAdminGroupWalksH($app['size'] ?? '—'); ?></div>
                            </div>
                            <div class="meta-box">
                                <div class="meta-label">Age</div>
                                <div class="meta-value"><?php echo ddAdminGroupWalksH($app['age'] ?? '—'); ?></div>
                            </div>
                            <div class="meta-box">
                                <div class="meta-label">Group Experience</div>
                                <div class="meta-value"><?php echo ddAdminGroupWalksH($app['prior_group_experience'] ?? '—'); ?></div>
                            </div>

                            <div class="meta-box">
                                <div class="meta-label">Preferred Days</div>
                                <div class="meta-value"><?php echo ddAdminGroupWalksH($app['preferred_days'] ?? '—'); ?></div>
                            </div>
                            <div class="meta-box">
                                <div class="meta-label">Preferred Time</div>
                                <div class="meta-value"><?php echo ddAdminGroupWalksH($app['preferred_time'] ?? '—'); ?></div>
                            </div>
                        </div>

                        <div class="detail-grid">
                            <div class="detail-box">
                                <strong>Temperament / Social Behavior</strong>
                                <div class="detail-copy"><?php echo ddAdminGroupWalksH($app['temperament'] ?? '—'); ?></div>
                            </div>

                            <div class="detail-box">
                                <strong>Leash Behavior</strong>
                                <div class="detail-copy"><?php echo ddAdminGroupWalksH($app['leash_behavior'] ?? '—'); ?></div>
                            </div>

                            <div class="detail-box">
                                <strong>Applicant Notes</strong>
                                <div class="detail-copy"><?php echo ddAdminGroupWalksH((isset($app['notes']) && trim((string) $app['notes']) !== '') ? $app['notes'] : '—'); ?></div>
                            </div>

                            <div class="detail-box">
                                <strong>Admin Update</strong>
                                <form method="post" action="admin-group-walk-applications.php">
                                    <input type="hidden" name="csrf_token" value="<?php echo ddAdminGroupWalksH($csrfToken); ?>">
                                    <input type="hidden" name="action" value="update_application">
                                    <input type="hidden" name="application_id" value="<?php echo $appId; ?>">

                                    <div class="form-grid">
                                        <div>
                                            <label for="status_<?php echo $appId; ?>">Status</label>
                                            <select id="status_<?php echo $appId; ?>" name="status">
                                                <option value="new" <?php echo $status === 'new' ? 'selected' : ''; ?>>New</option>
                                                <option value="reviewed" <?php echo $status === 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                                                <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                                <option value="declined" <?php echo $status === 'declined' ? 'selected' : ''; ?>>Declined</option>
                                            </select>
                                        </div>

                                        <div>
                                            <label for="admin_notes_<?php echo $appId; ?>">Admin Notes</label>
                                            <textarea id="admin_notes_<?php echo $appId; ?>" name="admin_notes"><?php echo ddAdminGroupWalksH($app['admin_notes'] ?? ''); ?></textarea>
                                        </div>
                                    </div>

                                    <div class="btn-row">
                                        <button type="submit" class="btn btn-gold">Save Update</button>
                                        <a class="btn btn-light" href="mailto:<?php echo ddAdminGroupWalksH($app['email'] ?? ''); ?>">Email Applicant</a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>
</body>
</html>