<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/db.php';

/*
|--------------------------------------------------------------------------
| Password Reset Table Setup
|--------------------------------------------------------------------------
| Run this file once in your browser:
| https://yourdomain.com/setup-password-resets.php
|
| It creates a password_resets table used by forgot/reset password flow.
| After it succeeds, delete this file from the live server for safety.
|--------------------------------------------------------------------------
*/

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$messages = [];
$errors = [];

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection not available. Check db.php.');
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password_resets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            email TEXT NOT NULL,
            token_hash TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            used_at TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");

    $messages[] = 'password_resets table created or already exists.';

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_password_resets_email
        ON password_resets(email)
    ");
    $messages[] = 'Email index checked.';

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_password_resets_user_id
        ON password_resets(user_id)
    ");
    $messages[] = 'User ID index checked.';

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_password_resets_expires_at
        ON password_resets(expires_at)
    ");
    $messages[] = 'Expiration index checked.';

    $pdo->exec("
        CREATE UNIQUE INDEX IF NOT EXISTS idx_password_resets_token_hash
        ON password_resets(token_hash)
    ");
    $messages[] = 'Token hash unique index checked.';

    $cleanupStmt = $pdo->prepare("
        DELETE FROM password_resets
        WHERE used_at IS NOT NULL
           OR datetime(expires_at) < datetime('now')
    ");
    $cleanupStmt->execute();

    $messages[] = 'Old/used reset tokens cleaned up.';

    $tableCheck = $pdo->query("PRAGMA table_info(password_resets)")->fetchAll(PDO::FETCH_ASSOC);

    if (!$tableCheck) {
        throw new RuntimeException('Table verification failed.');
    }

    $messages[] = 'Password reset setup completed successfully.';
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Password Resets</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #0f1115;
            color: #f5f7fb;
            padding: 40px 20px;
        }

        .wrap {
            max-width: 760px;
            margin: 0 auto;
        }

        .card {
            background: #171a21;
            border: 1px solid #2a3040;
            border-radius: 18px;
            padding: 28px;
            box-shadow: 0 18px 60px rgba(0, 0, 0, 0.35);
        }

        h1 {
            margin-top: 0;
            font-size: 28px;
            line-height: 1.2;
        }

        p {
            color: #cfd6e4;
            line-height: 1.6;
        }

        .success,
        .error {
            border-radius: 14px;
            padding: 16px 18px;
            margin: 16px 0;
        }

        .success {
            background: rgba(34, 197, 94, 0.12);
            border: 1px solid rgba(34, 197, 94, 0.35);
            color: #d8ffe6;
        }

        .error {
            background: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.35);
            color: #ffe0e0;
        }

        ul {
            margin: 10px 0 0;
            padding-left: 20px;
        }

        li {
            margin: 8px 0;
            color: #dbe3f0;
        }

        .note {
            margin-top: 20px;
            padding: 14px 16px;
            border-radius: 12px;
            background: #11151d;
            border: 1px solid #2a3040;
            color: #c9d2e3;
        }

        code {
            background: #0b0d12;
            padding: 2px 6px;
            border-radius: 6px;
            color: #fff;
        }

        a {
            color: #9cc3ff;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Password Reset Setup</h1>
            <p>This page creates the database table needed for the basic forgot-password and reset-password flow.</p>

            <?php if ($errors): ?>
                <div class="error">
                    <strong>Setup failed:</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo h($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php else: ?>
                <div class="success">
                    <strong>Setup completed:</strong>
                    <ul>
                        <?php foreach ($messages as $message): ?>
                            <li><?php echo h($message); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="note">
                    Next step: build <code>forgot-password.php</code> and <code>reset-password.php</code>.<br><br>
                    For security, delete this file from the live server after it runs successfully.
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>