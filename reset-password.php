<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security-headers.php';

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
$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));

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
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($token === '') {
        $error = 'This password reset link is missing or invalid.';
    } elseif ($password === '' || $confirmPassword === '') {
        $error = 'Please complete both password fields.';
    } elseif (strlen($password) < 8) {
        $error = 'Your new password must be at least 8 characters long.';
    } elseif ($password !== $confirmPassword) {
        $error = 'The passwords do not match.';
    } else {
        try {
            $cleanupStmt = $pdo->prepare("
                DELETE FROM password_resets
                WHERE used_at IS NOT NULL
                   OR datetime(expires_at) < datetime('now')
            ");
            $cleanupStmt->execute();

            $tokenHash = hash('sha256', $token);

            $resetStmt = $pdo->prepare("
                SELECT pr.id, pr.user_id, pr.email, pr.expires_at, pr.used_at
                FROM password_resets pr
                WHERE pr.token_hash = :token_hash
                LIMIT 1
            ");
            $resetStmt->execute([
                ':token_hash' => $tokenHash,
            ]);
            $resetRow = $resetStmt->fetch(PDO::FETCH_ASSOC);

            if (!$resetRow) {
                $error = 'This password reset link is invalid or has expired.';
            } elseif (!empty($resetRow['used_at'])) {
                $error = 'This password reset link has already been used.';
            } elseif (strtotime((string) $resetRow['expires_at']) < time()) {
                $error = 'This password reset link has expired.';
            } else {
                $userStmt = $pdo->prepare("
                    SELECT id
                    FROM users
                    WHERE id = :user_id
                    LIMIT 1
                ");
                $userStmt->execute([
                    ':user_id' => (int) $resetRow['user_id'],
                ]);
                $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);

                if (!$userRow) {
                    $error = 'This password reset request is no longer valid.';
                } else {
                    $newPasswordHash = password_hash($password, PASSWORD_DEFAULT);

                    $pdo->beginTransaction();

                    $updateUserStmt = $pdo->prepare("
                        UPDATE users
                        SET password_hash = :password_hash
                        WHERE id = :user_id
                    ");
                    $updateUserStmt->execute([
                        ':password_hash' => $newPasswordHash,
                        ':user_id' => (int) $resetRow['user_id'],
                    ]);

                    $markUsedStmt = $pdo->prepare("
                        UPDATE password_resets
                        SET used_at = :used_at
                        WHERE id = :id
                    ");
                    $markUsedStmt->execute([
                        ':used_at' => gmdate('Y-m-d H:i:s'),
                        ':id' => (int) $resetRow['id'],
                    ]);

                    $deleteOtherTokensStmt = $pdo->prepare("
                        DELETE FROM password_resets
                        WHERE user_id = :user_id
                          AND id != :id
                    ");
                    $deleteOtherTokensStmt->execute([
                        ':user_id' => (int) $resetRow['user_id'],
                        ':id' => (int) $resetRow['id'],
                    ]);

                    $pdo->commit();

                    $success = 'Your password has been reset successfully. You can now log in with your new password.';
                    $token = '';
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Something went wrong while resetting your password. Please try again.';
        }
    }
}

$isTokenPresent = ($token !== '');
$isSuccess = ($success !== '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | Doggie Dorian's</title>
    <meta name="description" content="Reset your Doggie Dorian's account password securely.">
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

        input[type="password"] {
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

        input[type="password"]:focus {
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

        .links {
            margin-top: 22px;
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            font-size: 14px;
        }

        .links a {
            color: #f0cf8a;
            text-decoration: none;
        }

        .links a:hover {
            text-decoration: underline;
        }

        .invalid-box {
            background: var(--panel-2);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 18px;
            color: var(--muted);
            line-height: 1.6;
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
                <h1>Reset Password</h1>
                <p class="subtitle">
                    Create a new secure password for your account.
                </p>
            </div>

            <div class="body">
                <?php if ($error !== ''): ?>
                    <div class="alert error"><?php echo h($error); ?></div>
                <?php endif; ?>

                <?php if ($success !== ''): ?>
                    <div class="alert success"><?php echo h($success); ?></div>
                <?php endif; ?>

                <?php if ($isSuccess): ?>
                    <div class="links">
                        <a href="login.php">Go to Login</a>
                        <a href="index.php">Return to Homepage</a>
                    </div>
                <?php elseif ($isTokenPresent): ?>
                    <form method="post" action="">
                        <input type="hidden" name="token" value="<?php echo h($token); ?>">

                        <div class="field">
                            <label for="password">New Password</label>
                            <input
                                type="password"
                                id="password"
                                name="password"
                                placeholder="Enter your new password"
                                autocomplete="new-password"
                                required
                            >
                        </div>

                        <div class="field">
                            <label for="confirm_password">Confirm New Password</label>
                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                placeholder="Re-enter your new password"
                                autocomplete="new-password"
                                required
                            >
                        </div>

                        <button type="submit" class="btn">Reset Password</button>
                    </form>

                    <div class="help">
                        Use at least 8 characters for your new password. Once the password is changed, this reset link cannot be used again.
                    </div>

                    <div class="links">
                        <a href="login.php">Back to Login</a>
                        <a href="forgot-password.php">Request Another Reset Link</a>
                    </div>
                <?php else: ?>
                    <div class="invalid-box">
                        This reset link is missing or no longer valid. Please request a new password reset link to continue.
                    </div>

                    <div class="links">
                        <a href="forgot-password.php">Request Reset Link</a>
                        <a href="login.php">Back to Login</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>