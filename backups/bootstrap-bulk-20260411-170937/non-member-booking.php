<?php
declare(strict_types=1);

// Security headers
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    if (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    ) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    header(
        "Content-Security-Policy: " .
        "default-src 'self'; " .
        "img-src 'self' data: https:; " .
        "style-src 'self' 'unsafe-inline' https:; " .
        "script-src 'self' 'unsafe-inline' https:; " .
        "font-src 'self' data: https:; " .
        "connect-src 'self' https:; " .
        "frame-ancestors 'self'; " .
        "base-uri 'self'; " .
        "form-action 'self'; " .
        "object-src 'none';"
    );
}

// Detect HTTPS correctly behind proxy/load balancer
$isHttps =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

// Force HTTPS
if (!$isHttps && ($_SERVER['HTTP_HOST'] ?? '') !== 'localhost') {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
    exit;
}

// Secure session settings must be applied before session_start()
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

require_once __DIR__ . '/includes/pricing.php';

$isLoggedIn = isset($_SESSION['member_id']) || isset($_SESSION['user_id']) || isset($_SESSION['id']);

$flashType = $_SESSION['nonmember_flash_type'] ?? '';
$flashMessage = $_SESSION['nonmember_flash_message'] ?? '';
$formData = $_SESSION['nonmember_form_data'] ?? [];

unset(
    $_SESSION['nonmember_flash_type'],
    $_SESSION['nonmember_flash_message'],
    $_SESSION['nonmember_form_data']
);

function old_value(array $formData, string $key): string
{
    return htmlspecialchars((string) ($formData[$key] ?? ''), ENT_QUOTES, 'UTF-8');
}

$pricingMatrix = dd_pricing_matrix();
$dropInConfig = dd_get_drop_in_config(false);
$daycareConfig = dd_get_daycare_config(false);
$sittingConfig = dd_get_sitting_config(false);

