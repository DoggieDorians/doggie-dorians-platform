<?php
declare(strict_types=1);

session_start();

/**
 * Fully clear session data
 */
$_SESSION = array();

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        !empty($params['secure']),
        !empty($params['httponly'])
    );
}

session_destroy();

/**
 * Optional cache prevention
 */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

/**
 * Send user back to public homepage
 */
header('Location: index.php');
exit;