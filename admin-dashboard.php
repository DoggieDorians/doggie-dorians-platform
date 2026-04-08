<?php
declare(strict_types=1);

session_start();
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

function isAdmin()
{
    if (!empty($_SESSION['is_admin'])) {
        return true;
    }

    return isset($_SESSION['role']) && strtolower((string) $_SESSION['role']) === 'admin';
}

if (!isAdmin()) {
    redirectTo('admin-login.php');
}

function hasTable(PDO $pdo, $table)
{
    static $cache = array();

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :name LIMIT 1");
        $stmt->execute(array(':name' => $table));
        $cache[$table] = (bool) $stmt->fetchColumn();
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = false;
        return false;
    } catch (Exception $e) {
        $cache[$table] = false;
        return false;
    }
}

function getTableColumns(PDO $pdo, $table)
{
    static $cache = array();

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!hasTable($pdo, $table)) {
        $cache[$table] = array();
        return array();
    }

    try {
        $safeTable = str_replace('"', '""', $table);
        $stmt = $pdo->query('PRAGMA table_info("' . $safeTable . '")');
        $columns = array();

        if ($stmt) {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if (isset($row['name'])) {
                    $columns[] = (string) $row['name'];
                }
            }
        }

        $cache[$table] = $columns;
        return $columns;
    } catch (Throwable $e) {
        $cache[$table] = array();
        return array();
    } catch (Exception $e) {
        $cache[$table] = array();
        return array();
    }
}

function firstExistingColumn(PDO $pdo, $table, array $candidates)
{
    $columns = getTableColumns($pdo, $table);
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

function safeExecute(PDOStatement $stmt, array $params = array())
{
    try {
        return $stmt->execute($params);
    } catch (Throwable $e) {
        return false;
    } catch (Exception $e) {
        return false;
    }
}

function safeFetchAll(PDO $pdo, $sql, array $params = array())
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
    } catch (Exception $e) {
        return array();
    }
}

function safeFetchOne(PDO $pdo, $sql, array $params = array())
{
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt->execute($params)) {
            return null;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    } catch (Throwable $e) {
        return null;
    } catch (Exception $e) {
        return null;
    }
}

function countTable(PDO $pdo, $table)
{
    if (!hasTable($pdo, $table)) {
        return 0;
    }

    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    } catch (Exception $e) {
        return 0;
    }
}

function bookingBaseTable(PDO $pdo)
{
    foreach (array('bookings', 'walks') as $candidate) {
        if (hasTable($pdo, $candidate)) {
            return $candidate;
        }
    }

    return null;
}

function normalizeStatus($status)
{
    $status = strtolower(trim((string) $status));

    if ($status === 'new' || $status === 'open' || $status === 'unassigned') {
        return 'available';
    }
    if ($status === 'assigned' || $status === 'confirmed') {
        return 'accepted';
    }
    if ($status === 'active' || $status === 'walking' || $status === 'started' || $status === 'in progress') {
        return 'in_progress';
    }
    if ($status === 'done' || $status === 'finished' || $status === 'closed') {
        return 'completed';
    }
    if ($status === 'canceled' || $status === 'cancelled' || $status === 'void') {
        return 'cancelled';
    }

    return $status !== '' ? $status : 'pending';
}

function normalizePublicStatus($status)
{
    $status = strtolower(trim((string) $status));
    $allowed = array('new', 'reviewed', 'confirmed', 'completed', 'cancelled');

    if (in_array($status, $allowed, true)) {
        return $status;
    }

    return 'new';
}

