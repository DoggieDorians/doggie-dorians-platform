<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/stripe-config.php';
require_once __DIR__ . '/vendor/autoload.php';

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirectTo(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function hasTable(PDO $pdo, string $table): bool
{
    static $cache = array();

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :name LIMIT 1");
        $stmt->execute(array(':name' => $table));
        $cache[$table] = (bool) $stmt->fetchColumn();
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = false;
        return false;
    } catch (Exception $e) {
        $cache[$table] = false;
        return false;
    }
}

function getTableColumns(PDO $pdo, string $table): array
{
    static $cache = array();

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!hasTable($pdo, $table)) {
        $cache[$table] = array();
        return $cache[$table];
    }

    try {
        $safeTable = str_replace('"', '""', $table);
        $stmt = $pdo->query('PRAGMA table_info("' . $safeTable . '")');
        $columns = array();

        if ($stmt) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($row['name'])) {
                    $columns[] = (string) $row['name'];
                }
            }
        }

        $cache[$table] = $columns;
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = array();
        return $cache[$table];
    } catch (Exception $e) {
        $cache[$table] = array();
        return $cache[$table];
    }
}

function firstExistingColumn(PDO $pdo, string $table, array $candidates): ?string
{
    $columns = getTableColumns($pdo, $table);

    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function safeExecute(PDOStatement $stmt, array $params = array()): bool
{
    try {
        return $stmt->execute($params);
    } catch (Throwable $e) {
        return false;
    } catch (Exception $e) {
        return false;
    }
}

function safeFetchOne(PDO $pdo, string $sql, array $params = array()): ?array
{
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt->execute($params)) {
            return null;
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    } catch (Exception $e) {
        return null;
    }
}

function getBaseUrl(): string
{
    if (function_exists('dd_stripe_public_base_url')) {
        $baseUrl = trim((string) dd_stripe_public_base_url());
        if ($baseUrl !== '') {
            return rtrim($baseUrl, '/');
        }
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $host;
}

function currentMemberIdFromSession(): int
{
    foreach (array('member_id', 'user_id', 'id') as $key) {
        if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) {
            return (int) $_SESSION[$key];
        }
    }

    return 0;
}

function membershipCsrfToken(): string
{
    $token = (string) ($_SESSION['membership_csrf_token'] ?? '');

    if ($token === '') {
        $token = bin2hex(random_bytes(32));
        $_SESSION['membership_csrf_token'] = $token;
    }

    return $token;
}

