<?php
declare(strict_types=1);

/**
 * Doggie Dorian's
 * Secure Stripe configuration loader
 *
 * Loads Stripe secrets from a private server-side file
 * located outside the public web root.
 */

function dd_private_stripe_env_path(): string
{
    return '/homepages/39/d4299671946/private/stripe-env.php';
}

function dd_fail_stripe_config(string $logMessage): never
{
    http_response_code(500);
    error_log($logMessage);
    exit('Server configuration error.');
}

function dd_load_private_stripe_env(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $path = dd_private_stripe_env_path();

    if (!is_file($path)) {
        dd_fail_stripe_config('Stripe config file not found: ' . $path);
    }

    $loaded = require $path;

    if (!is_array($loaded)) {
        dd_fail_stripe_config('Stripe config file did not return an array: ' . $path);
    }

    $config = $loaded;
    return $config;
}

function dd_env(string $key, ?string $default = null): ?string
{
    $config = dd_load_private_stripe_env();
    $value = $config[$key] ?? $default;

    if ($value === null) {
        return $default;
    }

    $value = trim((string)$value);

    if ($value === '') {
        return $default;
    }

    return $value;
}

function dd_require_env(string $key): string
{
    $value = dd_env($key);

    if ($value === null || $value === '') {
        dd_fail_stripe_config('Missing required Stripe config value: ' . $key);
    }

    return $value;
}

function dd_app_env(): string
{
    return strtolower(dd_env('APP_ENV', 'production') ?? 'production');
}

function dd_stripe_mode(): string
{
    $mode = strtolower(dd_env('STRIPE_MODE', '') ?? '');

    if ($mode === 'live' || $mode === 'test') {
        return $mode;
    }

    return dd_app_env() === 'production' ? 'live' : 'test';
}

function dd_normalize_base_url(string $url): string
{
    $url = trim($url);
    $url = rtrim($url, '/');

    if ($url === '') {
        dd_fail_stripe_config('Stripe public base URL is empty.');
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        dd_fail_stripe_config('Invalid Stripe public base URL: ' . $url);
    }

    return $url;
}

function dd_infer_public_base_url_from_success_url(string $successUrl): string
{
    $parts = parse_url($successUrl);

    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        dd_fail_stripe_config('Could not infer Stripe public base URL from STRIPE_SUCCESS_URL.');
    }

    $base = $parts['scheme'] . '://' . $parts['host'];

    if (!empty($parts['port'])) {
        $base .= ':' . $parts['port'];
    }

    return dd_normalize_base_url($base);
}

function dd_stripe_config(): array
{
    static $stripeConfig = null;

    if ($stripeConfig !== null) {
        return $stripeConfig;
    }

    $mode = dd_stripe_mode();

    if ($mode === 'live') {
        $secretKey      = dd_require_env('STRIPE_SECRET_KEY_LIVE');
        $publishableKey = dd_require_env('STRIPE_PUBLISHABLE_KEY_LIVE');
    } else {
        $secretKey      = dd_require_env('STRIPE_SECRET_KEY_TEST');
        $publishableKey = dd_require_env('STRIPE_PUBLISHABLE_KEY_TEST');
    }

    $successUrl = dd_require_env('STRIPE_SUCCESS_URL');
    $cancelUrl  = dd_require_env('STRIPE_CANCEL_URL');

    $publicBaseUrl = dd_env('PUBLIC_BASE_URL');
    if ($publicBaseUrl === null || $publicBaseUrl === '') {
        $publicBaseUrl = dd_infer_public_base_url_from_success_url($successUrl);
    } else {
        $publicBaseUrl = dd_normalize_base_url($publicBaseUrl);
    }

    $stripeConfig = [
        'mode'            => $mode,
        'secret_key'      => $secretKey,
        'publishable_key' => $publishableKey,
        'webhook_secret'  => dd_env('STRIPE_WEBHOOK_SECRET', '') ?? '',
        'currency'        => strtolower(dd_env('STRIPE_CURRENCY', 'usd') ?? 'usd'),
        'success_url'     => $successUrl,
        'cancel_url'      => $cancelUrl,
        'public_base_url' => $publicBaseUrl,
    ];

    return $stripeConfig;
}

function dd_stripe_secret_key(): string
{
    return dd_stripe_config()['secret_key'];
}

function dd_stripe_publishable_key(): string
{
    return dd_stripe_config()['publishable_key'];
}

function dd_stripe_webhook_secret(): string
{
    return dd_stripe_config()['webhook_secret'];
}

function dd_stripe_currency(): string
{
    return dd_stripe_config()['currency'];
}

function dd_stripe_success_url(): string
{
    return dd_stripe_config()['success_url'];
}

function dd_stripe_cancel_url(): string
{
    return dd_stripe_config()['cancel_url'];
}

function dd_stripe_public_base_url(): string
{
    return dd_stripe_config()['public_base_url'];
}

/**
 * Backward-compatible wrappers for any older files
 * still calling the legacy helper names.
 */

function stripe_secret_key(): string
{
    return dd_stripe_secret_key();
}

function stripe_publishable_key(): string
{
    return dd_stripe_publishable_key();
}

function stripe_webhook_secret(): string
{
    return dd_stripe_webhook_secret();
}

function stripe_currency(): string
{
    return dd_stripe_currency();
}

function stripe_success_url(): string
{
    return dd_stripe_success_url();
}

function stripe_cancel_url(): string
{
    return dd_stripe_cancel_url();
}

function stripe_public_base_url(): string
{
    return dd_stripe_public_base_url();
}