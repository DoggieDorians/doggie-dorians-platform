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
    foreach (['member_id', 'user_id', 'id'] as $key) {
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