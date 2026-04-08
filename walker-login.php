<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';

/*
|--------------------------------------------------------------------------
| Walker Login
|--------------------------------------------------------------------------
| PURPOSE
| - Dedicated login for walker/staff/employee portal
| - Keeps login.php member-only
| - Blocks admin/member roles from walker portal access
|
| DEFAULT ASSUMPTION
| - Worker accounts live in users table
| - Passwords are stored hashed in password column
|--------------------------------------------------------------------------
*/

/* ==========================================================================
   CONFIG
   ========================================================================== */

$USERS_TABLE = 'users';
$USER_ID_COL = 'id';

$allowedWorkerRoles = ['walker', 'staff', 'employee'];
$allowedEnabledStatuses = ['active', 'approved', 'enabled', 'available'];

$possibleNameCols = ['name', 'full_name', 'display_name'];
$possibleEmailCols = ['email'];
$possibleUsernameCols = ['username'];
$possiblePasswordCols = ['password'];
$possibleRoleCols = ['role'];
$possibleStatusCols = ['status'];

/* ==========================================================================
   REDIRECT IF ALREADY LOGGED IN
   ========================================================================== */

if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    $currentRole = strtolower(trim((string)($_SESSION['role'] ?? '')));

    if (in_array($currentRole, $allowedWorkerRoles, true)) {
        header('Location: walker-dashboard.php');
        exit;
    }

    if ($currentRole === 'admin') {
        header('Location: admin.php');
        exit;
    }

    header('Location: dashboard.php');
    exit;
}

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

function isEnabledStatus(string $status, array $allowed): bool
{
    $status = strtolower(trim($status));
    if ($status === '') {
        return true;
    }
    return in_array($status, $allowed, true);
}

/* ==========================================================================
   FLASH + FORM STATE
   ========================================================================== */

$flashType = $_SESSION['walker_flash_type'] ?? '';
$flashMessage = $_SESSION['walker_flash_message'] ?? '';
unset($_SESSION['walker_flash_type'], $_SESSION['walker_flash_message']);

$error = '';
$identifier = '';

/* ==========================================================================
   SCHEMA DETECTION
   ========================================================================== */

$schema = [
    'name_col' => null,
    'email_col' => null,
    'username_col' => null,
    'password_col' => null,
    'role_col' => null,
    'status_col' => null,
];

if (!tableExists($pdo, $USERS_TABLE)) {
    $error = "The users table was not found. Update \$USERS_TABLE in walker-login.php if needed.";
} else {
    try {
        $columns = getTableColumns($pdo, $USERS_TABLE);

        if (!in_array($USER_ID_COL, $columns, true)) {
            $error = 'User ID column not found in users table.';
        } else {
            $schema['name_col'] = firstExistingColumn($possibleNameCols, $columns);
            $schema['email_col'] = firstExistingColumn($possibleEmailCols, $columns);
            $schema['username_col'] = firstExistingColumn($possibleUsernameCols, $columns);
            $schema['password_col'] = firstExistingColumn($possiblePasswordCols, $columns);
            $schema['role_col'] = firstExistingColumn($possibleRoleCols, $columns);
            $schema['status_col'] = firstExistingColumn($possibleStatusCols, $columns);

            if ($schema['email_col'] === null && $schema['username_col'] === null) {
                $error = 'No email or username column was found in users table.';
            } elseif ($schema['password_col'] === null) {
                $error = 'Password column not found in users table.';
            } elseif ($schema['role_col'] === null) {
                $error = 'Role column not found in users table.';
            }
        }
    } catch (Throwable $e) {
        $error = 'Schema error: ' . $e->getMessage();
    }
}

/* ==========================================================================
   LOGIN HANDLER
   ========================================================================== */

