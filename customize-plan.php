<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/member_config.php';
require_once __DIR__ . '/includes/pricing.php';

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('money_fmt')) {
    function money_fmt(float $amount): string
    {
        return '$' . number_format($amount, 2);
    }
}

if (!function_exists('redirectTo')) {
    function redirectTo(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }
}

function hasTable(PDO $pdo, string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :name LIMIT 1");
        $stmt->execute([':name' => $table]);
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
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    if (!hasTable($pdo, $table)) {
        $cache[$table] = [];
        return [];
    }

    try {
        $safeTable = str_replace('"', '""', $table);
        $stmt = $pdo->query('PRAGMA table_info("' . $safeTable . '")');
        $columns = [];

        if ($stmt) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($row['name'])) {
                    $columns[] = (string) $row['name'];
                }
            }
        }

        $cache[$table] = $columns;
        return $columns;
    } catch (Throwable $e) {
        $cache[$table] = [];
        return [];
    } catch (Exception $e) {
        $cache[$table] = [];
        return [];
    }
}

function firstExistingColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection is not available.';
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = (string) $_SESSION['csrf_token'];

$member = currentMember($pdo);

if (!$member || (int) ($member['id'] ?? 0) <= 0) {
    $_SESSION['custom_plan_flash_type'] = 'error';
    $_SESSION['custom_plan_flash_message'] = 'Custom plans are reserved for members. Please create or sign in to your member account first.';
    redirectTo('signup.php');
}

$flashType = (string) ($_SESSION['custom_plan_flash_type'] ?? '');
$flashMessage = (string) ($_SESSION['custom_plan_flash_message'] ?? '');
unset($_SESSION['custom_plan_flash_type'], $_SESSION['custom_plan_flash_message']);

$memberRates = [
    'walks_15' => (float) dd_get_service_pricing('walk', true, ['duration_minutes' => 15])['unit_price'],
    'walks_20' => (float) dd_get_service_pricing('walk', true, ['duration_minutes' => 20])['unit_price'],
    'walks_30' => (float) dd_get_service_pricing('walk', true, ['duration_minutes' => 30])['unit_price'],
    'walks_45' => (float) dd_get_service_pricing('walk', true, ['duration_minutes' => 45])['unit_price'],
    'walks_60' => (float) dd_get_service_pricing('walk', true, ['duration_minutes' => 60])['unit_price'],
    'daycare_small' => (float) dd_get_service_pricing('daycare', true, ['dog_size' => 'small', 'quantity' => 1])['unit_price'],
    'daycare_medium' => (float) dd_get_service_pricing('daycare', true, ['dog_size' => 'medium', 'quantity' => 1])['unit_price'],
    'daycare_large' => (float) dd_get_service_pricing('daycare', true, ['dog_size' => 'large', 'quantity' => 1])['unit_price'],
    'boarding_small' => (float) dd_get_service_pricing('boarding', true, ['dog_size' => 'small', 'quantity' => 1])['unit_price'],
    'boarding_medium' => (float) dd_get_service_pricing('boarding', true, ['dog_size' => 'medium', 'quantity' => 1])['unit_price'],
    'boarding_large' => (float) dd_get_service_pricing('boarding', true, ['dog_size' => 'large', 'quantity' => 1])['unit_price'],
    'drop_ins' => (float) dd_get_service_pricing('drop_in', true, ['quantity' => 1, 'add_walk' => false])['unit_price'],
];

$discountThreshold = 500.00;
$discountPercent = 0.10;

$errors = [];
$planName = '';

$walks15 = 0;
$walks20 = 0;
$walks30 = 0;
$walks45 = 0;
$walks60 = 0;

$daycareSmall = 0;
$daycareMedium = 0;
$daycareLarge = 0;

$boardingSmall = 0;
$boardingMedium = 0;
$boardingLarge = 0;

$dropIns = 0;

