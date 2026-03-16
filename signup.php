<?php
session_start();
require_once __DIR__ . '/data/config/db.php';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($fullName === '' || $email === '' || $password === '' || $confirmPassword === '') {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        try {
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $checkStmt->execute([$email]);
            $existingUser = $checkStmt->fetch();

            if ($existingUser) {
                $error = 'An account with that email already exists.';
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $pdo->beginTransaction();

                $userStmt = $pdo->prepare("
                    INSERT INTO users (full_name, email, phone, password_hash)
                    VALUES (?, ?, ?, ?)
                ");
                $userStmt->execute([$fullName, $email, $phone, $passwordHash]);

                $userId = $pdo->lastInsertId();

                $profileStmt = $pdo->prepare("
                    INSERT INTO client_profiles (user_id)
                    VALUES (?)
                ");
                $profileStmt->execute([$userId]);

                $pdo->commit();

                $success = 'Account created successfully. You can now log in.';
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Something went wrong: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up | Doggie Dorian's</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f7f8fb;
            color: #111;
        }

        .page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }

        .card {
            width: 100%;
            max-width: 520px;
            background: #ffffff;
            border-radius: 18px;
            padding: 32px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            box-sizing: border-box;
        }

        h1 {
            margin: 0 0 8px;
            font-size: 32px;
            text-align: center;
        }

        .subtext {
            margin: 0 0 24px;
            text-align: center;
            color: #666;
            line-height: 1.5;
        }

        label {
            display: block;
            margin: 14px 0 6px;
            font-weight: 700;
        }

        input {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #d9d9d9;
            border-radius: 12px;
            box-sizing: border-box;
            font-size: 15px;
        }

        input:focus {
            outline: none;
            border-color: #111;
        }

        button {
            width: 100%;
            margin-top: 20px;
            padding: 14px;
            background: #111;
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
        }

        button:hover {
            opacity: 0.95;
        }

        .message {
            margin-bottom: 16px;
            padding: 12px 14px;
            border-radius: 12px;
            font-size: 14px;
        }

        .error {
            background: #ffe7e7;
            color: #9b1111;
        }

        .success {
            background: #e8f8ea;
            color: #146c2e;
        }

        .footer-link {
            margin-top: 18px;
            text-align: center;
            color: #666;
        }

        .footer-link a {
            color: #111;
            text-decoration: none;
            font-weight: 700;
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="card">
            <h1>Create Your Account</h1>
            <p class="subtext">Join Doggie Dorian’s and start building your luxury pet care profile.</p>

            <?php if ($error !== ''): ?>
                <div class="message error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="message success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name" required>

                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" required>

                <label for="phone">Phone Number</label>
                <input type="text" id="phone" name="phone">

                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>

                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required>

                <button type="submit">Create Account</button>
            </form>

            <div class="footer-link">
                Already have an account? <a href="login.php">Log in</a>
            </div>
        </div>
    </div>
</body>
</html>