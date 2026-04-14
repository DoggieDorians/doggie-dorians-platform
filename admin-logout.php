<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

/*
|--------------------------------------------------------------------------
| Admin Logout
|--------------------------------------------------------------------------
| PURPOSE
| - Clean logout for admin portal
| - Clears admin session safely
| - Starts a fresh session for logout flash
| - Redirects to admin-login.php
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Clear session data
|--------------------------------------------------------------------------
*/
$_SESSION = array();

/*
|--------------------------------------------------------------------------
| Clear session cookie if sessions use cookies
|--------------------------------------------------------------------------
*/
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'] ?? '/',
        $params['domain'] ?? '',
        (bool) ($params['secure'] ?? false),
        (bool) ($params['httponly'] ?? true)
    );
}

/*
|--------------------------------------------------------------------------
| Destroy old session completely
|--------------------------------------------------------------------------
*/
session_destroy();

/*
|--------------------------------------------------------------------------
| Start a fresh session only for logout flash
|--------------------------------------------------------------------------
*/
session_start();
session_regenerate_id(true);

$_SESSION['admin_flash_type'] = 'success';
$_SESSION['admin_flash_message'] = 'You have been logged out.';

header('Location: admin-login.php');
exit;