$availableCustomPlanColumns = getTableColumns($pdo, 'custom_plans');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = (string) ($_POST['csrf_token'] ?? '');

    if ($postedCsrf === '' || !hash_equals($csrfToken, $postedCsrf)) {
        $errors[] = 'Your session expired. Please refresh the page and try again.';
    }

    $planName = trim((string) ($_POST['plan_name'] ?? ''));

    $walks15 = max(0, (int) ($_POST['walks_15'] ?? 0));
    $walks20 = max(0, (int) ($_POST['walks_20'] ?? 0));
    $walks30 = max(0, (int) ($_POST['walks_30'] ?? 0));
    $walks45 = max(0, (int) ($_POST['walks_45'] ?? 0));
    $walks60 = max(0, (int) ($_POST['walks_60'] ?? 0));

    $daycareSmall = max(0, (int) ($_POST['daycare_small'] ?? 0));
    $daycareMedium = max(0, (int) ($_POST['daycare_medium'] ?? 0));
    $daycareLarge = max(0, (int) ($_POST['daycare_large'] ?? 0));

    $boardingSmall = max(0, (int) ($_POST['boarding_small'] ?? 0));
    $boardingMedium = max(0, (int) ($_POST['boarding_medium'] ?? 0));
    $boardingLarge = max(0, (int) ($_POST['boarding_large'] ?? 0));

    $dropIns = max(0, (int) ($_POST['drop_ins'] ?? 0));

    if ($planName === '') {
        $errors[] = 'Please enter a plan name.';
    }

    if (
        $walks15 === 0 &&
        $walks20 === 0 &&
        $walks30 === 0 &&
        $walks45 === 0 &&
        $walks60 === 0 &&
        $daycareSmall === 0 &&
        $daycareMedium === 0 &&
        $daycareLarge === 0 &&
        $boardingSmall === 0 &&
        $boardingMedium === 0 &&
        $boardingLarge === 0 &&
        $dropIns === 0
    ) {
        $errors[] = 'Please add at least one service to your plan.';
    }

    if (empty($availableCustomPlanColumns)) {
        $errors[] = 'The custom_plans table could not be read. Please verify your database connection.';
    }

    $subtotal =
        ($walks15 * $memberRates['walks_15']) +
        ($walks20 * $memberRates['walks_20']) +
        ($walks30 * $memberRates['walks_30']) +
        ($walks45 * $memberRates['walks_45']) +
        ($walks60 * $memberRates['walks_60']) +
        ($daycareSmall * $memberRates['daycare_small']) +
        ($daycareMedium * $memberRates['daycare_medium']) +
        ($daycareLarge * $memberRates['daycare_large']) +
        ($boardingSmall * $memberRates['boarding_small']) +
        ($boardingMedium * $memberRates['boarding_medium']) +
        ($boardingLarge * $memberRates['boarding_large']) +
        ($dropIns * $memberRates['drop_ins']);

    $subtotal = round($subtotal, 2);
    $discountAmount = $subtotal > $discountThreshold ? round($subtotal * $discountPercent, 2) : 0.00;
    $monthlyTotal = max(0, round($subtotal - $discountAmount, 2));

    if (!$errors) {
        try {
            $totalDaycareDays = $daycareSmall + $daycareMedium + $daycareLarge;
            $totalBoardingNights = $boardingSmall + $boardingMedium + $boardingLarge;

            $dataMap = [
                'member_id' => (int) $member['id'],
                'plan_name' => $planName,
                'walks_15' => $walks15,
                'walks_20' => $walks20,
                'walks_30' => $walks30,
                'walks_45' => $walks45,
                'walks_60' => $walks60,
                'daycare_days' => $totalDaycareDays,
                'boarding_nights' => $totalBoardingNights,
                'drop_ins' => $dropIns,
                'monthly_total' => $monthlyTotal,
                'payment_mode' => 'upfront',
                'payment_status' => 'pending',
                'daycare_small' => $daycareSmall,
                'daycare_medium' => $daycareMedium,
                'daycare_large' => $daycareLarge,
                'boarding_small' => $boardingSmall,
                'boarding_medium' => $boardingMedium,
                'boarding_large' => $boardingLarge,
                'discount_amount' => $discountAmount,
                'discount_percent' => $discountAmount > 0 ? 10 : 0,
                'subtotal_amount' => $subtotal,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            $columns = [];
            $values = [];
            $params = [];

            foreach ($dataMap as $column => $value) {
                if (in_array($column, $availableCustomPlanColumns, true)) {
                    $columns[] = $column;
                    $values[] = ':' . $column;
                    $params[':' . $column] = $value;
                }
            }

            if (empty($columns)) {
                throw new RuntimeException('No compatible columns were found in custom_plans.');
            }

            $sql = '
                INSERT INTO custom_plans (
                    ' . implode(', ', $columns) . '
                ) VALUES (
                    ' . implode(', ', $values) . '
                )
            ';

            $insert = $pdo->prepare($sql);
            $insert->execute($params);

            $planId = (int) $pdo->lastInsertId();

            redirectTo('payment-portal.php?mode=custom_plan&plan_id=' . $planId);
        } catch (Throwable $e) {
            error_log('customize-plan.php save error: ' . $e->getMessage());
            $errors[] = 'The custom plan could not be saved. Please try again.';
        } catch (Exception $e) {
            error_log('customize-plan.php save error: ' . $e->getMessage());
            $errors[] = 'The custom plan could not be saved. Please try again.';
        }
    }
}

$plans = [];

