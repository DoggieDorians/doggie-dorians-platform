<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';

/**
 * Doggie Dorian's
 * admin-create-worker.php
 *
 * Stable admin-only worker creation page.
 */

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection not available.');
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect_to(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function is_admin_session(): bool
{
    $roleCandidates = [
        $_SESSION['role'] ?? null,
        $_SESSION['user_role'] ?? null,
        $_SESSION['account_role'] ?? null,
        $_SESSION['account_type'] ?? null,
    ];

    foreach ($roleCandidates as $role) {
        if (is_string($role) && strtolower(trim($role)) === 'admin') {
            return true;
        }
    }

    if (!empty($_SESSION['is_admin']) || !empty($_SESSION['admin_logged_in'])) {
        return true;
    }

    return false;
}

if (!isset($_SESSION['user_id']) && empty($_SESSION['admin_logged_in'])) {
    redirect_to('admin-login.php');
}

if (!is_admin_session()) {
    redirect_to('login.php');
}

function table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1");
        $stmt->execute([':table' => $table]);
        return $cache[$table] = (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        return $cache[$table] = false;
    }
}

function get_columns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    if (!table_exists($pdo, $table)) {
        return $cache[$table] = [];
    }

    try {
        $stmt = $pdo->query("PRAGMA table_info($table)");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $columns = [];

        foreach ($rows as $row) {
            if (!empty($row['name'])) {
                $columns[] = (string) $row['name'];
            }
        }

        return $cache[$table] = $columns;
    } catch (Throwable) {
        return $cache[$table] = [];
    }
}

$usersTable = 'users';

if (!table_exists($pdo, $usersTable)) {
    exit('Users table not found.');
}

$userColumns = get_columns($pdo, $usersTable);

$idCol = in_array('id', $userColumns, true) ? 'id' : (in_array('user_id', $userColumns, true) ? 'user_id' : null);
$roleCol = in_array('role', $userColumns, true) ? 'role' : (
    in_array('user_role', $userColumns, true) ? 'user_role' : (
        in_array('account_role', $userColumns, true) ? 'account_role' : (
            in_array('account_type', $userColumns, true) ? 'account_type' : null
        )
    )
);

if ($idCol === null || $roleCol === null) {
    exit('Users table is missing required ID or role columns.');
}

$hasFullName = in_array('full_name', $userColumns, true);
$hasName = in_array('name', $userColumns, true);
$hasFirstName = in_array('first_name', $userColumns, true);
$hasLastName = in_array('last_name', $userColumns, true);
$hasUsername = in_array('username', $userColumns, true);
$hasEmail = in_array('email', $userColumns, true);
$hasPassword = in_array('password', $userColumns, true);
$hasPasswordHash = in_array('password_hash', $userColumns, true);
$hasPhone = in_array('phone', $userColumns, true);
$hasPhoneNumber = in_array('phone_number', $userColumns, true);
$hasMobile = in_array('mobile', $userColumns, true);
$hasStatus = in_array('status', $userColumns, true);
$hasAccountStatus = in_array('account_status', $userColumns, true);
$hasWorkerStatus = in_array('worker_status', $userColumns, true);
$hasIsActive = in_array('is_active', $userColumns, true);
$hasActive = in_array('active', $userColumns, true);
$hasEnabled = in_array('enabled', $userColumns, true);
$hasDisabled = in_array('disabled', $userColumns, true);
$hasAvailability = in_array('availability', $userColumns, true);
$hasWorkerAvailability = in_array('worker_availability', $userColumns, true);
$hasSchedule = in_array('schedule', $userColumns, true);
$hasBio = in_array('bio', $userColumns, true);
$hasAbout = in_array('about', $userColumns, true);
$hasAboutMe = in_array('about_me', $userColumns, true);
$hasNotes = in_array('notes', $userColumns, true);
$hasWorkerBio = in_array('worker_bio', $userColumns, true);
$hasCreatedAt = in_array('created_at', $userColumns, true);

$success = '';
$error = '';

