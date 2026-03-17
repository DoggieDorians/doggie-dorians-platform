<?php
session_start();
$isLoggedIn = isset($_SESSION['member_id']);

$flashType = $_SESSION['nonmember_flash_type'] ?? '';
$flashMessage = $_SESSION['nonmember_flash_message'] ?? '';
$formData = $_SESSION['nonmember_form_data'] ?? [];

unset(
    $_SESSION['nonmember_flash_type'],
    $_SESSION['nonmember_flash_message'],
    $_SESSION['nonmember_form_data']
);

function old_value(array $formData, string $key): string {
    return htmlspecialchars($formData[$key] ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Non-Member Booking | Doggie Dorian’s</title>
  <meta name="description" content="Book non-member dog walks, daycare, and boarding with Doggie Dorian’s. Premium service with transparent pricing and a luxury booking experience.">

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
      grid-template-columns: repeat(3, 1fr);
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
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.72);
      font-size: 0.92rem;
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
    function updateBookingFormUI() {
      const serviceType = document.getElementById('service_type').value;
      const walkFields = document.querySelectorAll('.walk-only');
      const sizeFields = document.querySelectorAll('.size-only');
      const dateEndWrap = document.getElementById('date_end_wrap');
      const walkTimeWrap = document.getElementById('walk_time_wrap');

      walkFields.forEach(el => {
        el.style.display = serviceType === 'Walk' ? 'grid' : 'none';
      });

      sizeFields.forEach(el => {
        el.style.display = (serviceType === 'Daycare' || serviceType === 'Boarding') ? 'grid' : 'none';
      });

      if (dateEndWrap) {
        dateEndWrap.style.display = serviceType === 'Boarding' ? 'grid' : 'none';
      }

      if (walkTimeWrap) {
        walkTimeWrap.style.display = serviceType === 'Walk' ? 'grid' : 'none';
      }

      updateEstimatedPrice();
    }

    function updateEstimatedPrice() {
      const serviceType = document.getElementById('service_type').value;
      const walkDuration = document.getElementById('walk_duration').value;
      const dogSize = document.getElementById('dog_size').value;
      const estimateField = document.getElementById('estimated_price');
      const estimateText = document.getElementById('estimated_price_text');

      let amount = 0;
      let label = 'Select service details to view an estimate.';

      if (serviceType === 'Walk') {
        const walkPrices = {
          '15': 23,
          '20': 25,
          '30': 30,
          '45': 38,
          '60': 42
        };

        if (walkPrices[walkDuration]) {
          amount = walkPrices[walkDuration];
          label = '$' + amount + ' estimated for one non-member walk.';
        }
      }

      if (serviceType === 'Daycare') {
        const daycarePrices = {
          'Small': 65,
          'Medium': 85,
          'Large': 110
        };

        if (daycarePrices[dogSize]) {
          amount = daycarePrices[dogSize];
          label = '$' + amount + ' estimated for one daycare session.';
        }
      }

      if (serviceType === 'Boarding') {
        const boardingPrices = {
          'Small': 90,
          'Medium': 110,
          'Large': 120
        };

        if (boardingPrices[dogSize]) {
          amount = boardingPrices[dogSize];
          label = '$' + amount + ' estimated per boarding night.';
        }
      }

      if (estimateField) {
        estimateField.value = amount > 0 ? amount.toFixed(2) : '';
      }

      if (estimateText) {
        estimateText.textContent = label;
      }
    }

    window.addEventListener('DOMContentLoaded', function () {
      updateBookingFormUI();

      document.getElementById('service_type').addEventListener('change', updateBookingFormUI);
      document.getElementById('walk_duration').addEventListener('change', updateEstimatedPrice);
      document.getElementById('dog_size').addEventListener('change', updateEstimatedPrice);
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
              <div class="eyebrow">Non-Member Booking</div>
              <h1>
                Book premium care without a <span>membership</span>.
              </h1>
              <p>
                This page is built for clients who want one-time or occasional bookings for walks, daycare, or boarding while still experiencing the elevated Doggie Dorian’s brand.
              </p>

              <div class="hero-pills">
                <div class="hero-pill">Luxury booking experience</div>
                <div class="hero-pill">Transparent pricing</div>
                <div class="hero-pill">Walks, daycare, and boarding</div>
                <div class="hero-pill">Saved directly to your system</div>
              </div>
            </div>

            <div class="hero-side">
              <div class="hero-panel">
                <small>What Clients Can Book</small>
                <h3>Flexible premium care for non-members.</h3>
                <p>
                  Clients can request walks, daycare, or boarding, along with dog size, feeding preferences, and preferred walk times.
                </p>

                <div class="hero-panel-list">
                  <div>Walk requests from 15 to 60 minutes</div>
                  <div>Daycare and boarding priced by dog size</div>
                  <div>Feeding schedule and preferred walk-time selection</div>
                  <div>Real submission saved to your database</div>
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
              <li><span>15-Minute Walk</span><strong>$23</strong></li>
              <li><span>20-Minute Walk</span><strong>$25</strong></li>
              <li><span>30-Minute Walk</span><strong>$30</strong></li>
              <li><span>45-Minute Walk</span><strong>$38</strong></li>
              <li><span>60-Minute Walk</span><strong>$42</strong></li>
            </ul>
          </div>

          <div class="pricing-card">
            <h3>Boarding Pricing</h3>
            <p>Pricing per night based on dog size.</p>
            <ul class="price-list">
              <li><span>Small Dog</span><strong>$90</strong></li>
              <li><span>Medium Dog</span><strong>$110</strong></li>
              <li><span>Large Dog</span><strong>$120</strong></li>
            </ul>
          </div>

          <div class="pricing-card">
            <h3>Daycare Pricing</h3>
            <p>Pricing per session based on dog size.</p>
            <ul class="price-list">
              <li><span>Small Dog</span><strong>$65</strong></li>
              <li><span>Medium Dog</span><strong>$85</strong></li>
              <li><span>Large Dog</span><strong>$110</strong></li>
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
              <h3>Submit a non-member booking request.</h3>
              <p>
                This form captures one-time or occasional booking requests and stores them directly in your database for follow-up and scheduling.
              </p>

              <div class="booking-copy-list">
                <div>Walk duration selection for non-member bookings</div>
                <div>Boarding and daycare by dog size</div>
                <div>Feeding schedule preference built in</div>
                <div>Preferred walk time field for walk clients</div>
              </div>

              <div class="quote-box">
                <small>Estimated Price</small>
                <strong id="estimated_price_text">Select service details to view an estimate.</strong>
                <span>Boarding pricing shown is per night. Daycare pricing shown is per session.</span>
              </div>
            </div>

            <form class="booking-form" action="process-non-member-booking.php" method="post">
              <input type="hidden" id="estimated_price" name="estimated_price" value="<?php echo old_value($formData, 'estimated_price'); ?>">

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
                    <option value="Daycare" <?php echo old_value($formData, 'service_type') === 'Daycare' ? 'selected' : ''; ?>>Daycare</option>
                    <option value="Boarding" <?php echo old_value($formData, 'service_type') === 'Boarding' ? 'selected' : ''; ?>>Boarding</option>
                  </select>
                </div>
              </div>

              <div class="form-row">
                <div class="field-group">
                  <label for="dog_name">Dog Name</label>
                  <input type="text" id="dog_name" name="dog_name" required placeholder="Your dog's name" value="<?php echo old_value($formData, 'dog_name'); ?>">
                </div>

                <div class="field-group size-only" style="display:none;">
                  <label for="dog_size">Dog Size</label>
                  <select id="dog_size" name="dog_size">
                    <option value="">Choose a size</option>
                    <option value="Small" <?php echo old_value($formData, 'dog_size') === 'Small' ? 'selected' : ''; ?>>Small</option>
                    <option value="Medium" <?php echo old_value($formData, 'dog_size') === 'Medium' ? 'selected' : ''; ?>>Medium</option>
                    <option value="Large" <?php echo old_value($formData, 'dog_size') === 'Large' ? 'selected' : ''; ?>>Large</option>
                  </select>
                </div>
              </div>

              <div class="form-row walk-only" style="display:none;">
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

                <div class="field-group" id="walk_time_wrap" style="display:none;">
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

              <div class="form-row">
                <div class="field-group">
                  <label for="date_start">Requested Start Date</label>
                  <input type="date" id="date_start" name="date_start" required value="<?php echo old_value($formData, 'date_start'); ?>">
                </div>

                <div class="field-group" id="date_end_wrap" style="display:none;">
                  <label for="date_end">Requested End Date</label>
                  <input type="date" id="date_end" name="date_end" value="<?php echo old_value($formData, 'date_end'); ?>">
                  <div class="helper">Only needed for boarding requests.</div>
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

              <button type="submit" class="btn btn-gold">Submit Booking Request</button>
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