try {
    if (!empty($availableCustomPlanColumns)) {
        $orderColumn = firstExistingColumn($availableCustomPlanColumns, ['created_at', 'id', 'plan_id']);
        if ($orderColumn === null) {
            $orderColumn = 'rowid';
        }

        $safeOrderColumn = $orderColumn === 'rowid'
            ? 'rowid'
            : '"' . str_replace('"', '""', $orderColumn) . '"';

        $stmt = $pdo->prepare("
            SELECT *
            FROM custom_plans
            WHERE member_id = :member_id
            ORDER BY {$safeOrderColumn} DESC
            LIMIT 8
        ");
        $stmt->execute([':member_id' => (int) $member['id']]);
        $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $plans = [];
}

$pageTitle = 'Custom Plan Builder';
$pageEyebrow = 'Member-Only Plan Builder';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Custom Plan Builder | Doggie Dorian's</title>
  <meta name="description" content="Build a custom Doggie Dorian's member care plan with walks, daycare, boarding, and drop-ins.">
  <meta name="theme-color" content="#07080b">
  <style>
    * { box-sizing: border-box; }

    :root{
      --dd-bg:#07080b;
      --dd-bg-soft:#0d1016;
      --dd-panel:rgba(255,255,255,0.05);
      --dd-line:rgba(255,255,255,0.10);
      --dd-text:#f6f1e8;
      --dd-muted:#c9c0af;
      --dd-gold:#d7b26a;
      --dd-gold-light:#f0d59f;
      --dd-shadow:0 22px 65px rgba(0,0,0,0.38);
      --dd-radius:28px;
    }

    html{
      scroll-behavior:smooth;
    }

    body{
      margin:0;
      background:
        radial-gradient(circle at top, rgba(215,178,106,0.10), transparent 25%),
        linear-gradient(180deg, #06070a 0%, #0b0d12 45%, #06070a 100%);
      color:var(--dd-text);
      font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      min-height:100vh;
    }

    a{
      color:inherit;
      text-decoration:none;
    }

    .page-nav{
      position:sticky;
      top:0;
      z-index:1000;
      width:100%;
      background:linear-gradient(180deg, rgba(7,8,11,0.95), rgba(7,8,11,0.78));
      backdrop-filter:blur(18px);
      -webkit-backdrop-filter:blur(18px);
      border-bottom:1px solid rgba(255,255,255,0.08);
    }

    .page-nav-shell{
      max-width:1320px;
      margin:0 auto;
      padding:16px 20px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:18px;
      flex-wrap:wrap;
    }

    .page-brand{
      color:#f6f1e8;
      text-decoration:none;
      font-size:1.35rem;
      font-weight:900;
      letter-spacing:.03em;
    }

    .page-nav-links{
      display:flex;
      align-items:center;
      gap:12px;
      flex-wrap:wrap;
    }

    .page-nav-links a{
      padding:10px 14px;
      border-radius:999px;
      background:rgba(255,255,255,0.06);
      border:1px solid rgba(255,255,255,0.08);
      color:rgba(246,241,232,0.86);
      font-size:.95rem;
      font-weight:700;
    }

    .page-nav-links a:hover,
    .page-nav-links a.active{
      color:#171105;
      background:linear-gradient(135deg, var(--dd-gold), var(--dd-gold-light));
      border-color:rgba(255,255,255,0.14);
    }

    .page-nav-actions{
      display:flex;
      align-items:center;
      gap:12px;
      flex-wrap:wrap;
    }

    .page-btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:46px;
      padding:0 18px;
      border-radius:999px;
      text-decoration:none;
      font-weight:800;
    }

    .page-btn-dark{
      background:rgba(255,255,255,0.05);
      border:1px solid rgba(255,255,255,0.10);
      color:#f6f1e8;
    }

    .page-btn-gold{
      background:linear-gradient(135deg, var(--dd-gold), var(--dd-gold-light));
      color:#171105;
      box-shadow:0 14px 34px rgba(215,178,106,0.22);
    }

    .plan-page{
      color:var(--dd-text);
      min-height:calc(100vh - 120px);
      padding:34px 18px 72px;
    }

    .plan-shell{
      max-width:1320px;
      margin:0 auto;
      display:grid;
      gap:24px;
    }

    .page-intro{
      display:grid;
      grid-template-columns:1.15fr .85fr;
      gap:24px;
    }

    .intro-card,
    .plan-card,
    .summary-box,
    .hero-status{
      background:linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.03));
      border:1px solid var(--dd-line);
      border-radius:var(--dd-radius);
      box-shadow:var(--dd-shadow);
    }

    .intro-card{
      padding:32px 28px;
    }

    .hero-status{
      padding:28px 24px;
    }

    .plan-eyebrow{
      display:inline-flex;
      align-items:center;
      gap:10px;
      padding:9px 15px;
      border-radius:999px;
      border:1px solid rgba(215,178,106,0.24);
      background:rgba(215,178,106,0.08);
      color:var(--dd-gold-light);
      font-size:.78rem;
      font-weight:800;
      letter-spacing:.08em;
      text-transform:uppercase;
      margin-bottom:16px;
    }

    .plan-eyebrow::before{
      content:"";
      width:8px;
      height:8px;
      border-radius:50%;
      background:var(--dd-gold);
      box-shadow:0 0 14px rgba(215,178,106,0.95);
    }

    .intro-card h1{
      margin:0 0 14px;
      font-size:clamp(2rem,3vw,3.2rem);
      line-height:1.06;
      color:#fff;
    }

    .intro-card p{
      margin:0;
      max-width:860px;
      color:var(--dd-muted);
      line-height:1.7;
      font-size:1rem;
    }

    .hero-actions{
      display:flex;
      gap:12px;
      flex-wrap:wrap;
      margin-top:22px;
    }

    .hero-link{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:48px;
      padding:0 18px;
      border-radius:999px;
      background:rgba(255,255,255,0.05);
      border:1px solid rgba(255,255,255,0.10);
      color:#fff;
      font-weight:800;
      text-decoration:none;
    }

    .hero-link-gold{
      background:linear-gradient(135deg, var(--dd-gold), var(--dd-gold-light));
      color:#171105;
      border:1px solid rgba(255,255,255,0.12);
      box-shadow:0 16px 38px rgba(215,181,109,.20);
    }

    .hero-status h2{
      margin:0 0 10px;
      font-size:1.45rem;
      line-height:1.15;
    }

    .hero-status p{
      margin:0;
      color:var(--dd-muted);
      line-height:1.65;
    }

    .status-list{
      display:grid;
      gap:12px;
      margin-top:18px;
    }

    .status-item{
      padding:14px 16px;
      border-radius:18px;
      background:rgba(255,255,255,0.04);
      border:1px solid rgba(255,255,255,0.08);
      color:var(--dd-muted);
      line-height:1.55;
    }

    .message{
      border-radius:18px;
      padding:15px 18px;
      border:1px solid rgba(255,255,255,0.10);
    }

    .message.error{
      background:rgba(214,123,123,0.14);
      border-color:rgba(214,123,123,0.30);
      color:#ffd5d5;
    }

    .message.info{
      background:rgba(198,178,139,0.12);
      border-color:rgba(198,178,139,0.25);
      color:#f3e5c7;
    }

    .plan-layout{
      display:grid;
      grid-template-columns:minmax(0, 1.1fr) minmax(360px, .9fr);
      gap:24px;
      align-items:start;
    }

    .plan-main,
    .plan-side{
      display:grid;
      gap:24px;
    }

    .plan-card{
      padding:24px;
    }

    .plan-card h2{
      margin:0 0 14px;
      font-size:1.42rem;
      line-height:1.2;
    }

    .plan-sub{
      color:var(--dd-muted);
      line-height:1.6;
      margin-bottom:16px;
    }

    .note-box{
      background:rgba(212,175,55,0.10);
      border:1px solid rgba(212,175,55,0.22);
      color:#f3e5c7;
      border-radius:18px;
      padding:15px 16px;
      line-height:1.6;
    }

    .form-group{
      display:flex;
      flex-direction:column;
      margin-bottom:16px;
    }

    .form-group label{
      font-weight:800;
      margin-bottom:8px;
    }

    .form-group input{
      padding:14px 16px;
      border:1px solid rgba(255,255,255,0.10);
      border-radius:16px;
      font-size:15px;
      background:rgba(0,0,0,0.26);
      color:#fff;
    }

    .form-group input::placeholder,
    .qty-input input::placeholder{
      color:rgba(255,255,255,0.42);
    }

    .section-heading{
      margin:22px 0 12px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:14px;
      flex-wrap:wrap;
    }

    .section-label{
      font-size:13px;
      text-transform:uppercase;
      letter-spacing:1px;
      color:rgba(244,241,234,0.58);
      font-weight:800;
    }

    .section-caption{
      color:rgba(244,241,234,0.52);
      font-size:13px;
    }

    .service-grid{
      display:grid;
      gap:12px;
    }

    .service-card{
      background:rgba(255,255,255,0.04);
      border:1px solid rgba(255,255,255,0.06);
      border-radius:18px;
      padding:16px;
    }

    .service-top{
      margin-bottom:12px;
    }

    .service-top strong{
      display:block;
      color:#fff;
      margin-bottom:4px;
    }

    .service-top span{
      color:rgba(244,241,234,0.68);
      font-size:14px;
      line-height:1.55;
    }

    .service-prices{
      display:grid;
      grid-template-columns:1fr auto;
      gap:10px;
      align-items:center;
    }

    .price-box{
      background:rgba(255,255,255,0.05);
      border:1px solid rgba(255,255,255,0.06);
      border-radius:14px;
      padding:12px 14px;
    }

    .price-box small{
      display:block;
      color:rgba(244,241,234,0.58);
      font-size:12px;
      margin-bottom:4px;
    }

    .price-box b{
      color:#fff;
      font-size:15px;
    }

    .qty-input input{
      width:118px;
      padding:12px 14px;
      border:1px solid rgba(255,255,255,0.10);
      border-radius:14px;
      font-size:15px;
      background:rgba(0,0,0,0.26);
      color:#fff;
      text-align:center;
    }

    .action-row{
      display:flex;
      gap:12px;
      flex-wrap:wrap;
      margin-top:20px;
    }

    .save-button{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      background:linear-gradient(135deg, var(--dd-gold), var(--dd-gold-light));
      color:#171105;
      border:none;
      border-radius:999px;
      padding:14px 22px;
      font-weight:900;
      cursor:pointer;
      box-shadow:0 16px 38px rgba(215,181,109,.20);
    }

    .secondary-link{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      border-radius:999px;
      padding:14px 18px;
      border:1px solid rgba(255,255,255,0.10);
      background:rgba(255,255,255,0.04);
      color:#fff;
      text-decoration:none;
      font-weight:800;
    }

    .summary-box{
      padding:24px;
      color:#fff;
    }

    .summary-box h3{
      margin:0 0 10px;
      font-size:1.2rem;
    }

    .summary-total{
      font-size:42px;
      font-weight:900;
      color:#f2d471;
      margin:10px 0 8px;
      line-height:1;
    }

    .summary-sub{
      color:rgba(255,255,255,0.82);
      line-height:1.55;
    }

    .summary-list{
      display:grid;
      gap:10px;
      margin-top:18px;
    }

    .summary-item{
      display:flex;
      justify-content:space-between;
      gap:12px;
      padding-bottom:10px;
      border-bottom:1px solid rgba(255,255,255,0.10);
    }

    .summary-empty{
      color:rgba(255,255,255,0.62);
    }

    .summary-discount{
      margin-top:14px;
      padding:12px 14px;
      border-radius:14px;
      background:rgba(125,206,141,0.14);
      border:1px solid rgba(125,206,141,0.26);
      color:#d7f1dd;
      font-size:14px;
      line-height:1.5;
    }

    .detail-list{
      display:grid;
      gap:10px;
      margin-top:16px;
    }

    .detail-item{
      display:flex;
      justify-content:space-between;
      gap:14px;
      padding:12px 14px;
      background:rgba(255,255,255,0.04);
      border:1px solid rgba(255,255,255,0.06);
      border-radius:14px;
    }

    .saved-plans-title{
      margin:0 0 12px;
    }

    .saved-plans-sub{
      color:rgba(244,241,234,0.72);
      line-height:1.6;
      margin-bottom:16px;
    }

    .saved-plan-list{
      display:grid;
      gap:14px;
    }

    .saved-plan{
      background:rgba(255,255,255,0.04);
      border:1px solid rgba(255,255,255,0.06);
      border-radius:18px;
      padding:16px;
    }

    .saved-plan h3{
      margin:0 0 10px;
      font-size:18px;
    }

    .saved-plan-meta{
      color:rgba(244,241,234,0.65);
      font-size:14px;
      margin-bottom:14px;
    }

    .saved-plan-grid{
      display:grid;
      grid-template-columns:repeat(2,1fr);
      gap:10px 12px;
    }

    .saved-plan-box{
      background:rgba(255,255,255,0.05);
      border:1px solid rgba(255,255,255,0.06);
      border-radius:14px;
      padding:12px 14px;
    }

    .saved-plan-box strong{
      display:block;
      font-size:12px;
      text-transform:uppercase;
      letter-spacing:1px;
      color:rgba(244,241,234,0.58);
      margin-bottom:6px;
    }

    .empty-state{
      background:rgba(255,255,255,0.04);
      border:1px solid rgba(255,255,255,0.06);
      border-radius:18px;
      padding:18px;
      color:rgba(244,241,234,0.68);
      line-height:1.6;
    }

    .site-footer{
      margin-top:40px;
      border-top:1px solid rgba(255,255,255,0.08);
      background:rgba(255,255,255,0.02);
    }

    .footer-container{
      max-width:1320px;
      margin:0 auto;
      padding:24px 18px 14px;
      display:flex;
      justify-content:space-between;
      gap:18px;
      flex-wrap:wrap;
    }

    .footer-brand h3{
      margin:0 0 8px;
      color:#fff;
      font-size:1.1rem;
    }

    .footer-brand p{
      margin:0;
      color:rgba(244,241,234,0.62);
      line-height:1.6;
      max-width:520px;
    }

    .footer-links{
      display:flex;
      gap:12px;
      flex-wrap:wrap;
      align-items:flex-start;
    }

    .footer-links a{
      color:rgba(244,241,234,0.72);
      font-weight:700;
    }

    .footer-links a:hover{
      color:var(--dd-gold-light);
    }

    .footer-bottom{
      max-width:1320px;
      margin:0 auto;
      padding:0 18px 24px;
      color:rgba(244,241,234,0.54);
      font-size:.92rem;
    }

    @media (max-width:1080px){
      .page-intro,
      .plan-layout{
        grid-template-columns:1fr;
      }
    }

    @media (max-width:760px){
      .page-nav-shell{
        flex-direction:column;
        align-items:flex-start;
      }

      .page-nav-links,
      .page-nav-actions{
        width:100%;
        flex-wrap:wrap;
      }

      .saved-plan-grid,
      .service-prices{
        grid-template-columns:1fr;
      }

      .qty-input input{
        width:100%;
      }

      .intro-card,
      .hero-status,
      .plan-card,
      .summary-box{
        padding:20px;
        border-radius:22px;
      }

      .intro-card h1{
        font-size:1.95rem;
      }

      .plan-page{
        padding:22px 16px 56px;
      }

      .footer-container{
        flex-direction:column;
      }
    }
  </style>