$form = [
    'full_name' => '',
    'first_name' => '',
    'last_name' => '',
    'username' => '',
    'email' => '',
    'phone' => '',
    'role' => 'walker',
    'status' => 'active',
    'availability' => '',
    'bio' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['full_name'] = trim((string) ($_POST['full_name'] ?? ''));
    $form['first_name'] = trim((string) ($_POST['first_name'] ?? ''));
    $form['last_name'] = trim((string) ($_POST['last_name'] ?? ''));
    $form['username'] = trim((string) ($_POST['username'] ?? ''));
    $form['email'] = trim((string) ($_POST['email'] ?? ''));
    $form['phone'] = trim((string) ($_POST['phone'] ?? ''));
    $form['role'] = strtolower(trim((string) ($_POST['role'] ?? 'walker')));
    $form['status'] = strtolower(trim((string) ($_POST['status'] ?? 'active')));
    $form['availability'] = trim((string) ($_POST['availability'] ?? ''));
    $form['bio'] = trim((string) ($_POST['bio'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $allowedRoles = ['walker', 'worker', 'staff', 'employee'];
    if (!in_array($form['role'], $allowedRoles, true)) {
        $form['role'] = 'walker';
    }

    $displayName = $form['full_name'] !== ''
        ? $form['full_name']
        : trim($form['first_name'] . ' ' . $form['last_name']);

    if ($displayName === '' && $form['username'] === '') {
        $error = 'Please enter a worker name or username.';
    } elseif ($form['email'] === '') {
        $error = 'Please enter an email address.';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($password === '') {
        $error = 'Please enter a password.';
    } else {
        try {
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM {$usersTable} WHERE email = :email");
            $checkStmt->execute([':email' => $form['email']]);
            $emailExists = (int) $checkStmt->fetchColumn() > 0;

            if ($emailExists) {
                $error = 'That email is already in use.';
            } else {
                $insertColumns = [];
                $insertValues = [];
                $params = [];

                if ($hasFullName) {
                    $insertColumns[] = 'full_name';
                    $insertValues[] = ':full_name';
                    $params[':full_name'] = $displayName;
                } elseif ($hasName) {
                    $insertColumns[] = 'name';
                    $insertValues[] = ':name';
                    $params[':name'] = $displayName;
                }

                if ($hasFirstName) {
                    $insertColumns[] = 'first_name';
                    $insertValues[] = ':first_name';
                    $params[':first_name'] = $form['first_name'];
                }

                if ($hasLastName) {
                    $insertColumns[] = 'last_name';
                    $insertValues[] = ':last_name';
                    $params[':last_name'] = $form['last_name'];
                }

                if ($hasUsername) {
                    $insertColumns[] = 'username';
                    $insertValues[] = ':username';
                    $params[':username'] = $form['username'] !== '' ? $form['username'] : strtolower(str_replace(' ', '', $displayName));
                }

                if ($hasEmail) {
                    $insertColumns[] = 'email';
                    $insertValues[] = ':email';
                    $params[':email'] = $form['email'];
                }

                if ($hasPasswordHash) {
                    $insertColumns[] = 'password_hash';
                    $insertValues[] = ':password_hash';
                    $params[':password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                } elseif ($hasPassword) {
                    $insertColumns[] = 'password';
                    $insertValues[] = ':password';
                    $params[':password'] = password_hash($password, PASSWORD_DEFAULT);
                }

                $insertColumns[] = $roleCol;
                $insertValues[] = ':role';
                $params[':role'] = $form['role'];

                if ($hasPhone) {
                    $insertColumns[] = 'phone';
                    $insertValues[] = ':phone';
                    $params[':phone'] = $form['phone'];
                } elseif ($hasPhoneNumber) {
                    $insertColumns[] = 'phone_number';
                    $insertValues[] = ':phone_number';
                    $params[':phone_number'] = $form['phone'];
                } elseif ($hasMobile) {
                    $insertColumns[] = 'mobile';
                    $insertValues[] = ':mobile';
                    $params[':mobile'] = $form['phone'];
                }

                if ($hasStatus) {
                    $insertColumns[] = 'status';
                    $insertValues[] = ':status';
                    $params[':status'] = $form['status'];
                } elseif ($hasAccountStatus) {
                    $insertColumns[] = 'account_status';
                    $insertValues[] = ':account_status';
                    $params[':account_status'] = $form['status'];
                } elseif ($hasWorkerStatus) {
                    $insertColumns[] = 'worker_status';
                    $insertValues[] = ':worker_status';
                    $params[':worker_status'] = $form['status'];
                }

                if ($hasIsActive) {
                    $insertColumns[] = 'is_active';
                    $insertValues[] = ':is_active';
                    $params[':is_active'] = $form['status'] === 'active' ? 1 : 0;
                } elseif ($hasActive) {
                    $insertColumns[] = 'active';
                    $insertValues[] = ':active';
                    $params[':active'] = $form['status'] === 'active' ? 1 : 0;
                } elseif ($hasEnabled) {
                    $insertColumns[] = 'enabled';
                    $insertValues[] = ':enabled';
                    $params[':enabled'] = $form['status'] === 'active' ? 1 : 0;
                } elseif ($hasDisabled) {
                    $insertColumns[] = 'disabled';
                    $insertValues[] = ':disabled';
                    $params[':disabled'] = $form['status'] === 'active' ? 0 : 1;
                }

                if ($hasAvailability) {
                    $insertColumns[] = 'availability';
                    $insertValues[] = ':availability';
                    $params[':availability'] = $form['availability'];
                } elseif ($hasWorkerAvailability) {
                    $insertColumns[] = 'worker_availability';
                    $insertValues[] = ':worker_availability';
                    $params[':worker_availability'] = $form['availability'];
                } elseif ($hasSchedule) {
                    $insertColumns[] = 'schedule';
                    $insertValues[] = ':schedule';
                    $params[':schedule'] = $form['availability'];
                }

                if ($hasBio) {
                    $insertColumns[] = 'bio';
                    $insertValues[] = ':bio';
                    $params[':bio'] = $form['bio'];
                } elseif ($hasAbout) {
                    $insertColumns[] = 'about';
                    $insertValues[] = ':about';
                    $params[':about'] = $form['bio'];
                } elseif ($hasAboutMe) {
                    $insertColumns[] = 'about_me';
                    $insertValues[] = ':about_me';
                    $params[':about_me'] = $form['bio'];
                } elseif ($hasNotes) {
                    $insertColumns[] = 'notes';
                    $insertValues[] = ':notes';
                    $params[':notes'] = $form['bio'];
                } elseif ($hasWorkerBio) {
                    $insertColumns[] = 'worker_bio';
                    $insertValues[] = ':worker_bio';
                    $params[':worker_bio'] = $form['bio'];
                }

                if ($hasCreatedAt) {
                    $insertColumns[] = 'created_at';
                    $insertValues[] = ':created_at';
                    $params[':created_at'] = date('Y-m-d H:i:s');
                }

                $sql = sprintf(
                    'INSERT INTO %s (%s) VALUES (%s)',
                    $usersTable,
                    implode(', ', $insertColumns),
                    implode(', ', $insertValues)
                );

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                $newId = (int) $pdo->lastInsertId();

                if ($newId > 0) {
                    redirect_to('admin-worker-view.php?id=' . $newId);
                }

                $success = 'Worker account created successfully.';
                $form = [
                    'full_name' => '',
                    'first_name' => '',
                    'last_name' => '',
                    'username' => '',
                    'email' => '',
                    'phone' => '',
                    'role' => 'walker',
                    'status' => 'active',
                    'availability' => '',
                    'bio' => '',
                ];
            }
        } catch (Throwable $e) {
            $error = 'Could not create worker: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Worker | Doggie Dorian’s</title>
    <meta name="description" content="Admin create worker page for Doggie Dorian’s.">
    <style>
        :root {
            --bg: #07101d;
            --panel: rgba(15, 23, 42, 0.92);
            --line: rgba(148, 163, 184, 0.16);
            --text: #e5edf7;
            --muted: #94a3b8;
            --gold: #d4af37;
            --gold-soft: #f5deb3;
            --green: #22c55e;
            --red: #ef4444;
            --shadow: 0 24px 70px rgba(2, 8, 23, 0.42);
            --max: 1120px;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            min-height: 100%;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(212, 175, 55, 0.14), transparent 28%),
                radial-gradient(circle at top right, rgba(56, 189, 248, 0.08), transparent 22%),
                linear-gradient(180deg, #07101d 0%, #0b1220 50%, #0f172a 100%);
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .page {
            max-width: var(--max);
            margin: 0 auto;
            padding: 28px 18px 80px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .brand {
            font-size: 1.55rem;
            font-weight: 900;
            letter-spacing: 0.04em;
        }

        .top-links {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .top-link {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            font-weight: 700;
            font-size: 0.94rem;
        }

        .hero, .panel {
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(15, 23, 42, 0.82));
            border: 1px solid rgba(212, 175, 55, 0.14);
            border-radius: 28px;
            padding: 24px;
            box-shadow: var(--shadow);
        }

        .hero {
            margin-bottom: 22px;
        }

        .eyebrow {
            color: var(--gold-soft);
            text-transform: uppercase;
            letter-spacing: 0.14em;
            font-size: 0.75rem;
            font-weight: 800;
            margin-bottom: 10px;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 2rem;
            line-height: 1.08;
        }

        .sub {
            color: rgba(244,241,234,0.72);
            line-height: 1.65;
            font-size: 0.98rem;
            max-width: 820px;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 16px;
            margin-bottom: 16px;
            font-weight: 700;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.16);
            color: #d7f1dd;
            border: 1px solid rgba(34, 197, 94, 0.20);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.16);
            color: #ffd5d5;
            border: 1px solid rgba(239, 68, 68, 0.20);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .field {
            display: grid;
            gap: 8px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        label {
            font-size: 0.84rem;
            font-weight: 800;
            color: var(--gold-soft);
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        input, select, textarea {
            width: 100%;
            border: 1px solid rgba(255,255,255,0.09);
            background: rgba(255,255,255,0.05);
            color: var(--text);
            border-radius: 16px;
            padding: 14px 14px;
            font: inherit;
            outline: none;
        }

        textarea {
            min-height: 140px;
            resize: vertical;
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 48px;
            padding: 0 18px;
            border-radius: 999px;
            font-weight: 800;
            border: 1px solid transparent;
            cursor: pointer;
            font: inherit;
        }

        .btn-primary {
            background: linear-gradient(135deg, #d4af37, #f5deb3);
            color: #0f172a;
        }

        .btn-secondary {
            background: rgba(255,255,255,0.05);
            border-color: rgba(255,255,255,0.08);
            color: var(--text);
        }

        @media (max-width: 760px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            h1 {
                font-size: 1.65rem;
            }

            .page {
                padding: 20px 12px 60px;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="topbar">
            <div class="brand">Doggie Dorian’s</div>
            <div class="top-links">
                <a class="top-link" href="admin-dashboard.php">Dashboard</a>
                <a class="top-link" href="admin-bookings.php">Bookings</a>
                <a class="top-link" href="admin-walker-management.php">Workers</a>
                <a class="top-link" href="admin-create-worker.php">Create Worker</a>
            </div>
        </div>

        <section class="hero">
            <div class="eyebrow">Admin Worker Control</div>
            <h1>Create Worker</h1>
            <div class="sub">
                Add a new walker, worker, staff, or employee account into the Doggie Dorian’s system using flexible user schema support.
            </div>
        </section>

        <section class="panel">
            <?php if ($success !== ''): ?>
                <div class="alert alert-success"><?= h($success) ?></div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post" action="">
                <div class="form-grid">
                    <div class="field">
                        <label for="full_name">Full Name</label>
                        <input id="full_name" name="full_name" type="text" value="<?= h($form['full_name']) ?>" placeholder="Worker full name">
                    </div>

                    <div class="field">
                        <label for="username">Username</label>
                        <input id="username" name="username" type="text" value="<?= h($form['username']) ?>" placeholder="worker username">
                    </div>

                    <div class="field">
                        <label for="first_name">First Name</label>
                        <input id="first_name" name="first_name" type="text" value="<?= h($form['first_name']) ?>" placeholder="First name">
                    </div>

                    <div class="field">
                        <label for="last_name">Last Name</label>
                        <input id="last_name" name="last_name" type="text" value="<?= h($form['last_name']) ?>" placeholder="Last name">
                    </div>

                    <div class="field">
                        <label for="email">Email</label>
                        <input id="email" name="email" type="email" value="<?= h($form['email']) ?>" placeholder="worker@email.com" required>
                    </div>

                    <div class="field">
                        <label for="phone">Phone</label>
                        <input id="phone" name="phone" type="text" value="<?= h($form['phone']) ?>" placeholder="Phone number">
                    </div>

                    <div class="field">
                        <label for="password">Password</label>
                        <input id="password" name="password" type="password" placeholder="Create password" required>
                    </div>

                    <div class="field">
                        <label for="role">Role</label>
                        <select id="role" name="role">
                            <option value="walker" <?= $form['role'] === 'walker' ? 'selected' : '' ?>>Walker</option>
                            <option value="worker" <?= $form['role'] === 'worker' ? 'selected' : '' ?>>Worker</option>
                            <option value="staff" <?= $form['role'] === 'staff' ? 'selected' : '' ?>>Staff</option>
                            <option value="employee" <?= $form['role'] === 'employee' ? 'selected' : '' ?>>Employee</option>
                        </select>
                    </div>

                    <div class="field">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="active" <?= $form['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="disabled" <?= $form['status'] === 'disabled' ? 'selected' : '' ?>>Disabled</option>
                        </select>
                    </div>

                    <div class="field full">
                        <label for="availability">Availability</label>
                        <input id="availability" name="availability" type="text" value="<?= h($form['availability']) ?>" placeholder="Example: Mon-Fri mornings, weekends flexible">
                    </div>

                    <div class="field full">
                        <label for="bio">Bio / Notes</label>
                        <textarea id="bio" name="bio" placeholder="Add worker notes, specialties, experience, or admin bio..."><?= h($form['bio']) ?></textarea>
                    </div>
                </div>

                <div class="actions">
                    <button class="btn btn-primary" type="submit">Create Worker</button>
                    <a class="btn btn-secondary" href="admin-walker-management.php">Back to Worker Management</a>
                </div>
            </form>
        </section>
    </div>
</body>
</html>