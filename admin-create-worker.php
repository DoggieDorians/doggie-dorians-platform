<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-auth.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection not available.');
}

function ddAdminCreateWorkerH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ddAdminCreateWorkerRedirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function ddAdminCreateWorkerQuoteIdentifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function ddAdminCreateWorkerTableExists(PDO $pdo, string $table): bool
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

function ddAdminCreateWorkerGetColumns(PDO $pdo, string $table): array
{
    static $cache = array();

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    if (!ddAdminCreateWorkerTableExists($pdo, $table)) {
        $cache[$table] = array();
        return $cache[$table];
    }

    try {
        $stmt = $pdo->query('PRAGMA table_info(' . ddAdminCreateWorkerQuoteIdentifier($table) . ')');
        if (!($stmt instanceof PDOStatement)) {
            $cache[$table] = array();
            return $cache[$table];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $columns = array();

        foreach ($rows as $row) {
            if (!empty($row['name'])) {
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

function ddAdminCreateWorkerHasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function ddAdminCreateWorkerFirstExistingColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function ddAdminCreateWorkerCsrfToken(): string
{
    if (empty($_SESSION['admin_create_worker_csrf']) || !is_string($_SESSION['admin_create_worker_csrf'])) {
        $_SESSION['admin_create_worker_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['admin_create_worker_csrf'];
}

function ddAdminCreateWorkerValidateCsrf(?string $submittedToken): bool
{
    $sessionToken = $_SESSION['admin_create_worker_csrf'] ?? '';

    if (!is_string($sessionToken) || $sessionToken === '' || $submittedToken === null || $submittedToken === '') {
        return false;
    }

    return hash_equals($sessionToken, $submittedToken);
}

$usersTable = 'users';

if (!ddAdminCreateWorkerTableExists($pdo, $usersTable)) {
    exit('Users table not found.');
}

$userColumns = ddAdminCreateWorkerGetColumns($pdo, $usersTable);

$idCol = ddAdminCreateWorkerFirstExistingColumn($userColumns, array('id', 'user_id'));
$roleCol = ddAdminCreateWorkerFirstExistingColumn($userColumns, array('role', 'user_role', 'account_role', 'account_type'));

if ($idCol === null || $roleCol === null) {
    exit('Users table is missing required ID or role columns.');
}

$hasFullName = ddAdminCreateWorkerHasColumn($userColumns, 'full_name');
$hasName = ddAdminCreateWorkerHasColumn($userColumns, 'name');
$hasFirstName = ddAdminCreateWorkerHasColumn($userColumns, 'first_name');
$hasLastName = ddAdminCreateWorkerHasColumn($userColumns, 'last_name');
$hasUsername = ddAdminCreateWorkerHasColumn($userColumns, 'username');
$hasEmail = ddAdminCreateWorkerHasColumn($userColumns, 'email');
$hasPassword = ddAdminCreateWorkerHasColumn($userColumns, 'password');
$hasPasswordHash = ddAdminCreateWorkerHasColumn($userColumns, 'password_hash');
$hasPhone = ddAdminCreateWorkerHasColumn($userColumns, 'phone');
$hasPhoneNumber = ddAdminCreateWorkerHasColumn($userColumns, 'phone_number');
$hasMobile = ddAdminCreateWorkerHasColumn($userColumns, 'mobile');
$hasStatus = ddAdminCreateWorkerHasColumn($userColumns, 'status');
$hasAccountStatus = ddAdminCreateWorkerHasColumn($userColumns, 'account_status');
$hasWorkerStatus = ddAdminCreateWorkerHasColumn($userColumns, 'worker_status');
$hasIsActive = ddAdminCreateWorkerHasColumn($userColumns, 'is_active');
$hasActive = ddAdminCreateWorkerHasColumn($userColumns, 'active');
$hasEnabled = ddAdminCreateWorkerHasColumn($userColumns, 'enabled');
$hasDisabled = ddAdminCreateWorkerHasColumn($userColumns, 'disabled');
$hasAvailability = ddAdminCreateWorkerHasColumn($userColumns, 'availability');
$hasWorkerAvailability = ddAdminCreateWorkerHasColumn($userColumns, 'worker_availability');
$hasSchedule = ddAdminCreateWorkerHasColumn($userColumns, 'schedule');
$hasBio = ddAdminCreateWorkerHasColumn($userColumns, 'bio');
$hasAbout = ddAdminCreateWorkerHasColumn($userColumns, 'about');
$hasAboutMe = ddAdminCreateWorkerHasColumn($userColumns, 'about_me');
$hasNotes = ddAdminCreateWorkerHasColumn($userColumns, 'notes');
$hasWorkerBio = ddAdminCreateWorkerHasColumn($userColumns, 'worker_bio');
$hasCreatedAt = ddAdminCreateWorkerHasColumn($userColumns, 'created_at');

$success = '';
$error = '';

$form = array(
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
);

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

    if (!ddAdminCreateWorkerValidateCsrf(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
        $error = 'Security check failed. Please refresh the page and try again.';
    } else {
        $allowedRoles = array('walker', 'worker', 'staff', 'employee');
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
                if ($hasEmail) {
                    $checkSql = 'SELECT COUNT(*) AS match_count FROM ' . ddAdminCreateWorkerQuoteIdentifier($usersTable)
                        . ' WHERE LOWER(' . ddAdminCreateWorkerQuoteIdentifier('email') . ') = LOWER(:email)';
                    $checkStmt = $pdo->prepare($checkSql);
                    $checkStmt->execute(array(':email' => $form['email']));
                    $emailExists = (int) $checkStmt->fetchColumn() > 0;

                    if ($emailExists) {
                        $error = 'That email is already in use.';
                    }
                }

                if ($error === '') {
                    $insertColumns = array();
                    $insertValues = array();
                    $params = array();

                    $addField = function (string $column, string $placeholder, $value) use (&$insertColumns, &$insertValues, &$params): void {
                        $insertColumns[] = $column;
                        $insertValues[] = $placeholder;
                        $params[$placeholder] = $value;
                    };

                    if ($hasFullName) {
                        $addField('full_name', ':full_name', $displayName);
                    } elseif ($hasName) {
                        $addField('name', ':name', $displayName);
                    }

                    if ($hasFirstName) {
                        $addField('first_name', ':first_name', $form['first_name'] !== '' ? $form['first_name'] : null);
                    }

                    if ($hasLastName) {
                        $addField('last_name', ':last_name', $form['last_name'] !== '' ? $form['last_name'] : null);
                    }

                    if ($hasUsername) {
                        $generatedUsername = $form['username'] !== '' ? $form['username'] : strtolower((string) preg_replace('/\s+/', '', $displayName));
                        $addField('username', ':username', $generatedUsername !== '' ? $generatedUsername : null);
                    }

                    if ($hasEmail) {
                        $addField('email', ':email', $form['email']);
                    }

                    $passwordHashValue = password_hash($password, PASSWORD_DEFAULT);

                    if ($hasPasswordHash) {
                        $addField('password_hash', ':password_hash', $passwordHashValue);
                    } elseif ($hasPassword) {
                        $addField('password', ':password', $passwordHashValue);
                    }

                    $addField($roleCol, ':role', $form['role']);

                    if ($hasPhone) {
                        $addField('phone', ':phone', $form['phone'] !== '' ? $form['phone'] : null);
                    } elseif ($hasPhoneNumber) {
                        $addField('phone_number', ':phone_number', $form['phone'] !== '' ? $form['phone'] : null);
                    } elseif ($hasMobile) {
                        $addField('mobile', ':mobile', $form['phone'] !== '' ? $form['phone'] : null);
                    }

                    if ($hasStatus) {
                        $addField('status', ':status', $form['status']);
                    } elseif ($hasAccountStatus) {
                        $addField('account_status', ':account_status', $form['status']);
                    } elseif ($hasWorkerStatus) {
                        $addField('worker_status', ':worker_status', $form['status']);
                    }

                    if ($hasIsActive) {
                        $addField('is_active', ':is_active', $form['status'] === 'active' ? 1 : 0);
                    } elseif ($hasActive) {
                        $addField('active', ':active', $form['status'] === 'active' ? 1 : 0);
                    } elseif ($hasEnabled) {
                        $addField('enabled', ':enabled', $form['status'] === 'active' ? 1 : 0);
                    } elseif ($hasDisabled) {
                        $addField('disabled', ':disabled', $form['status'] === 'active' ? 0 : 1);
                    }

                    if ($hasAvailability) {
                        $addField('availability', ':availability', $form['availability'] !== '' ? $form['availability'] : null);
                    } elseif ($hasWorkerAvailability) {
                        $addField('worker_availability', ':worker_availability', $form['availability'] !== '' ? $form['availability'] : null);
                    } elseif ($hasSchedule) {
                        $addField('schedule', ':schedule', $form['availability'] !== '' ? $form['availability'] : null);
                    }

                    if ($hasBio) {
                        $addField('bio', ':bio', $form['bio'] !== '' ? $form['bio'] : null);
                    } elseif ($hasAbout) {
                        $addField('about', ':about', $form['bio'] !== '' ? $form['bio'] : null);
                    } elseif ($hasAboutMe) {
                        $addField('about_me', ':about_me', $form['bio'] !== '' ? $form['bio'] : null);
                    } elseif ($hasNotes) {
                        $addField('notes', ':notes', $form['bio'] !== '' ? $form['bio'] : null);
                    } elseif ($hasWorkerBio) {
                        $addField('worker_bio', ':worker_bio', $form['bio'] !== '' ? $form['bio'] : null);
                    }

                    if ($hasCreatedAt) {
                        $addField('created_at', ':created_at', date('Y-m-d H:i:s'));
                    }

                    if (empty($insertColumns)) {
                        $error = 'No compatible user columns were found for worker creation.';
                    } else {
                        $quotedColumns = array();
                        foreach ($insertColumns as $column) {
                            $quotedColumns[] = ddAdminCreateWorkerQuoteIdentifier($column);
                        }

                        $sql = 'INSERT INTO ' . ddAdminCreateWorkerQuoteIdentifier($usersTable)
                            . ' (' . implode(', ', $quotedColumns) . ')'
                            . ' VALUES (' . implode(', ', $insertValues) . ')';

                        $stmt = $pdo->prepare($sql);

                        foreach ($params as $placeholder => $value) {
                            if (is_int($value)) {
                                $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
                            } elseif ($value === null) {
                                $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
                            } else {
                                $stmt->bindValue($placeholder, (string) $value, PDO::PARAM_STR);
                            }
                        }

                        $stmt->execute();

                        $newId = (int) $pdo->lastInsertId();

                        if ($newId > 0) {
                            ddAdminCreateWorkerRedirect('admin-worker-view.php?id=' . $newId);
                        }

                        $success = 'Worker account created successfully.';
                        $form = array(
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
                        );
                    }
                }
            } catch (Throwable $e) {
                $error = 'Could not create worker.';
            }
        }
    }
}

$csrfToken = ddAdminCreateWorkerCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Create Worker | Doggie Dorian’s</title>
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
                <a class="top-link" href="admin-nav.php">Admin Nav</a>
                <a class="top-link" href="admin-bookings.php">Bookings</a>
                <a class="top-link" href="admin-walker-management.php">Workers</a>
                <a class="top-link" href="admin-create-worker.php">Create Worker</a>
                <a class="top-link" href="logout.php">Logout</a>
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
                <div class="alert alert-success"><?php echo ddAdminCreateWorkerH($success); ?></div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?php echo ddAdminCreateWorkerH($error); ?></div>
            <?php endif; ?>

            <form method="post" action="">
                <input type="hidden" name="csrf_token" value="<?php echo ddAdminCreateWorkerH($csrfToken); ?>">

                <div class="form-grid">
                    <div class="field">
                        <label for="full_name">Full Name</label>
                        <input id="full_name" name="full_name" type="text" value="<?php echo ddAdminCreateWorkerH($form['full_name']); ?>" placeholder="Worker full name">
                    </div>

                    <div class="field">
                        <label for="username">Username</label>
                        <input id="username" name="username" type="text" value="<?php echo ddAdminCreateWorkerH($form['username']); ?>" placeholder="worker username">
                    </div>

                    <div class="field">
                        <label for="first_name">First Name</label>
                        <input id="first_name" name="first_name" type="text" value="<?php echo ddAdminCreateWorkerH($form['first_name']); ?>" placeholder="First name">
                    </div>

                    <div class="field">
                        <label for="last_name">Last Name</label>
                        <input id="last_name" name="last_name" type="text" value="<?php echo ddAdminCreateWorkerH($form['last_name']); ?>" placeholder="Last name">
                    </div>

                    <div class="field">
                        <label for="email">Email</label>
                        <input id="email" name="email" type="email" value="<?php echo ddAdminCreateWorkerH($form['email']); ?>" placeholder="worker@email.com" required>
                    </div>

                    <div class="field">
                        <label for="phone">Phone</label>
                        <input id="phone" name="phone" type="text" value="<?php echo ddAdminCreateWorkerH($form['phone']); ?>" placeholder="Phone number">
                    </div>

                    <div class="field">
                        <label for="password">Password</label>
                        <input id="password" name="password" type="password" placeholder="Create password" required>
                    </div>

                    <div class="field">
                        <label for="role">Role</label>
                        <select id="role" name="role">
                            <option value="walker" <?php echo $form['role'] === 'walker' ? 'selected' : ''; ?>>Walker</option>
                            <option value="worker" <?php echo $form['role'] === 'worker' ? 'selected' : ''; ?>>Worker</option>
                            <option value="staff" <?php echo $form['role'] === 'staff' ? 'selected' : ''; ?>>Staff</option>
                            <option value="employee" <?php echo $form['role'] === 'employee' ? 'selected' : ''; ?>>Employee</option>
                        </select>
                    </div>

                    <div class="field">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="active" <?php echo $form['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="disabled" <?php echo $form['status'] === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                        </select>
                    </div>

                    <div class="field full">
                        <label for="availability">Availability</label>
                        <input id="availability" name="availability" type="text" value="<?php echo ddAdminCreateWorkerH($form['availability']); ?>" placeholder="Example: Mon-Fri mornings, weekends flexible">
                    </div>

                    <div class="field full">
                        <label for="bio">Bio / Notes</label>
                        <textarea id="bio" name="bio" placeholder="Add worker notes, specialties, experience, or admin bio..."><?php echo ddAdminCreateWorkerH($form['bio']); ?></textarea>
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