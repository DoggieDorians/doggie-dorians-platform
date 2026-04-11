<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
/*
|--------------------------------------------------------------------------
| Walker Logout
|--------------------------------------------------------------------------
| PURPOSE
| - Clean logout for walker/staff/employee portal
| - Clears session safely
| - Redirects to walker-login.php
|--------------------------------------------------------------------------
*/

// Optional flash before destroying session
$_SESSION['walker_flash_type'] = 'success';
$_SESSION['walker_flash_message'] = 'You have been logged out.';

$_SESSION = [];

// Clear session cookie if used
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'] ?? '/',
        $params['domain'] ?? '',
        (bool)($params['secure'] ?? false),
        (bool)($params['httponly'] ?? true)
    );
}

// Start a fresh session for flash message redirect
session_destroy();
$_SESSION['walker_flash_type'] = 'success';
$_SESSION['walker_flash_message'] = 'You have been logged out.';

header('Location: walker-login.php');
exit;