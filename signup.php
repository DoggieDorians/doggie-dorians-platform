<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
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
        return $cache[$table] = (bool)$stmt->fetchColumn();
    } catch (Throwable) {
        return $cache[$table] = false;
    }
}

function getTableColumns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!tableExists($pdo, $table)) {
        return $cache[$table] = [];
    }

    try {
        $stmt = $pdo->query('PRAGMA table_info("' . $table . '")');
        $columns = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[] = $row['name'];
        }

        return $cache[$table] = $columns;
    } catch (Throwable) {
        return $cache[$table] = [];
    }
}

function hasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function currentUserId(): int
{
    foreach (['user_id', 'id'] as $key) {
        if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) {
            return (int)$_SESSION[$key];
        }
    }
    return 0;
}

if (currentUserId() > 0) {
    redirectTo('dashboard.php');
}

$error = '';
$form = [
    'first_name' => '',
    'last_name'  => '',
    'email'      => '',
    'phone'      => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['first_name'] = trim($_POST['first_name'] ?? '');
    $form['last_name']  = trim($_POST['last_name'] ?? '');
    $form['email']      = trim($_POST['email'] ?? '');
    $form['phone']      = trim($_POST['phone'] ?? '');

    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';
    $agreeTos = isset($_POST['agree_tos']);

    if (!$form['first_name'] || !$form['last_name'] || !$form['email'] || !$password || !$confirm) {
        $error = 'Please complete all required fields.';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (!$agreeTos) {
        $error = 'You must accept Terms.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $form['email']]);

            if ($stmt->fetch()) {
                throw new Exception('Email already exists.');
            }

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $fullName = $form['first_name'] . ' ' . $form['last_name'];

            $pdo->prepare("
                INSERT INTO users (first_name, last_name, full_name, email, phone, password, role, created_at)
                VALUES (:first, :last, :full, :email, :phone, :password, 'member', datetime('now'))
            ")->execute([
                ':first' => $form['first_name'],
                ':last' => $form['last_name'],
                ':full' => $fullName,
                ':email' => $form['email'],
                ':phone' => $form['phone'],
                ':password' => $passwordHash,
            ]);

            $userId = (int)$pdo->lastInsertId();

            if (tableExists($pdo, 'members')) {
                $pdo->prepare("
                    INSERT INTO members (user_id, full_name, email, phone, created_at)
                    VALUES (:uid, :name, :email, :phone, datetime('now'))
                ")->execute([
                    ':uid' => $userId,
                    ':name' => $fullName,
                    ':email' => $form['email'],
                    ':phone' => $form['phone'],
                ]);

                $_SESSION['member_id'] = (int)$pdo->lastInsertId();
            }

            $pdo->commit();

            session_regenerate_id(true);

            $_SESSION['user_id'] = $userId;
            $_SESSION['id'] = $userId;
            $_SESSION['email'] = $form['email'];
            $_SESSION['full_name'] = $fullName;
            $_SESSION['role'] = 'member';

            redirectTo('dashboard.php');

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up | Doggie Dorian’s</title>
    <style>
        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            font-family: Georgia, "Times New Roman", serif;
            background:
                radial-gradient(circle at top, rgba(212, 175, 55, 0.14), transparent 35%),
                linear-gradient(180deg, #05060a 0%, #090b12 45%, #04050a 100%);
            color: #f4f1ea;
        }

        .signup-page {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .topbar {
            width: 100%;
            padding: 20px 22px 0;
        }

        .topbar-inner {
            max-width: 1120px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .brand {
            color: #f4f1ea;
            text-decoration: none;
            font-size: 1.1rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .brand span {
            color: #d4af37;
        }

        .top-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .top-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: #f4f1ea;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.04);
            padding: 10px 14px;
            border-radius: 999px;
            font-size: 0.95rem;
            transition: 0.2s ease;
        }

        .top-link:hover {
            background: rgba(255,255,255,0.08);
        }

        .signup-main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 34px 18px 72px;
        }

        .signup-shell {
            width: 100%;
            max-width: 1120px;
            display: grid;
            grid-template-columns: 1.05fr 0.95fr;
            gap: 28px;
        }

        .hero-card,
        .form-card {
            position: relative;
            overflow: hidden;
            background: linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.03));
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 28px;
            padding: 34px 28px 30px;
            box-shadow: 0 24px 70px rgba(0,0,0,0.40);
            backdrop-filter: blur(8px);
        }

        .hero-card::before,
        .form-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(212,175,55,0.10), transparent 35%);
            pointer-events: none;
        }

        .eyebrow {
            position: relative;
            z-index: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 14px;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 18px;
            background: rgba(212,175,55,0.14);
            color: #f2d471;
            border: 1px solid rgba(212,175,55,0.25);
        }

        .hero-title,
        .form-title {
            position: relative;
            z-index: 1;
            margin: 0 0 12px;
            font-size: 2.35rem;
            line-height: 1.08;
            color: #fff;
        }

        .hero-text,
        .form-text {
            position: relative;
            z-index: 1;
            margin: 0;
            color: rgba(244,241,234,0.78);
            line-height: 1.7;
            font-size: 1.02rem;
        }

        .benefits {
            position: relative;
            z-index: 1;
            margin-top: 24px;
            display: grid;
            gap: 14px;
        }

        .benefit {
            padding: 18px 20px;
            border-radius: 18px;
            background: rgba(255,255,255,0.035);
            border: 1px solid rgba(255,255,255,0.07);
        }

        .benefit-title {
            margin: 0 0 6px;
            font-size: 1rem;
            color: #fff;
            font-weight: 700;
        }

        .benefit-text {
            margin: 0;
            color: rgba(244,241,234,0.72);
            line-height: 1.65;
            font-size: 0.98rem;
        }

        .error-box {
            position: relative;
            z-index: 1;
            margin-bottom: 18px;
            padding: 14px 16px;
            border-radius: 16px;
            background: rgba(255,92,92,0.08);
            border: 1px solid rgba(255,92,92,0.18);
            color: #ffd2d2;
            line-height: 1.6;
        }

        .form-grid {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-top: 24px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        label {
            font-size: 0.92rem;
            color: rgba(244,241,234,0.90);
            font-weight: 700;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="tel"] {
            width: 100%;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.05);
            color: #fff;
            border-radius: 16px;
            padding: 14px 16px;
            font-size: 1rem;
            outline: none;
        }

        input::placeholder {
            color: rgba(244,241,234,0.45);
        }

        input:focus {
            border-color: rgba(212,175,55,0.45);
            box-shadow: 0 0 0 3px rgba(212,175,55,0.10);
        }

        .checkbox-row {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-top: 20px;
            color: rgba(244,241,234,0.80);
            line-height: 1.6;
            font-size: 0.95rem;
        }

        .checkbox-row input {
            margin-top: 4px;
        }

        .checkbox-row a {
            color: #f2d471;
            text-decoration: none;
        }

        .checkbox-row a:hover {
            text-decoration: underline;
        }

        .form-actions {
            position: relative;
            z-index: 1;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 26px;
        }

        .btn-primary,
        .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 180px;
            padding: 14px 20px;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 700;
            transition: 0.2s ease;
            cursor: pointer;
            border: none;
            font-family: inherit;
            font-size: 1rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #e2c48d, #b9975b);
            color: #0b0b10;
            box-shadow: 0 10px 24px rgba(185,151,91,0.22);
        }

        .btn-primary:hover {
            filter: brightness(1.04);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: rgba(255,255,255,0.06);
            color: #ffffff;
            border: 1px solid rgba(255,255,255,0.08);
        }

        .btn-secondary:hover {
            background: rgba(255,255,255,0.10);
        }

        .signin-row {
            position: relative;
            z-index: 1;
            margin-top: 22px;
            color: rgba(244,241,234,0.72);
            font-size: 0.96rem;
        }

        .signin-row a {
            color: #f2d471;
            text-decoration: none;
            font-weight: 700;
        }

        .signin-row a:hover {
            text-decoration: underline;
        }

        @media (max-width: 900px) {
            .signup-shell {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 680px) {
            .topbar {
                padding: 16px 14px 0;
            }

            .topbar-inner {
                flex-direction: column;
                align-items: stretch;
            }

            .brand {
                text-align: center;
            }

            .top-actions {
                justify-content: center;
            }

            .signup-main {
                align-items: flex-start;
                padding: 24px 14px 56px;
            }

            .hero-card,
            .form-card {
                padding: 26px 18px 24px;
                border-radius: 22px;
            }

            .hero-title,
            .form-title {
                font-size: 1.95rem;
                text-align: center;
            }

            .hero-text,
            .form-text {
                text-align: center;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .btn-primary,
            .btn-secondary {
                width: 100%;
                min-width: 0;
            }
        }
    </style>
</head>
<body>
    <div class="signup-page">
        <div class="topbar">
            <div class="topbar-inner">
                <a href="index.php" class="brand">Doggie <span>Dorian’s</span></a>

                <div class="top-actions">
                    <a href="index.php" class="top-link">Home</a>
                    <a href="memberships.php" class="top-link">Memberships</a>
                    <a href="contact.php" class="top-link">Contact</a>
                </div>
            </div>
        </div>

        <main class="signup-main">
            <div class="signup-shell">
                <section class="hero-card">
                    <div class="eyebrow">Member Access</div>
                    <h1 class="hero-title">Create your Doggie Dorian’s account</h1>
                    <p class="hero-text">
                        Set up your member profile to manage bookings, view service activity, and access membership features from your dashboard.
                    </p>

                    <div class="benefits">
                        <div class="benefit">
                            <h3 class="benefit-title">Private dashboard access</h3>
                            <p class="benefit-text">Track your account, review activity, and manage your services in one place.</p>
                        </div>

                        <div class="benefit">
                            <h3 class="benefit-title">Luxury client experience</h3>
                            <p class="benefit-text">Enjoy a polished booking flow designed around premium care and clear member visibility.</p>
                        </div>

                        <div class="benefit">
                            <h3 class="benefit-title">Ready for membership upgrades</h3>
                            <p class="benefit-text">Once your account is active, you can move into your preferred membership and Stripe checkout flow.</p>
                        </div>
                    </div>
                </section>

                <section class="form-card">
                    <div class="eyebrow">Sign Up</div>
                    <h2 class="form-title">Start your account</h2>
                    <p class="form-text">
                        Complete the form below to create your account and continue into the member experience.
                    </p>

                    <?php if ($error !== ''): ?>
                        <div class="error-box"><?= h($error) ?></div>
                    <?php endif; ?>

                    <form method="post" action="signup.php" novalidate>
                        <div class="form-grid">
                            <div class="field">
                                <label for="first_name">First Name</label>
                                <input
                                    type="text"
                                    id="first_name"
                                    name="first_name"
                                    value="<?= h($form['first_name']) ?>"
                                    autocomplete="given-name"
                                    required
                                >
                            </div>

                            <div class="field">
                                <label for="last_name">Last Name</label>
                                <input
                                    type="text"
                                    id="last_name"
                                    name="last_name"
                                    value="<?= h($form['last_name']) ?>"
                                    autocomplete="family-name"
                                    required
                                >
                            </div>

                            <div class="field full">
                                <label for="email">Email</label>
                                <input
                                    type="email"
                                    id="email"
                                    name="email"
                                    value="<?= h($form['email']) ?>"
                                    autocomplete="email"
                                    required
                                >
                            </div>

                            <div class="field full">
                                <label for="phone">Phone</label>
                                <input
                                    type="tel"
                                    id="phone"
                                    name="phone"
                                    value="<?= h($form['phone']) ?>"
                                    autocomplete="tel"
                                >
                            </div>

                            <div class="field">
                                <label for="password">Password</label>
                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    autocomplete="new-password"
                                    required
                                >
                            </div>

                            <div class="field">
                                <label for="confirm_password">Confirm Password</label>
                                <input
                                    type="password"
                                    id="confirm_password"
                                    name="confirm_password"
                                    autocomplete="new-password"
                                    required
                                >
                            </div>
                        </div>

                        <label class="checkbox-row">
                            <input type="checkbox" name="agree_tos" value="1" <?= isset($_POST['agree_tos']) ? 'checked' : '' ?>>
                            <span>
                                I accept the <a href="tos.php">Terms of Service</a> and understand account creation is required to access member features.
                            </span>
                        </label>

                        <div class="form-actions">
                            <button type="submit" class="btn-primary">Create Account</button>
                            <a href="index.php" class="btn-secondary">Return Home</a>
                        </div>
                    </form>

                    <div class="signin-row">
                        Already have an account? <a href="login.php">Sign in here</a>
                    </div>
                </section>
            </div>
        </main>
    </div>
</body>
</html>