</head>
<body>

<nav class="page-nav">
  <div class="page-nav-shell">
    <a href="index.php" class="page-brand">Doggie Dorian's</a>

    <div class="page-nav-links">
      <a href="index.php">Home</a>
      <a href="services.php">Services</a>
      <a href="memberships.php" class="active">Membership</a>
      <a href="contact.php">Contact</a>
    </div>

    <div class="page-nav-actions">
      <a href="dashboard.php" class="page-btn page-btn-dark">Dashboard</a>
      <a href="book-service.php" class="page-btn page-btn-gold">Book Services</a>
    </div>
  </div>
</nav>

<main class="plan-page">
  <div class="plan-shell">

    <section class="page-intro">
      <div class="intro-card">
        <div class="plan-eyebrow">Member-Only Custom Plans</div>
        <h1>Build a private care plan around how you actually book.</h1>
        <p>
          Combine member-rate walks, daycare, boarding, and drop-ins into one custom package that fits your dog’s real routine. Plans above <?= h(money_fmt($discountThreshold)) ?> automatically receive a 10% discount before payment.
        </p>

        <div class="hero-actions">
          <a href="dashboard.php" class="hero-link">Dashboard</a>
          <a href="book-service.php" class="hero-link">Book Services</a>
          <a href="memberships.php" class="hero-link">Memberships</a>
          <a href="pricing.php" class="hero-link hero-link-gold">Review Pricing</a>
        </div>
      </div>

      <div class="hero-status">
        <div class="plan-eyebrow">How It Works</div>
        <h2>Luxury flexibility, cleaner monthly planning.</h2>
        <p>
          This page is for members who want a more tailored service mix than a standard membership tier. Build the package, review the live total, then continue into payment.
        </p>

        <div class="status-list">
          <div class="status-item"><strong>Step 1:</strong> Name your plan and choose service quantities.</div>
          <div class="status-item"><strong>Step 2:</strong> Live pricing updates automatically using member rates.</div>
          <div class="status-item"><strong>Step 3:</strong> Continue to payment once the package feels right.</div>
        </div>
      </div>
    </section>

    <?php if ($flashMessage !== ''): ?>
      <div class="message <?= h($flashType === 'error' ? 'error' : 'info') ?>"><?= h($flashMessage) ?></div>
    <?php endif; ?>

    <section class="plan-layout">
      <div class="plan-main">
        <div class="plan-card">
          <h2>Build Your Custom Plan</h2>
          <div class="plan-sub">
            Name your plan, set your service quantities, and continue to payment once the mix feels right.
          </div>

          <div class="note-box">
            Member pricing is pulled from your centralized pricing engine. When your subtotal passes <?= h(money_fmt($discountThreshold)) ?>, the plan receives an automatic 10% discount.
          </div>

          <?php if ($errors): ?>
            <div class="message error" style="margin-top:16px;">
              <?php foreach ($errors as $error): ?>
                <div><?= h($error) ?></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <form method="post" action="" id="planForm">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

            <div class="form-group">
              <label for="plan_name">Plan Name</label>
              <input id="plan_name" name="plan_name" type="text" value="<?= h($planName) ?>" placeholder="Example: Bentley VIP Monthly Plan" required>
            </div>

            <div class="section-heading">
              <div class="section-label">Walks</div>
              <div class="section-caption">Build your recurring walk cadence</div>
            </div>

            <div class="service-grid">
              <?php
              $walkCards = [
                  ['walks_15', '15 Minute Walks', 'Quick relief and short support visits', $memberRates['walks_15'], $walks15],
                  ['walks_20', '20 Minute Walks', 'Short daily care and routine support', $memberRates['walks_20'], $walks20],
                  ['walks_30', '30 Minute Walks', 'Balanced exercise and stimulation', $memberRates['walks_30'], $walks30],
                  ['walks_45', '45 Minute Walks', 'Extended walk for higher energy dogs', $memberRates['walks_45'], $walks45],
                  ['walks_60', '60 Minute Walks', 'Premium full-hour walk experience', $memberRates['walks_60'], $walks60],
              ];
              foreach ($walkCards as [$field, $title, $sub, $rate, $value]): ?>
                <div class="service-card">
                  <div class="service-top">
                    <strong><?= h($title) ?></strong>
                    <span><?= h($sub) ?></span>
                  </div>
                  <div class="service-prices">
                    <div class="price-box">
                      <small>Member Rate</small>
                      <b><?= h(money_fmt((float) $rate)) ?></b>
                    </div>
                    <div class="qty-input">
                      <input type="number" min="0" name="<?= h($field) ?>" id="<?= h($field) ?>" value="<?= h((string) $value) ?>" placeholder="Qty">
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="section-heading">
              <div class="section-label">Daycare</div>
              <div class="section-caption">Choose care by dog size</div>
            </div>

            <div class="service-grid">
              <?php
              $daycareCards = [
                  ['daycare_small', 'Daycare Days — Small Dog', 'Include small-dog daycare days in your plan', $memberRates['daycare_small'], $daycareSmall],
                  ['daycare_medium', 'Daycare Days — Medium Dog', 'Include medium-dog daycare days in your plan', $memberRates['daycare_medium'], $daycareMedium],
                  ['daycare_large', 'Daycare Days — Large Dog', 'Include large-dog daycare days in your plan', $memberRates['daycare_large'], $daycareLarge],
              ];
              foreach ($daycareCards as [$field, $title, $sub, $rate, $value]): ?>
                <div class="service-card">
                  <div class="service-top">
                    <strong><?= h($title) ?></strong>
                    <span><?= h($sub) ?></span>
                  </div>
                  <div class="service-prices">
                    <div class="price-box">
                      <small>Member Rate</small>
                      <b><?= h(money_fmt((float) $rate)) ?></b>
                    </div>
                    <div class="qty-input">
                      <input type="number" min="0" name="<?= h($field) ?>" id="<?= h($field) ?>" value="<?= h((string) $value) ?>" placeholder="Qty">
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="section-heading">
              <div class="section-label">Boarding</div>
              <div class="section-caption">Add overnight care by dog size</div>
            </div>

            <div class="service-grid">
              <?php
              $boardingCards = [
                  ['boarding_small', 'Boarding Nights — Small Dog', 'Include overnight boarding for small dogs', $memberRates['boarding_small'], $boardingSmall],
                  ['boarding_medium', 'Boarding Nights — Medium Dog', 'Include overnight boarding for medium dogs', $memberRates['boarding_medium'], $boardingMedium],
                  ['boarding_large', 'Boarding Nights — Large Dog', 'Include overnight boarding for large dogs', $memberRates['boarding_large'], $boardingLarge],
              ];
              foreach ($boardingCards as [$field, $title, $sub, $rate, $value]): ?>
                <div class="service-card">
                  <div class="service-top">
                    <strong><?= h($title) ?></strong>
                    <span><?= h($sub) ?></span>
                  </div>
                  <div class="service-prices">
                    <div class="price-box">
                      <small>Member Rate</small>
                      <b><?= h(money_fmt((float) $rate)) ?></b>
                    </div>
                    <div class="qty-input">
                      <input type="number" min="0" name="<?= h($field) ?>" id="<?= h($field) ?>" value="<?= h((string) $value) ?>" placeholder="Qty">
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="section-heading">
              <div class="section-label">Drop-Ins</div>
              <div class="section-caption">Shorter visits and check-ins</div>
            </div>

            <div class="service-grid">
              <div class="service-card">
                <div class="service-top">
                  <strong>Drop-In Visits</strong>
                  <span>Include check-ins and shorter care visits in your plan</span>
                </div>
                <div class="service-prices">
                  <div class="price-box">
                    <small>Member Rate</small>
                    <b><?= h(money_fmt((float) $memberRates['drop_ins'])) ?></b>
                  </div>
                  <div class="qty-input">
                    <input type="number" min="0" name="drop_ins" id="drop_ins" value="<?= h((string) $dropIns) ?>" placeholder="Qty">
                  </div>
                </div>
              </div>
            </div>

            <div class="action-row">
              <button class="save-button" type="submit">Continue to Payment</button>
              <a href="pricing.php" class="secondary-link">Review Base Pricing</a>
            </div>
          </form>
        </div>
      </div>

      <div class="plan-side">
        <div class="summary-box">
          <h3>Live Plan Total</h3>
          <div class="summary-total" id="monthlyTotal">$0.00</div>
          <div class="summary-sub">
            Based on real member pricing. Plans above <?= h(money_fmt($discountThreshold)) ?> receive 10% off automatically.
          </div>

          <div class="detail-list">
            <div class="detail-item">
              <span>Discount Threshold</span>
              <strong><?= h(money_fmt($discountThreshold)) ?></strong>
            </div>
            <div class="detail-item">
              <span>Automatic Discount</span>
              <strong><?= h((string) round($discountPercent * 100)) ?>%</strong>
            </div>
          </div>

          <div class="summary-list" id="summaryList"></div>
          <div class="summary-discount" id="discountBox" style="display:none;"></div>
        </div>

        <div class="plan-card">
          <h2 class="saved-plans-title">Saved Plans</h2>
          <div class="saved-plans-sub">
            Your latest custom plans are shown here for quick reference before you create a new one.
          </div>

          <?php if (!$plans): ?>
            <div class="empty-state">
              No saved plans yet. Build your first custom plan and continue through payment when you're ready.
            </div>
          <?php else: ?>
            <div class="saved-plan-list">
              <?php foreach ($plans as $plan): ?>
                <div class="saved-plan">
                  <h3><?= h((string) ($plan['plan_name'] ?? 'Custom Plan')) ?></h3>
                  <div class="saved-plan-meta">
                    <?= h(ucfirst((string) ($plan['payment_mode'] ?? 'upfront'))) ?> · <?= h(ucfirst((string) ($plan['payment_status'] ?? 'pending'))) ?>
                  </div>

                  <div class="saved-plan-grid">
                    <div class="saved-plan-box">
                      <strong>15 Min Walks</strong>
                      <?= h((string) ($plan['walks_15'] ?? 0)) ?>
                    </div>
                    <div class="saved-plan-box">
                      <strong>20 Min Walks</strong>
                      <?= h((string) ($plan['walks_20'] ?? 0)) ?>
                    </div>
                    <div class="saved-plan-box">
                      <strong>30 Min Walks</strong>
                      <?= h((string) ($plan['walks_30'] ?? 0)) ?>
                    </div>
                    <div class="saved-plan-box">
                      <strong>45 Min Walks</strong>
                      <?= h((string) ($plan['walks_45'] ?? 0)) ?>
                    </div>
                    <div class="saved-plan-box">
                      <strong>60 Min Walks</strong>
                      <?= h((string) ($plan['walks_60'] ?? 0)) ?>
                    </div>
                    <div class="saved-plan-box">
                      <strong>Daycare Days</strong>
                      <?= h((string) ($plan['daycare_days'] ?? 0)) ?>
                    </div>
                    <div class="saved-plan-box">
                      <strong>Boarding Nights</strong>
                      <?= h((string) ($plan['boarding_nights'] ?? 0)) ?>
                    </div>
                    <?php if (array_key_exists('daycare_small', $plan)): ?>
                      <div class="saved-plan-box">
                        <strong>Small Daycare</strong>
                        <?= h((string) ($plan['daycare_small'] ?? 0)) ?>
                      </div>
                    <?php endif; ?>
                    <?php if (array_key_exists('daycare_medium', $plan)): ?>
                      <div class="saved-plan-box">
                        <strong>Medium Daycare</strong>
                        <?= h((string) ($plan['daycare_medium'] ?? 0)) ?>
                      </div>
                    <?php endif; ?>
                    <?php if (array_key_exists('daycare_large', $plan)): ?>
                      <div class="saved-plan-box">
                        <strong>Large Daycare</strong>
                        <?= h((string) ($plan['daycare_large'] ?? 0)) ?>
                      </div>
                    <?php endif; ?>
                    <?php if (array_key_exists('boarding_small', $plan)): ?>
                      <div class="saved-plan-box">
                        <strong>Small Boarding</strong>
                        <?= h((string) ($plan['boarding_small'] ?? 0)) ?>
                      </div>
                    <?php endif; ?>
                    <?php if (array_key_exists('boarding_medium', $plan)): ?>
                      <div class="saved-plan-box">
                        <strong>Medium Boarding</strong>
                        <?= h((string) ($plan['boarding_medium'] ?? 0)) ?>
                      </div>
                    <?php endif; ?>
                    <?php if (array_key_exists('boarding_large', $plan)): ?>
                      <div class="saved-plan-box">
                        <strong>Large Boarding</strong>
                        <?= h((string) ($plan['boarding_large'] ?? 0)) ?>
                      </div>
                    <?php endif; ?>
                    <div class="saved-plan-box">
                      <strong>Drop-Ins</strong>
                      <?= h((string) ($plan['drop_ins'] ?? 0)) ?>
                    </div>
                    <?php if (array_key_exists('subtotal_amount', $plan)): ?>
                      <div class="saved-plan-box">
                        <strong>Subtotal</strong>
                        <?= h(money_fmt((float) ($plan['subtotal_amount'] ?? 0))) ?>
                      </div>
                    <?php endif; ?>
                    <?php if (array_key_exists('discount_amount', $plan)): ?>
                      <div class="saved-plan-box">
                        <strong>Discount</strong>
                        <?= h(money_fmt((float) ($plan['discount_amount'] ?? 0))) ?>
                      </div>
                    <?php endif; ?>
                    <div class="saved-plan-box">
                      <strong>Final Total</strong>
                      <?= h(money_fmt((float) ($plan['monthly_total'] ?? 0))) ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </section>
  </div>
