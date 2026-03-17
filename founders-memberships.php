<?php
session_start();
$isLoggedIn = isset($_SESSION['member_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Founders Memberships | Doggie Dorian’s</title>
  <meta name="description" content="Exclusive founders memberships from Doggie Dorian’s featuring premium monthly care, quarterly credits, priority access, and elevated founding-member perks.">

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
      --muted: rgba(255,255,255,0.68);
      --muted-soft: rgba(255,255,255,0.56);
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
      grid-template-columns: 1.08fr 0.92fr;
      gap: 0;
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
      font-size: clamp(2.8rem, 5vw, 5.2rem);
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
      font-size: 1.06rem;
      color: rgba(255,255,255,0.78);
      max-width: 760px;
      margin-bottom: 28px;
    }

    .hero-pills {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      margin-bottom: 30px;
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

    .hero-actions {
      display: flex;
      gap: 14px;
      flex-wrap: wrap;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 54px;
      padding: 0 22px;
      border-radius: 999px;
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

    .hero-side {
      min-height: 100%;
      padding: 28px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      border-left: 1px solid rgba(255,255,255,0.07);
      background:
        linear-gradient(180deg, rgba(18,18,23,0.78), rgba(12,12,16,0.92)),
        radial-gradient(circle at center, rgba(212,175,55,0.10), transparent 46%);
    }

    .signature-card {
      border-radius: 26px;
      padding: 28px;
      background:
        linear-gradient(180deg, rgba(212,175,55,0.12), rgba(255,255,255,0.03)),
        rgba(255,255,255,0.02);
      border: 1px solid rgba(212,175,55,0.18);
      box-shadow: var(--shadow);
    }

    .signature-card small {
      display: block;
      font-family: Arial, sans-serif;
      font-size: 0.74rem;
      text-transform: uppercase;
      letter-spacing: 2.2px;
      color: var(--gold-soft);
      margin-bottom: 10px;
    }

    .signature-card h3 {
      font-size: 1.85rem;
      line-height: 1.05;
      color: var(--cream);
      margin-bottom: 10px;
    }

    .signature-card p {
      font-family: Arial, sans-serif;
      font-size: 0.96rem;
      color: rgba(255,255,255,0.76);
      margin-bottom: 18px;
    }

    .signature-list {
      display: grid;
      gap: 10px;
    }

    .signature-list div {
      padding: 12px 14px;
      border-radius: 16px;
      background: rgba(255,255,255,0.045);
      border: 1px solid rgba(255,255,255,0.08);
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.84);
      font-size: 0.93rem;
    }

    .hero-note {
      margin-top: 18px;
      border-radius: 20px;
      padding: 18px;
      background: rgba(255,255,255,0.035);
      border: 1px solid rgba(255,255,255,0.08);
    }

    .hero-note strong {
      display: block;
      color: var(--cream);
      font-size: 1rem;
      margin-bottom: 6px;
    }

    .hero-note span {
      font-family: Arial, sans-serif;
      font-size: 0.92rem;
      color: rgba(255,255,255,0.72);
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
      font-size: clamp(2rem, 3vw, 3.3rem);
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

    .packages-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 24px;
      align-items: stretch;
    }

    .package-card {
      position: relative;
      overflow: hidden;
      border-radius: 32px;
      border: 1px solid rgba(255,255,255,0.08);
      box-shadow: var(--shadow-lg);
      display: flex;
      flex-direction: column;
      min-height: 100%;
    }

    .package-card::before {
      content: "";
      position: absolute;
      inset: 0;
      pointer-events: none;
      background: linear-gradient(180deg, rgba(255,255,255,0.03), transparent 28%);
    }

    .package-card.silver {
      background:
        linear-gradient(180deg, rgba(255,255,255,0.055), rgba(255,255,255,0.02)),
        radial-gradient(circle at top left, rgba(255,255,255,0.04), transparent 30%);
    }

    .package-card.gold {
      background:
        linear-gradient(180deg, rgba(212,175,55,0.14), rgba(255,255,255,0.03)),
        radial-gradient(circle at top left, rgba(240,215,122,0.08), transparent 34%),
        rgba(255,255,255,0.02);
      border-color: rgba(212,175,55,0.24);
      transform: translateY(-4px);
    }

    .package-top {
      position: relative;
      padding: 30px 30px 20px;
      border-bottom: 1px solid rgba(255,255,255,0.08);
    }

    .package-label {
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

    .package-title {
      font-size: 2rem;
      line-height: 1.05;
      color: var(--cream);
      margin-bottom: 10px;
    }

    .package-subtitle {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.74);
      font-size: 0.98rem;
      margin-bottom: 18px;
      max-width: 560px;
    }

    .package-price {
      display: flex;
      align-items: baseline;
      gap: 8px;
      margin-bottom: 8px;
    }

    .package-price strong {
      font-size: 3rem;
      line-height: 1;
      color: var(--cream);
    }

    .package-price span {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.72);
      font-size: 1rem;
    }

    .package-value {
      font-family: Arial, sans-serif;
      color: var(--gold-soft);
      font-size: 0.95rem;
    }

    .package-body {
      padding: 28px 30px 30px;
      display: flex;
      flex-direction: column;
      gap: 22px;
      flex: 1;
    }

    .package-stats {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
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

    .package-bottom {
      margin-top: auto;
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }

    .structure-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 18px;
    }

    .structure-card {
      border-radius: 24px;
      padding: 26px;
      background: linear-gradient(180deg, rgba(255,255,255,0.045), rgba(255,255,255,0.02));
      border: 1px solid rgba(255,255,255,0.08);
      box-shadow: var(--shadow);
    }

    .structure-card h3 {
      color: var(--cream);
      font-size: 1.2rem;
      margin-bottom: 10px;
    }

    .structure-card p {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.72);
      font-size: 0.95rem;
    }

    .highlight-band {
      border-radius: 30px;
      padding: 30px;
      background:
        linear-gradient(135deg, rgba(212,175,55,0.16), rgba(255,255,255,0.04)),
        rgba(255,255,255,0.02);
      border: 1px solid rgba(212,175,55,0.18);
      box-shadow: var(--shadow-lg);
      display: grid;
      grid-template-columns: 1.05fr 0.95fr;
      gap: 20px;
      align-items: center;
    }

    .highlight-band h3 {
      font-size: 2rem;
      color: var(--cream);
      margin-bottom: 10px;
    }

    .highlight-band p {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.76);
      max-width: 780px;
    }

    .highlight-points {
      display: grid;
      gap: 10px;
    }

    .highlight-points div {
      padding: 13px 14px;
      border-radius: 16px;
      background: rgba(255,255,255,0.045);
      border: 1px solid rgba(255,255,255,0.08);
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.84);
      font-size: 0.94rem;
    }

    .cta-band {
      padding: 30px;
      border-radius: 30px;
      background:
        linear-gradient(135deg, rgba(212,175,55,0.16), rgba(255,255,255,0.04)),
        rgba(255,255,255,0.025);
      border: 1px solid rgba(212,175,55,0.18);
      box-shadow: var(--shadow-lg);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 20px;
      flex-wrap: wrap;
    }

    .cta-band h3 {
      font-size: 2rem;
      color: var(--cream);
      margin-bottom: 8px;
    }

    .cta-band p {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.76);
      max-width: 780px;
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

    @media (max-width: 1100px) {
      .hero-grid,
      .packages-grid,
      .structure-grid,
      .highlight-band {
        grid-template-columns: 1fr;
      }

      .package-card.gold {
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
      .highlight-band h3,
      .cta-band h3 {
        font-size: 2rem;
      }

      .package-stats {
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

      .package-top,
      .package-body,
      .structure-card,
      .highlight-band,
      .cta-band,
      .signature-card {
        padding-left: 22px;
        padding-right: 22px;
      }

      .package-price strong {
        font-size: 2.3rem;
      }

      .btn {
        width: 100%;
      }
    }
  </style>
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
              <div class="eyebrow">Exclusive Founding Access</div>
              <h1>
                Founders memberships built for clients who want a more <span>elevated</span> standard of care.
              </h1>
              <p>
                These signature packages were created for early members who want premium monthly value, stronger perks, quarterly founder credits, and priority access as Doggie Dorian’s grows into a higher-end pet care brand.
              </p>

              <div class="hero-pills">
                <div class="hero-pill">Limited early-member access</div>
                <div class="hero-pill">Monthly founder pricing</div>
                <div class="hero-pill">Annual credits paid quarterly</div>
                <div class="hero-pill">Priority booking access</div>
              </div>

              <div class="hero-actions">
                <a href="contact.php" class="btn btn-gold">Apply for Founders Access</a>
                <a href="memberships.php" class="btn btn-dark">View All Memberships</a>
              </div>
            </div>

            <div class="hero-side">
              <div class="signature-card">
                <small>Signature Founder Benefits</small>
                <h3>Premium value, elevated perks, and early-member status.</h3>
                <p>
                  The founders collection is designed to feel stronger than a standard plan and more exclusive than an ordinary membership.
                </p>

                <div class="signature-list">
                  <div>Priority scheduling and preferred booking access</div>
                  <div>Annual founder credit distributed quarterly</div>
                  <div>Monthly walk rollover into the following month</div>
                  <div>Birthday gifts, premium extras, and founder-only perks</div>
                </div>
              </div>

              <div class="hero-note">
                <strong>Built to feel premium from the first month.</strong>
                <span>
                  These plans are meant to reward early loyalty while positioning the brand at a much higher level.
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section>
      <div class="container">
        <div class="section-intro">
          <div class="mini">Founders Packages</div>
          <h2>Choose your founder tier.</h2>
          <p>
            Each package is structured to feel generous, polished, and genuinely premium while still being easy to understand at a glance.
          </p>
        </div>

        <div class="packages-grid">
          <article class="package-card silver">
            <div class="package-top">
              <div class="package-label">Silver Founder</div>
              <h3 class="package-title">Silver Founding Membership</h3>
              <p class="package-subtitle">
                A premium monthly package for clients who want strong recurring value, better flexibility, and exclusive early-member status.
              </p>

              <div class="package-price">
                <strong>$500</strong>
                <span>/ month</span>
              </div>

              <div class="package-value">Premium recurring care with elevated founder benefits.</div>
            </div>

            <div class="package-body">
              <div class="package-stats">
                <div class="stat-box">
                  <strong>14</strong>
                  <span>30-Minute Walks / Month</span>
                </div>
                <div class="stat-box">
                  <strong>3</strong>
                  <span>Daycare Days</span>
                </div>
                <div class="stat-box">
                  <strong>2</strong>
                  <span>Boarding Nights</span>
                </div>
              </div>

              <div class="feature-group">
                <h3>Included Each Month</h3>
                <ul class="feature-list">
                  <li>14 complimentary 30-minute dog walks per month</li>
                  <li>3 daycare days included</li>
                  <li>2 boarding nights included</li>
                  <li>Priority booking access</li>
                  <li>Founding member recognition and preferred status</li>
                </ul>
              </div>

              <div class="feature-group">
                <h3>Founder Perks</h3>
                <ul class="feature-list">
                  <li>$500 annual founder credit, distributed as $125 quarterly</li>
                  <li>Monthly walk rollover into the following month</li>
                  <li>1 complimentary birthday walk each year</li>
                  <li>1 birthday gift for your dog each year</li>
                  <li>1 seasonal treat box each year</li>
                  <li>Early access to select future services and offerings</li>
                  <li>Founder-only surprise perks and appreciation gifts</li>
                </ul>
              </div>

              <div class="package-bottom">
                <a href="contact.php" class="btn btn-gold">Apply for Silver Founder</a>
                <a href="customize-plan.php" class="btn btn-dark">Build a Custom Plan</a>
              </div>
            </div>
          </article>

          <article class="package-card gold">
            <div class="package-top">
              <div class="package-label">Gold Founder • Signature Tier</div>
              <h3 class="package-title">Gold Founding Membership</h3>
              <p class="package-subtitle">
                Our higher-tier founder package for clients who want a stronger monthly experience, richer value, and a more elite early-member position.
              </p>

              <div class="package-price">
                <strong>$800</strong>
                <span>/ month</span>
              </div>

              <div class="package-value">Designed to feel high-touch, elevated, and distinctly premium.</div>
            </div>

            <div class="package-body">
              <div class="package-stats">
                <div class="stat-box">
                  <strong>20</strong>
                  <span>30-Minute Walks / Month</span>
                </div>
                <div class="stat-box">
                  <strong>5</strong>
                  <span>Daycare Days</span>
                </div>
                <div class="stat-box">
                  <strong>3</strong>
                  <span>Boarding Nights</span>
                </div>
              </div>

              <div class="feature-group">
                <h3>Included Each Month</h3>
                <ul class="feature-list">
                  <li>20 complimentary 30-minute dog walks per month</li>
                  <li>5 daycare days included</li>
                  <li>3 boarding nights included</li>
                  <li>Priority booking and elevated scheduling access</li>
                  <li>Top-tier founding member status</li>
                </ul>
              </div>

              <div class="feature-group">
                <h3>Signature Founder Perks</h3>
                <ul class="feature-list">
                  <li>$800 annual founder credit, distributed as $200 quarterly</li>
                  <li>Monthly walk rollover into the following month</li>
                  <li>1 complimentary birthday walk each year</li>
                  <li>1 premium birthday gift for your dog each year</li>
                  <li>2 seasonal luxury treat boxes each year</li>
                  <li>VIP priority scheduling when availability is limited</li>
                  <li>Complimentary last-minute booking flexibility when available</li>
                  <li>First access to premium future features and VIP upgrades</li>
                  <li>Enhanced founder gifts, surprise perks, and loyalty rewards</li>
                </ul>
              </div>

              <div class="package-bottom">
                <a href="contact.php" class="btn btn-gold">Apply for Gold Founder</a>
                <a href="book-walk.php" class="btn btn-dark">Book a Service</a>
              </div>
            </div>
          </article>
        </div>
      </div>
    </section>

    <section>
      <div class="container">
        <div class="section-intro">
          <div class="mini">Membership Structure</div>
          <h2>Clear, premium, and easy to understand.</h2>
          <p>
            The experience should feel luxurious, but the package structure should still be simple and confident.
          </p>
        </div>

        <div class="structure-grid">
          <div class="structure-card">
            <h3>Monthly Membership</h3>
            <p>
              Founder pricing is billed monthly, giving members recurring premium value every month they remain active.
            </p>
          </div>

          <div class="structure-card">
            <h3>Annual Credit, Paid Quarterly</h3>
            <p>
              Silver includes a $500 annual founder credit paid as $125 per quarter, while Gold includes an $800 annual founder credit paid as $200 per quarter.
            </p>
          </div>

          <div class="structure-card">
            <h3>Walk Rollover</h3>
            <p>
              Unused monthly walks may roll into the following month, giving members added flexibility and retained value.
            </p>
          </div>
        </div>
      </div>
    </section>

    <section>
      <div class="container">
        <div class="highlight-band">
          <div>
            <h3>Perks that make the memberships feel truly special.</h3>
            <p>
              The strongest founder offers are not only about bundled services. They also create emotional value through gifts, thoughtful extras, premium recognition, and a level of access that feels different from a standard plan.
            </p>
          </div>

          <div class="highlight-points">
            <div>Birthday gifts and a complimentary birthday walk</div>
            <div>Treat boxes and premium surprise perks throughout the year</div>
            <div>Priority access that rewards early loyalty</div>
            <div>A more memorable, premium relationship with the brand</div>
          </div>
        </div>
      </div>
    </section>

    <section>
      <div class="container">
        <div class="cta-band">
          <div>
            <h3>Interested in becoming a founding member?</h3>
            <p>
              Reach out to secure your place, ask questions, or discuss which founder package best fits your dog’s routine and the level of care you want.
            </p>
          </div>

          <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <a href="contact.php" class="btn btn-gold">Contact Us</a>
            <a href="memberships.php" class="btn btn-dark">View All Memberships</a>
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