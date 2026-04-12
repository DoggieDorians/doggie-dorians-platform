<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
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

function isMemberLike()
{
    return currentUserRole() === 'member' || !empty($_SESSION['user_id']) || !empty($_SESSION['member_id']) || !empty($_SESSION['id']);
}

if (!isMemberLike()) {
    redirectTo('login.php');
}

function sessionInt(array $keys): int
{
    foreach ($keys as $key) {
        if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) {
            return (int) $_SESSION[$key];
        }
    }

    return 0;
}

function safeExecute(PDOStatement $stmt, array $params = array()): bool
{
    try {
        return $stmt->execute($params);
    } catch (Throwable $e) {
        return false;
    } catch (Exception $e) {
        return false;
    }
}

function fetchOne(PDO $pdo, string $sql, array $params = array()): ?array
{
    $stmt = $pdo->prepare($sql);
    if (!safeExecute($stmt, $params)) {
        return null;
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function countUnreadNotificationsForUser(PDO $pdo, int $userId): int
{
    $tables = array('notifications', 'user_notifications', 'alerts');

    foreach ($tables as $table) {
        try {
            $check = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name = " . $pdo->quote($table) . " LIMIT 1");
            if (!$check || !$check->fetchColumn()) {
                continue;
            }

            $columnsStmt = $pdo->query('PRAGMA table_info("' . str_replace('"', '""', $table) . '")');
            if (!$columnsStmt) {
                continue;
            }

            $columns = array();
            foreach ($columnsStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
                if (isset($column['name'])) {
                    $columns[] = (string) $column['name'];
                }
            }

            $readCol = null;
            foreach (array('is_read', 'read_status', 'seen', 'viewed') as $candidate) {
                if (in_array($candidate, $columns, true)) {
                    $readCol = $candidate;
                    break;
                }
            }

            if ($readCol === null) {
                continue;
            }

            foreach (array('user_id', 'member_id') as $ownerCol) {
                if (!in_array($ownerCol, $columns, true)) {
                    continue;
                }

                $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$ownerCol} = :id AND COALESCE({$readCol}, 0) = 0");
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

function resolveUserRow(PDO $pdo): ?array
{
    $userIdCandidates = array_filter(array_unique(array(
        sessionInt(array('user_id')),
        sessionInt(array('id')),
        sessionInt(array('client_id')),
        sessionInt(array('member_id')),
    )));

    foreach ($userIdCandidates as $candidate) {
        $row = fetchOne($pdo, 'SELECT * FROM users WHERE id = :id LIMIT 1', array(':id' => $candidate));
        if ($row !== null) {
            return $row;
        }
    }

    $memberIdCandidates = array_filter(array_unique(array(
        sessionInt(array('member_id')),
        sessionInt(array('id')),
        sessionInt(array('user_id')),
    )));

    foreach ($memberIdCandidates as $candidate) {
        $memberRow = fetchOne($pdo, 'SELECT * FROM members WHERE id = :id LIMIT 1', array(':id' => $candidate));
        if ($memberRow !== null && !empty($memberRow['email'])) {
            $userRow = fetchOne($pdo, 'SELECT * FROM users WHERE lower(email) = lower(:email) LIMIT 1', array(':email' => (string) $memberRow['email']));
            if ($userRow !== null) {
                return $userRow;
            }
        }
    }

    return null;
}

function resolveMemberRow(PDO $pdo, ?array $userRow): ?array
{
    $memberIdCandidates = array_filter(array_unique(array(
        sessionInt(array('member_id')),
        sessionInt(array('id')),
        sessionInt(array('user_id')),
    )));

    foreach ($memberIdCandidates as $candidate) {
        $row = fetchOne($pdo, 'SELECT * FROM members WHERE id = :id LIMIT 1', array(':id' => $candidate));
        if ($row !== null) {
            return $row;
        }
    }

    if ($userRow !== null && !empty($userRow['email'])) {
        $row = fetchOne($pdo, 'SELECT * FROM members WHERE lower(email) = lower(:email) LIMIT 1', array(':email' => (string) $userRow['email']));
        if ($row !== null) {
            return $row;
        }
    }

    return null;
}

function resolveClientProfileRow(PDO $pdo, int $userId, ?array $userRow, ?array $memberRow): ?array
{
    if ($userId > 0) {
        $row = fetchOne($pdo, 'SELECT * FROM client_profiles WHERE user_id = :user_id LIMIT 1', array(':user_id' => $userId));
        if ($row !== null) {
            return $row;
        }
    }

    $fallbackIds = array();
    if ($memberRow !== null && isset($memberRow['id']) && is_numeric($memberRow['id'])) {
        $fallbackIds[] = (int) $memberRow['id'];
    }
    $fallbackIds[] = sessionInt(array('user_id'));
    $fallbackIds[] = sessionInt(array('id'));
    $fallbackIds[] = sessionInt(array('member_id'));

    foreach (array_filter(array_unique($fallbackIds)) as $candidate) {
        $row = fetchOne($pdo, 'SELECT * FROM client_profiles WHERE user_id = :user_id LIMIT 1', array(':user_id' => $candidate));
        if ($row !== null) {
            return $row;
        }
    }

    if ($userRow !== null && isset($userRow['id']) && is_numeric($userRow['id'])) {
        return fetchOne($pdo, 'SELECT * FROM client_profiles WHERE user_id = :user_id LIMIT 1', array(':user_id' => (int) $userRow['id']));
    }

    return null;
}

function resolveMembershipType(PDO $pdo, int $userId): string
{
    if ($userId <= 0) {
        return 'Active';
    }

    try {
        $columnsStmt = $pdo->query('PRAGMA table_info("member_memberships")');
        if (!$columnsStmt) {
            return 'Active';
        }

        $columns = array();
        foreach ($columnsStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            if (isset($column['name'])) {
                $columns[] = (string) $column['name'];
            }
        }

        if (empty($columns)) {
            return 'Active';
        }

        $userColumn = null;
        foreach (array('user_id', 'member_id', 'client_id') as $candidate) {
            if (in_array($candidate, $columns, true)) {
                $userColumn = $candidate;
                break;
            }
        }

        if ($userColumn === null) {
            return 'Active';
        }

        $nameColumn = null;
        foreach (array('membership_name', 'plan_name', 'membership_type', 'membership', 'plan_type', 'plan_slug', 'membership_slug') as $candidate) {
            if (in_array($candidate, $columns, true)) {
                $nameColumn = $candidate;
                break;
            }
        }

        if ($nameColumn !== null) {
            $stmt = $pdo->prepare("SELECT {$nameColumn} AS membership_name FROM member_memberships WHERE {$userColumn} = :id ORDER BY id DESC LIMIT 1");
            if (safeExecute($stmt, array(':id' => $userId))) {
                $value = $stmt->fetchColumn();
                if ($value !== false && trim((string) $value) !== '') {
                    return (string) $value;
                }
            }
        }
    } catch (Throwable $e) {
        return 'Active';
    } catch (Exception $e) {
        return 'Active';
    }

    return 'Active';
}

function buildProfileData(?array $userRow, ?array $memberRow, ?array $clientProfileRow, string $membershipType): array
{
    $addressLine1 = $clientProfileRow !== null ? trim((string) ($clientProfileRow['address_line1'] ?? '')) : '';
    $addressLine2 = $clientProfileRow !== null ? trim((string) ($clientProfileRow['address_line2'] ?? '')) : '';

    return array(
        'full_name' => trim((string) (($userRow['full_name'] ?? '') !== '' ? $userRow['full_name'] : '')),
        'email' => trim((string) (($userRow['email'] ?? '') !== '' ? $userRow['email'] : ($memberRow['email'] ?? ''))),
        'phone' => trim((string) (($userRow['phone'] ?? '') !== '' ? $userRow['phone'] : ($memberRow['phone'] ?? ''))),
        'username' => trim((string) ($memberRow['username'] ?? '')),
        'address' => $addressLine1,
        'address_line2' => $addressLine2,
        'city' => trim((string) ($clientProfileRow['city'] ?? '')),
        'state' => trim((string) ($clientProfileRow['state'] ?? '')),
        'zip' => trim((string) ($clientProfileRow['zip_code'] ?? '')),
        'membership_type' => $membershipType !== '' ? $membershipType : 'Active',
        'preferred_login' => trim((string) ($memberRow['preferred_login'] ?? '')),
    );
}

function saveProfile(PDO $pdo, int $userId, ?array $memberRow, string $fullName, string $phone, string $address, string $addressLine2, string $city, string $state, string $zip): array
{
    if ($userId <= 0) {
        return array('ok' => false, 'message' => 'Your user account could not be resolved.');
    }

    try {
        $pdo->beginTransaction();

        $userStmt = $pdo->prepare('UPDATE users SET full_name = :full_name, phone = :phone, updated_at = :updated_at WHERE id = :id');
        if (!safeExecute($userStmt, array(
            ':full_name' => $fullName,
            ':phone' => $phone,
            ':updated_at' => date('Y-m-d H:i:s'),
            ':id' => $userId,
        ))) {
            $pdo->rollBack();
            return array('ok' => false, 'message' => 'The users record could not be updated.');
        }

        if ($memberRow !== null && isset($memberRow['id']) && is_numeric($memberRow['id'])) {
            $memberStmt = $pdo->prepare('UPDATE members SET phone = :phone WHERE id = :id');
            if (!safeExecute($memberStmt, array(
                ':phone' => $phone,
                ':id' => (int) $memberRow['id'],
            ))) {
                $pdo->rollBack();
                return array('ok' => false, 'message' => 'The members record could not be updated.');
            }
        }

        $existingClientProfile = fetchOne($pdo, 'SELECT * FROM client_profiles WHERE user_id = :user_id LIMIT 1', array(':user_id' => $userId));

        if ($existingClientProfile !== null) {
            $clientStmt = $pdo->prepare(
                'UPDATE client_profiles
                 SET address_line1 = :address_line1,
                     address_line2 = :address_line2,
                     city = :city,
                     state = :state,
                     zip_code = :zip_code,
                     updated_at = :updated_at
                 WHERE user_id = :user_id'
            );

            if (!safeExecute($clientStmt, array(
                ':address_line1' => $address,
                ':address_line2' => $addressLine2,
                ':city' => $city,
                ':state' => $state,
                ':zip_code' => $zip,
                ':updated_at' => date('Y-m-d H:i:s'),
                ':user_id' => $userId,
            ))) {
                $pdo->rollBack();
                return array('ok' => false, 'message' => 'The client profile could not be updated.');
            }
        } else {
            $clientStmt = $pdo->prepare(
                'INSERT INTO client_profiles (user_id, address_line1, address_line2, city, state, zip_code, created_at, updated_at)
                 VALUES (:user_id, :address_line1, :address_line2, :city, :state, :zip_code, :created_at, :updated_at)'
            );

            $now = date('Y-m-d H:i:s');
            if (!safeExecute($clientStmt, array(
                ':user_id' => $userId,
                ':address_line1' => $address,
                ':address_line2' => $addressLine2,
                ':city' => $city,
                ':state' => $state,
                ':zip_code' => $zip,
                ':created_at' => $now,
                ':updated_at' => $now,
            ))) {
                $pdo->rollBack();
                return array('ok' => false, 'message' => 'The client profile could not be created.');
            }
        }

        $pdo->commit();

        $savedClientProfile = fetchOne($pdo, 'SELECT * FROM client_profiles WHERE user_id = :user_id LIMIT 1', array(':user_id' => $userId));
        $savedAddress = trim((string) ($savedClientProfile['address_line1'] ?? ''));
        $savedAddressLine2 = trim((string) ($savedClientProfile['address_line2'] ?? ''));
        $savedCity = trim((string) ($savedClientProfile['city'] ?? ''));
        $savedState = trim((string) ($savedClientProfile['state'] ?? ''));
        $savedZip = trim((string) ($savedClientProfile['zip_code'] ?? ''));

        if ($savedAddress !== $address || $savedAddressLine2 !== $addressLine2 || $savedCity !== $city || $savedState !== $state || $savedZip !== $zip) {
            return array('ok' => false, 'message' => 'Your profile save ran, but the address fields are not being read back from the database yet.');
        }

        return array('ok' => true, 'message' => 'Your profile was updated successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return array('ok' => false, 'message' => 'We could not update your profile right now.');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return array('ok' => false, 'message' => 'We could not update your profile right now.');
    }
}

$userRow = resolveUserRow($pdo);
$memberRow = resolveMemberRow($pdo, $userRow);
$userId = $userRow !== null && isset($userRow['id']) && is_numeric($userRow['id']) ? (int) $userRow['id'] : 0;

if ($userId <= 0) {
    http_response_code(404);
    echo 'Profile could not be loaded.';
    exit;
}

$flash = isset($_SESSION['profile_flash']) ? (string) $_SESSION['profile_flash'] : '';
$flashType = isset($_SESSION['profile_flash_type']) ? (string) $_SESSION['profile_flash_type'] : '';
unset($_SESSION['profile_flash'], $_SESSION['profile_flash_type']);

$unreadNotifications = countUnreadNotificationsForUser($pdo, $userId);
$clientProfileRow = resolveClientProfileRow($pdo, $userId, $userRow, $memberRow);
$membershipType = resolveMembershipType($pdo, $userId);
$profile = buildProfileData($userRow, $memberRow, $clientProfileRow, $membershipType);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim((string) (isset($_POST['full_name']) ? $_POST['full_name'] : ''));
    $phone = trim((string) (isset($_POST['phone']) ? $_POST['phone'] : ''));
    $address = trim((string) (isset($_POST['address']) ? $_POST['address'] : ''));
    $addressLine2 = trim((string) (isset($_POST['address_line2']) ? $_POST['address_line2'] : ''));
    $city = trim((string) (isset($_POST['city']) ? $_POST['city'] : ''));
    $state = trim((string) (isset($_POST['state']) ? $_POST['state'] : ''));
    $zip = trim((string) (isset($_POST['zip']) ? $_POST['zip'] : ''));

    if ($fullName === '') {
        $_SESSION['profile_flash_type'] = 'error';
        $_SESSION['profile_flash'] = 'Full name is required.';
        redirectTo('profile.php');
    }

    $result = saveProfile($pdo, $userId, $memberRow, $fullName, $phone, $address, $addressLine2, $city, $state, $zip);

    $_SESSION['name'] = $fullName;
    $_SESSION['full_name'] = $fullName;
    $_SESSION['profile_flash_type'] = $result['ok'] ? 'success' : 'error';
    $_SESSION['profile_flash'] = $result['message'];
    redirectTo('profile.php');
}

$displayName = $profile['full_name'] !== '' ? $profile['full_name'] : 'Member';
$fullAddress = trim(implode(' ', array_filter(array(
    $profile['address'],
    $profile['address_line2'],
    $profile['city'],
    $profile['state'],
    $profile['zip'],
), static function ($value) {
    return trim((string) $value) !== '';
})));
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
                        <div class="detail-value"><?php echo h($fullAddress !== '' ? $fullAddress : '—'); ?></div>
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
                        <label for="address_line2">Apartment / Unit</label>
                        <input type="text" id="address_line2" name="address_line2" value="<?php echo h($profile['address_line2']); ?>">
                    </div>
                </div>

                <div class="form-grid">
                    <div>
                        <label for="city">City</label>
                        <input type="text" id="city" name="city" value="<?php echo h($profile['city']); ?>">
                    </div>

                    <div>
                        <label for="state">State</label>
                        <input type="text" id="state" name="state" value="<?php echo h($profile['state']); ?>">
                    </div>
                </div>

                <div class="form-grid">
                    <div>
                        <label for="zip">ZIP Code</label>
                        <input type="text" id="zip" name="zip" value="<?php echo h($profile['zip']); ?>">
                    </div>

                    <div></div>
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