</main>

<footer class="site-footer">
  <div class="footer-container">
    <div class="footer-brand">
      <h3>Doggie Dorian's</h3>
      <p>Luxury dog walking, daycare, boarding, and personalized pet care.</p>
    </div>

    <div class="footer-links">
      <a href="services.php">Services</a>
      <a href="memberships.php">Memberships</a>
      <a href="privacy-policy.php">Privacy Policy</a>
      <a href="legal-notice.php">Legal Notice</a>
      <a href="contact.php">Contact</a>
    </div>
  </div>

  <div class="footer-bottom">
    <p>&copy; <?= date('Y') ?> Doggie Dorian's. All rights reserved.</p>
  </div>
</footer>

<script>
const memberRates = {
  walks_15: <?= json_encode((float)$memberRates['walks_15']) ?>,
  walks_20: <?= json_encode((float)$memberRates['walks_20']) ?>,
  walks_30: <?= json_encode((float)$memberRates['walks_30']) ?>,
  walks_45: <?= json_encode((float)$memberRates['walks_45']) ?>,
  walks_60: <?= json_encode((float)$memberRates['walks_60']) ?>,
  daycare_small: <?= json_encode((float)$memberRates['daycare_small']) ?>,
  daycare_medium: <?= json_encode((float)$memberRates['daycare_medium']) ?>,
  daycare_large: <?= json_encode((float)$memberRates['daycare_large']) ?>,
  boarding_small: <?= json_encode((float)$memberRates['boarding_small']) ?>,
  boarding_medium: <?= json_encode((float)$memberRates['boarding_medium']) ?>,
  boarding_large: <?= json_encode((float)$memberRates['boarding_large']) ?>,
  drop_ins: <?= json_encode((float)$memberRates['drop_ins']) ?>
};

