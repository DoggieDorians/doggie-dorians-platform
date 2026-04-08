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

function currentUserRole()
{
    $role = isset($_SESSION['role']) ? (string) $_SESSION['role'] : '';

    if ($role !== '') {
        return strtolower($role);
    }

    if (!empty($_SESSION['is_admin'])) {
        return 'admin';
    }

    if (!empty($_SESSION['walker_id']) || !empty($_SESSION['staff_id']) || !empty($_SESSION['employee_id'])) {
        return 'walker';
    }

    return 'member';
}

function currentUserId()
{
    $keys = array('user_id', 'member_id', 'client_id', 'id');

    foreach ($keys as $key) {
        if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) {
            return (int) $_SESSION[$key];
        }
    }

    return 0;
}

function isMemberLike()
{
    return currentUserRole() === 'member' || currentUserId() > 0;
}

if (!isMemberLike()) {
    redirectTo('login.php');
}

function hasTable(PDO $pdo, $table)
{
    static $cache = array();

    if (array_key_exists($table, $cache)) {
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

    if (array_key_exists($table, $cache)) {
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

function hasColumn(PDO $pdo, $table, $column)
{
    return in_array($column, getTableColumns($pdo, $table), true);
}

function firstExistingColumn(PDO $pdo, $table, array $candidates)
{
    foreach ($candidates as $candidate) {
        if (hasColumn($pdo, $table, $candidate)) {
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

function countUnreadNotificationsForUser(PDO $pdo, $userId)
{
    $userId = (int) $userId;
    $tables = array('notifications', 'user_notifications', 'alerts');

    foreach ($tables as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $readCol = firstExistingColumn($pdo, $table, array('is_read', 'read_status', 'seen', 'viewed'));
        $userCol = firstExistingColumn($pdo, $table, array('user_id'));
        $memberCol = firstExistingColumn($pdo, $table, array('member_id'));

        if ($readCol === null) {
            continue;
        }

        try {
            if ($userCol !== null) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$userCol} = :id AND COALESCE({$readCol}, 0) = 0");
                if (safeExecute($stmt, array(':id' => $userId))) {
                    return (int) $stmt->fetchColumn();
                }
            }

            if ($memberCol !== null) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$memberCol} = :id AND COALESCE({$readCol}, 0) = 0");
                if (safeExecute($stmt, array(':id' => $userId))) {
                    return (int) $stmt->fetchColumn();
                }
            }
        } catch (Throwable $e) {
            continue;
        } catch (Exception $e) {
            continue;
        }
    }

    return 0;
}

function getProfileRow(PDO $pdo, $userId)
{
    $userId = (int) $userId;

    $tables = array('users', 'members', 'client_profiles');

    foreach ($tables as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $idCol = firstExistingColumn($pdo, $table, array('id', 'user_id', 'member_id', 'client_id'));
        if ($idCol === null) {
            continue;
        }

        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE {$idCol} = :id LIMIT 1");
        if (!safeExecute($stmt, array(':id' => $userId))) {
            continue;
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            $row['_source_table'] = $table;
            $row['_id_column'] = $idCol;
            return $row;
        }
    }

    return null;
}

function valueFromRow(array $row, array $candidates, $default = '')
{
    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $row) && $row[$candidate] !== null && $row[$candidate] !== '') {
            return $row[$candidate];
        }
    }

    return $default;
}

function buildProfileData(array $row)
{
    return array(
        'full_name' => (string) valueFromRow($row, array('full_name', 'name', 'client_name', 'member_name'), ''),
        'email' => (string) valueFromRow($row, array('email'), ''),
        'phone' => (string) valueFromRow($row, array('phone', 'phone_number', 'mobile', 'cell_phone'), ''),
        'username' => (string) valueFromRow($row, array('username'), ''),
        'address' => (string) valueFromRow($row, array('address', 'street_address'), ''),
        'city' => (string) valueFromRow($row, array('city'), ''),
        'state' => (string) valueFromRow($row, array('state', 'province'), ''),
        'zip' => (string) valueFromRow($row, array('zip', 'zipcode', 'postal_code'), ''),
        'membership_type' => (string) valueFromRow($row, array('membership_type', 'membership', 'plan_type'), 'Active'),
        'preferred_login' => (string) valueFromRow($row, array('preferred_login'), ''),
    );
}

