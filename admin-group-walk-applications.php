<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection is not available.';
    exit;
}

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirectTo($url)
{
    header('Location: ' . $url);
    exit;
}

function currentUserRole()
{
    $role = isset($_SESSION['role']) ? (string) $_SESSION['role'] : '';

    if ($role !== '') {
        return strtolower($role);
    }

    if (!empty($_SESSION['is_admin'])) {
        return 'admin';
    }

    return 'member';
}

function isAdmin()
{
    if (!empty($_SESSION['is_admin'])) {
        return true;
    }

    return currentUserRole() === 'admin';
}

if (!isAdmin()) {
    redirectTo('admin-login.php');
}

function safe_execute(PDO $pdo, $sql, array $params = array())
{
    try {
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    } catch (Throwable $e) {
        return false;
    } catch (Exception $e) {
        return false;
    }
}

function safe_fetch_all(PDO $pdo, $sql, array $params = array())
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    } catch (Throwable $e) {
        return array();
    } catch (Exception $e) {
        return array();
    }
}

function safe_fetch_one(PDO $pdo, $sql, array $params = array())
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    } catch (Throwable $e) {
        return null;
    } catch (Exception $e) {
        return null;
    }
}

function table_exists(PDO $pdo, $table)
{
    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1");
        $stmt->execute(array(':table' => $table));
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    } catch (Exception $e) {
        return false;
    }
}

function create_group_walks_table_if_needed(PDO $pdo)
{
    if (table_exists($pdo, 'group_walk_applications')) {
        return true;
    }

    $sql = "
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
            notes TEXT DEFAULT '',
            admin_notes TEXT DEFAULT '',
            status TEXT NOT NULL DEFAULT 'new',
            created_at TEXT NOT NULL
        )
    ";

    return safe_execute($pdo, $sql);
}

function get_table_columns(PDO $pdo, $table)
{
    try {
        $safeTable = str_replace('"', '""', $table);
        $stmt = $pdo->query('PRAGMA table_info("' . $safeTable . '")');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        $columns = array();

        foreach ($rows as $row) {
            if (isset($row['name']) && $row['name'] !== '') {
                $columns[] = (string) $row['name'];
            }
        }

        return $columns;
    } catch (Throwable $e) {
        return array();
    } catch (Exception $e) {
        return array();
    }
}

function column_exists(array $columns, $column)
{
    return in_array($column, $columns, true);
}

function ensure_admin_notes_column(PDO $pdo)
{
    if (!table_exists($pdo, 'group_walk_applications')) {
        return;
    }

    $columns = get_table_columns($pdo, 'group_walk_applications');
    if (column_exists($columns, 'admin_notes')) {
        return;
    }

    try {
        $pdo->exec('ALTER TABLE group_walk_applications ADD COLUMN admin_notes TEXT DEFAULT ""');
    } catch (Throwable $e) {
    } catch (Exception $e) {
    }
}

function normalize_status($status)
{
    $status = strtolower(trim((string) $status));
    $allowed = array('new', 'reviewed', 'approved', 'declined');

    if (in_array($status, $allowed, true)) {
        return $status;
    }

    return 'new';
}

function format_datetime_display($value)
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

function status_badge_class($status)
{
    $status = normalize_status($status);

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

function create_notification_if_possible(PDO $pdo, $title, $message)
{
    if (!table_exists($pdo, 'notifications')) {
        return;
    }

    $columns = get_table_columns($pdo, 'notifications');
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
        if (in_array($column, $columns, true)) {
            $insertCols[] = $column;
            $placeholders[] = ':' . $column;
            $params[':' . $column] = $value;
        }
    }

    if (empty($insertCols)) {
        return;
    }

    $sql = 'INSERT INTO notifications (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $placeholders) . ')';
    safe_execute($pdo, $sql, $params);
}

if (!create_group_walks_table_if_needed($pdo)) {
    http_response_code(500);
    echo 'Could not prepare the group walk applications table.';
    exit;
}

ensure_admin_notes_column($pdo);

