<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

unset($_SESSION['admin_logged_in']);
unset($_SESSION['admin_email']);
unset($_SESSION['admin_name']);

if (!empty($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
    unset($_SESSION['user_role']);
}

header('Location: admin-login.php');
exit;