function membershipPriceId(string $baseKey): string
{
    if (!function_exists('dd_env')) {
        return '';
    }

    $baseKey = trim($baseKey);
    if ($baseKey === '') {
        return '';
    }

    $mode = function_exists('dd_stripe_mode')
        ? strtolower(trim((string) dd_stripe_mode()))
        : '';

    $keysToTry = array();

    if (str_ends_with($baseKey, '_TEST') || str_ends_with($baseKey, '_LIVE')) {
        $keysToTry[] = $baseKey;
    } else {
        if ($mode === 'live') {
            $keysToTry[] = $baseKey . '_LIVE';
            $keysToTry[] = $baseKey . '_TEST';
        } else {
            $keysToTry[] = $baseKey . '_TEST';
            $keysToTry[] = $baseKey . '_LIVE';
        }

        $keysToTry[] = $baseKey;
    }

    foreach ($keysToTry as $key) {
        $value = trim((string) (dd_env($key, '') ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function founderPlanCatalog(): array
{
    return array(
        'founder_walk_club' => array(
            'slug' => 'founder_walk_club',
            'name' => 'Founder Walk Club',
            'price' => 250,
            'value' => 300,
            'tag' => 'Founding Walk Access',
            'stripe_price_id' => membershipPriceId('STRIPE_PRICE_ID_FOUNDER_WALK'),
            'summary' => 'Built for clients who mainly want recurring walks, premium booking access, and a cleaner high-touch membership experience.',
            'features' => array(
                '12 included 30-minute walks each month',
                'Unused walks roll over into the following month only',
                'Priority scheduling access',
                'Reserved availability during peak demand',
                'Founder-only private contact path',
                '$250 annual service credit issued quarterly',
                'Locked-in founder pricing',
            ),
        ),
        'founder_care_club' => array(
            'slug' => 'founder_care_club',
            'name' => 'Founder Care Club',
            'price' => 499,
            'value' => 650,
            'tag' => 'Most Popular',
            'stripe_price_id' => membershipPriceId('STRIPE_PRICE_ID_FOUNDER_CARE'),
            'summary' => 'For clients who want stronger recurring support across walks, daycare, and drop-ins with founder-level priority.',
            'features' => array(
                '16 included 30-minute walks each month',
                '2 included daycare days each month',
                '2 included drop-in visits each month',
                'Unused walks roll over into the following month only',
                '10% off boarding bookings',
                '$500 annual service credit issued quarterly',
                'Higher founder scheduling priority',
            ),
        ),
        'founder_elite_club' => array(
            'slug' => 'founder_elite_club',
            'name' => 'Founder Elite Club',
            'price' => 899,
            'value' => 1100,
            'tag' => 'Highest Tier',
            'stripe_price_id' => membershipPriceId('STRIPE_PRICE_ID_FOUNDER_ELITE'),
            'summary' => 'Your most exclusive founder package for premium recurring care, elevated flexibility, and top-tier access.',
            'features' => array(
                '20 included 30-minute walks each month',
                '4 included daycare days each month',
                '4 included drop-in visits each month',
                '3 complimentary boarding nights',
                '20% off additional boarding bookings',
                '$750 annual service credit issued quarterly',
                'Highest founder scheduling priority',
            ),
        ),
    );
}

function ensureMembershipPlansTable(PDO $pdo): bool
{
    if (hasTable($pdo, 'membership_plans')) {
        return true;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS membership_plans (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT,
                name TEXT,
                created_at TEXT
            )
        ");
    } catch (Throwable $e) {
        return hasTable($pdo, 'membership_plans');
    } catch (Exception $e) {
        return hasTable($pdo, 'membership_plans');
    }

    return hasTable($pdo, 'membership_plans');
}

function ensureMembershipPlanColumns(PDO $pdo): bool
{
    if (!ensureMembershipPlansTable($pdo)) {
        return false;
    }

    $columns = getTableColumns($pdo, 'membership_plans');

    try {
        if (!in_array('slug', $columns, true)) {
            $pdo->exec("ALTER TABLE membership_plans ADD COLUMN slug TEXT");
        }
    } catch (Throwable $e) {
    } catch (Exception $e) {
    }

    $columns = getTableColumns($pdo, 'membership_plans');

    try {
        if (!in_array('name', $columns, true)) {
            $pdo->exec("ALTER TABLE membership_plans ADD COLUMN name TEXT");
        }
    } catch (Throwable $e) {
    } catch (Exception $e) {
    }

    $columns = getTableColumns($pdo, 'membership_plans');

    try {
        if (!in_array('created_at', $columns, true)) {
            $pdo->exec("ALTER TABLE membership_plans ADD COLUMN created_at TEXT");
        }
    } catch (Throwable $e) {
    } catch (Exception $e) {
    }

    return true;
}

function findMembershipPlanRow(PDO $pdo, string $slug, string $name): ?array
{
    if (!ensureMembershipPlanColumns($pdo)) {
        return null;
    }

    $slugColumns = array('slug', 'plan_slug', 'code');
    $nameColumns = array('name', 'plan_name', 'title');

    foreach ($slugColumns as $column) {
        if (!in_array($column, getTableColumns($pdo, 'membership_plans'), true)) {
            continue;
        }

        $row = safeFetchOne(
            $pdo,
            "SELECT * FROM membership_plans WHERE LOWER(TRIM(COALESCE($column, ''))) = :value LIMIT 1",
            array(':value' => strtolower(trim($slug)))
        );

        if ($row !== null) {
            return $row;
        }
    }

    foreach ($nameColumns as $column) {
        if (!in_array($column, getTableColumns($pdo, 'membership_plans'), true)) {
            continue;
        }

        $row = safeFetchOne(
            $pdo,
            "SELECT * FROM membership_plans WHERE LOWER(TRIM(COALESCE($column, ''))) = :value LIMIT 1",
            array(':value' => strtolower(trim($name)))
        );

        if ($row !== null) {
            return $row;
        }
    }

    return null;
}

function insertMembershipPlanRow(PDO $pdo, string $slug, string $name): bool
{
    if (!ensureMembershipPlanColumns($pdo)) {
        return false;
    }

    $columns = getTableColumns($pdo, 'membership_plans');
    $insertColumns = array();
    $params = array();

    if (in_array('slug', $columns, true)) {
        $insertColumns[] = 'slug';
        $params[':slug'] = $slug;
    } elseif (in_array('plan_slug', $columns, true)) {
        $insertColumns[] = 'plan_slug';
        $params[':plan_slug'] = $slug;
    } elseif (in_array('code', $columns, true)) {
        $insertColumns[] = 'code';
        $params[':code'] = $slug;
    }

    if (in_array('name', $columns, true)) {
        $insertColumns[] = 'name';
        $params[':name'] = $name;
    } elseif (in_array('plan_name', $columns, true)) {
        $insertColumns[] = 'plan_name';
        $params[':plan_name'] = $name;
    } elseif (in_array('title', $columns, true)) {
        $insertColumns[] = 'title';
        $params[':title'] = $name;
    }

    if (in_array('created_at', $columns, true)) {
        $insertColumns[] = 'created_at';
        $params[':created_at'] = date('Y-m-d H:i:s');
    }

    if (empty($insertColumns)) {
        return false;
    }

    try {
        $placeholders = array();
        foreach ($insertColumns as $column) {
            $placeholders[] = ':' . $column;
        }

        $sql = 'INSERT INTO membership_plans (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $pdo->prepare($sql);

        return safeExecute($stmt, $params);
    } catch (Throwable $e) {
        return false;
    } catch (Exception $e) {
        return false;
    }
}

function backfillFounderPlanRows(PDO $pdo): void
{
    if (!ensureMembershipPlanColumns($pdo)) {
        return;
    }

    $catalog = founderPlanCatalog();

    foreach ($catalog as $slug => $plan) {
        $row = findMembershipPlanRow($pdo, $slug, $plan['name']);
        if ($row !== null) {
            continue;
        }

        insertMembershipPlanRow($pdo, $slug, $plan['name']);
    }
}

function normalizeExistingFounderPlanRows(PDO $pdo): void
{
    if (!ensureMembershipPlanColumns($pdo)) {
        return;
    }

    $catalog = founderPlanCatalog();
    $columns = getTableColumns($pdo, 'membership_plans');

    $idCol = in_array('id', $columns, true) ? 'id' : (in_array('plan_id', $columns, true) ? 'plan_id' : null);
    $slugCol = firstExistingColumn($pdo, 'membership_plans', array('slug', 'plan_slug', 'code'));
    $nameCol = firstExistingColumn($pdo, 'membership_plans', array('name', 'plan_name', 'title'));

    if ($idCol === null) {
        return;
    }

    foreach ($catalog as $slug => $plan) {
        $row = findMembershipPlanRow($pdo, $slug, $plan['name']);
        if ($row === null) {
            continue;
        }

        $updateParts = array();
        $params = array(':id' => (int) ($row[$idCol] ?? 0));

        if ($slugCol !== null && (string) ($row[$slugCol] ?? '') !== $slug) {
            $updateParts[] = $slugCol . ' = :slug';
            $params[':slug'] = $slug;
        }

        if ($nameCol !== null && (string) ($row[$nameCol] ?? '') !== $plan['name']) {
            $updateParts[] = $nameCol . ' = :name';
            $params[':name'] = $plan['name'];
        }

        if (!empty($updateParts)) {
            try {
                $stmt = $pdo->prepare(
                    'UPDATE membership_plans SET ' . implode(', ', $updateParts) . ' WHERE ' . $idCol . ' = :id'
                );
                safeExecute($stmt, $params);
            } catch (Throwable $e) {
            } catch (Exception $e) {
            }
        }
    }
}

function ensureFounderMembershipPlans(PDO $pdo): bool
{
    if (!ensureMembershipPlanColumns($pdo)) {
        return false;
    }

    backfillFounderPlanRows($pdo);
    normalizeExistingFounderPlanRows($pdo);
    backfillFounderPlanRows($pdo);

    $catalog = founderPlanCatalog();

    foreach ($catalog as $plan) {
        if (findMembershipPlanRow($pdo, $plan['slug'], $plan['name']) === null) {
            return false;
        }
    }

    return true;
}

function lookupMembershipPlanId(PDO $pdo, string $slug, string $name): int
{
    $row = findMembershipPlanRow($pdo, $slug, $name);
    if ($row === null) {
        return 0;
    }

    foreach (array('id', 'plan_id') as $key) {
        if (isset($row[$key]) && is_numeric($row[$key])) {
            return (int) $row[$key];
        }
    }

    return 0;
}

$isLoggedIn = isset($_SESSION['member_id']) || isset($_SESSION['user_id']) || isset($_SESSION['user']) || isset($_SESSION['email']);

if (!$isLoggedIn) {
    $redirect = rawurlencode('memberships.php');
    redirectTo('login.php?redirect=' . $redirect);
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection is not available.';
    exit;
}

$currentUserName = '';
foreach (array('member_name', 'full_name', 'name', 'user_name', 'email') as $sessionKey) {
    if (!empty($_SESSION[$sessionKey]) && is_string($_SESSION[$sessionKey])) {
        $currentUserName = trim($_SESSION[$sessionKey]);
        break;
    }
}

$currentMemberId = currentMemberIdFromSession();
$tosVersion = '2026-04-07';

$plansBySlug = founderPlanCatalog();
$plans = array_values($plansBySlug);

$error = '';
$success = '';
$selectedPlanSlug = trim((string) ($_GET['plan'] ?? ''));
$checkoutReady = false;
$checkoutPayload = $_SESSION['pending_membership_checkout'] ?? null;
$csrfToken = membershipCsrfToken();

ensureFounderMembershipPlans($pdo);

/*
|--------------------------------------------------------------------------
| Allow plan switching without being overridden by the old pending session
|--------------------------------------------------------------------------
*/
if (
    is_array($checkoutPayload)
    && !empty($checkoutPayload['plan_slug'])
    && isset($plansBySlug[(string) $checkoutPayload['plan_slug']])
) {
    $sessionPlanSlug = (string) $checkoutPayload['plan_slug'];

    if ($selectedPlanSlug !== '' && $selectedPlanSlug !== $sessionPlanSlug) {
        unset($_SESSION['pending_membership_checkout']);
        $checkoutPayload = null;
        $checkoutReady = false;
    } elseif ($selectedPlanSlug === '') {
        $selectedPlanSlug = $sessionPlanSlug;
        $checkoutReady = true;
    } else {
        $checkoutReady = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['membership_csrf_token'] ?? '');

    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } else {
        $selectedPlanSlug = trim((string) ($_POST['plan'] ?? ''));
        $tosAccepted = isset($_POST['agree_tos']) && (string) $_POST['agree_tos'] === '1';

        if (!isset($plansBySlug[$selectedPlanSlug])) {
            $error = 'Please choose a membership before continuing.';
        } elseif (!$tosAccepted) {
            $error = 'You must agree to the Membership Terms of Service before continuing.';
        } elseif ($currentMemberId <= 0) {
            $error = 'Your account session is missing a member ID. Please log out and sign back in.';
        } elseif (!ensureFounderMembershipPlans($pdo)) {
            $error = 'Founder membership plans could not be prepared in the database.';
        } else {
            $plan = $plansBySlug[$selectedPlanSlug];
            $planId = lookupMembershipPlanId($pdo, $plan['slug'], $plan['name']);

            if ($planId <= 0) {
                $error = 'The selected membership plan could not be matched to the database.';
            } else {
                $_SESSION['pending_membership_checkout'] = array(
                    'type' => 'membership',
                    'plan_id' => $planId,
                    'plan_slug' => $plan['slug'],
                    'plan_name' => $plan['name'],
                    'monthly_price' => (int) $plan['price'],
                    'stripe_price_id' => (string) $plan['stripe_price_id'],
                    'member_id' => $currentMemberId,
                    'tos_version' => $tosVersion,
                    'tos_accepted' => true,
                    'tos_accepted_at' => date('c'),
                    'started_from' => 'memberships.php',
                );

                $checkoutPayload = $_SESSION['pending_membership_checkout'];
                $checkoutReady = true;

                $stripeSecretKey = trim((string) dd_stripe_secret_key());
                $stripePriceId = trim((string) $plan['stripe_price_id']);

                if ($stripeSecretKey === '') {
                    $error = 'Membership checkout is temporarily unavailable. Please try again shortly.';
                } elseif ($stripePriceId === '') {
                    $error = 'This membership is not yet available for checkout. Please contact support for assistance.';
                } else {
                    try {
                        \Stripe\Stripe::setApiKey($stripeSecretKey);

                        $baseUrl = getBaseUrl();
                        $memberEmail = !empty($_SESSION['email']) && is_string($_SESSION['email'])
                            ? trim((string) $_SESSION['email'])
                            : '';

                        $metadata = array(
                            'ledger_action' => 'membership_signup',
                            'member_id' => (string) $currentMemberId,
                            'plan_id' => (string) $planId,
                            'plan_slug' => $plan['slug'],
                            'plan_name' => $plan['name'],
                            'tos_version' => $tosVersion,
                        );

                        $checkoutParams = array(
                            'mode' => 'subscription',
                            'line_items' => array(
                                array(
                                    'price' => $stripePriceId,
                                    'quantity' => 1,
                                ),
                            ),
                            'success_url' => $baseUrl . '/dashboard.php?membership_checkout=success',
                            'cancel_url' => $baseUrl . '/memberships.php?plan=' . rawurlencode($plan['slug']) . '&membership_checkout=cancelled#selection',
                            'metadata' => $metadata,
                            'subscription_data' => array(
                                'metadata' => $metadata,
                            ),
                            'client_reference_id' => (string) $currentMemberId,
                            'allow_promotion_codes' => false,
                        );

                        if ($memberEmail !== '') {
                            $checkoutParams['customer_email'] = $memberEmail;
                        }

                        $checkoutSession = \Stripe\Checkout\Session::create($checkoutParams);

                        if (!empty($checkoutSession->url) && is_string($checkoutSession->url)) {
                            redirectTo($checkoutSession->url);
                        }

                        $error = 'Secure checkout could not be started right now. Please try again.';
                    } catch (Throwable $e) {
                        error_log('Membership Stripe checkout error: ' . $e->getMessage());
                        $error = 'Secure checkout could not be started right now. Please try again.';
                    } catch (Exception $e) {
                        error_log('Membership Stripe checkout error: ' . $e->getMessage());
                        $error = 'Secure checkout could not be started right now. Please try again.';
                    }
                }
            }
        }
    }
}

$selectedPlan = ($selectedPlanSlug !== '' && isset($plansBySlug[$selectedPlanSlug]))
    ? $plansBySlug[$selectedPlanSlug]
    : null;

if (isset($_GET['membership_checkout']) && $_GET['membership_checkout'] === 'cancelled' && $error === '') {
    $error = 'Stripe checkout was cancelled before payment was completed.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Memberships | Doggie Dorian’s</title>
    <meta name="description" content="Choose a Doggie Dorian’s membership, review premium founder plans, and accept the membership terms before checkout.">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --bg: #07080b;
            --bg-soft: #0d1016;
            --panel: rgba(255,255,255,0.05);
            --panel-strong: rgba(255,255,255,0.08);
            --line: rgba(255,255,255,0.10);
            --text: #f6f1e8;
            --muted: #c9c0af;
            --soft: #9d968a;
            --gold: #d7b26a;
            --gold-light: #f0d59f;
            --gold-soft: rgba(215,178,106,0.12);
            --danger: #ffcbc0;
            --danger-bg: rgba(201, 92, 71, 0.14);
            --success: #daf2c8;
            --success-bg: rgba(90, 148, 73, 0.14);
            --white: #ffffff;
            --shadow: 0 22px 65px rgba(0,0,0,0.38);
            --max: 1280px;
            --radius: 28px;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: "Georgia", "Times New Roman", serif;
            background:
                radial-gradient(circle at top, rgba(215,178,106,0.10), transparent 25%),
                linear-gradient(180deg, #06070a 0%, #0b0d12 45%, #06070a 100%);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        button,
        input {
            font: inherit;
        }

        .container {
            width: min(var(--max), calc(100% - 32px));
            margin: 0 auto;
        }

        .site-header {
            position: sticky;
            top: 0;
            z-index: 1000;
            backdrop-filter: blur(18px);
            background: rgba(7, 8, 11, 0.78);
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }

        .nav-wrap {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            flex-wrap: wrap;
            padding: 18px 0;
        }

        .brand {
            color: var(--white);
            font-size: 1.14rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .nav-links,
        .nav-actions {
            display: flex;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
        }

        .nav-links a {
            color: var(--muted);
            font-size: 0.95rem;
            transition: color 0.22s ease;
        }

        .nav-links a:hover,
        .nav-links a.active {
            color: var(--gold);
        }

        .btn {
            align-items: center;
            border: 1px solid transparent;
            border-radius: 999px;
            cursor: pointer;
            display: inline-flex;
            font-size: 0.95rem;
            font-weight: 700;
            justify-content: center;
            min-height: 50px;
            padding: 0 22px;
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease, background 0.18s ease;
            white-space: nowrap;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-gold {
            background: linear-gradient(135deg, var(--gold), var(--gold-light));
            box-shadow: 0 16px 38px rgba(215,181,109,.28);
            color: #171105;
        }

        .btn-light {
            background: rgba(255,255,255,0.05);
            border-color: rgba(255,255,255,0.12);
            color: var(--text);
        }

        .btn-block {
            width: 100%;
        }

        .card {
            background: linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.03));
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 28px;
        }

        .eyebrow {
            align-items: center;
            background: rgba(215,178,106,0.08);
            border: 1px solid rgba(215,178,106,0.24);
            border-radius: 999px;
            color: var(--gold-light);
            display: inline-flex;
            font-size: 0.78rem;
            font-weight: 700;
            gap: 10px;
            letter-spacing: 0.08em;
            margin-bottom: 16px;
            padding: 9px 15px;
            text-transform: uppercase;
        }

        .eyebrow::before {
            background: var(--gold);
            border-radius: 50%;
            box-shadow: 0 0 14px rgba(215,178,106,0.95);
            content: "";
            height: 8px;
            width: 8px;
        }

        .footer {
            color: var(--soft);
            display: flex;
            flex-wrap: wrap;
            font-size: 0.92rem;
            gap: 16px;
            justify-content: space-between;
            margin-top: 34px;
        }

        .footer-links {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
        }

        .footer-links a:hover {
            color: var(--gold-light);
        }

        .flash {
            border-radius: 18px;
            font-weight: 700;
            margin-bottom: 22px;
            padding: 16px 18px;
        }

        .flash.error {
            background: var(--danger-bg);
            border: 1px solid rgba(201, 92, 71, 0.30);
            color: var(--danger);
        }

        .flash.success {
            background: var(--success-bg);
            border: 1px solid rgba(90, 148, 73, 0.30);
            color: var(--success);
        }

        .helper,
        .sub,
        .lead {
            color: var(--muted);
            font-size: 1.02rem;
        }

        .helper {
            color: var(--soft);
            font-size: 0.94rem;
        }

        .hero {
            display: grid;
            gap: 24px;
            grid-template-columns: 1.2fr 0.8fr;
            margin-bottom: 26px;
        }

        .hero-pills,
        .mini-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 20px;
        }

        .page {
            padding: 34px 0 72px;
        }

        .pill {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.10);
            border-radius: 999px;
            color: var(--text);
            font-size: 0.92rem;
            padding: 10px 14px;
        }

        .plan-badge {
            align-self: flex-start;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.10);
            border-radius: 999px;
            color: var(--gold-light);
            display: inline-flex;
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            padding: 8px 12px;
            text-transform: uppercase;
        }

        .plan-card {
            display: flex;
            flex-direction: column;
            gap: 18px;
            min-height: 100%;
            position: relative;
        }

        .plan-card.selected {
            border-color: rgba(215,178,106,0.45);
            box-shadow: 0 26px 70px rgba(215,178,106,0.10), var(--shadow);
        }

        .plan-price {
            align-items: baseline;
            display: flex;
            gap: 10px;
        }

        .plan-price strong {
            color: var(--white);
            font-size: 2.3rem;
        }

        .plan-price span {
            color: var(--muted);
        }

        .plans-grid {
            display: grid;
            gap: 22px;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            margin-top: 20px;
        }

        .ready-box {
            background: linear-gradient(180deg, rgba(215,178,106,0.10), rgba(255,255,255,0.03));
            border: 1px solid rgba(215,178,106,0.22);
            border-radius: 22px;
            padding: 20px;
        }

        .ready-meta {
            color: var(--muted);
            display: grid;
            gap: 10px;
            margin-top: 14px;
        }

        .section-title {
            margin: 12px 0 18px;
        }

        .selection-panel {
            display: grid;
            gap: 24px;
            grid-template-columns: 1.1fr 0.9fr;
            margin-top: 28px;
        }

        .selection-summary {
            display: grid;
            gap: 18px;
        }

        .site-header + .page .container {
            position: relative;
        }

        .stack {
            display: grid;
            gap: 16px;
        }

        .summary-box,
        .tos-box {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 22px;
            padding: 20px;
        }

        .summary-row {
            color: var(--muted);
            display: flex;
            gap: 16px;
            justify-content: space-between;
        }

        .summary-row strong {
            color: var(--white);
        }

        .summary-rows {
            display: grid;
            gap: 10px;
            margin-top: 10px;
        }

        .tos-box label {
            align-items: flex-start;
            color: var(--muted);
            cursor: pointer;
            display: flex;
            gap: 12px;
        }

        .tos-box input[type="checkbox"] {
            accent-color: #d7b26a;
            flex: 0 0 auto;
            height: 18px;
            margin-top: 5px;
            width: 18px;
        }

        .tos-link {
            color: var(--gold-light);
            text-decoration: underline;
            text-underline-offset: 3px;
        }

        .value-note {
            color: var(--soft);
            font-size: 0.96rem;
        }

        .welcome-list {
            display: grid;
            gap: 14px;
            margin-top: 18px;
        }

        .welcome-item {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 18px;
            padding: 14px 16px;
        }

        h1, h2, h3 {
            line-height: 1.12;
        }

        h1 {
            font-size: clamp(2rem, 3vw, 3.4rem);
            margin-bottom: 16px;
        }

        h2 {
            font-size: clamp(1.55rem, 2vw, 2.2rem);
            margin-bottom: 12px;
        }

        h3 {
            font-size: 1.18rem;
            margin-bottom: 10px;
        }

        @media (max-width: 1100px) {
            .hero,
            .selection-panel,
            .plans-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 700px) {
            .card {
                border-radius: 22px;
                padding: 20px;
            }

            .footer {
                flex-direction: column;
            }

            .nav-actions .btn,
            .nav-links,
            .nav-actions {
                width: 100%;
            }

            .nav-wrap {
                align-items: flex-start;
            }

            .page {
                padding: 22px 0 54px;
            }

            .summary-row {
                flex-direction: column;
                gap: 4px;
            }
        }
    </style>
</head>
<body>
    <header class="site-header">
        <div class="container nav-wrap">
            <a href="index.php" class="brand">Doggie Dorian’s</a>

            <nav class="nav-links">
                <a href="index.php">Home</a>
                <a href="pricing.php">Pricing</a>
                <a href="memberships.php" class="active">Memberships</a>
                <a href="group-walks.php">Group Walks</a>
                <a href="contact.php">Contact</a>
            </nav>

            <div class="nav-actions">
                <a href="dashboard.php" class="btn btn-light">Dashboard</a>
                <a href="book-service.php" class="btn btn-gold">Book Services</a>
            </div>
        </div>
    </header>

    <main class="page">
        <div class="container">
            <?php if ($error !== ''): ?>
                <div class="flash error"><?php echo h($error); ?></div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="flash success"><?php echo h($success); ?></div>
            <?php endif; ?>

            <section class="hero">
                <div class="card">
                    <div class="eyebrow">Members Only Access</div>
                    <h1>Secure Your Founder Membership</h1>
                    <p class="lead">
                        You’re now inside the private member experience. Select your founder membership,
                        review what’s included, and complete your enrollment to unlock premium access.
                    </p>

                    <div class="hero-pills">
                        <div class="pill">Private member access</div>
                        <div class="pill">Founder-only pricing</div>
                        <div class="pill">Priority booking privileges</div>
                        <div class="pill">Premium service experience</div>
                    </div>
                </div>

                <div class="card">
                    <div class="eyebrow">Account Status</div>
                    <h2>Welcome<?php echo $currentUserName !== '' ? ', ' . h($currentUserName) : ''; ?></h2>
                    <p class="sub">
                        Your account is active and ready to proceed with membership enrollment.
                    </p>

                    <div class="welcome-list">
                        <div class="welcome-item">
                            <strong>Step 1:</strong> select the membership that fits your dog’s routine.
                        </div>
                        <div class="welcome-item">
                            <strong>Step 2:</strong> review and accept the membership terms.
                        </div>
                        <div class="welcome-item">
                            <strong>Step 3:</strong> proceed to secure checkout to activate your membership.
                        </div>
                    </div>
                </div>
            </section>

            <section class="stack">
                <div class="section-title">
                    <div class="eyebrow">Founder Collection</div>
                    <h2>Founder Membership Collection</h2>
                    <p class="sub">
                        Exclusive access, priority scheduling, and premium care designed for a higher standard of service.
                    </p>
                </div>

                <div class="plans-grid">
                    <?php foreach ($plans as $plan): ?>
                        <?php $isSelected = $selectedPlan !== null && $selectedPlan['slug'] === $plan['slug']; ?>
                        <div class="card plan-card<?php echo $isSelected ? ' selected' : ''; ?>" id="<?php echo h($plan['slug']); ?>">
                            <div class="plan-badge"><?php echo h($plan['tag']); ?></div>

                            <div>
                                <h3><?php echo h($plan['name']); ?></h3>
                                <p class="sub"><?php echo h($plan['summary']); ?></p>
                            </div>

                            <div class="plan-price">
                                <strong>$<?php echo number_format((int) $plan['price']); ?></strong>
                                <span>/ month</span>
                            </div>

                            <div class="value-note">Estimated membership value: $<?php echo number_format((int) $plan['value']); ?>+</div>

                            <ul class="feature-list">
                                <?php foreach ($plan['features'] as $feature): ?>
                                    <li><?php echo h($feature); ?></li>
                                <?php endforeach; ?>
                            </ul>

                            <a class="btn <?php echo $isSelected ? 'btn-gold' : 'btn-light'; ?> btn-block" href="memberships.php?plan=<?php echo rawurlencode($plan['slug']); ?>#selection">
                                <?php echo $isSelected ? 'Selected Plan' : 'Choose This Plan'; ?>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="selection-panel" id="selection">
                <div class="card selection-summary">
                    <div class="eyebrow">Membership Checkout</div>
                    <h2><?php echo $selectedPlan ? 'Confirm Your Selection' : 'Select Your Membership'; ?></h2>
                    <p class="sub">
                        Confirm your membership selection and prepare for a seamless, secure checkout experience.
                    </p>

                    <div class="summary-box">
                        <?php if ($selectedPlan): ?>
                            <h3><?php echo h($selectedPlan['name']); ?></h3>
                            <div class="summary-rows">
                                <div class="summary-row">
                                    <span>Membership</span>
                                    <strong><?php echo h($selectedPlan['name']); ?></strong>
                                </div>
                                <div class="summary-row">
                                    <span>Recurring price</span>
                                    <strong>$<?php echo number_format((int) $selectedPlan['price']); ?>/month</strong>
                                </div>
                                <div class="summary-row">
                                    <span>Terms version</span>
                                    <strong><?php echo h($tosVersion); ?></strong>
                                </div>
                                <div class="summary-row">
                                    <span>Status</span>
                                    <strong><?php echo $checkoutReady ? 'Ready for secure checkout' : 'Waiting for terms acceptance'; ?></strong>
                                </div>
                            </div>

                            <div class="mini-pills">
                                <div class="pill">Secure recurring membership</div>
                                <div class="pill">Protected account setup</div>
                                <div class="pill">Ready for activation</div>
                            </div>
                        <?php else: ?>
                            <p class="sub">
                                Select one of the founder memberships above to unlock exclusive access and continue your enrollment.
                            </p>
                        <?php endif; ?>
                    </div>

                    <?php if ($checkoutReady && is_array($checkoutPayload) && $selectedPlan): ?>
                        <div class="ready-box">
                            <h3>Selection Saved</h3>
                            <p class="sub">
                                Your membership selection and terms acceptance have been saved to your secure checkout session.
                            </p>

                            <div class="ready-meta">
                                <div><strong>Plan:</strong> <?php echo h((string) $checkoutPayload['plan_name']); ?></div>
                                <div><strong>Plan ID:</strong> <?php echo h((string) $checkoutPayload['plan_id']); ?></div>
                                <div><strong>TOS Accepted:</strong> Yes</div>
                                <div><strong>TOS Version:</strong> <?php echo h((string) $checkoutPayload['tos_version']); ?></div>
                                <div><strong>Accepted At:</strong> <?php echo h((string) $checkoutPayload['tos_accepted_at']); ?></div>
                            </div>

                            <div style="margin-top:18px;">
                                <a href="dashboard.php" class="btn btn-light">Return to Dashboard</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="eyebrow">Terms Agreement</div>
                    <h2>Final Step: Secure Your Membership</h2>
                    <p class="sub">
                        To finalize your membership, please review and accept the Membership Terms of Service.
                    </p>

                    <form method="post" action="memberships.php<?php echo $selectedPlan ? '?plan=' . rawurlencode($selectedPlan['slug']) : ''; ?>#selection" class="stack" style="margin-top: 18px;">
                        <input type="hidden" name="membership_csrf_token" value="<?php echo h($csrfToken); ?>">
                        <input type="hidden" name="plan" value="<?php echo h($selectedPlan['slug'] ?? ''); ?>">

                        <div class="tos-box">
                            <label for="agree_tos">
                                <input type="checkbox" id="agree_tos" name="agree_tos" value="1" <?php echo $checkoutReady ? 'checked' : ''; ?>>
                                <span>
                                    I agree to the <a class="tos-link" href="tos.php" target="_blank" rel="noopener noreferrer">Doggie Dorian’s Membership Terms of Service</a>,
                                    including billing terms, usage guidelines, and founder membership conditions.
                                </span>
                            </label>
                        </div>

                        <div class="helper">
                            Selected plan:
                            <strong><?php echo $selectedPlan ? h($selectedPlan['name']) : 'None selected yet'; ?></strong>
                        </div>

                        <button type="submit" class="btn btn-gold btn-block">
                            Continue to Secure Checkout
                        </button>

                        <div class="helper">
                            Complete your selection to continue to secure checkout and activate your membership.
                        </div>
                    </form>
                </div>
            </section>

            <footer class="footer">
                <div>
                    © <?php echo date('Y'); ?> Doggie Dorian’s — exclusive memberships, elevated care, and a premium client experience.
                </div>

                <div class="footer-links">
                    <a href="tos.php">Terms of Service</a>
                    <a href="privacy-policy.php">Privacy Policy</a>
                    <a href="legal-notice.php">Legal Notice</a>
                </div>
            </footer>
        </div>
    </main>
</body>
</html>