function fetchRecentMemberBookings(PDO $pdo, $limit)
{
    $table = bookingBaseTable($pdo);
    if ($table === null) {
        return array();
    }

    $rows = safeFetchAll($pdo, 'SELECT * FROM ' . $table . ' ORDER BY rowid DESC LIMIT ' . (int) $limit);
    $items = array();

    foreach ($rows as $row) {
        $items[] = array(
            'id' => (int) valueFromRow($row, array('id', 'booking_id', 'walk_id'), 0),
            'client_name' => (string) valueFromRow($row, array('client_name', 'owner_name', 'member_name', 'customer_name', 'full_name', 'name'), 'Member Client'),
            'service_type' => (string) valueFromRow($row, array('service_type', 'type', 'booking_type', 'category', 'service'), 'service'),
            'status' => normalizeStatus((string) valueFromRow($row, array('status', 'booking_status', 'service_status', 'walk_status'), 'pending')),
            'date' => (string) valueFromRow($row, array('service_date', 'booking_date', 'walk_date', 'date', 'scheduled_date', 'start_date', 'created_at'), ''),
        );
    }

    return $items;
}

function fetchRecentPublicBookings(PDO $pdo, $limit)
{
    if (!hasTable($pdo, 'non_member_bookings')) {
        return array();
    }

    $rows = safeFetchAll($pdo, 'SELECT * FROM non_member_bookings ORDER BY rowid DESC LIMIT ' . (int) $limit);
    $items = array();

    foreach ($rows as $row) {
        $items[] = array(
            'id' => (int) valueFromRow($row, array('id'), 0),
            'client_name' => (string) valueFromRow($row, array('full_name', 'name'), 'Public Client'),
            'service_type' => (string) valueFromRow($row, array('service_type', 'service'), 'service'),
            'status' => normalizePublicStatus((string) valueFromRow($row, array('status'), 'new')),
            'date' => (string) valueFromRow($row, array('service_date', 'date', 'created_at'), ''),
        );
    }

    return $items;
}

function fetchRecentGroupWalkApplications(PDO $pdo, $limit)
{
    if (!hasTable($pdo, 'group_walk_applications')) {
        return array();
    }

    $rows = safeFetchAll($pdo, 'SELECT * FROM group_walk_applications ORDER BY rowid DESC LIMIT ' . (int) $limit);
    $items = array();

    foreach ($rows as $row) {
        $items[] = array(
            'id' => (int) valueFromRow($row, array('id'), 0),
            'owner_name' => (string) valueFromRow($row, array('owner_name', 'name'), 'Applicant'),
            'dog_name' => (string) valueFromRow($row, array('dog_name', 'pet_name'), 'Dog'),
            'status' => strtolower(trim((string) valueFromRow($row, array('status'), 'new'))),
            'date' => (string) valueFromRow($row, array('created_at'), ''),
        );
    }

    return $items;
}

