<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security-headers.php';

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

function valueFromRow(array $row, array $candidates, $default = '')
{
    foreach ($candidates as $candidate) {
        if (isset($row[$candidate]) && $row[$candidate] !== null && $row[$candidate] !== '') {
            return (string) $row[$candidate];
        }
    }

    return $default;
}

function formatDateTimeDisplay($value)
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

function fetchMembers(PDO $pdo)
{
    $possibleTables = array('users', 'members', 'client_profiles');

    foreach ($possibleTables as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $columns = getTableColumns($pdo, $table);
        if (empty($columns)) {
            continue;
        }

        $idCol = firstExistingColumn($pdo, $table, array('id', 'user_id', 'member_id', 'client_id'));
        if ($idCol === null) {
            continue;
        }

        $nameCol = firstExistingColumn($pdo, $table, array('full_name', 'name', 'client_name', 'member_name'));
        $emailCol = firstExistingColumn($pdo, $table, array('email'));
        $phoneCol = firstExistingColumn($pdo, $table, array('phone', 'phone_number', 'mobile', 'cell_phone'));
        $usernameCol = firstExistingColumn($pdo, $table, array('username'));
        $membershipCol = firstExistingColumn($pdo, $table, array('membership_type', 'membership', 'plan_type'));
        $preferredLoginCol = firstExistingColumn($pdo, $table, array('preferred_login'));
        $createdCol = firstExistingColumn($pdo, $table, array('created_at', 'date_created', 'registered_at'));
        $addressCol = firstExistingColumn($pdo, $table, array('address', 'street_address'));
        $cityCol = firstExistingColumn($pdo, $table, array('city'));
        $stateCol = firstExistingColumn($pdo, $table, array('state', 'province'));
        $zipCol = firstExistingColumn($pdo, $table, array('zip', 'zipcode', 'postal_code'));

        $select = array(
            $idCol . ' AS member_id',
            ($nameCol !== null ? $nameCol : "''") . ' AS full_name',
            ($emailCol !== null ? $emailCol : "''") . ' AS email',
            ($phoneCol !== null ? $phoneCol : "''") . ' AS phone',
            ($usernameCol !== null ? $usernameCol : "''") . ' AS username',
            ($membershipCol !== null ? $membershipCol : "''") . ' AS membership_type',
            ($preferredLoginCol !== null ? $preferredLoginCol : "''") . ' AS preferred_login',
            ($createdCol !== null ? $createdCol : "''") . ' AS created_at',
            ($addressCol !== null ? $addressCol : "''") . ' AS address',
            ($cityCol !== null ? $cityCol : "''") . ' AS city',
            ($stateCol !== null ? $stateCol : "''") . ' AS state',
            ($zipCol !== null ? $zipCol : "''") . ' AS zip_code'
        );

        $sql = 'SELECT ' . implode(', ', $select) . ' FROM ' . $table;

        $roleCol = firstExistingColumn($pdo, $table, array('role', 'user_role', 'account_type'));
        $isAdminCol = in_array('is_admin', $columns, true) ? 'is_admin' : null;
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

        if ($createdCol !== null) {
            $sql .= ' ORDER BY ' . $createdCol . ' DESC';
        } else {
            $sql .= ' ORDER BY ' . $idCol . ' DESC';
        }

        $rows = safeFetchAll($pdo, $sql);
        if (!empty($rows)) {
            return $rows;
        }
    }

    return array();
}

$members = fetchMembers($pdo);
$totalMembers = count($members);

$search = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

if ($search !== '') {
    $filtered = array();

    foreach ($members as $member) {
        $haystack = strtolower(
            valueFromRow($member, array('full_name')) . ' ' .
            valueFromRow($member, array('email')) . ' ' .
            valueFromRow($member, array('phone')) . ' ' .
            valueFromRow($member, array('username')) . ' ' .
            valueFromRow($member, array('membership_type'))
        );

        if (strpos($haystack, strtolower($search)) !== false) {
            $filtered[] = $member;
        }
    }

    $members = $filtered;
}

$withPhone = 0;
$withEmail = 0;
$recentSignups = 0;
$thirtyDaysAgo = strtotime('-30 days');

