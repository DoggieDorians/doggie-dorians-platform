<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function tableExists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare("
        SELECT name
        FROM sqlite_master
        WHERE type = 'table'
          AND name = :table_name
        LIMIT 1
    ");
    $stmt->execute([
        ':table_name' => $tableName,
    ]);

    return (bool) $stmt->fetchColumn();
}

function ensurePasswordResetsTable(PDO $pdo): void
{
    if (!tableExists($pdo, 'password_resets')) {
        $pdo->exec("
            CREATE TABLE password_resets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                email TEXT NOT NULL,
                token_hash TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                used_at TEXT DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_password_resets_user_id
            ON password_resets(user_id)
        ");

        $pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_password_resets_token_hash
            ON password_resets(token_hash)
        ");

        $pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_password_resets_expires_at
            ON password_resets(expires_at)
        ");

        return;
    }

    $columnsStmt = $pdo->query("PRAGMA table_info(password_resets)");
    $columns = $columnsStmt ? $columnsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $existing = [];

    foreach ($columns as $column) {
        if (isset($column['name'])) {
            $existing[] = (string) $column['name'];
        }
    }

    if (!in_array('used_at', $existing, true)) {
        $pdo->exec("ALTER TABLE password_resets ADD COLUMN used_at TEXT DEFAULT NULL");
    }

    if (!in_array('created_at', $existing, true)) {
        $pdo->exec("ALTER TABLE password_resets ADD COLUMN created_at TEXT DEFAULT CURRENT_TIMESTAMP");
    }

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_password_resets_user_id
        ON password_resets(user_id)
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_password_resets_token_hash
        ON password_resets(token_hash)
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_password_resets_expires_at
        ON password_resets(expires_at)
    ");
}

$error = '';
$success = '';
$email = '';
$resetLink = '';

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not available.');
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    ensurePasswordResetsTable($pdo);
} catch (Throwable $e) {
    die('Database connection error.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));

    if ($email === '') {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            $cleanupStmt = $pdo->prepare("
                DELETE FROM password_resets
                WHERE used_at IS NOT NULL
                   OR datetime(expires_at) < datetime('now')
            ");
            $cleanupStmt->execute();

            $userStmt = $pdo->prepare("
                SELECT id, email
                FROM users
                WHERE lower(email) = lower(:email)
                LIMIT 1
            ");
            $userStmt->execute([
                ':email' => $email,
            ]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $deleteOldStmt = $pdo->prepare("
                    DELETE FROM password_resets
                    WHERE user_id = :user_id
                ");
                $deleteOldStmt->execute([
                    ':user_id' => (int) $user['id'],
                ]);

                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);
                $expiresAt = gmdate('Y-m-d H:i:s', time() + 3600);

                $insertStmt = $pdo->prepare("
                    INSERT INTO password_resets (user_id, email, token_hash, expires_at)
                    VALUES (:user_id, :email, :token_hash, :expires_at)
                ");
                $insertStmt->execute([
                    ':user_id' => (int) $user['id'],
                    ':email' => (string) $user['email'],
                    ':token_hash' => $tokenHash,
                    ':expires_at' => $expiresAt,
                ]);

                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '/')), '/');
                $resetPath = ($basePath === '' ? '' : $basePath) . '/reset-password.php';
                $resetLink = $scheme . '://' . $host . $resetPath . '?token=' . urlencode($token);
            }

            $success = 'If that email exists in our system, a password reset link has been created.';
        } catch (Throwable $e) {
            $error = 'Something went wrong while processing your request. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | Doggie Dorian's</title>
    <meta name="description" content="Request a password reset for your Doggie Dorian's account.">
    <style>
        * {
            box-sizing: border-box;
        }

        :root {
            --bg: #0c0f14;
            --panel: #131923;
            --panel-2: #101620;
            --border: rgba(255, 255, 255, 0.08);
            --text: #f5f7fb;
            --muted: #b7c0d1;
            --gold: #d6b36a;
            --gold-soft: rgba(214, 179, 106, 0.16);
            --danger: #ff7d7d;
            --danger-bg: rgba(255, 125, 125, 0.12);
            --success: #9be7b3;
            --success-bg: rgba(90, 201, 129, 0.12);
            --input: #0d131c;
            --shadow: 0 24px 70px rgba(0, 0, 0, 0.35);
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Arial, Helvetica, sans-serif;
            background:
                radial-gradient(circle at top, rgba(214, 179, 106, 0.08), transparent 28%),
                linear-gradient(180deg, #0a0d12 0%, #0f141c 100%);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 18px;
        }

        .wrap {
            width: 100%;
            max-width: 560px;
        }

        .card {
            background: linear-gradient(180deg, rgba(19, 25, 35, 0.98), rgba(14, 19, 28, 0.98));
            border: 1px solid var(--border);
            border-radius: 24px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .top {
            padding: 32px 32px 18px;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(180deg, rgba(214, 179, 106, 0.08), rgba(214, 179, 106, 0.02));
        }

        .brand {
            margin: 0 0 10px;
            font-size: 14px;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--gold);
            font-weight: 700;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 30px;
            line-height: 1.15;
        }

        .subtitle {
            margin: 0;
            color: var(--muted);
            line-height: 1.6;
            font-size: 15px;
        }

        .body {
            padding: 28px 32px 32px;
        }

        .alert {
            border-radius: 16px;
            padding: 14px 16px;
            margin-bottom: 18px;
            line-height: 1.55;
            font-size: 14px;
        }

        .alert.error {
            background: var(--danger-bg);
            border: 1px solid rgba(255, 125, 125, 0.26);
            color: #ffd8d8;
        }

        .alert.success {
            background: var(--success-bg);
            border: 1px solid rgba(90, 201, 129, 0.26);
            color: #ddffe8;
        }

        .field {
            margin-bottom: 18px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 700;
            color: #edf2fa;
        }

        input[type="email"] {
            width: 100%;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: var(--input);
            color: var(--text);
            border-radius: 14px;
            padding: 15px 16px;
            font-size: 15px;
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        input[type="email"]:focus {
            border-color: rgba(214, 179, 106, 0.6);
            box-shadow: 0 0 0 4px rgba(214, 179, 106, 0.12);
        }

        .btn {
            width: 100%;
            border: none;
            border-radius: 14px;
            padding: 15px 18px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            background: linear-gradient(180deg, #e3c27c 0%, #cfa85a 100%);
            color: #17120a;
            transition: transform 0.15s ease, box-shadow 0.15s ease, opacity 0.15s ease;
            box-shadow: 0 16px 32px rgba(207, 168, 90, 0.2);
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 20px 36px rgba(207, 168, 90, 0.24);
        }

        .btn:active {
            transform: translateY(0);
        }

        .help {
            margin-top: 18px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }

        .help a,
        .links a,
        .reset-box a {
            color: #f0cf8a;
            text-decoration: none;
        }

        .help a:hover,
        .links a:hover,
        .reset-box a:hover {
            text-decoration: underline;
        }

        .reset-box {
            margin-top: 18px;
            background: var(--panel-2);
            border: 1px solid rgba(214, 179, 106, 0.2);
            border-radius: 16px;
            padding: 16px;
        }

        .reset-box strong {
            display: block;
            margin-bottom: 8px;
            color: #fff2cf;
        }

        .reset-box p {
            margin: 0 0 10px;
            color: var(--muted);
            line-height: 1.6;
            font-size: 14px;
        }

        .reset-link {
            word-break: break-word;
            line-height: 1.6;
            font-size: 14px;
        }

        .links {
            margin-top: 22px;
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            font-size: 14px;
        }

        @media (max-width: 640px) {
            .top,
            .body {
                padding-left: 20px;
                padding-right: 20px;
            }

            h1 {
                font-size: 26px;
            }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <div class="top">
                <p class="brand">Doggie Dorian's</p>
                <h1>Forgot Password</h1>
                <p class="subtitle">
                    Enter the email address connected to your account and we’ll create a secure password reset link.
                </p>
            </div>

            <div class="body">
                <?php if ($error !== ''): ?>
                    <div class="alert error"><?php echo h($error); ?></div>
                <?php endif; ?>

                <?php if ($success !== ''): ?>
                    <div class="alert success"><?php echo h($success); ?></div>
                <?php endif; ?>

                <form method="post" action="">
                    <div class="field">
                        <label for="email">Email Address</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            value="<?php echo h($email); ?>"
                            placeholder="Enter your email address"
                            autocomplete="email"
                            required
                        >
                    </div>

                    <button type="submit" class="btn">Create Reset Link</button>
                </form>

                <?php if ($resetLink !== ''): ?>
                    <div class="reset-box">
                        <strong>Testing Reset Link</strong>
                        <p>
                            Email sending is not connected yet, so your reset link is shown below for testing.
                            Later, this can be replaced with real email delivery.
                        </p>
                        <div class="reset-link">
                            <a href="<?php echo h($resetLink); ?>"><?php echo h($resetLink); ?></a>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="help">
                    For security, the reset link expires after 1 hour and becomes invalid after it is used.
                </div>

                <div class="links">
                    <a href="login.php">Back to Login</a>
                    <a href="index.php">Return to Homepage</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>