$flash = isset($_SESSION['admin_group_walks_flash']) ? (string) $_SESSION['admin_group_walks_flash'] : '';
$flashType = isset($_SESSION['admin_group_walks_flash_type']) ? (string) $_SESSION['admin_group_walks_flash_type'] : '';
unset($_SESSION['admin_group_walks_flash'], $_SESSION['admin_group_walks_flash_type']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    if ($action === 'update_application') {
        $applicationId = (int) (isset($_POST['application_id']) ? $_POST['application_id'] : 0);
        $newStatus = normalize_status(isset($_POST['status']) ? $_POST['status'] : 'new');
        $adminNotes = trim((string) (isset($_POST['admin_notes']) ? $_POST['admin_notes'] : ''));

        if ($applicationId <= 0) {
            $_SESSION['admin_group_walks_flash_type'] = 'error';
            $_SESSION['admin_group_walks_flash'] = 'Invalid application selected.';
            redirectTo('admin-group-walk-applications.php');
        }

        $existing = safe_fetch_one(
            $pdo,
            'SELECT * FROM group_walk_applications WHERE id = :id LIMIT 1',
            array(':id' => $applicationId)
        );

        if ($existing === null) {
            $_SESSION['admin_group_walks_flash_type'] = 'error';
            $_SESSION['admin_group_walks_flash'] = 'Application not found.';
            redirectTo('admin-group-walk-applications.php');
        }

        $updated = safe_execute(
            $pdo,
            'UPDATE group_walk_applications SET status = :status, admin_notes = :admin_notes WHERE id = :id',
            array(
                ':status' => $newStatus,
                ':admin_notes' => $adminNotes,
                ':id' => $applicationId,
            )
        );

        if ($updated) {
            create_notification_if_possible(
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

        redirectTo('admin-group-walk-applications.php');
    }
}

$statusFilter = normalize_status(isset($_GET['status']) ? $_GET['status'] : 'new');
$showAll = isset($_GET['status']) && (string) $_GET['status'] === 'all';

if ($showAll) {
    $applications = safe_fetch_all(
        $pdo,
        'SELECT * FROM group_walk_applications ORDER BY created_at DESC, id DESC'
    );
} else {
    $applications = safe_fetch_all(
        $pdo,
        'SELECT * FROM group_walk_applications WHERE status = :status ORDER BY created_at DESC, id DESC',
        array(':status' => $statusFilter)
    );
}

$countsRow = safe_fetch_one(
    $pdo,
    "SELECT
        COUNT(*) AS total_count,
        SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) AS new_count,
        SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) AS reviewed_count,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
        SUM(CASE WHEN status = 'declined' THEN 1 ELSE 0 END) AS declined_count
     FROM group_walk_applications"
);

