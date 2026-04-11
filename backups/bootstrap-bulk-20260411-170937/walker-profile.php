<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security-headers.php';

session_start();
require_once __DIR__ . '/db.php';

/*
|--------------------------------------------------------------------------
| Walker Profile
|--------------------------------------------------------------------------
| PURPOSE
| - Worker-safe profile page
| - Lets a logged-in worker edit only their own account
| - Supports optional password change
|--------------------------------------------------------------------------
*/

/* ==========================================================================
   ACCESS CONTROL
   ========================================================================== */

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    $_SESSION['walker_flash_type'] = 'error';
    $_SESSION['walker_flash_message'] = 'Please log in to access the worker portal.';
    header('Location: walker-login.php');
    exit;
}

$allowedWorkerRoles = ['walker', 'staff', 'employee'];
$currentRole = strtolower(trim((string)($_SESSION['role'] ?? '')));

if (!in_array($currentRole, $allowedWorkerRoles, true)) {
    $_SESSION['walker_flash_type'] = 'error';
    $_SESSION['walker_flash_message'] = 'You do not have permission to access the worker profile.';
    header('Location: login.php');
    exit;
}

$workerId = (int)($_SESSION['user_id'] ?? 0);
if ($workerId <= 0) {
    $_SESSION['walker_flash_type'] = 'error';
    $_SESSION['walker_flash_message'] = 'Invalid worker session.';
    header('Location: walker-login.php');
    exit;
}

/* ==========================================================================
   FLASH
   ========================================================================== */

$flashType = $_SESSION['walker_flash_type'] ?? '';
$flashMessage = $_SESSION['walker_flash_message'] ?? '';
unset($_SESSION['walker_flash_type'], $_SESSION['walker_flash_message']);

/* ==========================================================================
   CONFIG
   ========================================================================== */

$USERS_TABLE = 'users';
$USER_ID_COL = 'id';

$allowedWorkerRoles = ['walker', 'staff', 'employee'];

$possibleNameCols = ['name', 'full_name', 'display_name'];
$possibleEmailCols = ['email'];
$possiblePhoneCols = ['phone', 'phone_number', 'mobile'];
$possiblePasswordCols = ['password'];
$possibleRoleCols = ['role'];
$possibleStatusCols = ['status'];
$possibleBioCols = ['bio', 'about_me', 'about'];
$possibleAvailabilityCols = ['availability', 'availability_notes', 'schedule_notes'];
$possibleUsernameCols = ['username'];

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

function niceRole(?string $role): string
{
    $role = trim((string)$role);
    if ($role === '') {
        return 'Worker';
    }

    $role = str_replace(['_', '-'], ' ', strtolower($role));
    return ucwords($role);
}

/* ==========================================================================
   STATE
   ========================================================================== */

$error = '';

$name = '';
$email = '';
$phone = '';
$username = '';
$role = '';
$status = '';
$bio = '';
$availability = '';

/* ==========================================================================
   SCHEMA
   ========================================================================== */

$schema = [
    'name_col' => null,
    'email_col' => null,
    'phone_col' => null,
    'password_col' => null,
    'role_col' => null,
    'status_col' => null,
    'bio_col' => null,
    'availability_col' => null,
    'username_col' => null,
];

if (!tableExists($pdo, $USERS_TABLE)) {
    $error = "The users table was not found. Update \$USERS_TABLE in walker-profile.php if needed.";
} else {
    try {
        $columns = getTableColumns($pdo, $USERS_TABLE);

        if (!in_array($USER_ID_COL, $columns, true)) {
            $error = 'User ID column not found in users table.';
        } else {
            $schema['name_col'] = firstExistingColumn($possibleNameCols, $columns);
            $schema['email_col'] = firstExistingColumn($possibleEmailCols, $columns);
            $schema['phone_col'] = firstExistingColumn($possiblePhoneCols, $columns);
            $schema['password_col'] = firstExistingColumn($possiblePasswordCols, $columns);
            $schema['role_col'] = firstExistingColumn($possibleRoleCols, $columns);
            $schema['status_col'] = firstExistingColumn($possibleStatusCols, $columns);
            $schema['bio_col'] = firstExistingColumn($possibleBioCols, $columns);
            $schema['availability_col'] = firstExistingColumn($possibleAvailabilityCols, $columns);
            $schema['username_col'] = firstExistingColumn($possibleUsernameCols, $columns);

            if ($schema['email_col'] === null) {
                $error = 'Email column not found in users table.';
            } elseif ($schema['role_col'] === null) {
                $error = 'Role column not found in users table.';
            }
        }
    } catch (Throwable $e) {
        $error = 'Schema error: ' . $e->getMessage();
    }
}

