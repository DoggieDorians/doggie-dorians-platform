<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/admin-config.php';

$error = '';

if (!empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: admin-dashboard.php');
    exit;
}

if (!isset($_SESSION['admin_login_attempts'])) {
    $_SESSION['admin_login_attempts'] = 0;
}

if (!isset($_SESSION['admin_login_locked_until'])) {
    $_SESSION['admin_login_locked_until'] = 0;
}

$now = time();
$lockedUntil = (int) $_SESSION['admin_login_locked_until'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($lockedUntil > $now) {
        $remaining = $lockedUntil - $now;
        $error = 'Too many failed login attempts. Please wait ' . $remaining . ' seconds and try again.';
    } else {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            $error = 'Please enter both email and password.';
        } else {
            $emailMatches = hash_equals(strtolower($masterAdminEmail), strtolower($email));
            $passwordMatches = password_verify($password, $masterAdminPasswordHash);

            if ($emailMatches && $passwordMatches) {
                session_regenerate_id(true);

                $_SESSION['admin_logged_in'] = true;
                $_SESSION['user_role'] = 'admin';
                $_SESSION['admin_email'] = $masterAdminEmail;
                $_SESSION['admin_name'] = $masterAdminDisplayName;
                $_SESSION['admin_login_attempts'] = 0;
                $_SESSION['admin_login_locked_until'] = 0;

                header('Location: admin-dashboard.php');
                exit;
            } else {
                $_SESSION['admin_login_attempts']++;

                if ($_SESSION['admin_login_attempts'] >= 5) {
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

$prefillEmail = htmlspecialchars((string) ($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | Doggie Dorian's</title>
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
            background:rgba(255,100,100,0.10);
            border:1px solid rgba(255,100,100,0.25);
            color:#ffd5d5;
        }

        .helper{
            margin-top:16px;
            color:var(--muted);
            font-size:13px;
            line-height:1.6;
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
                operations, statuses, and the full premium client experience.
            </p>

            <div class="feature-list">
                <div class="feature">
                    <strong>Unified booking management</strong>
                    View and manage bookings in one premium control center.
                </div>
                <div class="feature">
                    <strong>Operational control</strong>
                    Review requests, update statuses, and keep the business organized.
                </div>
                <div class="feature">
                    <strong>Separate from client access</strong>
                    Keeps your customer-facing dashboard and admin system fully separated.
                </div>
            </div>
        </section>

        <section class="right">
            <h2 class="card-title">Admin Login</h2>
            <div class="card-sub">Enter your secure admin credentials to continue.</div>

            <?php if ($error !== ''): ?>
                <div class="message"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="post" action="admin-login.php" novalidate>
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