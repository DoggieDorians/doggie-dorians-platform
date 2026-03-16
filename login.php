<?php
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

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT id, full_name, email, password_hash, role, status
                FROM users
                WHERE email = ?
                LIMIT 1
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user) {
                $error = 'No account was found with that email address.';
            } elseif ($user['status'] !== 'active') {
                $error = 'This account is not active. Please contact support.';
            } elseif (!password_verify($password, $user['password_hash'])) {
                $error = 'Incorrect password. Please try again.';
            } else {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];

                if ($user['role'] === 'admin') {
                    header('Location: admin.php');
                } else {
                    header('Location: dashboard.php');
                }
                exit;
            }
        } catch (PDOException $e) {
            $error = 'Login failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Doggie Dorian's</title>
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
            box-sizing: border-box;
        }

        .card {
            width: 100%;
            max-width: 500px;
            background: #fff;
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
            <h1>Welcome Back</h1>
            <p class="subtext">Log in to access your Doggie Dorian’s account.</p>

            <?php if ($error !== ''): ?>
                <div class="message error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" required>

                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>

                <button type="submit">Log In</button>
            </form>

            <div class="footer-link">
                Don’t have an account? <a href="signup.php">Create one</a>
            </div>
        </div>
    </div>
</body>
</html>