/* ==========================================================================
   LOAD CURRENT WORKER
   ========================================================================== */

$workerExists = false;

if ($error === '') {
    try {
        $selectParts = [
            $USER_ID_COL . ' AS worker_id',
            $schema['role_col'] . ' AS worker_role'
        ];

        $selectParts[] = $schema['name_col'] ? $schema['name_col'] . ' AS worker_name' : "'' AS worker_name";
        $selectParts[] = $schema['email_col'] ? $schema['email_col'] . ' AS worker_email' : "'' AS worker_email";
        $selectParts[] = $schema['phone_col'] ? $schema['phone_col'] . ' AS worker_phone' : "'' AS worker_phone";
        $selectParts[] = $schema['status_col'] ? $schema['status_col'] . ' AS worker_status' : "'' AS worker_status";
        $selectParts[] = $schema['bio_col'] ? $schema['bio_col'] . ' AS worker_bio' : "'' AS worker_bio";
        $selectParts[] = $schema['availability_col'] ? $schema['availability_col'] . ' AS worker_availability' : "'' AS worker_availability";
        $selectParts[] = $schema['username_col'] ? $schema['username_col'] . ' AS worker_username' : "'' AS worker_username";
        $selectParts[] = $schema['password_col'] ? $schema['password_col'] . ' AS worker_password' : "'' AS worker_password";

        $sqlLoad = "
            SELECT
                " . implode(",\n                ", $selectParts) . "
            FROM $USERS_TABLE
            WHERE $USER_ID_COL = :worker_id
            LIMIT 1
        ";

        $stmtLoad = $pdo->prepare($sqlLoad);
        $stmtLoad->execute([':worker_id' => $workerId]);
        $worker = $stmtLoad->fetch(PDO::FETCH_ASSOC);

        if (!$worker) {
            $error = 'Worker account not found.';
        } else {
            $loadedRole = strtolower(trim((string)($worker['worker_role'] ?? '')));
            if (!in_array($loadedRole, $allowedWorkerRoles, true)) {
                $error = 'This account is not a worker/staff/employee account.';
            } else {
                $workerExists = true;

                $name = (string)($worker['worker_name'] ?? '');
                $email = (string)($worker['worker_email'] ?? '');
                $phone = (string)($worker['worker_phone'] ?? '');
                $username = (string)($worker['worker_username'] ?? '');
                $role = $loadedRole;
                $status = (string)($worker['worker_status'] ?? '');
                $bio = (string)($worker['worker_bio'] ?? '');
                $availability = (string)($worker['worker_availability'] ?? '');
                $storedPasswordHash = (string)($worker['worker_password'] ?? '');
            }
        }
    } catch (Throwable $e) {
        $error = 'Load profile error: ' . $e->getMessage();
    }
}

/* ==========================================================================
   HANDLE SUBMIT
   ========================================================================== */