function updateProfile(PDO $pdo, array $row, array $updates)
{
    $table = isset($row['_source_table']) ? (string) $row['_source_table'] : '';
    $idColumn = isset($row['_id_column']) ? (string) $row['_id_column'] : '';
    $idValue = isset($row[$idColumn]) ? (int) $row[$idColumn] : 0;

    if ($table === '' || $idColumn === '' || $idValue <= 0) {
        return false;
    }

    $columns = getTableColumns($pdo, $table);
    if (empty($columns)) {
        return false;
    }

    $sets = array();
    $params = array();

    foreach ($updates as $column => $value) {
        if (in_array($column, $columns, true)) {
            $sets[] = $column . ' = :' . $column;
            $params[':' . $column] = $value;
        }
    }

    if (empty($sets)) {
        return true;
    }

    if (in_array('updated_at', $columns, true) && !isset($updates['updated_at'])) {
        $sets[] = 'updated_at = :updated_at';
        $params[':updated_at'] = date('Y-m-d H:i:s');
    }

    $params[':id'] = $idValue;

    $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE ' . $idColumn . ' = :id';
    $stmt = $pdo->prepare($sql);

    return safeExecute($stmt, $params);
}

$userId = currentUserId();
if ($userId <= 0) {
    redirectTo('login.php');
}

$flash = isset($_SESSION['profile_flash']) ? (string) $_SESSION['profile_flash'] : '';
$flashType = isset($_SESSION['profile_flash_type']) ? (string) $_SESSION['profile_flash_type'] : '';
unset($_SESSION['profile_flash'], $_SESSION['profile_flash_type']);

$unreadNotifications = countUnreadNotificationsForUser($pdo, $userId);

$profileRow = getProfileRow($pdo, $userId);
if ($profileRow === null) {
    http_response_code(404);
    echo 'Profile could not be loaded.';
    exit;
}

$profile = buildProfileData($profileRow);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim((string) (isset($_POST['full_name']) ? $_POST['full_name'] : ''));
    $phone = trim((string) (isset($_POST['phone']) ? $_POST['phone'] : ''));
    $address = trim((string) (isset($_POST['address']) ? $_POST['address'] : ''));
    $city = trim((string) (isset($_POST['city']) ? $_POST['city'] : ''));
    $state = trim((string) (isset($_POST['state']) ? $_POST['state'] : ''));
    $zip = trim((string) (isset($_POST['zip']) ? $_POST['zip'] : ''));

    if ($fullName === '') {
        $_SESSION['profile_flash_type'] = 'error';
        $_SESSION['profile_flash'] = 'Full name is required.';
        redirectTo('profile.php');
    }

    $updates = array(
        'full_name' => $fullName,
        'name' => $fullName,
        'client_name' => $fullName,
        'member_name' => $fullName,
        'phone' => $phone,
        'phone_number' => $phone,
        'mobile' => $phone,
        'cell_phone' => $phone,
        'address' => $address,
        'street_address' => $address,
        'city' => $city,
        'state' => $state,
        'province' => $state,
        'zip' => $zip,
        'zipcode' => $zip,
        'postal_code' => $zip,
    );

    $saved = updateProfile($pdo, $profileRow, $updates);

    if ($saved) {
        $_SESSION['name'] = $fullName;
        $_SESSION['full_name'] = $fullName;
        $_SESSION['profile_flash_type'] = 'success';
        $_SESSION['profile_flash'] = 'Your profile was updated successfully.';
    } else {
        $_SESSION['profile_flash_type'] = 'error';
        $_SESSION['profile_flash'] = 'We could not update your profile right now.';
    }

    redirectTo('profile.php');
}

