<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (!function_exists('dd_admin_redirect')) {
    function dd_admin_redirect(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('dd_admin_normalize_role')) {
    function dd_admin_normalize_role(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }
}

if (!function_exists('dd_admin_session_bool')) {
    function dd_admin_session_bool(string $key): bool
    {
        return isset($_SESSION[$key]) && $_SESSION[$key] === true;
    }
}

if (!function_exists('dd_admin_session_nonempty')) {
    function dd_admin_session_nonempty(string $key): bool
    {
        return isset($_SESSION[$key]) && $_SESSION[$key] !== '' && $_SESSION[$key] !== null;
    }
}

if (!function_exists('dd_admin_is_authenticated')) {
    function dd_admin_is_authenticated(): bool
    {
        $roleCandidates = array(
            dd_admin_normalize_role($_SESSION['role'] ?? ''),
            dd_admin_normalize_role($_SESSION['user_role'] ?? ''),
            dd_admin_normalize_role($_SESSION['user_type'] ?? ''),
            dd_admin_normalize_role($_SESSION['account_type'] ?? ''),
            dd_admin_normalize_role($_SESSION['access_role'] ?? ''),
            dd_admin_normalize_role($_SESSION['admin']['role'] ?? ''),
        );

        $hasAdminRole = in_array('admin', $roleCandidates, true);

        $hasAdminFlag = (
            dd_admin_session_bool('admin_logged_in')
            || dd_admin_session_bool('is_admin')
            || (
                isset($_SESSION['admin'])
                && is_array($_SESSION['admin'])
                && (
                    (!empty($_SESSION['admin']['logged_in']) && $_SESSION['admin']['logged_in'] === true)
                    || (!empty($_SESSION['admin']['is_admin']) && $_SESSION['admin']['is_admin'] === true)
                )
            )
        );

        $hasAdminIdentity = (
            dd_admin_session_nonempty('admin_id')
            || dd_admin_session_nonempty('admin_email')
            || dd_admin_session_nonempty('admin_name')
            || (
                isset($_SESSION['admin'])
                && is_array($_SESSION['admin'])
                && !empty($_SESSION['admin'])
            )
        );

        return ($hasAdminFlag && ($hasAdminRole || $hasAdminIdentity))
            || ($hasAdminRole && $hasAdminIdentity);
    }
}

if (!function_exists('dd_admin_normalize_session')) {
    function dd_admin_normalize_session(): void
    {
        if (!dd_admin_is_authenticated()) {
            return;
        }

        $_SESSION['admin_logged_in'] = true;
        $_SESSION['is_admin'] = true;
        $_SESSION['role'] = 'admin';
        $_SESSION['user_role'] = 'admin';

        if (empty($_SESSION['admin_name']) && !empty($_SESSION['admin']['name'])) {
            $_SESSION['admin_name'] = (string) $_SESSION['admin']['name'];
        }

        if (empty($_SESSION['admin_email']) && !empty($_SESSION['admin']['email'])) {
            $_SESSION['admin_email'] = (string) $_SESSION['admin']['email'];
        }

        if (empty($_SESSION['admin_id'])) {
            if (!empty($_SESSION['admin']['id'])) {
                $_SESSION['admin_id'] = (int) $_SESSION['admin']['id'];
            } elseif (!empty($_SESSION['user_id'])) {
                $_SESSION['admin_id'] = (int) $_SESSION['user_id'];
            } else {
                $_SESSION['admin_id'] = 1;
            }
        }

        if (empty($_SESSION['user_id']) && !empty($_SESSION['admin_id'])) {
            $_SESSION['user_id'] = (int) $_SESSION['admin_id'];
        }

        if (!isset($_SESSION['admin']) || !is_array($_SESSION['admin'])) {
            $_SESSION['admin'] = array();
        }

        $_SESSION['admin']['logged_in'] = true;
        $_SESSION['admin']['is_admin'] = true;
        $_SESSION['admin']['role'] = 'admin';

        if (empty($_SESSION['admin']['id']) && !empty($_SESSION['admin_id'])) {
            $_SESSION['admin']['id'] = (int) $_SESSION['admin_id'];
        }

        if (empty($_SESSION['admin']['email']) && !empty($_SESSION['admin_email'])) {
            $_SESSION['admin']['email'] = (string) $_SESSION['admin_email'];
        }

        if (empty($_SESSION['admin']['name']) && !empty($_SESSION['admin_name'])) {
            $_SESSION['admin']['name'] = (string) $_SESSION['admin_name'];
        }
    }
}

if (!function_exists('dd_admin_require_auth')) {
    function dd_admin_require_auth(): void
    {
        if (!dd_admin_is_authenticated()) {
            dd_admin_redirect('admin-login.php');
        }

        dd_admin_normalize_session();
    }
}

dd_admin_require_auth();