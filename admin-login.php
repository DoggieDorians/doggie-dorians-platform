<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/admin-config.php';

function dd_admin_login_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function dd_admin_login_redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function dd_admin_login_clear_non_admin_session_keys(): void
{
    unset(
        $_SESSION['walker_id'],
        $_SESSION['staff_id'],
        $_SESSION['employee_id'],
        $_SESSION['worker_id'],
        $_SESSION['member_id'],
        $_SESSION['client_id']
    );
}

function dd_admin_login_set_session(string $email, string $name): void
{
    dd_admin_login_clear_non_admin_session_keys();

    $_SESSION['admin_logged_in'] = true;
    $_SESSION['is_admin'] = true;
    $_SESSION['role'] = 'admin';
    $_SESSION['user_role'] = 'admin';
    $_SESSION['admin_id'] = 1;
    $_SESSION['user_id'] = 1;
    $_SESSION['admin_email'] = $email;
    $_SESSION['admin_name'] = $name;

    $_SESSION['admin'] = array(
        'id' => 1,
        'email' => $email,
        'name' => $name,
        'role' => 'admin',
        'logged_in' => true,
        'is_admin' => true,
    );

    $_SESSION['admin_login_attempts'] = 0;
    $_SESSION['admin_login_locked_until'] = 0;
}

function dd_admin_login_is_admin(): bool
{
    $role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    $userRole = strtolower(trim((string) ($_SESSION['user_role'] ?? '')));

    return (
        (!empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true)
        || (!empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true)
        || $role === 'admin'
        || $userRole === 'admin'
        || !empty($_SESSION['admin_id'])
        || (
            isset($_SESSION['admin'])
            && is_array($_SESSION['admin'])
            && (
                (!empty($_SESSION['admin']['logged_in']) && $_SESSION['admin']['logged_in'] === true)
                || (!empty($_SESSION['admin']['is_admin']) && $_SESSION['admin']['is_admin'] === true)
                || strtolower(trim((string) ($_SESSION['admin']['role'] ?? ''))) === 'admin'
            )
        )
    );
}

