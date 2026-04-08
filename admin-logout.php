<?php
declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| Admin Logout
|--------------------------------------------------------------------------
| PURPOSE
| - Clean logout for admin portal
| - Clears session safely
| - Redirects to admin-login.php
|--------------------------------------------------------------------------
*/

// Clear current session data
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

// Destroy old session
session_destroy();

// Start fresh session just for flash
session_start();
$_SESSION['admin_flash_type'] = 'success';
$_SESSION['admin_flash_message'] = 'You have been logged out.';

header('Location: admin-login.php');
exit;