if ($error === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim((string)($_POST['identifier'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($identifier === '' || $password === '') {
        $error = 'Please enter your login details.';
    } else {
        try {
            $selectParts = [
                $USER_ID_COL . ' AS user_id',
                $schema['password_col'] . ' AS password_hash',
                $schema['role_col'] . ' AS role_value'
            ];

            $selectParts[] = $schema['name_col'] ? $schema['name_col'] . ' AS name_value' : "'' AS name_value";
            $selectParts[] = $schema['email_col'] ? $schema['email_col'] . ' AS email_value' : "'' AS email_value";
            $selectParts[] = $schema['status_col'] ? $schema['status_col'] . ' AS status_value' : "'' AS status_value";
            if ($schema['username_col']) {
                $selectParts[] = $schema['username_col'] . ' AS username_value';
            } else {
                $selectParts[] = "'' AS username_value";
            }

            $whereParts = [];
            $params = [];

            if ($schema['email_col'] !== null) {
                $whereParts[] = $schema['email_col'] . ' = :identifier_email';
                $params[':identifier_email'] = $identifier;
            }

            if ($schema['username_col'] !== null) {
                $whereParts[] = $schema['username_col'] . ' = :identifier_username';
                $params[':identifier_username'] = $identifier;
            }

            $sql = "
                SELECT
                    " . implode(",\n                    ", $selectParts) . "
                FROM $USERS_TABLE
                WHERE (" . implode(' OR ', $whereParts) . ")
                LIMIT 1
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $error = 'Invalid login credentials.';
            } else {
                $storedRole = strtolower(trim((string)($user['role_value'] ?? '')));
                $storedStatus = strtolower(trim((string)($user['status_value'] ?? '')));

                if (!password_verify($password, (string)($user['password_hash'] ?? ''))) {
                    $error = 'Invalid login credentials.';
                } elseif (!in_array($storedRole, $allowedWorkerRoles, true)) {
                    $error = 'This account does not have worker portal access.';
                } elseif (!isEnabledStatus($storedStatus, $allowedEnabledStatuses)) {
                    $error = 'This worker account is not currently active.';
                } else {
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = (int)($user['user_id'] ?? 0);
                    $_SESSION['role'] = $storedRole;
                    $_SESSION['name'] = (string)($user['name_value'] ?? '');
                    $_SESSION['email'] = (string)($user['email_value'] ?? '');
                    $_SESSION['username'] = (string)($user['username_value'] ?? '');

                    $_SESSION['walker_flash_type'] = 'success';
                    $_SESSION['walker_flash_message'] = 'Welcome back.';

                    header('Location: walker-dashboard.php');
                    exit;
                }
            }
        } catch (Throwable $e) {
            $error = 'Login error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Walker Login | Doggie Dorian’s</title>
    <meta name="description" content="Secure worker login for Doggie Dorian’s walker portal.">
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
            --blue: #8fc5ff;
            --green: #8ae3b0;
            --red: #ffb0b0;
            --shadow: 0 30px 80px rgba(0,0,0,0.42);
            --radius-xl: 30px;
            --radius-lg: 22px;
            --radius-md: 16px;
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

        a {
            color: inherit;
            text-decoration: none;
        }

        button {
            font: inherit;
        }

        .page {
            width: min(1180px, calc(100% - 32px));
            min-height: 100vh;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1.02fr .98fr;
            gap: 22px;
            align-items: center;
            padding: 28px 0;
        }

        .brand-panel,
        .login-panel {
            background: var(--card);
            border: 1px solid var(--border);
            backdrop-filter: blur(16px);
            box-shadow: var(--shadow);
            border-radius: var(--radius-xl);
        }

        .brand-panel {
            padding: 34px;
            min-height: 700px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            background:
                radial-gradient(circle at top left, rgba(217,180,107,0.10), transparent 26%),
                linear-gradient(180deg, rgba(255,255,255,0.04), rgba(255,255,255,0.02));
        }

        .eyebrow {
            display: inline-block;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.18em;
            color: var(--gold);
            margin-bottom: 10px;
        }

        .headline {
            margin: 0;
            font-size: clamp(34px, 5vw, 58px);
            line-height: 0.98;
            letter-spacing: -0.05em;
            max-width: 580px;
        }

        .subheadline {
            margin: 18px 0 0;
            font-size: 16px;
            line-height: 1.75;
            color: var(--muted);
            max-width: 560px;
        }

        .feature-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-top: 26px;
        }

        .feature {
            background: var(--card-strong);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 20px;
            padding: 18px;
        }

        .feature-title {
            margin: 0 0 8px;
            font-size: 16px;
            letter-spacing: -0.02em;
        }

        .feature-copy {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.65;
        }

        .brand-footer {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            margin-top: 28px;
            color: var(--muted);
            font-size: 13px;
        }

        .login-panel {
            padding: 34px;
        }

        .panel-top {
            margin-bottom: 22px;
        }

        .panel-title {
            margin: 0;
            font-size: 34px;
            letter-spacing: -0.04em;
        }

        .panel-copy {
            margin: 12px 0 0;
            color: var(--muted);
            font-size: 15px;
            line-height: 1.7;
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

        form {
            display: grid;
            gap: 16px;
        }

        .field {
            display: grid;
            gap: 8px;
        }

        label {
            font-size: 14px;
            font-weight: 700;
            color: var(--text);
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.06);
            color: var(--text);
            border-radius: 16px;
            padding: 15px 16px;
            font-size: 15px;
            outline: none;
            transition: border-color .16s ease, background .16s ease, transform .16s ease;
        }

        input[type="text"]:focus,
        input[type="password"]:focus {
            border-color: rgba(217,180,107,0.65);
            background: rgba(255,255,255,0.08);
            transform: translateY(-1px);
        }

        .password-wrap {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            min-height: 36px;
            padding: 0 12px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.05);
            color: var(--text);
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
        }

        .helper {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
        }

        .submit-btn {
            min-height: 54px;
            border: none;
            border-radius: 999px;
            background: linear-gradient(135deg, var(--gold), var(--gold-strong));
            color: #17130e;
            font-size: 15px;
            font-weight: 800;
            letter-spacing: 0.01em;
            cursor: pointer;
            box-shadow: 0 16px 34px rgba(191,143,55,0.28);
            transition: transform .16s ease, box-shadow .16s ease;
        }

        .submit-btn:hover {
            transform: translateY(-1px);
        }

        .login-links {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 10px;
            font-size: 14px;
            color: var(--muted);
        }

        .login-links a {
            color: var(--text);
        }

        .login-links a:hover {
            color: var(--gold);
        }

        .access-note {
            margin-top: 18px;
            padding: 14px 16px;
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.04);
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
        }

        @media (max-width: 980px) {
            .page {
                grid-template-columns: 1fr;
            }

            .brand-panel {
                min-height: auto;
            }
        }

        @media (max-width: 760px) {
            .page {
                width: min(100% - 18px, 1180px);
                padding: 18px 0;
                gap: 16px;
            }

            .brand-panel,
            .login-panel {
                padding: 22px;
            }

            .feature-grid {
                grid-template-columns: 1fr;
            }

            .panel-title {
                font-size: 28px;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <section class="brand-panel">
            <div>
                <div class="eyebrow">Doggie Dorian’s Worker Portal</div>
                <h1 class="headline">Premium field access for your walker team.</h1>
                <p class="subheadline">
                    Secure login for workers handling live jobs, assignments, tracking, schedules, and service updates across the Doggie Dorian’s platform.
                </p>

                <div class="feature-grid">
                    <div class="feature">
                        <h3 class="feature-title">Assigned Jobs</h3>
                        <p class="feature-copy">
                            Review current workload, open assignments, and service details without exposing admin controls.
                        </p>
                    </div>

                    <div class="feature">
                        <h3 class="feature-title">Live Tracking</h3>
                        <p class="feature-copy">
                            Start active services, track timing, and complete work through a worker-safe flow.
                        </p>
                    </div>

                    <div class="feature">
                        <h3 class="feature-title">Worker Notifications</h3>
                        <p class="feature-copy">
                            Stay updated on assignments, opportunities, and active service alerts from one portal.
                        </p>
                    </div>

                    <div class="feature">
                        <h3 class="feature-title">Profile Access</h3>
                        <p class="feature-copy">
                            Keep worker contact details and availability organized for cleaner operations.
                        </p>
                    </div>
                </div>
            </div>

            <div class="brand-footer">
                <div>Worker roles supported: Walker · Staff · Employee</div>
                <div>Member and admin logins stay separate</div>
            </div>
        </section>

        <section class="login-panel">
            <div class="panel-top">
                <div class="eyebrow">Secure Access</div>
                <h2 class="panel-title">Walker Login</h2>
                <p class="panel-copy">
                    Sign in with your email or username and password to access the worker portal.
                </p>
            </div>

            <?php if ($flashMessage !== ''): ?>
                <div class="<?= $flashType === 'success' ? 'success-box' : 'error-box' ?>">
                    <?= h($flashMessage) ?>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="error-box"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post" action="walker-login.php" novalidate>
                <div class="field">
                    <label for="identifier">Email or Username</label>
                    <input
                        type="text"
                        id="identifier"
                        name="identifier"
                        value="<?= h($identifier) ?>"
                        placeholder="Enter your email or username"
                        autocomplete="username"
                        required
                    >
                    <div class="helper">
                        This worker portal accepts email and also username if your database includes a username field.
                    </div>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="password-wrap">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required
                        >
                        <button type="button" class="toggle-password" id="togglePassword">Show</button>
                    </div>
                </div>

                <button type="submit" class="submit-btn">Enter Worker Portal</button>
            </form>

            <div class="login-links">
                <a href="login.php">Member Login</a>
                <a href="admin-login.php">Admin Login</a>
            </div>

            <div class="access-note">
                Accounts without a worker role are blocked here. Admin and member accounts should use their own dedicated login pages.
            </div>
        </section>
    </div>

    <script>
        (function () {
            const passwordInput = document.getElementById('password');
            const toggleBtn = document.getElementById('togglePassword');

            if (!passwordInput || !toggleBtn) return;

            toggleBtn.addEventListener('click', function () {
                const isHidden = passwordInput.type === 'password';
                passwordInput.type = isHidden ? 'text' : 'password';
                toggleBtn.textContent = isHidden ? 'Hide' : 'Show';
            });
        })();
    </script>
</body>
</html>