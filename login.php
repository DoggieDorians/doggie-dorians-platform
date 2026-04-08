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

    return '';
}

function currentUserId()
{
    foreach (array('user_id', 'member_id', 'client_id', 'id') as $key) {
        if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) {
            return (int) $_SESSION[$key];
        }
    }
    return 0;
}

if (currentUserId() > 0 && currentUserRole() === 'member') {
    redirectTo('dashboard.php');
}

function verifyPasswordAgainstRow(array $row, $password)
{
    foreach (array('password', 'password_hash', 'hashed_password', 'pass_hash') as $field) {
        if (isset($row[$field]) && trim((string) $row[$field]) !== '') {
            $hash = (string) $row[$field];

            if (password_verify($password, $hash)) {
                return true;
            }

            if ($password === $hash) {
                return true;
            }
        }
    }

    return false;
}

function findMemberByLogin(PDO $pdo, $login)
{
    $login = trim((string) $login);
    if ($login === '') {
        return null;
    }

    $tables = array('users', 'members', 'client_profiles');

    foreach ($tables as $table) {
        if (!hasTable($pdo, $table)) {
            continue;
        }

        $idCol = firstExistingColumn($pdo, $table, array('id', 'user_id', 'member_id', 'client_id'));
        if ($idCol === null) {
            continue;
        }

        $loginFields = array();
        foreach (array('email', 'username', 'phone', 'phone_number') as $candidate) {
            if (in_array($candidate, getTableColumns($pdo, $table), true)) {
                $loginFields[] = $candidate;
            }
        }

        if (empty($loginFields)) {
            continue;
        }

        $where = array();
        $params = array(':login' => $login);

        foreach ($loginFields as $field) {
            $where[] = 'LOWER(COALESCE(' . $field . ', "")) = LOWER(:login)';
        }

        $roleCol = firstExistingColumn($pdo, $table, array('role', 'user_role', 'account_type'));
        $sql = 'SELECT * FROM ' . $table . ' WHERE (' . implode(' OR ', $where) . ')';

        if ($roleCol !== null) {
            $sql .= ' AND (LOWER(COALESCE(' . $roleCol . ', "member")) NOT IN ("admin","walker","staff","employee"))';
        }

        $sql .= ' LIMIT 1';

        $stmt = $pdo->prepare($sql);
        if (!safeExecute($stmt, $params)) {
            continue;
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            $row['_table'] = $table;
            $row['_id_col'] = $idCol;
            return $row;
        }
    }

    return null;
}

function valueFromRow(array $row, array $candidates, $default = '')
{
    foreach ($candidates as $candidate) {
        if (isset($row[$candidate]) && trim((string) $row[$candidate]) !== '') {
            return (string) $row[$candidate];
        }
    }

    return $default;
}

