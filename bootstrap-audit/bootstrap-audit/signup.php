<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security-headers.php';

session_start();
require_once __DIR__ . '/db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection is not available.');
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirectTo(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function tableExists(PDO $pdo, string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :name LIMIT 1");
        $stmt->execute([':name' => $table]);
        $cache[$table] = (bool)$stmt->fetchColumn();
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = false;
        return false;
    }
}

function getTableColumns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!tableExists($pdo, $table)) {
        $cache[$table] = [];
        return [];
    }

    try {
        $safeTable = str_replace('"', '""', $table);
        $stmt = $pdo->query('PRAGMA table_info("' . $safeTable . '")');
        $columns = [];

        if ($stmt) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($row['name'])) {
                    $columns[] = (string)$row['name'];
                }
            }
        }

        $cache[$table] = $columns;
        return $columns;
    } catch (Throwable $e) {
        $cache[$table] = [];
        return [];
    }
}

function hasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function buildInsert(PDO $pdo, string $table, array $data): PDOStatement
{
    $fields = array_keys($data);
    $placeholders = array_map(static fn(string $field): string => ':' . $field, $fields);

    $sql = sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        $table,
        implode(', ', $fields),
        implode(', ', $placeholders)
    );

    return $pdo->prepare($sql);
}

function currentLoggedInUserId(): int
{
    foreach (['user_id', 'member_id', 'id'] as $key) {
        if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) {
            return (int)$_SESSION[$key];
        }
    }
    return 0;
}

if (currentLoggedInUserId() > 0) {
    redirectTo('dashboard.php');
}

$error = '';
$success = '';