$totalCount = (int) (isset($countsRow['total_count']) ? $countsRow['total_count'] : 0);
$newCount = (int) (isset($countsRow['new_count']) ? $countsRow['new_count'] : 0);
$reviewedCount = (int) (isset($countsRow['reviewed_count']) ? $countsRow['reviewed_count'] : 0);
$approvedCount = (int) (isset($countsRow['approved_count']) ? $countsRow['approved_count'] : 0);
$declinedCount = (int) (isset($countsRow['declined_count']) ? $countsRow['declined_count'] : 0);
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
                <a class="top-link" href="admin-bookings.php">Bookings</a>
                <a class="top-link" href="admin-group-walk-applications.php">Group Walk Applications</a>
                <a class="top-link" href="logout.php">Logout</a>
            </div>
        </div>

        <?php if ($flash !== ''): ?>
            <div class="<?php echo $flashType === 'success' ? 'flash-success' : 'flash-error'; ?>">
                <?php echo h($flash); ?>
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
                    $appId = (int) (isset($app['id']) ? $app['id'] : 0);
                    $status = normalize_status(isset($app['status']) ? $app['status'] : 'new');
                    ?>
                    <div class="card application-card">
                        <div class="app-top">
                            <div class="app-title">
                                #<?php echo $appId; ?> · <?php echo h(isset($app['owner_name']) ? $app['owner_name'] : 'Applicant'); ?> · <?php echo h(isset($app['dog_name']) ? $app['dog_name'] : 'Dog'); ?>
                            </div>
                            <span class="badge <?php echo h(status_badge_class($status)); ?>">
                                <?php echo h($status); ?>
                            </span>
                        </div>

                        <div class="meta-grid">
                            <div class="meta-box">
                                <div class="meta-label">Email</div>
                                <div class="meta-value"><?php echo h(isset($app['email']) ? $app['email'] : '—'); ?></div>
                            </div>
                            <div class="meta-box">
                                <div class="meta-label">Phone</div>
                                <div class="meta-value"><?php echo h(isset($app['phone']) ? $app['phone'] : '—'); ?></div>
                            </div>
                            <div class="meta-box">
                                <div class="meta-label">Neighborhood</div>
                                <div class="meta-value"><?php echo h(isset($app['neighborhood']) ? $app['neighborhood'] : '—'); ?></div>
                            </div>
                            <div class="meta-box">
                                <div class="meta-label">Submitted</div>
                                <div class="meta-value"><?php echo h(format_datetime_display(isset($app['created_at']) ? $app['created_at'] : '')); ?></div>
                            </div>

                            <div class="meta-box">
                                <div class="meta-label">Breed</div>
                                <div class="meta-value"><?php echo h(isset($app['breed']) ? $app['breed'] : '—'); ?></div>
                            </div>
                            <div class="meta-box">
                                <div class="meta-label">Size</div>
                                <div class="meta-value"><?php echo h(isset($app['size']) ? $app['size'] : '—'); ?></div>
                            </div>
                            <div class="meta-box">
                                <div class="meta-label">Age</div>
                                <div class="meta-value"><?php echo h(isset($app['age']) ? $app['age'] : '—'); ?></div>
                            </div>
                            <div class="meta-box">
                                <div class="meta-label">Group Experience</div>
                                <div class="meta-value"><?php echo h(isset($app['prior_group_experience']) ? $app['prior_group_experience'] : '—'); ?></div>
                            </div>

                            <div class="meta-box">
                                <div class="meta-label">Preferred Days</div>
                                <div class="meta-value"><?php echo h(isset($app['preferred_days']) ? $app['preferred_days'] : '—'); ?></div>
                            </div>
                            <div class="meta-box">
                                <div class="meta-label">Preferred Time</div>
                                <div class="meta-value"><?php echo h(isset($app['preferred_time']) ? $app['preferred_time'] : '—'); ?></div>
                            </div>
                        </div>

                        <div class="detail-grid">
                            <div class="detail-box">
                                <strong>Temperament / Social Behavior</strong>
                                <div class="detail-copy"><?php echo h(isset($app['temperament']) ? $app['temperament'] : '—'); ?></div>
                            </div>

                            <div class="detail-box">
                                <strong>Leash Behavior</strong>
                                <div class="detail-copy"><?php echo h(isset($app['leash_behavior']) ? $app['leash_behavior'] : '—'); ?></div>
                            </div>

                            <div class="detail-box">
                                <strong>Applicant Notes</strong>
                                <div class="detail-copy"><?php echo h((isset($app['notes']) && trim((string) $app['notes']) !== '') ? $app['notes'] : '—'); ?></div>
                            </div>

                            <div class="detail-box">
                                <strong>Admin Update</strong>
                                <form method="post" action="admin-group-walk-applications.php">
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
                                            <textarea id="admin_notes_<?php echo $appId; ?>" name="admin_notes"><?php echo h(isset($app['admin_notes']) ? $app['admin_notes'] : ''); ?></textarea>
                                        </div>
                                    </div>

                                    <div class="btn-row">
                                        <button type="submit" class="btn btn-gold">Save Update</button>
                                        <a class="btn btn-light" href="mailto:<?php echo h(isset($app['email']) ? $app['email'] : ''); ?>">Email Applicant</a>
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