const labels = {
  walks_15: '15 Minute Walks',
  walks_20: '20 Minute Walks',
  walks_30: '30 Minute Walks',
  walks_45: '45 Minute Walks',
  walks_60: '60 Minute Walks',
  daycare_small: 'Daycare Days — Small Dog',
  daycare_medium: 'Daycare Days — Medium Dog',
  daycare_large: 'Daycare Days — Large Dog',
  boarding_small: 'Boarding Nights — Small Dog',
  boarding_medium: 'Boarding Nights — Medium Dog',
  boarding_large: 'Boarding Nights — Large Dog',
  drop_ins: 'Drop-In Visits'
};

const discountThreshold = <?= json_encode((float)$discountThreshold) ?>;
const discountPercent = <?= json_encode((float)$discountPercent) ?>;

function updatePlanSummary() {
  let subtotal = 0;
  const summaryList = document.getElementById('summaryList');
  const monthlyTotal = document.getElementById('monthlyTotal');
  const discountBox = document.getElementById('discountBox');

  summaryList.innerHTML = '';

  Object.keys(memberRates).forEach((key) => {
    const input = document.getElementById(key);
    if (!input) return;

    const qty = parseInt(input.value || '0', 10) || 0;

    if (qty > 0) {
      const lineTotal = qty * memberRates[key];
      subtotal += lineTotal;

      const item = document.createElement('div');
      item.className = 'summary-item';
      item.innerHTML = `
        <span>${labels[key]} × ${qty}</span>
        <strong>$${lineTotal.toFixed(2)}</strong>
      `;
      summaryList.appendChild(item);
    }
  });

  if (summaryList.innerHTML === '') {
    summaryList.innerHTML = '<div class="summary-item summary-empty"><span>No services selected yet</span><strong>$0.00</strong></div>';
  }

  let discountAmount = 0;
  if (subtotal > discountThreshold) {
    discountAmount = +(subtotal * discountPercent).toFixed(2);
    discountBox.style.display = 'block';
    discountBox.textContent = '10% discount applied: -$' + discountAmount.toFixed(2) + ' on subtotal of $' + subtotal.toFixed(2) + '.';
  } else {
    discountBox.style.display = 'none';
    discountBox.textContent = '';
  }

  const finalTotal = Math.max(0, subtotal - discountAmount);
  monthlyTotal.textContent = '$' + finalTotal.toFixed(2);
}

document.querySelectorAll('#planForm input[type="number"]').forEach((input) => {
  input.addEventListener('input', updatePlanSummary);
});

updatePlanSummary();
</script>

</body>
</html>