$form = [
    'first_name' => '',
    'last_name'  => '',
    'email'      => '',
    'phone'      => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['first_name'] = trim((string)($_POST['first_name'] ?? ''));
    $form['last_name']  = trim((string)($_POST['last_name'] ?? ''));
    $form['email']      = trim((string)($_POST['email'] ?? ''));
    $form['phone']      = trim((string)($_POST['phone'] ?? ''));
    $password           = (string)($_POST['password'] ?? '');
    $confirmPassword    = (string)($_POST['confirm_password'] ?? '');
    $agreeTos           = isset($_POST['agree_tos']) && $_POST['agree_tos'] === '1';

    if ($form['first_name'] === '' || $form['last_name'] === '' || $form['email'] === '' || $password === '' || $confirmPassword === '') {
        $error = 'Please complete all required fields.';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (mb_strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (!$agreeTos) {
        $error = 'You must agree to the Terms of Service.';
    } else {
        try {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            if (!tableExists($pdo, 'users')) {
                throw new RuntimeException('The users table was not found. Please make sure your database is set up correctly.');
            }

            $userColumns = getTableColumns($pdo, 'users');

            $duplicateSql = 'SELECT * FROM users WHERE email = :email LIMIT 1';
            $duplicateStmt = $pdo->prepare($duplicateSql);
            $duplicateStmt->execute([':email' => $form['email']]);
            $existingUser = $duplicateStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingUser) {
                $error = 'An account with that email already exists. Please log in instead.';
            } else {
                $pdo->beginTransaction();

                $fullName = trim($form['first_name'] . ' ' . $form['last_name']);
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $userData = [];

                if (hasColumn($userColumns, 'first_name')) {
                    $userData['first_name'] = $form['first_name'];
                }

                if (hasColumn($userColumns, 'last_name')) {
                    $userData['last_name'] = $form['last_name'];
                }

                if (hasColumn($userColumns, 'name')) {
                    $userData['name'] = $fullName;
                }

                if (hasColumn($userColumns, 'full_name')) {
                    $userData['full_name'] = $fullName;
                }

                if (hasColumn($userColumns, 'email')) {
                    $userData['email'] = $form['email'];
                }

                if (hasColumn($userColumns, 'phone')) {
                    $userData['phone'] = $form['phone'];
                }

                if (hasColumn($userColumns, 'password')) {
                    $userData['password'] = $passwordHash;
                } elseif (hasColumn($userColumns, 'password_hash')) {
                    $userData['password_hash'] = $passwordHash;
                } else {
                    throw new RuntimeException('The users table needs a password or password_hash column.');
                }

                if (hasColumn($userColumns, 'role')) {
                    $userData['role'] = 'member';
                }

                if (hasColumn($userColumns, 'user_type')) {
                    $userData['user_type'] = 'member';
                }

                if (hasColumn($userColumns, 'status')) {
                    $userData['status'] = 'active';
                }

                if (hasColumn($userColumns, 'is_active')) {
                    $userData['is_active'] = 1;
                }

                if (hasColumn($userColumns, 'preferred_login')) {
                    $userData['preferred_login'] = 'email';
                }

                if (hasColumn($userColumns, 'tos_accepted_at')) {
                    $userData['tos_accepted_at'] = date('Y-m-d H:i:s');
                }

                if (hasColumn($userColumns, 'created_at')) {
                    $userData['created_at'] = date('Y-m-d H:i:s');
                }

                if (hasColumn($userColumns, 'updated_at')) {
                    $userData['updated_at'] = date('Y-m-d H:i:s');
                }

                if (empty($userData['email'])) {
                    throw new RuntimeException('The users table must include an email column.');
                }

                $insertUserStmt = buildInsert($pdo, 'users', $userData);
                $insertUserStmt->execute(array_combine(
                    array_map(static fn(string $field): string => ':' . $field, array_keys($userData)),
                    array_values($userData)
                ));

                $userId = (int)$pdo->lastInsertId();
                $memberId = 0;

                if (tableExists($pdo, 'members')) {
                    $memberColumns = getTableColumns($pdo, 'members');
                    $memberData = [];

                    if (hasColumn($memberColumns, 'user_id')) {
                        $memberData['user_id'] = $userId;
                    }

                    if (hasColumn($memberColumns, 'name')) {
                        $memberData['name'] = $fullName;
                    }

                    if (hasColumn($memberColumns, 'full_name')) {
                        $memberData['full_name'] = $fullName;
                    }

                    if (hasColumn($memberColumns, 'first_name')) {
                        $memberData['first_name'] = $form['first_name'];
                    }

                    if (hasColumn($memberColumns, 'last_name')) {
                        $memberData['last_name'] = $form['last_name'];
                    }

                    if (hasColumn($memberColumns, 'email')) {
                        $memberData['email'] = $form['email'];
                    }

                    if (hasColumn($memberColumns, 'phone')) {
                        $memberData['phone'] = $form['phone'];
                    }

                    if (hasColumn($memberColumns, 'status')) {
                        $memberData['status'] = 'active';
                    }

                    if (hasColumn($memberColumns, 'membership_status')) {
                        $memberData['membership_status'] = 'active';
                    }

                    if (hasColumn($memberColumns, 'preferred_login')) {
                        $memberData['preferred_login'] = 'email';
                    }

                    if (hasColumn($memberColumns, 'password_hash')) {
                        $memberData['password_hash'] = $passwordHash;
                    }

                    if (hasColumn($memberColumns, 'tos_accepted_at')) {
                        $memberData['tos_accepted_at'] = date('Y-m-d H:i:s');
                    }

                    if (hasColumn($memberColumns, 'created_at')) {
                        $memberData['created_at'] = date('Y-m-d H:i:s');
                    }

                    if (hasColumn($memberColumns, 'updated_at')) {
                        $memberData['updated_at'] = date('Y-m-d H:i:s');
                    }

                    if (!empty($memberData)) {
                        $insertMemberStmt = buildInsert($pdo, 'members', $memberData);
                        $insertMemberStmt->execute(array_combine(
                            array_map(static fn(string $field): string => ':' . $field, array_keys($memberData)),
                            array_values($memberData)
                        ));
                        $memberId = (int)$pdo->lastInsertId();
                    }
                }

                $pdo->commit();

                session_regenerate_id(true);
                $_SESSION['user_id'] = $userId;
                $_SESSION['id'] = $userId;
                $_SESSION['user_email'] = $form['email'];
                $_SESSION['email'] = $form['email'];
                $_SESSION['user_name'] = $fullName;
                $_SESSION['full_name'] = $fullName;
                $_SESSION['role'] = 'member';

                if ($memberId > 0) {
                    $_SESSION['member_id'] = $memberId;
                }

                header('Location: dashboard.php');
                exit;
            }
        } catch (Throwable $e) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = 'Unable to create account right now. ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account | Doggie Dorian’s</title>
    <meta name="description" content="Create your Doggie Dorian’s account for premium dog care booking, memberships, and dashboard access.">
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
            font-size: clamp(2.3rem, 5vw, 4.4rem);
            line-height: 1.02;
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

        .field-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
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

        .checkbox-wrap {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            border-radius: 18px;
            padding: 14px 16px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.08);
            margin: 8px 0 18px;
        }

        .checkbox-wrap input {
            width: 18px;
            height: 18px;
            margin-top: 3px;
            flex: 0 0 auto;
        }

        .checkbox-wrap span {
            color: rgba(244,241,234,0.78);
            font-size: .94rem;
            line-height: 1.6;
        }

        .checkbox-wrap a {
            color: #f0d59f;
            text-decoration: underline;
            text-underline-offset: 2px;
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

            .field-grid {
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
                <div class="eyebrow">Create Your Account</div>
                <h1>Join first. Choose memberships after.</h1>
                <p>
                    Create your Doggie Dorian’s account to unlock your dashboard, manage pets, book services,
                    and access membership options once you are signed in.
                </p>

                <div class="feature-grid">
                    <div class="feature">
                        <strong>One premium account hub</strong>
                        Keep your bookings, pet details, and membership activity connected in one place.
                    </div>
                    <div class="feature">
                        <strong>Memberships come after signup</strong>
                        Once your account is created, you can review founder memberships, regular options, and custom plans.
                    </div>
                    <div class="feature">
                        <strong>Built for returning access</strong>
                        Sign in anytime to manage your care, payments, and future bookings.
                    </div>
                </div>
            </section>

            <section class="card">
                <h2>Create Account</h2>

                <?php if ($error !== ''): ?>
                    <div class="message message-error"><?php echo h($error); ?></div>
                <?php endif; ?>

                <form method="post" action="signup.php" novalidate>
                    <div class="field-grid">
                        <div class="field">
                            <label for="first_name">First name</label>
                            <input
                                type="text"
                                id="first_name"
                                name="first_name"
                                value="<?php echo h($form['first_name']); ?>"
                                required
                                autocomplete="given-name"
                            >
                        </div>

                        <div class="field">
                            <label for="last_name">Last name</label>
                            <input
                                type="text"
                                id="last_name"
                                name="last_name"
                                value="<?php echo h($form['last_name']); ?>"
                                required
                                autocomplete="family-name"
                            >
                        </div>
                    </div>

                    <div class="field-grid">
                        <div class="field">
                            <label for="email">Email address</label>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                value="<?php echo h($form['email']); ?>"
                                required
                                autocomplete="email"
                            >
                        </div>

                        <div class="field">
                            <label for="phone">Phone number</label>
                            <input
                                type="text"
                                id="phone"
                                name="phone"
                                value="<?php echo h($form['phone']); ?>"
                                autocomplete="tel"
                            >
                        </div>
                    </div>

                    <div class="field-grid">
                        <div class="field">
                            <label for="password">Password</label>
                            <input
                                type="password"
                                id="password"
                                name="password"
                                required
                                autocomplete="new-password"
                            >
                        </div>

                        <div class="field">
                            <label for="confirm_password">Confirm password</label>
                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                required
                                autocomplete="new-password"
                            >
                        </div>
                    </div>

                    <label class="checkbox-wrap">
                        <input type="checkbox" name="agree_tos" value="1" required>
                        <span>
                            I agree to the <a href="tos.php" target="_blank">Terms of Service</a>.
                        </span>
                    </label>

                    <div class="actions">
                        <button class="btn btn-gold" type="submit">Create Account</button>
                        <a href="login.php" class="btn btn-light">Already have an account?</a>
                    </div>

                    <div class="helper">
                        After signup, you’ll be sent to your dashboard, where you can explore memberships and booking options.
                    </div>
                </form>
            </section>
        </div>
    </div>
</body>
</html>