foreach ($members as $member) {
    if (trim(valueFromRow($member, array('phone'))) !== '') {
        $withPhone++;
    }
    if (trim(valueFromRow($member, array('email'))) !== '') {
        $withEmail++;
    }

    $createdAt = valueFromRow($member, array('created_at'));
    $createdTs = strtotime($createdAt);
    if ($createdTs !== false && $createdTs >= $thirtyDaysAgo) {
        $recentSignups++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Members | Doggie Dorian’s</title>
    <meta name="description" content="View all member signups and account details.">
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
            max-width: 1450px;
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

        .hero {
            display: grid;
            grid-template-columns: 1.08fr 0.92fr;
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
            grid-template-columns: repeat(4, 1fr);
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

        .search-form {
            display: grid;
            gap: 12px;
        }

        .search-row {
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 10px;
        }

        input {
            width: 100%;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(0,0,0,0.26);
            color: #fff;
            padding: 13px 14px;
            font: inherit;
            outline: none;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 16px;
            border-radius: 14px;
            font-size: .94rem;
            font-weight: 800;
            border: none;
            cursor: pointer;
            white-space: nowrap;
        }

        .btn-gold {
            background: linear-gradient(135deg, #e2c48d, #b9975b);
            color: #000;
        }

        .btn-light {
            background: rgba(255,255,255,0.06);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.12);
        }

        .table-wrap {
            overflow-x: auto;
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.03);
            margin-top: 24px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
        }

        th, td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            font-size: 14px;
            vertical-align: top;
        }

        th {
            background: rgba(255,255,255,0.04);
            color: rgba(255,255,255,0.68);
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .member-name {
            font-weight: 900;
            font-size: 15px;
        }

        .member-sub {
            color: rgba(255,255,255,0.64);
            font-size: 12px;
            margin-top: 4px;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            background: rgba(198,178,139,0.16);
            color: #f3e5c7;
            border: 1px solid rgba(198,178,139,0.28);
        }

        .muted {
            color: rgba(255,255,255,0.62);
        }

        .empty {
            padding: 18px;
            border-radius: 18px;
            background: rgba(255,255,255,0.03);
            border: 1px dashed rgba(255,255,255,0.12);
            color: rgba(255,255,255,0.62);
            margin-top: 24px;
        }

        @media (max-width: 1180px) {
            .hero,
            .grid {
                grid-template-columns: 1fr;
            }

            .search-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 700px) {
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

        <section class="hero">
            <div class="card hero-card">
                <div class="eyebrow">Member Directory</div>
                <h1>Admin Members</h1>
                <div class="sub">
                    View everyone who signed up as a member, review their contact details, and keep account visibility centralized inside the admin system.
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Search Members</div>
                <h2>Find a member fast</h2>
                <form class="search-form" method="get" action="admin-members.php">
                    <div class="search-row">
                        <input
                            type="text"
                            name="q"
                            value="<?php echo h($search); ?>"
                            placeholder="Search by name, email, phone, username, or membership type"
                        >
                        <button type="submit" class="btn btn-gold">Search</button>
                        <a href="admin-members.php" class="btn btn-light">Reset</a>
                    </div>
                </form>
            </div>
        </section>

        <div class="grid">
            <div class="stat-card">
                <div class="label">Visible Members</div>
                <div class="big"><?php echo (int) count($members); ?></div>
            </div>

            <div class="stat-card">
                <div class="label">Total Members</div>
                <div class="big"><?php echo (int) $totalMembers; ?></div>
            </div>

            <div class="stat-card">
                <div class="label">With Email</div>
                <div class="big"><?php echo (int) $withEmail; ?></div>
            </div>

            <div class="stat-card">
                <div class="label">With Phone</div>
                <div class="big"><?php echo (int) $withPhone; ?></div>
            </div>
        </div>

        <?php if (empty($members)): ?>
            <div class="empty">No member records matched your search.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Member</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Username</th>
                            <th>Membership</th>
                            <th>Preferred Login</th>
                            <th>Address</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($members as $member): ?>
                            <?php
                            $fullAddress = trim(
                                valueFromRow($member, array('address')) . ' ' .
                                valueFromRow($member, array('city')) . ' ' .
                                valueFromRow($member, array('state')) . ' ' .
                                valueFromRow($member, array('zip_code'))
                            );
                            ?>
                            <tr>
                                <td>
                                    <div class="member-name"><?php echo h(valueFromRow($member, array('full_name'), '—')); ?></div>
                                    <div class="member-sub">ID #<?php echo h(valueFromRow($member, array('member_id'), '—')); ?></div>
                                </td>
                                <td><?php echo h(valueFromRow($member, array('email'), '—')); ?></td>
                                <td><?php echo h(valueFromRow($member, array('phone'), '—')); ?></td>
                                <td><?php echo h(valueFromRow($member, array('username'), '—')); ?></td>
                                <td>
                                    <?php if (trim(valueFromRow($member, array('membership_type'))) !== ''): ?>
                                        <span class="pill"><?php echo h(valueFromRow($member, array('membership_type'))); ?></span>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo h(valueFromRow($member, array('preferred_login'), '—')); ?></td>
                                <td><?php echo h($fullAddress !== '' ? $fullAddress : '—'); ?></td>
                                <td><?php echo h(formatDateTimeDisplay(valueFromRow($member, array('created_at'), ''))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>