if ($error === '' && $workerExists && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $bio = trim((string)($_POST['bio'] ?? ''));
    $availability = trim((string)($_POST['availability'] ?? ''));
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($schema['name_col'] !== null && $name === '') {
        $error = 'Please enter your name.';
    } elseif ($email === '') {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($newPassword !== '' && $schema['password_col'] === null) {
        $error = 'Password updates are not available because no password column was found.';
    } elseif ($newPassword !== '' && $currentPassword === '') {
        $error = 'Please enter your current password to set a new one.';
    } elseif ($newPassword !== '' && $storedPasswordHash !== '' && !password_verify($currentPassword, $storedPasswordHash)) {
        $error = 'Your current password is incorrect.';
    } elseif ($newPassword !== '' && strlen($newPassword) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($newPassword !== '' && $newPassword !== $confirmPassword) {
        $error = 'New passwords do not match.';
    } else {
        try {
            $stmtEmail = $pdo->prepare("
                SELECT $USER_ID_COL
                FROM $USERS_TABLE
                WHERE {$schema['email_col']} = :email
                  AND $USER_ID_COL != :worker_id
                LIMIT 1
            ");
            $stmtEmail->execute([
                ':email' => $email,
                ':worker_id' => $workerId,
            ]);
            $existingEmail = $stmtEmail->fetchColumn();

            if ($existingEmail) {
                $error = 'That email is already in use.';
            } elseif ($schema['username_col'] !== null && $username !== '') {
                $stmtUsername = $pdo->prepare("
                    SELECT $USER_ID_COL
                    FROM $USERS_TABLE
                    WHERE {$schema['username_col']} = :username
                      AND $USER_ID_COL != :worker_id
                    LIMIT 1
                ");
                $stmtUsername->execute([
                    ':username' => $username,
                    ':worker_id' => $workerId,
                ]);
                $existingUsername = $stmtUsername->fetchColumn();

                if ($existingUsername) {
                    $error = 'That username is already in use.';
                }
            }

            if ($error === '') {
                $updateParts = [];
                $params = [
                    ':worker_id' => $workerId,
                ];

                if ($schema['name_col'] !== null) {
                    $updateParts[] = $schema['name_col'] . ' = :name';
                    $params[':name'] = $name;
                }

                $updateParts[] = $schema['email_col'] . ' = :email';
                $params[':email'] = $email;

                if ($schema['phone_col'] !== null) {
                    $updateParts[] = $schema['phone_col'] . ' = :phone';
                    $params[':phone'] = $phone;
                }

                if ($schema['username_col'] !== null) {
                    $updateParts[] = $schema['username_col'] . ' = :username';
                    $params[':username'] = $username;
                }

                if ($schema['bio_col'] !== null) {
                    $updateParts[] = $schema['bio_col'] . ' = :bio';
                    $params[':bio'] = $bio;
                }

                if ($schema['availability_col'] !== null) {
                    $updateParts[] = $schema['availability_col'] . ' = :availability';
                    $params[':availability'] = $availability;
                }

                if ($schema['password_col'] !== null && $newPassword !== '') {
                    $updateParts[] = $schema['password_col'] . ' = :password';
                    $params[':password'] = password_hash($newPassword, PASSWORD_DEFAULT);
                }

                $sqlUpdate = "
                    UPDATE $USERS_TABLE
                    SET " . implode(', ', $updateParts) . "
                    WHERE $USER_ID_COL = :worker_id
                ";

                $stmtUpdate = $pdo->prepare($sqlUpdate);
                $stmtUpdate->execute($params);

                $_SESSION['email'] = $email;
                $_SESSION['name'] = $name;
                $_SESSION['username'] = $username;

                $_SESSION['walker_flash_type'] = 'success';
                $_SESSION['walker_flash_message'] = 'Your profile has been updated successfully.';

                header('Location: walker-profile.php');
                exit;
            }
        } catch (Throwable $e) {
            $error = 'Profile update error: ' . $e->getMessage();
        }
    }
}

$displayName = trim($name) !== '' ? $name : 'Worker';
$firstName = explode(' ', $displayName)[0] ?: 'Worker';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Worker Profile | Doggie Dorian’s</title>
    <meta name="description" content="Worker profile page for Doggie Dorian’s.">
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

        a { color: inherit; text-decoration: none; }
        button { font: inherit; }

        .container {
            width: min(1180px, calc(100% - 32px));
            margin: 0 auto;
            padding: 28px 0 44px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .eyebrow {
            display: inline-block;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.18em;
            color: var(--gold);
            margin-bottom: 8px;
        }

        .headline {
            margin: 0;
            font-size: clamp(30px, 5vw, 50px);
            line-height: 0.98;
            letter-spacing: -0.05em;
        }

        .subheadline {
            margin: 12px 0 0;
            font-size: 15px;
            line-height: 1.75;
            color: var(--muted);
            max-width: 760px;
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
            border: none;
            cursor: pointer;
            background: linear-gradient(135deg, var(--gold), var(--gold-strong));
            color: #17130e;
            box-shadow: 0 16px 34px rgba(191,143,55,0.28);
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

        .grid {
            display: grid;
            grid-template-columns: .95fr 1.05fr;
            gap: 18px;
        }

        .panel,
        .hero-card,
        .info-card {
            background: var(--card);
            border: 1px solid var(--border);
            backdrop-filter: blur(16px);
            box-shadow: var(--shadow);
            border-radius: var(--radius-xl);
        }

        .panel,
        .hero-card {
            padding: 24px;
        }

        .hero-card h2,
        .panel h2 {
            margin: 0 0 14px;
            font-size: 24px;
            letter-spacing: -0.03em;
        }

        .hero-copy {
            color: var(--muted);
            font-size: 15px;
            line-height: 1.7;
            margin-bottom: 18px;
        }

        .info-stack {
            display: grid;
            gap: 12px;
        }

        .info-card {
            padding: 16px;
            background: var(--card-strong);
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,0.08);
        }

        .info-label {
            display: block;
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 6px;
        }

        .info-value {
            font-size: 15px;
            line-height: 1.6;
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
            background: rgba(143,197,255,0.12);
            color: var(--blue);
        }

        form {
            display: grid;
            gap: 16px;
        }

        .field-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
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

        input,
        textarea {
            width: 100%;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.06);
            color: var(--text);
            border-radius: 16px;
            padding: 14px 15px;
            font-size: 15px;
            outline: none;
            transition: border-color .16s ease, background .16s ease, transform .16s ease;
        }

        textarea {
            resize: vertical;
            min-height: 120px;
        }

        input:focus,
        textarea:focus {
            border-color: rgba(217,180,107,0.65);
            background: rgba(255,255,255,0.08);
            transform: translateY(-1px);
        }

        .helper {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
        }

        .section-label {
            margin-top: 6px;
            color: var(--gold);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            font-weight: 800;
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 6px;
        }

        .footer-note {
            margin-top: 20px;
            text-align: center;
            color: var(--muted);
            font-size: 13px;
        }

        @media (max-width: 980px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 760px) {
            .container {
                width: min(100% - 18px, 1180px);
                padding-top: 18px;
            }

            .hero-card,
            .panel {
                padding: 18px;
            }

            .field-grid {
                grid-template-columns: 1fr;
            }

            .topbar {
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
                <div class="eyebrow">Doggie Dorian’s Worker Portal</div>
                <h1 class="headline"><?= h($firstName) ?>’s Profile</h1>
                <p class="subheadline">
                    Manage your worker account details, contact information, bio, availability, and password from one worker-safe page.
                </p>
            </div>

            <div class="top-actions">
                <a class="btn-secondary" href="walker-dashboard.php">Dashboard</a>
                <a class="btn-secondary" href="walker-jobs.php">My Jobs</a>
                <a class="btn-secondary" href="walker-logout.php">Log Out</a>
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

        <?php if ($workerExists): ?>
            <div class="grid">
                <section class="hero-card">
                    <div class="badge"><?= h(niceRole($role)) ?> Access</div>
                    <h2 style="margin-top:14px;"><?= h($displayName) ?></h2>
                    <div class="hero-copy">
                        This is your worker-side account summary. You can update only your own information here.
                    </div>

                    <div class="info-stack">
                        <div class="info-card">
                            <span class="info-label">Name</span>
                            <div class="info-value"><?= h($name !== '' ? $name : 'Not provided') ?></div>
                        </div>

                        <div class="info-card">
                            <span class="info-label">Email</span>
                            <div class="info-value"><?= h($email !== '' ? $email : 'Not provided') ?></div>
                        </div>

                        <div class="info-card">
                            <span class="info-label">Phone</span>
                            <div class="info-value"><?= h($phone !== '' ? $phone : 'Not provided') ?></div>
                        </div>

                        <div class="info-card">
                            <span class="info-label">Username</span>
                            <div class="info-value"><?= h($username !== '' ? $username : 'Not provided') ?></div>
                        </div>

                        <div class="info-card">
                            <span class="info-label">Status</span>
                            <div class="info-value"><?= h($status !== '' ? $status : 'Not available') ?></div>
                        </div>

                        <div class="info-card">
                            <span class="info-label">Availability</span>
                            <div class="info-value"><?= h($availability !== '' ? $availability : 'Not provided') ?></div>
                        </div>
                    </div>
                </section>

                <section class="panel">
                    <h2>Edit profile</h2>

                    <form method="post" action="walker-profile.php" novalidate>
                        <div class="field-grid">
                            <div class="field">
                                <label for="name">Full Name</label>
                                <input
                                    type="text"
                                    id="name"
                                    name="name"
                                    value="<?= h($name) ?>"
                                    placeholder="Enter your full name"
                                >
                            </div>

                            <div class="field">
                                <label for="email">Email</label>
                                <input
                                    type="email"
                                    id="email"
                                    name="email"
                                    value="<?= h($email) ?>"
                                    placeholder="Enter your email"
                                    required
                                >
                            </div>
                        </div>

                        <div class="field-grid">
                            <div class="field">
                                <label for="phone">Phone</label>
                                <input
                                    type="text"
                                    id="phone"
                                    name="phone"
                                    value="<?= h($phone) ?>"
                                    placeholder="Enter your phone"
                                >
                            </div>

                            <div class="field">
                                <label for="username">Username</label>
                                <input
                                    type="text"
                                    id="username"
                                    name="username"
                                    value="<?= h($username) ?>"
                                    placeholder="Choose a username"
                                >
                                <div class="helper">
                                    Only used if your users table includes a username column.
                                </div>
                            </div>
                        </div>

                        <div class="field">
                            <label for="bio">Bio</label>
                            <textarea
                                id="bio"
                                name="bio"
                                placeholder="Tell the team a little about you..."
                            ><?= h($bio) ?></textarea>
                        </div>

                        <div class="field">
                            <label for="availability">Availability</label>
                            <textarea
                                id="availability"
                                name="availability"
                                placeholder="Example: weekdays after 2 PM, weekends flexible..."
                            ><?= h($availability) ?></textarea>
                        </div>

                        <div class="section-label">Password Change</div>

                        <div class="field-grid">
                            <div class="field">
                                <label for="current_password">Current Password</label>
                                <input
                                    type="password"
                                    id="current_password"
                                    name="current_password"
                                    placeholder="Required only if changing password"
                                >
                            </div>

                            <div class="field">
                                <label for="new_password">New Password</label>
                                <input
                                    type="password"
                                    id="new_password"
                                    name="new_password"
                                    placeholder="Minimum 8 characters"
                                >
                            </div>
                        </div>

                        <div class="field">
                            <label for="confirm_password">Confirm New Password</label>
                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                placeholder="Re-enter new password"
                            >
                            <div class="helper">
                                Leave password fields blank if you do not want to change your password.
                            </div>
                        </div>

                        <div class="actions">
                            <button type="submit" class="btn">Save Profile</button>
                            <a href="walker-dashboard.php" class="btn-secondary">Back To Dashboard</a>
                        </div>
                    </form>
                </section>
            </div>
        <?php endif; ?>

        <div class="footer-note">
            Signed in as <?= h($email !== '' ? $email : $displayName) ?> · Worker-only profile access enforced
        </div>
    </div>
</body>
</html>