$error = '';
$formLogin = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formLogin = trim((string) (isset($_POST['login']) ? $_POST['login'] : ''));
    $password = (string) (isset($_POST['password']) ? $_POST['password'] : '');

    if ($formLogin === '' || $password === '') {
        $error = 'Please enter your email, username, or phone and your password.';
    } else {
        $user = findMemberByLogin($pdo, $formLogin);

        if ($user === null || !verifyPasswordAgainstRow($user, $password)) {
            $error = 'Invalid login credentials.';
        } else {
            session_regenerate_id(true);

            $idCol = isset($user['_id_col']) ? (string) $user['_id_col'] : 'id';
            $userId = isset($user[$idCol]) ? (int) $user[$idCol] : 0;

            $_SESSION['user_id'] = $userId;
            $_SESSION['member_id'] = $userId;
            $_SESSION['id'] = $userId;
            $_SESSION['role'] = 'member';
            $_SESSION['is_admin'] = false;
            $_SESSION['name'] = valueFromRow($user, array('full_name', 'name', 'client_name', 'member_name', 'username'), 'Member');
            $_SESSION['full_name'] = valueFromRow($user, array('full_name', 'name', 'client_name', 'member_name'), $_SESSION['name']);
            $_SESSION['email'] = valueFromRow($user, array('email'), '');
            $_SESSION['username'] = valueFromRow($user, array('username'), '');

            $_SESSION['dashboard_flash'] = 'Welcome back.';
            redirectTo('dashboard.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Login | Doggie Dorian’s</title>
    <meta name="description" content="Member login for Doggie Dorian’s.">
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #09090d;
            color: #f4f1ea;
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            min-height: 100vh;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .page {
            max-width: 1280px;
            margin: 0 auto;
            padding: 28px 18px 80px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 24px;
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

        .top-link-signup {
            background: linear-gradient(135deg, #e2c48d, #b9975b);
            color: #0b0b10;
            border: 1px solid rgba(255,255,255,0.14);
        }

        .shell {
            display: grid;
            grid-template-columns: 1.02fr 0.98fr;
            gap: 22px;
        }

        .card {
            background: linear-gradient(180deg, rgba(255,255,255,0.065), rgba(255,255,255,0.03));
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 28px;
            padding: 30px;
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
            margin: 0 0 12px;
            font-size: clamp(2.2rem, 5vw, 4rem);
            line-height: 1.04;
        }

        h2 {
            margin: 0 0 12px;
            font-size: 1.4rem;
        }

        p {
            color: rgba(244,241,234,0.78);
            line-height: 1.7;
            font-size: 1rem;
            margin: 0 0 14px;
        }

        .feature-grid {
            display: grid;
            gap: 14px;
            margin-top: 24px;
        }

        .feature {
            padding: 16px 18px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
        }

        .feature strong {
            display: block;
            margin-bottom: 6px;
            color: #fff;
        }

        .message {
            border-radius: 16px;
            padding: 14px 16px;
            margin-bottom: 16px;
            line-height: 1.55;
        }

        .message-error {
            background: rgba(214,123,123,0.14);
            border: 1px solid rgba(214,123,123,0.30);
            color: #ffd5d5;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 14px;
        }

        label {
            font-size: .94rem;
            font-weight: 700;
            color: #fff;
        }

        input {
            width: 100%;
            border-radius: 16px;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(0,0,0,0.26);
            color: #fff;
            padding: 14px 15px;
            font-size: .96rem;
            outline: none;
        }

        input:focus {
            border-color: rgba(215,178,106,0.55);
            background: rgba(255,255,255,0.06);
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 48px;
            padding: 13px 18px;
            border-radius: 14px;
            font-size: .95rem;
            font-weight: 800;
            border: none;
            cursor: pointer;
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

        .helper {
            margin-top: 16px;
            color: rgba(244,241,234,0.62);
            font-size: .92rem;
            line-height: 1.6;
        }

        @media (max-width: 900px) {
            .shell {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 700px) {
            .topbar {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="topbar">
            <a href="index.php" class="brand">Doggie Dorian’s</a>

            <div class="top-links">
                <a href="index.php" class="top-link">Home</a>
                <a href="services.php" class="top-link">Services</a>
                <a href="memberships.php" class="top-link">Memberships</a>
                <a href="contact.php" class="top-link">Contact</a>
                <a href="login.php" class="top-link">Login</a>
                <a href="signup.php" class="top-link top-link-signup">Sign Up</a>
            </div>
        </div>

        <div class="shell">
            <section class="card hero-card">
                <div class="eyebrow">Member Access</div>
                <h1>Welcome back to Doggie Dorian’s.</h1>
                <p>
                    Log in to access your dashboard, manage bookings, update account details, and continue into memberships after your account is active.
                </p>

                <div class="feature-grid">
                    <div class="feature">
                        <strong>Your account comes first</strong>
                        Sign in to manage your profile, pets, and recurring care from one place.
                    </div>
                    <div class="feature">
                        <strong>Memberships come after login</strong>
                        Once inside, you can review founder access, regular memberships, and custom plan options.
                    </div>
                    <div class="feature">
                        <strong>Premium booking visibility</strong>
                        Keep your services, notifications, and future booking actions connected to one member profile.
                    </div>
                </div>
            </section>

            <section class="card">
                <div class="eyebrow">Login</div>
                <h2>Member sign in</h2>

                <?php if ($error !== ''): ?>
                    <div class="message message-error"><?php echo h($error); ?></div>
                <?php endif; ?>

                <form method="post" action="login.php" novalidate>
                    <div class="field">
                        <label for="login">Email, Username, or Phone</label>
                        <input
                            type="text"
                            id="login"
                            name="login"
                            value="<?php echo h($formLogin); ?>"
                            autocomplete="username"
                            required
                        >
                    </div>

                    <div class="field">
                        <label for="password">Password</label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            autocomplete="current-password"
                            required
                        >
                    </div>

                    <div class="actions">
                        <button type="submit" class="btn btn-gold">Login</button>
                        <a href="signup.php" class="btn btn-light">Create Account</a>
                    </div>

                    <div class="helper">
                        Use your member account credentials to access your dashboard and continue into memberships after login.
                    </div>
                </form>
            </section>
        </div>
    </div>
</body>
</html>