$displayName = $profile['full_name'] !== '' ? $profile['full_name'] : 'Member';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile | Doggie Dorian’s</title>
    <meta name="description" content="Manage your Doggie Dorian’s member profile.">
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #09090d;
            color: #f4f1ea;
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        a { color: inherit; text-decoration: none; }

        .page {
            max-width: 1220px;
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
            font-size: 1.5rem;
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
            grid-template-columns: 1.05fr 0.95fr;
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

        .flash {
            margin-bottom: 18px;
            padding: 14px 18px;
            border-radius: 16px;
            font-weight: 700;
        }

        .flash-success {
            background: rgba(125,206,141,0.14);
            border: 1px solid rgba(125,206,141,0.30);
            color: #d7f1dd;
        }

        .flash-error {
            background: rgba(214,123,123,0.14);
            border: 1px solid rgba(214,123,123,0.30);
            color: #ffd5d5;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
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
            font-size: 1.2rem;
            font-weight: 900;
        }

        .cta-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 12px 18px;
            border-radius: 14px;
            font-size: .94rem;
            font-weight: 800;
            transition: transform .15s ease;
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
            border: 1px solid rgba(255,255,255,0.12);
            color: #fff;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        form {
            display: grid;
            gap: 16px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: rgba(244,241,234,0.58);
            font-weight: 800;
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

        .detail-list {
            display: grid;
            gap: 12px;
            margin-top: 14px;
        }

        .detail-item {
            padding: 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .detail-label {
            color: rgba(244,241,234,0.56);
            text-transform: uppercase;
            letter-spacing: .12em;
            font-size: .73rem;
            font-weight: 800;
            margin-bottom: 6px;
        }

        .detail-value {
            font-size: .97rem;
            font-weight: 700;
            line-height: 1.5;
        }

        @media (max-width: 900px) {
            .hero,
            .form-grid,
            .stats {
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

            .cta-row {
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
            <div class="brand">Doggie Dorian’s</div>

            <div class="top-links">
                <a class="top-link" href="dashboard.php">Dashboard</a>
                <a class="top-link" href="book-service.php">Book Service</a>
                <a class="top-link" href="my-bookings.php">My Bookings</a>
                <a class="top-link" href="ambassadors.php">Ambassadors</a>
                <a class="top-link" href="notifications.php">Notifications<?php echo $unreadNotifications > 0 ? ' (' . (int) $unreadNotifications . ')' : ''; ?></a>
                <a class="top-link" href="logout.php">Logout</a>
            </div>
        </div>

        <?php if ($flash !== ''): ?>
            <div class="flash <?php echo $flashType === 'success' ? 'flash-success' : 'flash-error'; ?>">
                <?php echo h($flash); ?>
            </div>
        <?php endif; ?>

        <section class="hero">
            <div class="card hero-primary">
                <div class="eyebrow">Member Profile</div>
                <h1><?php echo h($displayName); ?></h1>
                <div class="sub">
                    Keep your member details current so bookings, updates, and care coordination stay smooth across your account.
                </div>

                <div class="stats">
                    <div class="stat">
                        <div class="stat-label">Membership</div>
                        <div class="stat-value"><?php echo h($profile['membership_type'] !== '' ? $profile['membership_type'] : 'Active'); ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Preferred Login</div>
                        <div class="stat-value"><?php echo h($profile['preferred_login'] !== '' ? $profile['preferred_login'] : 'Standard'); ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Notifications</div>
                        <div class="stat-value"><?php echo (int) $unreadNotifications; ?> unread</div>
                    </div>
                </div>

                <div class="cta-row">
                    <a class="btn btn-gold" href="book-service.php">Book Service</a>
                    <a class="btn btn-light" href="my-bookings.php">View My Bookings</a>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Account Snapshot</div>
                <h2>Current account details</h2>

                <div class="detail-list">
                    <div class="detail-item">
                        <div class="detail-label">Email</div>
                        <div class="detail-value"><?php echo h($profile['email'] !== '' ? $profile['email'] : '—'); ?></div>
                    </div>

                    <div class="detail-item">
                        <div class="detail-label">Phone</div>
                        <div class="detail-value"><?php echo h($profile['phone'] !== '' ? $profile['phone'] : '—'); ?></div>
                    </div>

                    <div class="detail-item">
                        <div class="detail-label">Address</div>
                        <div class="detail-value">
                            <?php
                            $fullAddress = trim($profile['address'] . ' ' . $profile['city'] . ' ' . $profile['state'] . ' ' . $profile['zip']);
                            echo h($fullAddress !== '' ? $fullAddress : '—');
                            ?>
                        </div>
                    </div>

                    <div class="detail-item">
                        <div class="detail-label">Username</div>
                        <div class="detail-value"><?php echo h($profile['username'] !== '' ? $profile['username'] : '—'); ?></div>
                    </div>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="eyebrow">Update Profile</div>
            <h2>Edit your information</h2>
            <div class="sub" style="margin-bottom:18px;">
                Update the basics you use most. Your email and username are shown here for visibility and may remain account-controlled depending on your setup.
            </div>

            <form method="post" action="profile.php" novalidate>
                <div class="form-grid">
                    <div>
                        <label for="full_name">Full Name</label>
                        <input type="text" id="full_name" name="full_name" value="<?php echo h($profile['full_name']); ?>" required>
                    </div>

                    <div>
                        <label for="phone">Phone</label>
                        <input type="text" id="phone" name="phone" value="<?php echo h($profile['phone']); ?>">
                    </div>
                </div>

                <div class="form-grid">
                    <div>
                        <label for="address">Street Address</label>
                        <input type="text" id="address" name="address" value="<?php echo h($profile['address']); ?>">
                    </div>

                    <div>
                        <label for="city">City</label>
                        <input type="text" id="city" name="city" value="<?php echo h($profile['city']); ?>">
                    </div>
                </div>

                <div class="form-grid">
                    <div>
                        <label for="state">State</label>
                        <input type="text" id="state" name="state" value="<?php echo h($profile['state']); ?>">
                    </div>

                    <div>
                        <label for="zip">ZIP Code</label>
                        <input type="text" id="zip" name="zip" value="<?php echo h($profile['zip']); ?>">
                    </div>
                </div>

                <div class="cta-row">
                    <button type="submit" class="btn btn-gold" style="border:none; cursor:pointer;">Save Profile</button>
                    <a class="btn btn-light" href="dashboard.php">Back to Dashboard</a>
                </div>
            </form>
        </section>
    </div>
</body>
</html>