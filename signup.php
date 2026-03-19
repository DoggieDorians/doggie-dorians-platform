<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/data/config/db.php';

if (isset($_SESSION['user_id'])) {
    if (($_SESSION['role'] ?? 'member') === 'admin') {
        header('Location: admin.php');
    } else {
        header('Location: dashboard.php');
    }
    exit;
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1");
    $stmt->execute(['table' => $table]);
    return (bool) $stmt->fetchColumn();
}

function getColumns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query("PRAGMA table_info(" . $table . ")");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $columns = [];

        foreach ($rows as $row) {
            if (!empty($row['name'])) {
                $columns[] = (string) $row['name'];
            }
        }

        return $columns;
    } catch (Throwable $e) {
        return [];
    }
}

function hasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($fullName === '' || $email === '' || $password === '' || $confirmPassword === '') {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        try {
            if (!tableExists($pdo, 'users')) {
                throw new RuntimeException('The users table was not found.');
            }

            $userColumns = getColumns($pdo, 'users');

            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $checkStmt->execute([$email]);
            $existingUser = $checkStmt->fetch();

            if ($existingUser) {
                $error = 'An account with that email already exists.';
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $pdo->beginTransaction();

                $insertColumns = ['full_name', 'email', 'password_hash'];
                $insertValues = [$fullName, $email, $passwordHash];
                $placeholders = ['?', '?', '?'];

                if (hasColumn($userColumns, 'phone')) {
                    $insertColumns[] = 'phone';
                    $insertValues[] = $phone;
                    $placeholders[] = '?';
                }

                if (hasColumn($userColumns, 'role')) {
                    $insertColumns[] = 'role';
                    $insertValues[] = 'member';
                    $placeholders[] = '?';
                }

                if (hasColumn($userColumns, 'status')) {
                    $insertColumns[] = 'status';
                    $insertValues[] = 'active';
                    $placeholders[] = '?';
                }

                $sql = "
                    INSERT INTO users (" . implode(', ', $insertColumns) . ")
                    VALUES (" . implode(', ', $placeholders) . ")
                ";

                $userStmt = $pdo->prepare($sql);
                $userStmt->execute($insertValues);

                $userId = (int)$pdo->lastInsertId();

                if (tableExists($pdo, 'client_profiles')) {
                    $profileColumns = getColumns($pdo, 'client_profiles');

                    if (hasColumn($profileColumns, 'user_id')) {
                        $profileStmt = $pdo->prepare("
                            INSERT INTO client_profiles (user_id)
                            VALUES (?)
                        ");
                        $profileStmt->execute([$userId]);
                    }
                }

                $pdo->commit();

                $success = 'Account created successfully. You can now log in.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Something went wrong: ' . $e->getMessage();
        }
    }
}

$oldFullName = htmlspecialchars((string)($_POST['full_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$oldEmail = htmlspecialchars((string)($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8');
$oldPhone = htmlspecialchars((string)($_POST['phone'] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up | Doggie Dorian's</title>
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
            --danger:#ff8a8a;
            --success:#9fe0b1;
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
        }

        .message.error{
            background:rgba(255,100,100,0.10);
            border:1px solid rgba(255,100,100,0.25);
            color:#ffd5d5;
        }

        .message.success{
            background:rgba(120,255,160,0.10);
            border:1px solid rgba(120,255,160,0.22);
            color:#d7ffe3;
        }

        .helper{
            margin-top:16px;
            color:var(--muted);
            font-size:13px;
            line-height:1.6;
            text-align:center;
        }

        .helper a{
            color:var(--gold-soft);
            text-decoration:none;
            font-weight:700;
        }

        @media (max-width: 920px){
            .wrap{ grid-template-columns:1fr; }
            .left{ border-right:none; border-bottom:1px solid var(--border); }
        }

        @media (max-width: 640px){
            .left,.right{ padding:32px 22px; }
            h1{ font-size:36px; }
            .card-title{ font-size:26px; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <section class="left">
            <div class="eyebrow">Doggie Dorian’s Membership</div>
            <h1>Create your premium pet care account.</h1>
            <p>
                Join Doggie Dorian’s and start building your luxury pet care profile.
            </p>

            <div class="feature-list">
                <div class="feature">
                    <strong>Faster future bookings</strong>
                    Save your information and make future bookings easier to manage.
                </div>
                <div class="feature">
                    <strong>Premium client experience</strong>
                    Keep your profile ready for services, updates, and future growth.
                </div>
                <div class="feature">
                    <strong>Built for your dog’s care journey</strong>
                    Your account becomes the foundation for bookings, memberships, and more.
                </div>
            </div>
        </section>

        <section class="right">
            <h2 class="card-title">Create Your Account</h2>
            <div class="card-sub">Join Doggie Dorian’s and get started today.</div>

            <?php if ($error !== ''): ?>
                <div class="message error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="message success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="POST" action="signup.php">
                <div class="field">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name" value="<?php echo $oldFullName; ?>" required>
                </div>

                <div class="field">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" value="<?php echo $oldEmail; ?>" required>
                </div>

                <div class="field">
                    <label for="phone">Phone Number</label>
                    <input type="text" id="phone" name="phone" value="<?php echo $oldPhone; ?>">
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>

                <div class="field">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>

                <button class="btn" type="submit">Create Account</button>
            </form>

            <div class="helper">
                Already have an account? <a href="login.php">Log in</a>
            </div>
        </section>
    </div>
</body>
</html>