$clientPricingData = [
    'walk' => [
        '15' => (float) $pricingMatrix['walk']['non_member'][15],
        '20' => (float) $pricingMatrix['walk']['non_member'][20],
        '30' => (float) $pricingMatrix['walk']['non_member'][30],
        '45' => (float) $pricingMatrix['walk']['non_member'][45],
        '60' => (float) $pricingMatrix['walk']['non_member'][60],
    ],
    'drop_in' => [
        'hourly_rate' => (float) $dropInConfig['hourly_rate'],
        'walk_add_on' => (float) $dropInConfig['walk_add_on'],
        'max_hours' => (int) $dropInConfig['max_hours'],
        'walk_duration_minutes' => (int) $dropInConfig['walk_duration_minutes'],
    ],
    'daycare' => [
        'base_rate' => (float) $daycareConfig['base_rate'],
        'hours' => (int) $daycareConfig['hours'],
        'food_fee' => (float) $daycareConfig['food_fee'],
        'included_walks' => (int) $daycareConfig['included_walks'],
        'included_walk_duration_minutes' => (int) $daycareConfig['included_walk_duration_minutes'],
        'additional_walk_rate' => (float) $daycareConfig['additional_walk_rate'],
        'additional_walk_duration_minutes' => (int) $daycareConfig['additional_walk_duration_minutes'],
    ],
    'sitting' => [
        'base_rate' => (float) $sittingConfig['base_rate'],
        'hours' => (int) $sittingConfig['hours'],
        'included_walks' => (int) $sittingConfig['included_walks'],
        'included_walk_duration_minutes' => (int) $sittingConfig['included_walk_duration_minutes'],
        'additional_walk_rate' => (float) $sittingConfig['additional_walk_rate'],
        'additional_walk_duration_minutes' => (int) $sittingConfig['additional_walk_duration_minutes'],
    ],
    'boarding' => [
        'small' => (float) $pricingMatrix['boarding']['non_member']['small'],
        'medium' => (float) $pricingMatrix['boarding']['non_member']['medium'],
        'large' => (float) $pricingMatrix['boarding']['non_member']['large'],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Non-Member Booking | Doggie Dorian’s</title>
  <meta name="description" content="Book non-member dog walks, drop-ins, daycare, in-home sitting, and boarding with Doggie Dorian’s. Premium service with transparent pricing and a luxury booking experience.">

  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    :root {
      --bg: #0a0a0d;
      --bg-2: #111116;
      --panel: rgba(255,255,255,0.045);
      --panel-strong: rgba(255,255,255,0.08);
      --border: rgba(255,255,255,0.09);
      --gold: #d4af37;
      --gold-soft: #f0d77a;
      --gold-deep: #b9921f;
      --cream: #f8f4ea;
      --text: rgba(255,255,255,0.88);
      --muted: rgba(255,255,255,0.70);
      --success: #1d8f5b;
      --danger: #b84b4b;
      --shadow: 0 20px 60px rgba(0,0,0,0.45);
      --shadow-lg: 0 28px 90px rgba(0,0,0,0.55);
      --radius-xl: 32px;
      --radius-lg: 24px;
      --radius-md: 18px;
      --max: 1280px;
    }

    html {
      scroll-behavior: smooth;
    }

    body {
      font-family: "Georgia", "Times New Roman", serif;
      color: var(--text);
      background:
        radial-gradient(circle at top left, rgba(212,175,55,0.13), transparent 26%),
        radial-gradient(circle at top right, rgba(212,175,55,0.08), transparent 22%),
        linear-gradient(180deg, #09090b 0%, #111116 100%);
      line-height: 1.6;
    }

    a {
      text-decoration: none;
      color: inherit;
    }

    button,
    input,
    select,
    textarea {
      font: inherit;
    }

    .container {
      width: min(var(--max), calc(100% - 32px));
      margin: 0 auto;
    }

    .topbar {
      position: sticky;
      top: 0;
      z-index: 1000;
      backdrop-filter: blur(16px);
      background: rgba(10,10,13,0.74);
      border-bottom: 1px solid rgba(255,255,255,0.08);
    }

    .nav {
      min-height: 84px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 20px;
      flex-wrap: wrap;
    }

    .brand {
      display: flex;
      flex-direction: column;
      line-height: 1.05;
    }

    .brand-name {
      font-size: 1.55rem;
      font-weight: 700;
      letter-spacing: 0.4px;
      color: var(--cream);
    }

    .brand-tag {
      margin-top: 6px;
      font-family: Arial, sans-serif;
      font-size: 0.74rem;
      text-transform: uppercase;
      letter-spacing: 2.7px;
      color: rgba(240,215,122,0.9);
    }

    .nav-links {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }

    .top-link {
      padding: 10px 14px;
      border-radius: 999px;
      background: rgba(255,255,255,0.06);
      border: 1px solid rgba(255,255,255,0.08);
      font-family: Arial, sans-serif;
      font-size: 0.95rem;
      font-weight: 700;
      color: rgba(255,255,255,0.87);
      transition: 0.22s ease;
    }

    .top-link:hover {
      background: rgba(255,255,255,0.10);
      color: var(--gold-soft);
    }

    .top-link-signup,
    .nav-cta {
      background: linear-gradient(135deg, #e2c48d, #b9975b);
      color: #0b0b10 !important;
      border: 1px solid rgba(255,255,255,0.14);
    }

    .hero {
      padding: 78px 0 34px;
    }

    .hero-shell {
      border-radius: var(--radius-xl);
      border: 1px solid rgba(255,255,255,0.08);
      background:
        linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.02)),
        radial-gradient(circle at top left, rgba(212,175,55,0.14), transparent 34%);
      box-shadow: var(--shadow-lg);
      overflow: hidden;
    }

    .hero-grid {
      display: grid;
      grid-template-columns: 1.05fr 0.95fr;
      align-items: stretch;
    }

    .hero-copy {
      padding: 58px 48px;
    }

    .eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      font-family: Arial, sans-serif;
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 2.5px;
      color: var(--gold-soft);
      margin-bottom: 18px;
    }

    .eyebrow::before {
      content: "";
      width: 40px;
      height: 1px;
      background: linear-gradient(90deg, var(--gold), transparent);
      display: inline-block;
    }

    .hero h1 {
      font-size: clamp(2.7rem, 5vw, 4.9rem);
      line-height: 0.96;
      letter-spacing: -1.8px;
      color: var(--cream);
      max-width: 880px;
      margin-bottom: 18px;
    }

    .hero h1 span {
      color: var(--gold-soft);
    }

    .hero p {
      font-family: Arial, sans-serif;
      font-size: 1.05rem;
      color: rgba(255,255,255,0.78);
      max-width: 760px;
      margin-bottom: 28px;
    }

    .hero-pills {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
    }

    .hero-pill {
      padding: 10px 16px;
      border-radius: 999px;
      font-family: Arial, sans-serif;
      font-size: 0.9rem;
      color: rgba(255,255,255,0.86);
      background: rgba(255,255,255,0.05);
      border: 1px solid rgba(255,255,255,0.08);
    }

    .hero-side {
      padding: 28px;
      border-left: 1px solid rgba(255,255,255,0.07);
      background:
        linear-gradient(180deg, rgba(18,18,23,0.78), rgba(12,12,16,0.92)),
        radial-gradient(circle at center, rgba(212,175,55,0.10), transparent 46%);
      display: flex;
      align-items: center;
    }

    .hero-panel {
      width: 100%;
      border-radius: 26px;
      padding: 28px;
      background:
        linear-gradient(180deg, rgba(212,175,55,0.12), rgba(255,255,255,0.03)),
        rgba(255,255,255,0.02);
      border: 1px solid rgba(212,175,55,0.18);
      box-shadow: var(--shadow);
    }

    .hero-panel small {
      display: block;
      font-family: Arial, sans-serif;
      font-size: 0.74rem;
      text-transform: uppercase;
      letter-spacing: 2.2px;
      color: var(--gold-soft);
      margin-bottom: 10px;
    }

    .hero-panel h3 {
      font-size: 1.85rem;
      line-height: 1.05;
      color: var(--cream);
      margin-bottom: 10px;
    }

    .hero-panel p {
      font-family: Arial, sans-serif;
      font-size: 0.96rem;
      color: rgba(255,255,255,0.76);
      margin-bottom: 18px;
    }

    .hero-panel-list {
      display: grid;
      gap: 10px;
    }

    .hero-panel-list div {
      padding: 12px 14px;
      border-radius: 16px;
      background: rgba(255,255,255,0.045);
      border: 1px solid rgba(255,255,255,0.08);
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.84);
      font-size: 0.93rem;
    }

    section {
      padding: 42px 0;
    }

    .section-intro {
      text-align: center;
      margin-bottom: 28px;
    }

    .section-intro .mini {
      font-family: Arial, sans-serif;
      color: var(--gold-soft);
      text-transform: uppercase;
      letter-spacing: 2.5px;
      font-size: 0.76rem;
      margin-bottom: 10px;
    }

    .section-intro h2 {
      font-size: clamp(2rem, 3vw, 3.2rem);
      line-height: 1.04;
      color: var(--cream);
      margin-bottom: 12px;
      letter-spacing: -1px;
    }

    .section-intro p {
      max-width: 760px;
      margin: 0 auto;
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.74);
      font-size: 1rem;
    }

    .flash {
      margin: 0 auto 28px;
      padding: 16px 18px;
      border-radius: 18px;
      font-family: Arial, sans-serif;
      max-width: 920px;
      border: 1px solid rgba(255,255,255,0.10);
    }

    .flash.success {
      background: rgba(29,143,91,0.14);
      border-color: rgba(29,143,91,0.32);
      color: #d6ffe9;
    }

    .flash.error {
      background: rgba(184,75,75,0.14);
      border-color: rgba(184,75,75,0.32);
      color: #ffe1e1;
    }

    .pricing-grid {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 20px;
    }

    .pricing-card {
      border-radius: 28px;
      padding: 26px;
      background: linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.02));
      border: 1px solid rgba(255,255,255,0.08);
      box-shadow: var(--shadow);
    }

    .pricing-card h3 {
      color: var(--cream);
      font-size: 1.5rem;
      margin-bottom: 12px;
    }

    .pricing-card p {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.74);
      margin-bottom: 16px;
      font-size: 0.95rem;
    }

    .price-list {
      display: grid;
      gap: 10px;
      list-style: none;
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.84);
      font-size: 0.95rem;
    }

    .price-list li {
      display: flex;
      justify-content: space-between;
      gap: 14px;
      padding: 12px 14px;
      border-radius: 14px;
      background: rgba(255,255,255,0.04);
      border: 1px solid rgba(255,255,255,0.07);
    }

    .booking-shell {
      border-radius: 30px;
      padding: 30px;
      background:
        linear-gradient(135deg, rgba(212,175,55,0.16), rgba(255,255,255,0.04)),
        rgba(255,255,255,0.02);
      border: 1px solid rgba(212,175,55,0.18);
      box-shadow: var(--shadow-lg);
    }

    .booking-grid {
      display: grid;
      grid-template-columns: 0.9fr 1.1fr;
      gap: 24px;
      align-items: start;
    }

    .booking-copy h3 {
      font-size: 2rem;
      color: var(--cream);
      margin-bottom: 10px;
    }

    .booking-copy p {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.76);
      margin-bottom: 18px;
      max-width: 720px;
    }

    .booking-copy-list {
      display: grid;
      gap: 10px;
    }

    .booking-copy-list div {
      padding: 13px 14px;
      border-radius: 16px;
      background: rgba(255,255,255,0.045);
      border: 1px solid rgba(255,255,255,0.08);
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.84);
      font-size: 0.94rem;
    }

    .quote-box {
      margin-top: 18px;
      border-radius: 20px;
      padding: 18px;
      background: rgba(255,255,255,0.05);
      border: 1px solid rgba(255,255,255,0.10);
    }

    .quote-box small {
      display: block;
      font-family: Arial, sans-serif;
      text-transform: uppercase;
      letter-spacing: 2px;
      color: var(--gold-soft);
      margin-bottom: 8px;
      font-size: 0.72rem;
    }

    .quote-box strong {
      display: block;
      font-size: 2rem;
      color: var(--cream);
      margin-bottom: 6px;
    }

    .quote-box span {
      display: block;
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.72);
      font-size: 0.92rem;
      line-height: 1.5;
    }

    .quote-box .quote-meta {
      margin-top: 10px;
      color: rgba(255,255,255,0.80);
      font-size: 0.9rem;
      font-family: Arial, sans-serif;
    }

    .booking-form {
      display: grid;
      gap: 16px;
    }

    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }

    .field-group {
      display: grid;
      gap: 8px;
    }

    .field-group label {
      font-family: Arial, sans-serif;
      font-size: 0.9rem;
      color: rgba(255,255,255,0.86);
      font-weight: 600;
    }

    .field-group input,
    .field-group select,
    .field-group textarea {
      width: 100%;
      border: 1px solid rgba(255,255,255,0.10);
      border-radius: 16px;
      background: rgba(255,255,255,0.04);
      color: var(--text);
      padding: 15px 16px;
      font-family: Arial, sans-serif;
      font-size: 0.95rem;
      outline: none;
      transition: border-color 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
    }

    .field-group input::placeholder,
    .field-group textarea::placeholder {
      color: rgba(255,255,255,0.42);
    }

    .field-group input:focus,
    .field-group select:focus,
    .field-group textarea:focus {
      border-color: rgba(212,175,55,0.55);
      background: rgba(255,255,255,0.06);
      box-shadow: 0 0 0 4px rgba(212,175,55,0.08);
    }

    .field-group textarea {
      min-height: 140px;
      resize: vertical;
    }

    .helper {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.56);
      font-size: 0.84rem;
      margin-top: -2px;
      line-height: 1.45;
    }

    .service-panel {
      padding: 16px;
      border-radius: 18px;
      background: rgba(255,255,255,0.045);
      border: 1px solid rgba(255,255,255,0.08);
      display: grid;
      gap: 14px;
    }

    .service-panel-title {
      font-family: Arial, sans-serif;
      color: var(--gold-soft);
      text-transform: uppercase;
      letter-spacing: 2px;
      font-size: 0.78rem;
      font-weight: 700;
    }

    .check-row {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 14px;
      border-radius: 16px;
      background: rgba(255,255,255,0.04);
      border: 1px solid rgba(255,255,255,0.07);
      font-family: Arial, sans-serif;
    }

    .check-row input[type="checkbox"] {
      width: 18px;
      height: 18px;
      accent-color: #d4af37;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 54px;
      padding: 0 22px;
      border-radius: 999px;
      border: none;
      cursor: pointer;
      font-family: Arial, sans-serif;
      font-size: 0.96rem;
      font-weight: 700;
      letter-spacing: 0.3px;
      transition: transform 0.2s ease, opacity 0.2s ease;
    }

    .btn:hover {
      transform: translateY(-2px);
      opacity: 0.97;
    }

    .btn-gold {
      color: #18140a;
      background: linear-gradient(135deg, #f0d77a 0%, #d4af37 46%, #b9921f 100%);
      box-shadow: 0 14px 30px rgba(212,175,55,0.24);
    }

    .hidden {
      display: none !important;
    }

    footer {
      padding: 38px 0 52px;
    }

    .footer-wrap {
      border-top: 1px solid rgba(255,255,255,0.08);
      padding-top: 26px;
      display: flex;
      justify-content: space-between;
      gap: 18px;
      flex-wrap: wrap;
    }

    .footer-brand {
      color: var(--cream);
      font-size: 1.15rem;
    }

    .footer-text {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.58);
      font-size: 0.93rem;
    }

    @media (max-width: 1180px) {
      .pricing-grid,
      .booking-grid,
      .hero-grid {
        grid-template-columns: 1fr;
      }

      .hero-side {
        border-left: none;
        border-top: 1px solid rgba(255,255,255,0.07);
      }
    }

    @media (max-width: 860px) {
      .nav {
        flex-direction: column;
        align-items: flex-start;
        padding: 16px 0;
      }

      .nav-links {
        width: 100%;
      }

      .hero-copy {
        padding: 38px 24px;
      }

      .hero-side {
        padding: 24px;
      }

      .hero h1,
      .section-intro h2,
      .booking-copy h3 {
        font-size: 2rem;
      }

      .form-row {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 560px) {
      .container {
        width: min(var(--max), calc(100% - 20px));
      }

      .hero {
        padding-top: 34px;
      }

      .brand-name {
        font-size: 1.28rem;
      }

      .brand-tag {
        letter-spacing: 2px;
      }

      .pricing-card,
      .booking-shell,
      .hero-panel {
        padding-left: 22px;
        padding-right: 22px;
      }

      .btn {
        width: 100%;
      }
    }
  </style>

  <script>
    const DD_PRICING = <?php echo json_encode($clientPricingData, JSON_UNESCAPED_SLASHES); ?>;

    function nightsBetween(start, end) {
      if (!start || !end) return 0;

      const startDate = new Date(start + 'T00:00:00');
      const endDate = new Date(end + 'T00:00:00');

      if (isNaN(startDate.getTime()) || isNaN(endDate.getTime()) || endDate <= startDate) {
        return 0;
      }

      const diff = endDate.getTime() - startDate.getTime();
      return Math.floor(diff / 86400000);
    }

    function money(value) {
      return '$' + Number(value || 0).toFixed(2);
    }

    function setFieldRequired(id, required) {
      const el = document.getElementById(id);
      if (!el) return;
      el.required = !!required;
    }

    function updateBookingFormUI() {
      const serviceType = document.getElementById('service_type').value;
      const walkFields = document.querySelectorAll('.walk-only');
      const boardingFields = document.querySelectorAll('.boarding-only');
      const dropInPanel = document.getElementById('dropin_panel');
      const daycarePanel = document.getElementById('daycare_panel');
      const sittingPanel = document.getElementById('sitting_panel');
      const dogSizeWrap = document.getElementById('dog_size_wrap');
      const endDateWrap = document.getElementById('date_end_wrap');
      const endDateLabel = document.getElementById('date_end_label');
      const endDateHelper = document.getElementById('date_end_helper');

      walkFields.forEach(el => {
        el.classList.toggle('hidden', serviceType !== 'Walk');
      });

      boardingFields.forEach(el => {
        el.classList.toggle('hidden', serviceType !== 'Boarding');
      });

      if (dogSizeWrap) {
        dogSizeWrap.classList.toggle('hidden', serviceType !== 'Boarding');
      }

      if (dropInPanel) {
        dropInPanel.classList.toggle('hidden', serviceType !== 'Drop-In');
      }

      if (daycarePanel) {
        daycarePanel.classList.toggle('hidden', serviceType !== 'Daycare');
      }

      if (sittingPanel) {
        sittingPanel.classList.toggle('hidden', serviceType !== 'Sitting');
      }

      if (endDateWrap) {
        endDateWrap.classList.toggle('hidden', serviceType !== 'Boarding');
      }

      if (endDateLabel) {
        endDateLabel.textContent = 'Check-Out Date';
      }

      if (endDateHelper) {
        endDateHelper.textContent = 'Boarding total uses nights. Example: April 1 to April 6 = 5 boarding nights.';
      }

      setFieldRequired('walk_duration', serviceType === 'Walk');
      setFieldRequired('dog_size', serviceType === 'Boarding');
      setFieldRequired('date_end', serviceType === 'Boarding');

      updateEstimatedPrice();
    }

    function updateEstimatedPrice() {
      const serviceType = document.getElementById('service_type').value;
      const walkDuration = document.getElementById('walk_duration').value;
      const dogSizeValue = document.getElementById('dog_size').value;
      const dateStart = document.getElementById('date_start').value;
      const dateEnd = document.getElementById('date_end').value;
      const dropInHours = parseInt(document.getElementById('drop_in_hours').value || '1', 10);
      const dropInAddWalk = document.getElementById('drop_in_add_walk').checked;
      const daycareProvideFood = document.getElementById('daycare_provide_food').checked;
      const daycareExtraWalks = parseInt(document.getElementById('daycare_extra_walks').value || '0', 10);
      const sittingExtraWalks = parseInt(document.getElementById('sitting_extra_walks').value || '0', 10);

      const dogSize = String(dogSizeValue || '').toLowerCase();
      const estimateField = document.getElementById('estimated_price');
      const estimateText = document.getElementById('estimated_price_text');
      const pricingTypeField = document.getElementById('pricing_type');
      const discountLabelField = document.getElementById('discount_label');
      const unitPriceField = document.getElementById('unit_price');
      const quantityField = document.getElementById('quantity');
      const quoteMeta = document.getElementById('quote_meta');

      let total = 0;
      let unitPrice = 0;
      let quantity = 1;
      let label = 'Select service details to view live pricing.';
      let pricingType = 'non_member';
      let discountLabel = 'standard_non_member';
      let meta = '';

      if (serviceType === 'Walk') {
        if (DD_PRICING.walk[walkDuration]) {
          unitPrice = Number(DD_PRICING.walk[walkDuration]);
          total = unitPrice;
          quantity = 1;
          label = money(total) + ' live price for one non-member walk.';
          meta = money(unitPrice) + ' per walk';
          discountLabel = 'standard_non_member';
        }
      }

      if (serviceType === 'Drop-In') {
        unitPrice = Number(DD_PRICING.drop_in.hourly_rate);
        quantity = Math.max(1, Math.min(Number(DD_PRICING.drop_in.max_hours), dropInHours || 1));
        total = unitPrice * quantity;

        if (dropInAddWalk) {
          total += Number(DD_PRICING.drop_in.walk_add_on);
        }

        label = money(total) + ' live price for your non-member drop-in.';
        meta = money(unitPrice) + ' per hour';
        if (dropInAddWalk) {
          meta += ' · +' + money(DD_PRICING.drop_in.walk_add_on) + ' walk add-on';
        }
        discountLabel = 'non_member_dropin_hourly_custom';
      }

      if (serviceType === 'Daycare') {
        unitPrice = Number(DD_PRICING.daycare.base_rate);
        quantity = 1;
        total = unitPrice;

        if (daycareProvideFood) {
          total += Number(DD_PRICING.daycare.food_fee);
        }

        if (daycareExtraWalks > 0) {
          total += Number(DD_PRICING.daycare.additional_walk_rate) * daycareExtraWalks;
        }

        label = money(total) + ' live price for one 6-hour daycare session.';
        meta = money(unitPrice) + ' base for ' + DD_PRICING.daycare.hours + ' hours';
        if (daycareProvideFood) {
          meta += ' · +' + money(DD_PRICING.daycare.food_fee) + ' food';
        }
        if (daycareExtraWalks > 0) {
          meta += ' · +' + daycareExtraWalks + ' extra walk(s)';
        }
        discountLabel = 'non_member_daycare_6hr_custom';
      }

      if (serviceType === 'Sitting') {
        unitPrice = Number(DD_PRICING.sitting.base_rate);
        quantity = 1;
        total = unitPrice;

        if (sittingExtraWalks > 0) {
          total += Number(DD_PRICING.sitting.additional_walk_rate) * sittingExtraWalks;
        }

        label = money(total) + ' live price for one in-home sitting session.';
        meta = money(unitPrice) + ' base for up to ' + DD_PRICING.sitting.hours + ' hours';
        if (sittingExtraWalks > 0) {
          meta += ' · +' + sittingExtraWalks + ' extra walk(s)';
        }
        discountLabel = 'non_member_sitting_custom';
      }

      if (serviceType === 'Boarding') {
        if (DD_PRICING.boarding[dogSize]) {
          unitPrice = Number(DD_PRICING.boarding[dogSize]);
          quantity = nightsBetween(dateStart, dateEnd);

          if (quantity > 0) {
            total = unitPrice * quantity;
            label = money(total) + ' live price for ' + quantity + ' boarding night' + (quantity === 1 ? '' : 's') + '.';
            meta = money(unitPrice) + ' per night · ' + quantity + ' night' + (quantity === 1 ? '' : 's');
            discountLabel = 'standard_non_member';
          } else {
            total = 0;
            label = 'Select a valid boarding date range to view live pricing.';
            meta = money(unitPrice) + ' per night';
          }
        }
      }

      if (estimateField) {
        estimateField.value = total > 0 ? total.toFixed(2) : '';
      }

      if (pricingTypeField) {
        pricingTypeField.value = pricingType;
      }

      if (discountLabelField) {
        discountLabelField.value = discountLabel;
      }

      if (unitPriceField) {
        unitPriceField.value = unitPrice > 0 ? unitPrice.toFixed(2) : '';
      }

      if (quantityField) {
        quantityField.value = String(quantity);
      }

      if (estimateText) {
        estimateText.textContent = label;
      }

      if (quoteMeta) {
        quoteMeta.textContent = meta;
      }
    }

    function validateBookingForSubmit() {
      const serviceType = document.getElementById('service_type').value;
      const walkDuration = document.getElementById('walk_duration').value;
      const dogSize = document.getElementById('dog_size').value;
      const dateStart = document.getElementById('date_start').value;
      const dateEnd = document.getElementById('date_end').value;
      const estimatedPrice = document.getElementById('estimated_price').value;

      if (!serviceType) {
        alert('Please choose a service type.');
        return false;
      }

      if (serviceType === 'Walk' && !walkDuration) {
        alert('Please choose a walk duration.');
        return false;
      }

      if (serviceType === 'Boarding') {
        if (!dogSize) {
          alert('Please choose your dog size for boarding.');
          return false;
        }

        if (!dateEnd) {
          alert('Please choose a check-out date for boarding.');
          return false;
        }

        if (nightsBetween(dateStart, dateEnd) <= 0) {
          alert('Please choose a valid boarding date range.');
          return false;
        }
      }

      if (!estimatedPrice || Number(estimatedPrice) <= 0) {
        alert('Please complete the booking details so the live price can be calculated before continuing to payment.');
        return false;
      }

      return true;
    }

    window.addEventListener('DOMContentLoaded', function () {
      updateBookingFormUI();

      [
        'service_type',
        'walk_duration',
        'dog_size',
        'date_start',
        'date_end',
        'drop_in_hours',
        'drop_in_add_walk',
        'daycare_provide_food',
        'daycare_extra_walks',
        'sitting_extra_walks'
      ].forEach(function(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('change', function () {
          if (id === 'service_type') {
            updateBookingFormUI();
          } else {
            updateEstimatedPrice();
          }
        });
        el.addEventListener('input', updateEstimatedPrice);
      });

      const bookingForm = document.getElementById('non_member_booking_form');
      if (bookingForm) {
        bookingForm.addEventListener('submit', function (event) {
          updateEstimatedPrice();
          if (!validateBookingForSubmit()) {
            event.preventDefault();
          }
        });
      }

      updateEstimatedPrice();
    });
  </script>
</head>
<body>

  <header class="topbar">
    <div class="container nav">
      <div class="brand">
        <a href="index.php" class="brand-name">Doggie Dorian’s</a>
        <div class="brand-tag">Luxury Pet Care Experience</div>
      </div>

      <nav class="nav-links">
        <a class="top-link" href="index.php">Home</a>
        <a class="top-link" href="services.php">Services</a>
        <a class="top-link" href="memberships.php">Memberships</a>
        <a class="top-link" href="contact.php">Contact</a>

        <?php if ($isLoggedIn): ?>
          <a class="top-link" href="dashboard.php">Dashboard</a>
        <?php else: ?>
          <a class="top-link" href="login.php">Login</a>
          <a class="top-link top-link-signup" href="signup.php">Sign Up</a>
        <?php endif; ?>

        <a href="customize-plan.php" class="top-link nav-cta">Build Your Plan</a>
      </nav>
    </div>
  </header>

  <main>
    <section class="hero">
      <div class="container">
        <div class="hero-shell">
          <div class="hero-grid">
            <div class="hero-copy">
              <div class="eyebrow">Non-Member Booking</div>
              <h1>
                Book premium care without a <span>membership</span>.
              </h1>
              <p>
                This page is built for clients who want one-time or occasional bookings for walks, drop-ins, daycare, in-home sitting, or boarding while still experiencing the elevated Doggie Dorian’s brand.
              </p>

              <div class="hero-pills">
                <div class="hero-pill">Luxury booking experience</div>
                <div class="hero-pill">Transparent pricing</div>
                <div class="hero-pill">Walks, drop-ins, daycare, sitting, and boarding</div>
                <div class="hero-pill">Live pricing updates</div>
              </div>
            </div>

            <div class="hero-side">
              <div class="hero-panel">
                <small>What Clients Can Book</small>
                <h3>Flexible premium care for non-members.</h3>
                <p>
                  Clients can request walks, drop-ins, daycare, in-home sitting, or boarding with a clean premium booking experience, transparent live pricing, and a dedicated non-member payment portal before secure checkout.
                </p>

                <div class="hero-panel-list">
                  <div>Walk requests from 15 to 60 minutes</div>
                  <div>Drop-ins billed hourly with optional walk add-on</div>
                  <div>6-hour daycare with optional food and extra walks</div>
                  <div>In-home sitting with included walk and add-ons</div>
                  <div>Boarding still priced by dog size</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section>
      <div class="container">
        <?php if ($flashMessage !== ''): ?>
          <div class="flash <?php echo $flashType === 'success' ? 'success' : 'error'; ?>">
            <?php echo htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8'); ?>
          </div>
        <?php endif; ?>

        <div class="section-intro">
          <div class="mini">Pricing</div>
          <h2>Transparent non-member pricing.</h2>
          <p>
            Clean, premium, and easy to understand for clients booking without a membership.
          </p>
        </div>

        <div class="pricing-grid">
          <div class="pricing-card">
            <h3>Walk Pricing</h3>
            <p>Non-member walk rates by duration.</p>
            <ul class="price-list">
              <li><span>15-Minute Walk</span><strong><?php echo dd_format_money($clientPricingData['walk']['15']); ?></strong></li>
              <li><span>20-Minute Walk</span><strong><?php echo dd_format_money($clientPricingData['walk']['20']); ?></strong></li>
              <li><span>30-Minute Walk</span><strong><?php echo dd_format_money($clientPricingData['walk']['30']); ?></strong></li>
              <li><span>45-Minute Walk</span><strong><?php echo dd_format_money($clientPricingData['walk']['45']); ?></strong></li>
              <li><span>60-Minute Walk</span><strong><?php echo dd_format_money($clientPricingData['walk']['60']); ?></strong></li>
            </ul>
          </div>

          <div class="pricing-card">
            <h3>Drop-In Pricing</h3>
            <p>Hourly non-member pricing with optional walk add-on.</p>
            <ul class="price-list">
              <li><span>1 Hour</span><strong><?php echo dd_format_money($clientPricingData['drop_in']['hourly_rate']); ?></strong></li>
              <li><span>2 Hours</span><strong><?php echo dd_format_money($clientPricingData['drop_in']['hourly_rate'] * 2); ?></strong></li>
              <li><span>Add 30-Min Walk</span><strong>+<?php echo dd_format_money($clientPricingData['drop_in']['walk_add_on']); ?></strong></li>
            </ul>
          </div>

          <div class="pricing-card">
            <h3>Daycare Pricing</h3>
            <p>6-hour daycare session for non-members.</p>
            <ul class="price-list">
              <li><span>6-Hour Session</span><strong><?php echo dd_format_money($clientPricingData['daycare']['base_rate']); ?></strong></li>
              <li><span>We Provide Food</span><strong>+<?php echo dd_format_money($clientPricingData['daycare']['food_fee']); ?></strong></li>
              <li><span>1 Included Walk</span><strong>Included</strong></li>
              <li><span>Additional 30-Min Walk</span><strong>+<?php echo dd_format_money($clientPricingData['daycare']['additional_walk_rate']); ?></strong></li>
            </ul>
          </div>

          <div class="pricing-card">
            <h3>In-Home Sitting</h3>
            <p>Premium in-home sitting for non-members.</p>
            <ul class="price-list">
              <li><span>Up to <?php echo (int) $clientPricingData['sitting']['hours']; ?> Hours</span><strong><?php echo dd_format_money($clientPricingData['sitting']['base_rate']); ?></strong></li>
              <li><span>1 Included Walk</span><strong>Included</strong></li>
              <li><span>Additional 30-Min Walk</span><strong>+<?php echo dd_format_money($clientPricingData['sitting']['additional_walk_rate']); ?></strong></li>
            </ul>
          </div>

          <div class="pricing-card">
            <h3>Boarding Pricing</h3>
            <p>Pricing per night based on dog size.</p>
            <ul class="price-list">
              <li><span>Small Dog</span><strong><?php echo dd_format_money($clientPricingData['boarding']['small']); ?></strong></li>
              <li><span>Medium Dog</span><strong><?php echo dd_format_money($clientPricingData['boarding']['medium']); ?></strong></li>
              <li><span>Large Dog</span><strong><?php echo dd_format_money($clientPricingData['boarding']['large']); ?></strong></li>
            </ul>
          </div>
        </div>
      </div>
    </section>

    <section id="non-member-booking-form">
      <div class="container">
        <div class="booking-shell">
          <div class="booking-grid">
            <div class="booking-copy">
              <h3>Submit your non-member booking and continue to payment.</h3>
              <p>
                This form captures one-time or occasional non-member bookings, calculates the live non-member total, and sends the client into the dedicated non-member payment portal for checkout.
              </p>

              <div class="booking-copy-list">
                <div>Walk duration selection for non-member bookings</div>
                <div>Drop-ins with hourly options and walk add-ons</div>
                <div>6-hour daycare with optional food and extra walks</div>
                <div>In-home sitting with included walk and optional extra walks</div>
                <div>Boarding still priced by size and night count</div>
              </div>

              <div class="quote-box">
                <small>Live Price</small>
                <strong id="estimated_price_text">Select service details to view live pricing.</strong>
                <span>This live total will carry into the non-member payment portal before Stripe checkout. Daycare is one 6-hour session. Sitting is priced as one session. Boarding totals use nights and still depend on dog size.</span>
                <div class="quote-meta" id="quote_meta"></div>
              </div>
            </div>

            <form class="booking-form" id="non_member_booking_form" action="process-non-member-booking.php" method="post">
              <input type="hidden" id="estimated_price" name="estimated_price" value="<?php echo old_value($formData, 'estimated_price'); ?>">
              <input type="hidden" id="pricing_type" name="pricing_type" value="<?php echo old_value($formData, 'pricing_type') !== '' ? old_value($formData, 'pricing_type') : 'non_member'; ?>">
              <input type="hidden" id="discount_label" name="discount_label" value="<?php echo old_value($formData, 'discount_label') !== '' ? old_value($formData, 'discount_label') : 'standard_non_member'; ?>">
              <input type="hidden" id="unit_price" name="unit_price" value="<?php echo old_value($formData, 'unit_price'); ?>">
              <input type="hidden" id="quantity" name="quantity" value="<?php echo old_value($formData, 'quantity'); ?>">

              <div class="form-row">
                <div class="field-group">
                  <label for="full_name">Full Name</label>
                  <input type="text" id="full_name" name="full_name" required placeholder="Your full name" value="<?php echo old_value($formData, 'full_name'); ?>">
                </div>

                <div class="field-group">
                  <label for="phone">Phone Number</label>
                  <input type="tel" id="phone" name="phone" placeholder="Your phone number" value="<?php echo old_value($formData, 'phone'); ?>">
                </div>
              </div>

              <div class="form-row">
                <div class="field-group">
                  <label for="email">Email Address</label>
                  <input type="email" id="email" name="email" required placeholder="Your email address" value="<?php echo old_value($formData, 'email'); ?>">
                </div>

                <div class="field-group">
                  <label for="service_type">Service Type</label>
                  <select id="service_type" name="service_type" required>
                    <option value="">Choose a service</option>
                    <option value="Walk" <?php echo old_value($formData, 'service_type') === 'Walk' ? 'selected' : ''; ?>>Walk</option>
                    <option value="Drop-In" <?php echo old_value($formData, 'service_type') === 'Drop-In' ? 'selected' : ''; ?>>Drop-In</option>
                    <option value="Daycare" <?php echo old_value($formData, 'service_type') === 'Daycare' ? 'selected' : ''; ?>>Daycare</option>
                    <option value="Sitting" <?php echo old_value($formData, 'service_type') === 'Sitting' ? 'selected' : ''; ?>>In-Home Sitting</option>
                    <option value="Boarding" <?php echo old_value($formData, 'service_type') === 'Boarding' ? 'selected' : ''; ?>>Boarding</option>
                  </select>
                </div>
              </div>

              <div class="form-row">
                <div class="field-group">
                  <label for="dog_name">Dog Name</label>
                  <input type="text" id="dog_name" name="dog_name" required placeholder="Your dog's name" value="<?php echo old_value($formData, 'dog_name'); ?>">
                </div>

                <div class="field-group hidden" id="dog_size_wrap">
                  <label for="dog_size">Dog Size</label>
                  <select id="dog_size" name="dog_size">
                    <option value="">Choose a size</option>
                    <option value="Small" <?php echo old_value($formData, 'dog_size') === 'Small' ? 'selected' : ''; ?>>Small</option>
                    <option value="Medium" <?php echo old_value($formData, 'dog_size') === 'Medium' ? 'selected' : ''; ?>>Medium</option>
                    <option value="Large" <?php echo old_value($formData, 'dog_size') === 'Large' ? 'selected' : ''; ?>>Large</option>
                  </select>
                </div>
              </div>

              <div class="form-row walk-only hidden">
                <div class="field-group">
                  <label for="walk_duration">Walk Duration</label>
                  <select id="walk_duration" name="walk_duration">
                    <option value="">Choose duration</option>
                    <option value="15" <?php echo old_value($formData, 'walk_duration') === '15' ? 'selected' : ''; ?>>15 Minutes</option>
                    <option value="20" <?php echo old_value($formData, 'walk_duration') === '20' ? 'selected' : ''; ?>>20 Minutes</option>
                    <option value="30" <?php echo old_value($formData, 'walk_duration') === '30' ? 'selected' : ''; ?>>30 Minutes</option>
                    <option value="45" <?php echo old_value($formData, 'walk_duration') === '45' ? 'selected' : ''; ?>>45 Minutes</option>
                    <option value="60" <?php echo old_value($formData, 'walk_duration') === '60' ? 'selected' : ''; ?>>60 Minutes</option>
                  </select>
                </div>

                <div class="field-group">
                  <label for="preferred_walk_time">Preferred Walk Time</label>
                  <select id="preferred_walk_time" name="preferred_walk_time">
                    <option value="">Choose preferred time</option>
                    <option value="Early Morning" <?php echo old_value($formData, 'preferred_walk_time') === 'Early Morning' ? 'selected' : ''; ?>>Early Morning</option>
                    <option value="Morning" <?php echo old_value($formData, 'preferred_walk_time') === 'Morning' ? 'selected' : ''; ?>>Morning</option>
                    <option value="Midday" <?php echo old_value($formData, 'preferred_walk_time') === 'Midday' ? 'selected' : ''; ?>>Midday</option>
                    <option value="Afternoon" <?php echo old_value($formData, 'preferred_walk_time') === 'Afternoon' ? 'selected' : ''; ?>>Afternoon</option>
                    <option value="Evening" <?php echo old_value($formData, 'preferred_walk_time') === 'Evening' ? 'selected' : ''; ?>>Evening</option>
                  </select>
                </div>
              </div>

              <div id="dropin_panel" class="service-panel hidden">
                <div class="service-panel-title">Drop-In Options</div>
                <div class="form-row">
                  <div class="field-group">
                    <label for="drop_in_hours">Drop-In Length</label>
                    <select id="drop_in_hours" name="drop_in_hours">
                      <option value="1" <?php echo old_value($formData, 'drop_in_hours') === '1' ? 'selected' : ''; ?>>1 Hour</option>
                      <option value="2" <?php echo old_value($formData, 'drop_in_hours') === '2' ? 'selected' : ''; ?>>2 Hours</option>
                    </select>
                    <div class="helper">Anything longer should be booked as daycare.</div>
                  </div>

                  <div class="field-group">
                    <label>&nbsp;</label>
                    <div class="check-row">
                      <input type="checkbox" id="drop_in_add_walk" name="drop_in_add_walk" value="1" <?php echo old_value($formData, 'drop_in_add_walk') === '1' ? 'checked' : ''; ?>>
                      <span>Add a 30-minute walk for <?php echo dd_format_money((float) $clientPricingData['drop_in']['walk_add_on']); ?></span>
                    </div>
                  </div>
                </div>
              </div>

              <div id="daycare_panel" class="service-panel hidden">
                <div class="service-panel-title">Daycare Options</div>
                <div class="form-row">
                  <div class="field-group">
                    <label>&nbsp;</label>
                    <div class="check-row">
                      <input type="checkbox" id="daycare_provide_food" name="daycare_provide_food" value="1" <?php echo old_value($formData, 'daycare_provide_food') === '1' ? 'checked' : ''; ?>>
                      <span>Have us provide food for <?php echo dd_format_money((float) $clientPricingData['daycare']['food_fee']); ?></span>
                    </div>
                  </div>

                  <div class="field-group">
                    <label for="daycare_extra_walks">Additional 30-Minute Walks</label>
                    <select id="daycare_extra_walks" name="daycare_extra_walks">
                      <option value="0" <?php echo old_value($formData, 'daycare_extra_walks') === '0' ? 'selected' : ''; ?>>0 extra walks</option>
                      <option value="1" <?php echo old_value($formData, 'daycare_extra_walks') === '1' ? 'selected' : ''; ?>>1 extra walk</option>
                      <option value="2" <?php echo old_value($formData, 'daycare_extra_walks') === '2' ? 'selected' : ''; ?>>2 extra walks</option>
                      <option value="3" <?php echo old_value($formData, 'daycare_extra_walks') === '3' ? 'selected' : ''; ?>>3 extra walks</option>
                    </select>
                    <div class="helper">1 complimentary 30-minute walk is already included.</div>
                  </div>
                </div>
              </div>

              <div id="sitting_panel" class="service-panel hidden">
                <div class="service-panel-title">In-Home Sitting Options</div>
                <div class="form-row">
                  <div class="field-group">
                    <label>&nbsp;</label>
                    <div class="check-row">
                      <span>Includes 1 complimentary <?php echo (int) $clientPricingData['sitting']['included_walk_duration_minutes']; ?>-minute walk</span>
                    </div>
                  </div>

                  <div class="field-group">
                    <label for="sitting_extra_walks">Additional 30-Minute Walks</label>
                    <select id="sitting_extra_walks" name="sitting_extra_walks">
                      <option value="0" <?php echo old_value($formData, 'sitting_extra_walks') === '0' ? 'selected' : ''; ?>>0 extra walks</option>
                      <option value="1" <?php echo old_value($formData, 'sitting_extra_walks') === '1' ? 'selected' : ''; ?>>1 extra walk</option>
                      <option value="2" <?php echo old_value($formData, 'sitting_extra_walks') === '2' ? 'selected' : ''; ?>>2 extra walks</option>
                      <option value="3" <?php echo old_value($formData, 'sitting_extra_walks') === '3' ? 'selected' : ''; ?>>3 extra walks</option>
                    </select>
                    <div class="helper">Base rate covers up to <?php echo (int) $clientPricingData['sitting']['hours']; ?> hours of in-home sitting.</div>
                  </div>
                </div>
              </div>

              <div class="form-row">
                <div class="field-group">
                  <label for="date_start">Requested Start Date</label>
                  <input type="date" id="date_start" name="date_start" required value="<?php echo old_value($formData, 'date_start'); ?>">
                </div>

                <div class="field-group hidden boarding-only" id="date_end_wrap">
                  <label for="date_end" id="date_end_label">Check-Out Date</label>
                  <input type="date" id="date_end" name="date_end" value="<?php echo old_value($formData, 'date_end'); ?>">
                  <div class="helper" id="date_end_helper">Boarding total uses nights. Example: April 1 to April 6 = 5 boarding nights.</div>
                </div>
              </div>

              <div class="form-row">
                <div class="field-group">
                  <label for="feeding_schedule">Feeding Schedule</label>
                  <select id="feeding_schedule" name="feeding_schedule">
                    <option value="">Choose feeding schedule</option>
                    <option value="Once Daily" <?php echo old_value($formData, 'feeding_schedule') === 'Once Daily' ? 'selected' : ''; ?>>Once Daily</option>
                    <option value="Twice Daily" <?php echo old_value($formData, 'feeding_schedule') === 'Twice Daily' ? 'selected' : ''; ?>>Twice Daily</option>
                    <option value="Three Times Daily" <?php echo old_value($formData, 'feeding_schedule') === 'Three Times Daily' ? 'selected' : ''; ?>>Three Times Daily</option>
                    <option value="Custom Schedule" <?php echo old_value($formData, 'feeding_schedule') === 'Custom Schedule' ? 'selected' : ''; ?>>Custom Schedule</option>
                  </select>
                </div>

                <div class="field-group">
                  <label for="preferred_contact">Preferred Contact Method</label>
                  <select id="preferred_contact" name="preferred_contact">
                    <option value="">Choose one</option>
                    <option value="Phone" <?php echo old_value($formData, 'preferred_contact') === 'Phone' ? 'selected' : ''; ?>>Phone</option>
                    <option value="Text" <?php echo old_value($formData, 'preferred_contact') === 'Text' ? 'selected' : ''; ?>>Text</option>
                    <option value="Email" <?php echo old_value($formData, 'preferred_contact') === 'Email' ? 'selected' : ''; ?>>Email</option>
                  </select>
                </div>
              </div>

              <div class="field-group">
                <label for="notes">Additional Notes</label>
                <textarea id="notes" name="notes" placeholder="Tell us anything helpful about your dog, routine, feeding details, pickup/drop-off preferences, or anything else we should know."><?php echo old_value($formData, 'notes'); ?></textarea>
              </div>

              <button type="submit" class="btn btn-gold">Continue to Payment Portal</button>
            </form>
          </div>
        </div>
      </div>
    </section>
  </main>

  <footer>
    <div class="container footer-wrap">
      <div>
        <div class="footer-brand">Doggie Dorian’s</div>
        <div class="footer-text">Luxury dog walking, drop-ins, daycare, boarding, and premium membership care.</div>
      </div>

      <div class="footer-text">
        © <?php echo date('Y'); ?> Doggie Dorian’s. All rights reserved.
      </div>
    </div>
  </footer>

</body>
</html>