function dd_admin_login_csrf_token(): string
{
    if (empty($_SESSION['admin_login_csrf']) || !is_string($_SESSION['admin_login_csrf'])) {
        $_SESSION['admin_login_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['admin_login_csrf'];
}

function dd_admin_login_validate_csrf(?string $submittedToken): bool
{
    $sessionToken = $_SESSION['admin_login_csrf'] ?? '';

    if (!is_string($sessionToken) || $sessionToken === '' || $submittedToken === null || $submittedToken === '') {
        return false;
    }

    return hash_equals($sessionToken, $submittedToken);
}

$error = '';
$email = trim((string) ($_POST['email'] ?? ''));

if (dd_admin_login_is_admin()) {
    $existingEmail = trim((string) ($_SESSION['admin_email'] ?? ($masterAdminEmail ?? '')));
    $existingName = trim((string) ($_SESSION['admin_name'] ?? ($masterAdminDisplayName ?? 'Doggie Dorian’s Admin')));

    if ($existingEmail === '') {
        $existingEmail = trim((string) ($masterAdminEmail ?? ''));
    }

    if ($existingName === '') {
        $existingName = trim((string) ($masterAdminDisplayName ?? 'Doggie Dorian’s Admin'));
    }

    dd_admin_login_set_session($existingEmail, $existingName);
    dd_admin_login_redirect('admin-dashboard.php');
}

if (!isset($_SESSION['admin_login_attempts']) || !is_numeric($_SESSION['admin_login_attempts'])) {
    $_SESSION['admin_login_attempts'] = 0;
}

if (!isset($_SESSION['admin_login_locked_until']) || !is_numeric($_SESSION['admin_login_locked_until'])) {
    $_SESSION['admin_login_locked_until'] = 0;
}

$now = time();
$lockedUntil = (int) $_SESSION['admin_login_locked_until'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dd_admin_login_validate_csrf(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
        $error = 'Security check failed. Please refresh the page and try again.';
    } elseif ($lockedUntil > $now) {
        $remaining = $lockedUntil - $now;
        $error = 'Too many failed login attempts. Please wait ' . $remaining . ' seconds and try again.';
    } else {
        $password = (string) ($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            $error = 'Please enter both email and password.';
        } else {
            $configuredEmail = trim((string) ($masterAdminEmail ?? ''));
            $configuredHash = trim((string) ($masterAdminPasswordHash ?? ''));
            $configuredName = trim((string) ($masterAdminDisplayName ?? 'Doggie Dorian’s Admin'));

            if ($configuredEmail === '' || $configuredHash === '') {
                $error = 'Admin login is not configured correctly in admin-config.php.';
            } else {
                $emailMatches = hash_equals(strtolower($configuredEmail), strtolower($email));
                $passwordMatches = password_verify($password, $configuredHash);

                if ($emailMatches && $passwordMatches) {
                    session_regenerate_id(true);
                    dd_admin_login_set_session($configuredEmail, $configuredName);
                    dd_admin_login_redirect('admin-dashboard.php');
                } else {
                    $_SESSION['admin_login_attempts'] = (int) $_SESSION['admin_login_attempts'] + 1;

                    if ((int) $_SESSION['admin_login_attempts'] >= 5) {
                        $_SESSION['admin_login_locked_until'] = time() + 300;
                        $_SESSION['admin_login_attempts'] = 0;
                        $error = 'Too many failed login attempts. Please wait 5 minutes and try again.';
                    } else {
                        $remainingAttempts = 5 - (int) $_SESSION['admin_login_attempts'];
                        $error = 'Invalid admin login credentials. ' . $remainingAttempts . ' attempt(s) remaining.';
                    }
                }
            }
        }
    }
}

$prefillEmail = dd_admin_login_h($email);
$csrfToken = dd_admin_login_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | Doggie Dorian’s</title>
    <meta name="description" content="Secure admin login for Doggie Dorian’s operations dashboard.">
    <style>
        :root{
            --bg:#0b0b10;
            --panel:rgba(255,255,255,0.06);
            --panel-2:rgba(255,255,255,0.04);
            --border:rgba(212,175,55,0.22);
            --gold:#d4af37;
            --gold-soft:#f0de9e;
            --text:#f8f5ee;
            --muted:#b9b3a6;
            --shadow:0 20px 60px rgba(0,0,0,0.35);
            --danger-bg:rgba(255,100,100,0.10);
            --danger-border:rgba(255,100,100,0.25);
            --danger-text:#ffd5d5;
        }

        *{box-sizing:border-box}

        body{
            margin:0;
            min-height:100vh;
            font-family:Inter, Arial, Helvetica, sans-serif;
            color:var(--text);
            background:
                radial-gradient(circle at top left, rgba(212,175,55,0.16), transparent 28%),
                radial-gradient(circle at bottom right, rgba(255,255,255,0.05), transparent 25%),
                linear-gradient(180deg, #08080c 0%, #111119 100%);
            display:flex;
            align-items:center;
            justify-content:center;
            padding:24px;
        }

        .wrap{
            width:100%;
            max-width:1100px;
            display:grid;
            grid-template-columns:1.05fr .95fr;
            overflow:hidden;
            border-radius:28px;
            border:1px solid var(--border);
            background:rgba(255,255,255,0.03);
            box-shadow:var(--shadow);
            backdrop-filter:blur(10px);
        }

        .left{
            padding:52px 42px;
            background:linear-gradient(180deg, rgba(212,175,55,0.10), rgba(255,255,255,0.01));
            border-right:1px solid var(--border);
        }

        .right{
            padding:52px 42px;
        }

        .eyebrow{
            display:inline-block;
            padding:10px 14px;
            border-radius:999px;
            border:1px solid var(--border);
            color:var(--gold-soft);
            background:rgba(212,175,55,0.08);
            text-transform:uppercase;
            font-size:12px;
            font-weight:800;
            letter-spacing:1px;
            margin-bottom:18px;
        }

        h1{
            margin:0 0 14px;
            font-size:46px;
            line-height:0.95;
            letter-spacing:-1.5px;
        }

        p{
            margin:0 0 14px;
            color:var(--muted);
            line-height:1.7;
            font-size:15px;
        }

        .feature-list{
            margin-top:26px;
            display:grid;
            gap:14px;
        }

        .feature{
            padding:16px 18px;
            border-radius:18px;
            background:var(--panel-2);
            border:1px solid rgba(255,255,255,0.06);
        }

        .feature strong{
            display:block;
            margin-bottom:6px;
            font-size:15px;
        }

        .card-title{
            margin:0 0 8px;
            font-size:30px;
            letter-spacing:-0.8px;
        }

        .card-sub{
            color:var(--muted);
            margin-bottom:24px;
            font-size:14px;
        }

        .field{
            margin-bottom:16px;
        }

        label{
            display:block;
            margin-bottom:8px;
            color:var(--gold-soft);
            font-size:12px;
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:1px;
        }

        input{
            width:100%;
            padding:15px 16px;
            border-radius:16px;
            border:1px solid rgba(255,255,255,0.10);
            background:rgba(255,255,255,0.05);
            color:var(--text);
            outline:none;
            font-size:15px;
        }

        input:focus{
            border-color:rgba(212,175,55,0.45);
            box-shadow:0 0 0 4px rgba(212,175,55,0.08);
        }

        .btn{
            width:100%;
            border:none;
            cursor:pointer;
            padding:16px 18px;
            border-radius:16px;
            font-weight:800;
            font-size:15px;
            color:#111;
            background:linear-gradient(180deg, #f0d77a, var(--gold));
            box-shadow:var(--shadow);
        }

        .message{
            margin-bottom:18px;
            padding:14px 16px;
            border-radius:16px;
            font-weight:700;
            background:var(--danger-bg);
            border:1px solid var(--danger-border);
            color:var(--danger-text);
        }

        .helper{
            margin-top:16px;
            color:var(--muted);
            font-size:13px;
            line-height:1.6;
        }

        .helper strong{
            color:var(--text);
        }

        @media (max-width: 920px){
            .wrap{grid-template-columns:1fr;}
            .left{border-right:none;border-bottom:1px solid var(--border);}
        }

        @media (max-width: 640px){
            .left,.right{padding:32px 22px;}
            h1{font-size:36px;}
            .card-title{font-size:26px;}
        }
    </style>
</head>
<body>
    <div class="wrap">
        <section class="left">
            <div class="eyebrow">Doggie Dorian’s Admin</div>
            <h1>Luxury control for a premium pet brand.</h1>
            <p>
                This login is for administrative access only. Use it to manage bookings,
                operations, statuses, notifications, and the full premium client experience.
            </p>

            <div class="feature-list">
                <div class="feature">
                    <strong>Unified booking management</strong>
                    Review operational flow across member, walker, tracking, and admin systems.
                </div>
                <div class="feature">
                    <strong>Premium operational control</strong>
                    Manage assignments, status changes, service transitions, and oversight.
                </div>
                <div class="feature">
                    <strong>Separate from client access</strong>
                    Keeps your customer-facing dashboard and admin system clearly separated.
                </div>
            </div>
        </section>

        <section class="right">
            <h2 class="card-title">Admin Login</h2>
            <div class="card-sub">Enter your secure admin credentials to continue.</div>

            <?php if ($error !== ''): ?>
                <div class="message"><?php echo dd_admin_login_h($error); ?></div>
            <?php endif; ?>

            <form method="post" action="admin-login.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo dd_admin_login_h($csrfToken); ?>">

                <div class="field">
                    <label for="email">Admin Email</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="admin@doggiedorians.com"
                        value="<?php echo $prefillEmail; ?>"
                        required
                        autocomplete="username"
                    >
                </div>

                <div class="field">
                    <label for="password">Admin Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        required
                        autocomplete="current-password"
                    >
                </div>

                <button class="btn" type="submit">Enter Admin Dashboard</button>
            </form>

            <div class="helper">
                This page uses a hashed admin password stored in <strong>admin-config.php</strong>.
            </div>
        </section>
    </div>
</body>
</html>