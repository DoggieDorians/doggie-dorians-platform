<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (
    empty($_SESSION['admin_logged_in']) ||
    $_SESSION['admin_logged_in'] !== true ||
    empty($_SESSION['user_role']) ||
    $_SESSION['user_role'] !== 'admin'
) {
    header('Location: admin-login.php');
    exit;
}