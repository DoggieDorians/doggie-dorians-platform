<?php
session_start();
$isLoggedIn = isset($_SESSION['member_id']);

$selectedPlan = isset($_GET['plan']) ? trim($_GET['plan']) : '';
$allowedPlans = ['Essential Membership', 'Preferred Membership', 'Signature Membership'];
if (!in_array($selectedPlan, $allowedPlans, true)) {
    $selectedPlan = '';
}

$flashType = $_SESSION['membership_flash_type'] ?? '';
$flashMessage = $_SESSION['membership_flash_message'] ?? '';
$formData = $_SESSION['membership_form_data'] ?? [];

unset($_SESSION['membership_flash_type'], $_SESSION['membership_flash_message'], $_SESSION['membership_form_data']);

function old_value(array $formData, string $key): string {
    return htmlspecialchars($formData[$key] ?? '', ENT_QUOTES, 'UTF-8');
}

if (!$selectedPlan && !empty($formData['selected_membership']) && in_array($formData['selected_membership'], $allowedPlans, true)) {
    $selectedPlan = $formData['selected_membership'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Memberships | Doggie Dorian’s</title>
  <meta name="description" content="Explore Doggie Dorian’s luxury dog care memberships with premium recurring value, priority booking, and elevated monthly benefits.">

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
      --muted-soft: rgba(255,255,255,0.56);
      --shadow: 0 20px 60px rgba(0,0,0,0.45);
      --shadow-lg: 0 28px 90px rgba(0,0,0,0.55);
      --radius-xl: 32px;
      --radius-lg: 24px;
      --radius-md: 18px;
      --max: 1280px;
      --success: #1d8f5b;
      --danger: #b84b4b;
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

    .nav-links a {
      font-family: Arial, sans-serif;
      font-size: 0.95rem;
      color: rgba(255,255,255,0.87);
      padding: 10px 14px;
      border-radius: 999px;
      transition: 0.22s ease;
    }

    .nav-links a:hover {
      background: rgba(255,255,255,0.06);
      color: var(--gold-soft);
    }

    .nav-cta {
      border: 1px solid rgba(212,175,55,0.38);
      background: linear-gradient(135deg, rgba(212,175,55,0.18), rgba(255,255,255,0.03));
      color: var(--cream) !important;
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
      position: relative;
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
      font-size: clamp(2.8rem, 5vw, 5rem);
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

    .plans-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 24px;
      align-items: stretch;
    }

    .plan-card {
      position: relative;
      overflow: hidden;
      border-radius: 32px;
      border: 1px solid rgba(255,255,255,0.08);
      box-shadow: var(--shadow-lg);
      display: flex;
      flex-direction: column;
      min-height: 100%;
      background:
        linear-gradient(180deg, rgba(255,255,255,0.055), rgba(255,255,255,0.02)),
        radial-gradient(circle at top left, rgba(255,255,255,0.04), transparent 30%);
    }

    .plan-card.featured {
      background:
        linear-gradient(180deg, rgba(212,175,55,0.14), rgba(255,255,255,0.03)),
        radial-gradient(circle at top left, rgba(240,215,122,0.08), transparent 34%),
        rgba(255,255,255,0.02);
      border-color: rgba(212,175,55,0.24);
      transform: translateY(-4px);
    }

    .plan-top {
      padding: 30px 30px 20px;
      border-bottom: 1px solid rgba(255,255,255,0.08);
    }

    .plan-label {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-family: Arial, sans-serif;
      font-size: 0.76rem;
      text-transform: uppercase;
      letter-spacing: 2.4px;
      color: var(--gold-soft);
      margin-bottom: 12px;
    }

    .plan-title {
      font-size: 2rem;
      line-height: 1.05;
      color: var(--cream);
      margin-bottom: 10px;
    }

    .plan-subtitle {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.74);
      font-size: 0.98rem;
      margin-bottom: 18px;
    }

    .plan-price {
      display: flex;
      align-items: baseline;
      gap: 8px;
      margin-bottom: 8px;
    }

    .plan-price strong {
      font-size: 3rem;
      line-height: 1;
      color: var(--cream);
    }

    .plan-price span {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.72);
      font-size: 1rem;
    }

    .plan-value {
      font-family: Arial, sans-serif;
      color: var(--gold-soft);
      font-size: 0.95rem;
    }

    .plan-body {
      padding: 28px 30px 30px;
      display: flex;
      flex-direction: column;
      gap: 22px;
      flex: 1;
    }

    .plan-stats {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 12px;
    }

    .stat-box {
      border-radius: 18px;
      padding: 16px 12px;
      background: rgba(255,255,255,0.04);
      border: 1px solid rgba(255,255,255,0.08);
      text-align: center;
    }

    .stat-box strong {
      display: block;
      color: var(--gold-soft);
      font-size: 1.22rem;
      margin-bottom: 4px;
    }

    .stat-box span {
      font-family: Arial, sans-serif;
      font-size: 0.84rem;
      color: rgba(255,255,255,0.68);
    }

    .feature-group h3 {
      font-size: 1.12rem;
      color: var(--cream);
      margin-bottom: 12px;
    }

    .feature-list {
      list-style: none;
      display: grid;
      gap: 10px;
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.84);
      font-size: 0.96rem;
    }

    .feature-list li {
      padding: 12px 14px;
      border-radius: 16px;
      background: rgba(255,255,255,0.04);
      border: 1px solid rgba(255,255,255,0.07);
    }

    .plan-bottom {
      margin-top: auto;
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
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

    .btn-dark {
      color: var(--text);
      border: 1px solid rgba(255,255,255,0.14);
      background: rgba(255,255,255,0.04);
    }

    .compare-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 18px;
    }

    .compare-card {
      border-radius: 24px;
      padding: 26px;
      background: linear-gradient(180deg, rgba(255,255,255,0.045), rgba(255,255,255,0.02));
      border: 1px solid rgba(255,255,255,0.08);
      box-shadow: var(--shadow);
    }

    .compare-card h3 {
      color: var(--cream);
      font-size: 1.2rem;
      margin-bottom: 10px;
    }

    .compare-card p {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.72);
      font-size: 0.95rem;
    }

    .signup-shell {
      border-radius: 30px;
      padding: 30px;
      background:
        linear-gradient(135deg, rgba(212,175,55,0.16), rgba(255,255,255,0.04)),
        rgba(255,255,255,0.02);
      border: 1px solid rgba(212,175,55,0.18);
      box-shadow: var(--shadow-lg);
    }

    .signup-grid {
      display: grid;
      grid-template-columns: 0.92fr 1.08fr;
      gap: 24px;
      align-items: start;
    }

    .signup-copy h3 {
      font-size: 2rem;
      color: var(--cream);
      margin-bottom: 10px;
    }

    .signup-copy p {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.76);
      margin-bottom: 18px;
      max-width: 720px;
    }

    .signup-copy-list {
      display: grid;
      gap: 10px;
    }

    .signup-copy-list div {
      padding: 13px 14px;
      border-radius: 16px;
      background: rgba(255,255,255,0.045);
      border: 1px solid rgba(255,255,255,0.08);
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.84);
      font-size: 0.94rem;
    }

    .signup-form {
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

    .form-note {
      font-family: Arial, sans-serif;
      font-size: 0.88rem;
      color: rgba(255,255,255,0.56);
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
      .plans-grid,
      .compare-grid,
      .signup-grid,
      .hero-grid {
        grid-template-columns: 1fr;
      }

      .plan-card.featured {
        transform: none;
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
      .signup-copy h3 {
        font-size: 2rem;
      }

      .form-row,
      .plan-stats {
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

      .plan-top,
      .plan-body,
      .compare-card,
      .signup-shell,
      .hero-panel {
        padding-left: 22px;
        padding-right: 22px;
      }

      .plan-price strong {
        font-size: 2.3rem;
      }

      .btn {
        width: 100%;
      }
    }
  </style>

  <script>
    function choosePlan(planName) {
      const field = document.getElementById('selected_membership');
      const section = document.getElementById('membership-signup');

      if (field) {
        field.value = planName;
      }

      if (section) {
        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    }

    window.addEventListener('DOMContentLoaded', function () {
      const selectedPlan = <?php echo json_encode($selectedPlan); ?>;
      if (selectedPlan) {
        const field = document.getElementById('selected_membership');
        if (field) {
          field.value = selectedPlan;
        }
      }
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
        <a href="index.php">Home</a>
        <a href="services.php">Services</a>
        <a href="memberships.php">Memberships</a>
        <a href="book-walk.php">Book</a>
        <a href="contact.php">Contact</a>

        <?php if ($isLoggedIn): ?>
          <a href="dashboard.php">Dashboard</a>
        <?php else: ?>
          <a href="login.php">Login</a>
        <?php endif; ?>

        <a href="customize-plan.php" class="nav-cta">Build Your Plan</a>
      </nav>
    </div>
  </header>

  <main>
    <section class="hero">
      <div class="container">
        <div class="hero-shell">
          <div class="hero-grid">
            <div class="hero-copy">
              <div class="eyebrow">Recurring Membership Care</div>
              <h1>
                Basic memberships with a more <span>premium</span> experience built in.
              </h1>
              <p>
                Our standard memberships are designed for clients who want consistency, convenience, better monthly value, and a more polished way to care for their dogs on a recurring basis.
              </p>

              <div class="hero-pills">
                <div class="hero-pill">Monthly recurring value</div>
                <div class="hero-pill">Priority member access</div>
                <div class="hero-pill">Luxury brand experience</div>
                <div class="hero-pill">Flexible premium care</div>
              </div>
            </div>

            <div class="hero-side">
              <div class="hero-panel">
                <small>Membership Advantages</small>
                <h3>Reliable care with stronger monthly value.</h3>
                <p>
                  These plans are ideal for dog owners who want recurring support without needing a custom founder package.
                </p>

                <div class="hero-panel-list">
                  <div>Lower per-service cost through monthly membership structure</div>
                  <div>Priority booking access compared with one-off requests</div>
                  <div>Consistent recurring care for a more seamless routine</div>
                  <div>Simple enrollment with room to upgrade later</div>
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
          <div class="mini">Basic Memberships</div>
          <h2>Choose the membership that fits your routine.</h2>
          <p>
            Each tier is designed to feel clear, elevated, and premium while giving members dependable recurring value.
          </p>
        </div>

        <div class="plans-grid">
          <article class="plan-card">
            <div class="plan-top">
              <div class="plan-label">Essential</div>
              <h3 class="plan-title">Essential Membership</h3>
              <p class="plan-subtitle">
                A strong entry-level membership for clients who want recurring care and a more convenient monthly routine.
              </p>

              <div class="plan-price">
                <strong>$199</strong>
                <span>/ month</span>
              </div>

              <div class="plan-value">A premium foundation for consistent monthly care.</div>
            </div>

            <div class="plan-body">
              <div class="plan-stats">
                <div class="stat-box">
                  <strong>6</strong>
                  <span>30-Minute Walks</span>
                </div>
                <div class="stat-box">
                  <strong>1</strong>
                  <span>Daycare Day</span>
                </div>
              </div>

              <div class="feature-group">
                <h3>What’s Included</h3>
                <ul class="feature-list">
                  <li>6 complimentary 30-minute walks per month</li>
                  <li>1 daycare day per month</li>
                  <li>Priority member booking access</li>
                  <li>Member-only pricing structure</li>
                  <li>Easy upgrade path into higher tiers</li>
                </ul>
              </div>

              <div class="plan-bottom">
                <button type="button" class="btn btn-gold" onclick="choosePlan('Essential Membership')">Join Essential</button>
              </div>
            </div>
          </article>

          <article class="plan-card featured">
            <div class="plan-top">
              <div class="plan-label">Preferred • Most Popular</div>
              <h3 class="plan-title">Preferred Membership</h3>
              <p class="plan-subtitle">
                Our balanced recurring membership for clients who want stronger monthly value and more premium flexibility.
              </p>

              <div class="plan-price">
                <strong>$349</strong>
                <span>/ month</span>
              </div>

              <div class="plan-value">The sweet spot for recurring walks and elevated convenience.</div>
            </div>

            <div class="plan-body">
              <div class="plan-stats">
                <div class="stat-box">
                  <strong>12</strong>
                  <span>30-Minute Walks</span>
                </div>
                <div class="stat-box">
                  <strong>2</strong>
                  <span>Daycare Days</span>
                </div>
              </div>

              <div class="feature-group">
                <h3>What’s Included</h3>
                <ul class="feature-list">
                  <li>12 complimentary 30-minute walks per month</li>
                  <li>2 daycare days per month</li>
                  <li>Priority member booking access</li>
                  <li>Better bundled monthly value</li>
                  <li>Preferred access during busier periods</li>
                </ul>
              </div>

              <div class="plan-bottom">
                <button type="button" class="btn btn-gold" onclick="choosePlan('Preferred Membership')">Join Preferred</button>
              </div>
            </div>
          </article>

          <article class="plan-card">
            <div class="plan-top">
              <div class="plan-label">Signature</div>
              <h3 class="plan-title">Signature Membership</h3>
              <p class="plan-subtitle">
                A more elevated recurring option for clients who want greater support, stronger convenience, and a more premium cadence of care.
              </p>

              <div class="plan-price">
                <strong>$549</strong>
                <span>/ month</span>
              </div>

              <div class="plan-value">Higher monthly support with more premium access built in.</div>
            </div>

            <div class="plan-body">
              <div class="plan-stats">
                <div class="stat-box">
                  <strong>18</strong>
                  <span>30-Minute Walks</span>
                </div>
                <div class="stat-box">
                  <strong>3</strong>
                  <span>Daycare Days</span>
                </div>
              </div>

              <div class="feature-group">
                <h3>What’s Included</h3>
                <ul class="feature-list">
                  <li>18 complimentary 30-minute walks per month</li>
                  <li>3 daycare days per month</li>
                  <li>Priority scheduling with stronger access</li>
                  <li>Higher monthly bundled value</li>
                  <li>Ideal for clients wanting more regular support</li>
                </ul>
              </div>

              <div class="plan-bottom">
                <button type="button" class="btn btn-gold" onclick="choosePlan('Signature Membership')">Join Signature</button>
              </div>
            </div>
          </article>
        </div>
      </div>
    </section>

    <section>
      <div class="container">
        <div class="section-intro">
          <div class="mini">Why Membership Works</div>
          <h2>Simple structure. Better value. More convenience.</h2>
          <p>
            Memberships work best when they make the client experience easier while giving clear recurring value month after month.
          </p>
        </div>

        <div class="compare-grid">
          <div class="compare-card">
            <h3>Recurring Convenience</h3>
            <p>
              Memberships help create a more dependable routine without needing to book every service as a separate one-off request.
            </p>
          </div>

          <div class="compare-card">
            <h3>Priority Access</h3>
            <p>
              Members can receive stronger access to scheduling and better placement during higher-demand periods.
            </p>
          </div>

          <div class="compare-card">
            <h3>More Premium Value</h3>
            <p>
              Bundled monthly services help the plans feel more elevated, more efficient, and more worthwhile than pay-as-you-go care.
            </p>
          </div>
        </div>
      </div>
    </section>

    <section id="membership-signup">
      <div class="container">
        <div class="signup-shell">
          <div class="signup-grid">
            <div class="signup-copy">
              <h3>Sign up for a membership.</h3>
              <p>
                Choose your preferred plan and submit your request directly through the website. This version stores requests in your database so you can review and manage them properly.
              </p>

              <div class="signup-copy-list">
                <div>Real on-site membership request submission</div>
                <div>Saved directly into your database</div>
                <div>Luxury presentation that feels polished and intentional</div>
                <div>Easy path to upgrade later into premium or founder tiers</div>
              </div>
            </div>

            <form class="signup-form" action="process-membership-signup.php" method="post">
              <div class="form-row">
                <div class="field-group">
                  <label for="full_name">Full Name</label>
                  <input type="text" id="full_name" name="full_name" placeholder="Your full name" required value="<?php echo old_value($formData, 'full_name'); ?>">
                </div>

                <div class="field-group">
                  <label for="phone">Phone Number</label>
                  <input type="tel" id="phone" name="phone" placeholder="Your phone number" value="<?php echo old_value($formData, 'phone'); ?>">
                </div>
              </div>

              <div class="form-row">
                <div class="field-group">
                  <label for="email">Email Address</label>
                  <input type="email" id="email" name="email" placeholder="Your email address" required value="<?php echo old_value($formData, 'email'); ?>">
                </div>

                <div class="field-group">
                  <label for="selected_membership">Selected Membership</label>
                  <select id="selected_membership" name="selected_membership" required>
                    <option value="">Choose a membership</option>
                    <option value="Essential Membership" <?php echo $selectedPlan === 'Essential Membership' ? 'selected' : ''; ?>>Essential Membership</option>
                    <option value="Preferred Membership" <?php echo $selectedPlan === 'Preferred Membership' ? 'selected' : ''; ?>>Preferred Membership</option>
                    <option value="Signature Membership" <?php echo $selectedPlan === 'Signature Membership' ? 'selected' : ''; ?>>Signature Membership</option>
                  </select>
                </div>
              </div>

              <div class="form-row">
                <div class="field-group">
                  <label for="dog_name">Dog Name</label>
                  <input type="text" id="dog_name" name="dog_name" placeholder="Your dog's name" value="<?php echo old_value($formData, 'dog_name'); ?>">
                </div>

                <div class="field-group">
                  <label for="preferred_contact">Preferred Contact Method</label>
                  <select id="preferred_contact" name="preferred_contact">
                    <option value="">Select one</option>
                    <option value="Phone" <?php echo old_value($formData, 'preferred_contact') === 'Phone' ? 'selected' : ''; ?>>Phone</option>
                    <option value="Text" <?php echo old_value($formData, 'preferred_contact') === 'Text' ? 'selected' : ''; ?>>Text</option>
                    <option value="Email" <?php echo old_value($formData, 'preferred_contact') === 'Email' ? 'selected' : ''; ?>>Email</option>
                  </select>
                </div>
              </div>

              <div class="field-group">
                <label for="notes">Tell Us More</label>
                <textarea id="notes" name="notes" placeholder="Share anything helpful about your schedule, your dog, or what you're looking for in a membership."><?php echo old_value($formData, 'notes'); ?></textarea>
              </div>

              <div class="form-note">
                Once submitted, your request is saved to your database. Later, we can build an admin page to review, approve, and manage membership signups.
              </div>

              <button type="submit" class="btn btn-gold">Submit Membership Request</button>
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
        <div class="footer-text">Luxury dog walking, daycare, boarding, and premium membership care.</div>
      </div>

      <div class="footer-text">
        © <?php echo date('Y'); ?> Doggie Dorian’s. All rights reserved.
      </div>
    </div>
  </footer>

</body>
</html>