function fetchMemberCount(PDO $pdo)
{
    foreach (array('users', 'members', 'client_profiles') as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $columns = getTableColumns($pdo, $table);
        if (empty($columns)) {
            continue;
        }

        $roleCol = firstExistingColumn($pdo, $table, array('role', 'user_role', 'account_type'));
        $isAdminCol = in_array('is_admin', $columns, true) ? 'is_admin' : null;

        $sql = 'SELECT COUNT(*) AS total_count FROM ' . $table;
        $conditions = array();

        if ($roleCol !== null) {
            $conditions[] = 'LOWER(COALESCE(' . $roleCol . ', "member")) NOT IN ("admin","administrator","walker","staff","employee","owner")';
        }

        if ($isAdminCol !== null) {
            $conditions[] = 'COALESCE(' . $isAdminCol . ', 0) = 0';
        }

        if (!empty($conditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $row = safeFetchOne($pdo, $sql);
        if ($row !== null) {
            return (int) (isset($row['total_count']) ? $row['total_count'] : 0);
        }
    }

    return 0;
}

function countUnreadNotifications(PDO $pdo)
{
    if (!hasTable($pdo, 'notifications')) {
        return 0;
    }

    $columns = getTableColumns($pdo, 'notifications');
    if (empty($columns)) {
        return 0;
    }

    $readCol = null;
    foreach (array('is_read', 'read_status', 'seen', 'viewed') as $candidate) {
        if (in_array($candidate, $columns, true)) {
            $readCol = $candidate;
            break;
        }
    }

    if ($readCol === null) {
        return countTable($pdo, 'notifications');
    }

    $row = safeFetchOne($pdo, 'SELECT COUNT(*) AS total_count FROM notifications WHERE COALESCE(' . $readCol . ', 0) = 0');
    return $row !== null ? (int) (isset($row['total_count']) ? $row['total_count'] : 0) : 0;
}

function valueFromRow(array $row, array $candidates, $default = '')
{
    foreach ($candidates as $candidate) {
        if (isset($row[$candidate]) && $row[$candidate] !== null && $row[$candidate] !== '') {
            return $row[$candidate];
        }
    }

    return $default;
}

function serviceDisplayName($type)
{
    $type = strtolower(trim((string) $type));

    if ($type === 'drop-in' || $type === 'dropin') {
        return 'Drop-In';
    }

    return ucfirst($type !== '' ? $type : 'Service');
}

function formatDateDisplay($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return $value;
    }

    return date('F j, Y', $ts);
}

function statusBadgeClass($status)
{
    $status = strtolower(trim((string) $status));

    if ($status === 'accepted' || $status === 'confirmed' || $status === 'approved') {
        return 'badge-accepted';
    }
    if ($status === 'in_progress' || $status === 'reviewed') {
        return 'badge-progress';
    }
    if ($status === 'completed') {
        return 'badge-complete';
    }
    if ($status === 'cancelled' || $status === 'rejected') {
        return 'badge-cancelled';
    }
    if ($status === 'available' || $status === 'new') {
        return 'badge-available';
    }

    return 'badge-pending';
}

$flash = isset($_SESSION['admin_dashboard_flash']) ? (string) $_SESSION['admin_dashboard_flash'] : '';
unset($_SESSION['admin_dashboard_flash']);

$memberBookings = countTable($pdo, 'bookings');
if ($memberBookings === 0) {
    $memberBookings = countTable($pdo, 'walks');
}
$publicBookings = countTable($pdo, 'non_member_bookings');
$groupWalkApps = countTable($pdo, 'group_walk_applications');
$memberCount = fetchMemberCount($pdo);
$unreadNotifications = countUnreadNotifications($pdo);

$totalBookings = $memberBookings + $publicBookings;

$recentMemberBookings = fetchRecentMemberBookings($pdo, 5);
$recentPublicBookings = fetchRecentPublicBookings($pdo, 5);
$recentGroupWalkApps = fetchRecentGroupWalkApplications($pdo, 5);

$newPublicCount = 0;
foreach ($recentPublicBookings as $row) {
    if ($row['status'] === 'new') {
        $newPublicCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Doggie Dorian’s</title>
    <meta name="description" content="Doggie Dorian’s admin control center.">
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #09090d;
            color: #fff;
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .page {
            max-width: 1400px;
            margin: auto;
            padding: 30px;
        }

        .top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            gap: 16px;
            flex-wrap: wrap;
        }

        .brand {
            font-weight: 900;
            font-size: 22px;
            letter-spacing: .03em;
        }

        .nav {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .nav a {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            color: #fff;
            font-weight: 700;
        }

        .flash {
            margin-bottom: 18px;
            padding: 14px 18px;
            border-radius: 16px;
            background: rgba(198,178,139,0.14);
            border: 1px solid rgba(198,178,139,0.30);
            color: #f3e5c2;
            font-weight: 700;
        }

        .hero {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 18px;
            margin-bottom: 22px;
        }

        .card {
            background: linear-gradient(180deg, rgba(255,255,255,0.065), rgba(255,255,255,0.03));
            padding: 22px;
            border-radius: 24px;
            border: 1px solid rgba(255,255,255,0.08);
            box-shadow: 0 20px 60px rgba(0,0,0,0.28);
        }

        .hero-card {
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
            font-size: 1.2rem;
        }

        .sub {
            color: rgba(255,255,255,0.74);
            line-height: 1.65;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 15px;
            margin-top: 20px;
        }

        .stat-card {
            background: rgba(255,255,255,0.04);
            padding: 18px;
            border-radius: 20px;
            border: 1px solid rgba(255,255,255,0.06);
        }

        .label {
            color: #aaa;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .08em;
            margin-bottom: 8px;
        }

        .big {
            font-size: 28px;
            font-weight: 900;
        }

        .btn-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 14px;
            border-radius: 12px;
            font-weight: 800;
        }

        .btn-gold {
            background: linear-gradient(135deg, #e2c48d, #b9975b);
            color: #000;
        }

        .btn-light {
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.12);
            color: #fff;
        }

        .sections {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-top: 24px;
        }

        .list {
            display: grid;
            gap: 12px;
        }

        .item {
            padding: 14px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .item-top {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 8px;
        }

        .item-title {
            font-size: 1rem;
            font-weight: 900;
        }

        .item-meta {
            color: rgba(255,255,255,0.68);
            font-size: .92rem;
            line-height: 1.55;
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

        .badge-pending { background: rgba(255,255,255,0.08); color: #f5f3ef; }
        .badge-available { background: rgba(125,150,255,0.16); color: #cbd6ff; }
        .badge-accepted { background: rgba(215,183,120,0.18); color: #f3dfb1; }
        .badge-progress { background: rgba(109,174,255,0.18); color: #d0e4ff; }
        .badge-complete { background: rgba(125,206,141,0.18); color: #d7f1dd; }
        .badge-cancelled { background: rgba(214,123,123,0.18); color: #ffd5d5; }

        .muted {
            color: rgba(255,255,255,0.62);
        }

        .empty {
            padding: 18px;
            border-radius: 18px;
            background: rgba(255,255,255,0.03);
            border: 1px dashed rgba(255,255,255,0.12);
            color: rgba(255,255,255,0.62);
        }

        @media (max-width: 1180px) {
            .grid {
                grid-template-columns: 1fr 1fr;
            }

            .hero,
            .sections {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 700px) {
            .grid {
                grid-template-columns: 1fr;
            }

            .page {
                padding: 20px 12px 60px;
            }

            h1 {
                font-size: 1.65rem;
            }
        }
    </style>
</head>
<body>
    <div class="page">

        <div class="top">
            <div class="brand">Doggie Dorian’s Admin</div>

            <div class="nav">
                <a href="admin-dashboard.php">Dashboard</a>
                <a href="admin-bookings.php">Bookings</a>
                <a href="admin-members.php">Members</a>
                <a href="admin-group-walk-applications.php">Group Walks</a>
                <a href="logout.php">Logout</a>
            </div>
        </div>

        <?php if ($flash !== ''): ?>
            <div class="flash"><?php echo h($flash); ?></div>
        <?php endif; ?>

        <section class="hero">
            <div class="card hero-card">
                <div class="eyebrow">Control Center</div>
                <h1>Admin Dashboard</h1>
                <div class="sub">
                    Monitor bookings, member growth, public requests, and group walk applications from one premium control panel.
                </div>

                <div class="btn-row">
                    <a href="admin-bookings.php" class="btn btn-gold">Manage Bookings</a>
                    <a href="admin-members.php" class="btn btn-light">View Members</a>
                    <a href="admin-group-walk-applications.php" class="btn btn-light">Review Applications</a>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Live Snapshot</div>
                <h2>System status</h2>
                <div class="sub">
                    Quick view of the most important activity on the platform right now.
                </div>

                <div class="btn-row">
                    <div class="btn btn-light">Unread Notifications: <?php echo (int) $unreadNotifications; ?></div>
                    <div class="btn btn-light">New Public Bookings: <?php echo (int) $newPublicCount; ?></div>
                </div>
            </div>
        </section>

        <div class="grid">
            <div class="stat-card">
                <div class="label">Total Bookings</div>
                <div class="big"><?php echo (int) $totalBookings; ?></div>
            </div>

            <div class="stat-card">
                <div class="label">Member Bookings</div>
                <div class="big"><?php echo (int) $memberBookings; ?></div>
            </div>

            <div class="stat-card">
                <div class="label">Public Bookings</div>
                <div class="big"><?php echo (int) $publicBookings; ?></div>
            </div>

            <div class="stat-card">
                <div class="label">Group Walk Apps</div>
                <div class="big"><?php echo (int) $groupWalkApps; ?></div>
            </div>

            <div class="stat-card">
                <div class="label">Members</div>
                <div class="big"><?php echo (int) $memberCount; ?></div>
            </div>
        </div>

        <section class="sections">
            <div class="card">
                <div class="eyebrow">Recent Member Bookings</div>
                <h2>Latest member activity</h2>

                <div class="list">
                    <?php if (empty($recentMemberBookings)): ?>
                        <div class="empty">No member bookings found yet.</div>
                    <?php else: ?>
                        <?php foreach ($recentMemberBookings as $item): ?>
                            <div class="item">
                                <div class="item-top">
                                    <div class="item-title">
                                        #<?php echo (int) $item['id']; ?> · <?php echo h(serviceDisplayName($item['service_type'])); ?>
                                    </div>
                                    <span class="badge <?php echo h(statusBadgeClass($item['status'])); ?>">
                                        <?php echo h(str_replace('_', ' ', $item['status'])); ?>
                                    </span>
                                </div>
                                <div class="item-meta">
                                    <?php echo h($item['client_name']); ?> · <?php echo h(formatDateDisplay($item['date'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Recent Public Bookings</div>
                <h2>Latest non-member requests</h2>

                <div class="list">
                    <?php if (empty($recentPublicBookings)): ?>
                        <div class="empty">No public bookings found yet.</div>
                    <?php else: ?>
                        <?php foreach ($recentPublicBookings as $item): ?>
                            <div class="item">
                                <div class="item-top">
                                    <div class="item-title">
                                        #<?php echo (int) $item['id']; ?> · <?php echo h(serviceDisplayName($item['service_type'])); ?>
                                    </div>
                                    <span class="badge <?php echo h(statusBadgeClass($item['status'])); ?>">
                                        <?php echo h(str_replace('_', ' ', $item['status'])); ?>
                                    </span>
                                </div>
                                <div class="item-meta">
                                    <?php echo h($item['client_name']); ?> · <?php echo h(formatDateDisplay($item['date'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Recent Group Walk Applications</div>
                <h2>Latest applicants</h2>

                <div class="list">
                    <?php if (empty($recentGroupWalkApps)): ?>
                        <div class="empty">No group walk applications found yet.</div>
                    <?php else: ?>
                        <?php foreach ($recentGroupWalkApps as $item): ?>
                            <div class="item">
                                <div class="item-top">
                                    <div class="item-title">
                                        #<?php echo (int) $item['id']; ?> · <?php echo h($item['owner_name']); ?>
                                    </div>
                                    <span class="badge <?php echo h(statusBadgeClass($item['status'])); ?>">
                                        <?php echo h(str_replace('_', ' ', $item['status'])); ?>
                                    </span>
                                </div>
                                <div class="item-meta">
                                    Dog: <?php echo h($item['dog_name']); ?> · <?php echo h(formatDateDisplay($item['date'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Quick Links</div>
                <h2>Admin shortcuts</h2>

                <div class="list">
                    <a class="item" href="admin-bookings.php">
                        <div class="item-title">Open bookings manager</div>
                        <div class="item-meta">Review member and public bookings in one place.</div>
                    </a>

                    <a class="item" href="admin-members.php">
                        <div class="item-title">Open member directory</div>
                        <div class="item-meta">See all signed-up members and their account details.</div>
                    </a>

                    <a class="item" href="admin-group-walk-applications.php">
                        <div class="item-title">Open group walk applications</div>
                        <div class="item-meta">Review, approve, or reject applicants.</div>
                    </a>
                </div>
            </div>
        </section>
    </div>
</body>
</html>