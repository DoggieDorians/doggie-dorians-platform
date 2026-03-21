<?php
session_start();
$isLoggedIn = isset($_SESSION['member_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Services | Doggie Dorian's</title>
  <meta
    name="description"
    content="Explore luxury dog walking, premium daycare, and boutique boarding from Doggie Dorian’s. Designed for Manhattan dog parents who want polished, dependable, elevated care."
  />
  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    :root {
      --bg: #09090c;
      --bg-2: #101016;
      --panel: rgba(255, 255, 255, 0.05);
      --panel-2: rgba(255, 255, 255, 0.08);
      --border: rgba(255, 255, 255, 0.1);
      --text: #f7f3ec;
      --muted: #cbc3b7;
      --soft: #9d9486;
      --gold: #d7b56d;
      --gold-2: #f2dba9;
      --shadow: 0 24px 70px rgba(0, 0, 0, 0.45);
      --radius-xl: 30px;
      --radius-lg: 22px;
      --radius-md: 18px;
      --max: 1240px;
    }

    html { scroll-behavior: smooth; }

    body {
      font-family: "Inter", "Helvetica Neue", Arial, sans-serif;
      background:
        radial-gradient(circle at top left, rgba(215, 181, 109, 0.16), transparent 24%),
        radial-gradient(circle at top right, rgba(242, 219, 169, 0.08), transparent 20%),
        linear-gradient(180deg, #09090c 0%, #101016 34%, #09090c 100%);
      color: var(--text);
      line-height: 1.6;
      overflow-x: hidden;
    }

    a {
      color: inherit;
      text-decoration: none;
    }

    .container {
      width: min(var(--max), calc(100% - 32px));
      margin: 0 auto;
    }

    .topbar {
      position: sticky;
      top: 0;
      z-index: 1000;
      backdrop-filter: blur(18px);
      background: rgba(8, 8, 11, 0.72);
      border-bottom: 1px solid rgba(255,255,255,.08);
    }

    .nav {
      min-height: 84px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 18px;
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 14px;
      min-width: 0;
    }

    .brand-mark {
      width: 48px;
      height: 48px;
      border-radius: 15px;
      display: grid;
      place-items: center;
      background: linear-gradient(135deg, rgba(242,219,169,.24), rgba(184,141,68,.72));
      border: 1px solid rgba(255,255,255,.12);
      color: #fff6e5;
      font-weight: 800;
      font-size: 1rem;
      box-shadow: inset 0 1px 0 rgba(255,255,255,.16), 0 10px 30px rgba(0,0,0,.24);
    }

    .brand-title {
      font-size: 1.08rem;
      font-weight: 800;
      letter-spacing: -0.03em;
      white-space: nowrap;
    }

    .brand-subtitle {
      font-size: 0.78rem;
      color: var(--soft);
      text-transform: uppercase;
      letter-spacing: 0.1em;
      margin-top: 2px;
    }

    .nav-links {
      list-style: none;
      display: flex;
      align-items: center;
      gap: 26px;
      color: var(--muted);
      font-size: 0.98rem;
    }

    .nav-links a {
      position: relative;
      transition: color 0.2s ease;
    }

    .nav-links a:hover { color: var(--text); }

    .nav-links a::after {
      content: "";
      position: absolute;
      left: 0;
      right: 0;
      bottom: -8px;
      height: 1px;
      background: linear-gradient(90deg, transparent, var(--gold), transparent);
      transform: scaleX(0);
      transition: transform 0.22s ease;
    }

    .nav-links a:hover::after { transform: scaleX(1); }

    .nav-actions {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 52px;
      padding: 0 22px;
      border-radius: 999px;
      border: 1px solid transparent;
      font-size: 0.97rem;
      font-weight: 700;
      letter-spacing: -0.01em;
      cursor: pointer;
      transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease, background 0.18s ease;
      white-space: nowrap;
    }

    .btn:hover { transform: translateY(-1px); }

    .btn-primary {
      background: linear-gradient(135deg, var(--gold-2), var(--gold));
      color: #171105;
      box-shadow: 0 16px 38px rgba(215,181,109,.3);
    }

    .btn-secondary {
      background: rgba(255,255,255,.05);
      border-color: rgba(255,255,255,.14);
      color: var(--text);
    }

    .btn-ghost {
      background: transparent;
      border-color: rgba(255,255,255,.1);
      color: var(--muted);
    }

    .eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 10px 16px;
      border-radius: 999px;
      border: 1px solid rgba(215, 181, 109, 0.24);
      background: rgba(215, 181, 109, 0.08);
      color: var(--gold-2);
      font-size: 0.82rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
    }

    .eyebrow::before {
      content: "";
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: var(--gold);
      box-shadow: 0 0 14px rgba(215, 181, 109, 0.95);
    }

    .hero {
      padding: 42px 0 34px;
    }

    .service-area-strip {
      padding: 18px 20px;
      border-radius: 18px;
      background:
        linear-gradient(135deg, rgba(242,219,169,.12), rgba(215,181,109,.05)),
        rgba(255,255,255,.04);
      border: 1px solid rgba(215,181,109,.2);
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      flex-wrap: wrap;
      margin-bottom: 24px;
    }

    .service-area-strip strong {
      color: var(--gold-2);
      display: block;
      margin-bottom: 4px;
    }

    .service-area-strip span {
      color: var(--muted);
      font-size: .96rem;
    }

    .hero-grid {
      display: grid;
      grid-template-columns: 1.04fr .96fr;
      gap: 26px;
      align-items: start;
    }

    .hero-copy h1 {
      font-size: clamp(2.7rem, 5vw, 5rem);
      line-height: .95;
      letter-spacing: -.06em;
      margin: 18px 0 16px;
    }

    .hero-copy h1 .accent { color: var(--gold-2); }

    .hero-copy p {
      color: var(--muted);
      font-size: 1.08rem;
      max-width: 720px;
      margin-bottom: 24px;
    }

    .hero-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 14px;
      margin-bottom: 22px;
    }

    .hero-badges {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
    }

    .hero-badge {
      padding: 10px 14px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,.1);
      background: rgba(255,255,255,.04);
      color: var(--text);
      font-size: .9rem;
      font-weight: 600;
    }

    .hero-panel {
      border-radius: 28px;
      padding: 26px;
      background:
        linear-gradient(180deg, rgba(255,255,255,.08), rgba(255,255,255,.03)),
        linear-gradient(160deg, #15151b, #101015);
      border: 1px solid rgba(255,255,255,.08);
      box-shadow: var(--shadow);
    }

    .hero-panel h3 {
      font-size: 1.55rem;
      margin-bottom: 8px;
      letter-spacing: -.03em;
    }

    .hero-panel p {
      color: var(--muted);
      margin-bottom: 18px;
    }

    .quick-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
    }

    .quick-box {
      padding: 16px;
      border-radius: 18px;
      background: rgba(255,255,255,.04);
      border: 1px solid rgba(255,255,255,.08);
    }

    .quick-box small {
      display: block;
      color: var(--soft);
      text-transform: uppercase;
      letter-spacing: .08em;
      font-size: .72rem;
      margin-bottom: 6px;
    }

    .quick-box strong {
      display: block;
      color: var(--gold-2);
      font-size: 1.08rem;
      margin-bottom: 4px;
    }

    .quick-box span {
      color: var(--muted);
      font-size: .92rem;
    }

    .section {
      padding: 84px 0;
    }

    .section-head {
      max-width: 860px;
      margin-bottom: 28px;
    }

    .section-head h2 {
      font-size: clamp(2rem, 3vw, 3.4rem);
      line-height: 1.03;
      letter-spacing: -0.04em;
      margin-bottom: 14px;
    }

    .section-head p {
      color: var(--muted);
      font-size: 1.04rem;
    }

    .service-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 22px;
    }

    .service-card {
      position: relative;
      overflow: hidden;
      border-radius: 26px;
      padding: 28px;
      background:
        linear-gradient(180deg, rgba(255,255,255,.08), rgba(255,255,255,.03)),
        linear-gradient(160deg, #15151b, #101015);
      border: 1px solid rgba(255,255,255,.08);
      box-shadow: var(--shadow);
    }

    .service-card::after {
      content: "";
      position: absolute;
      left: 0;
      right: 0;
      bottom: 0;
      height: 2px;
      background: linear-gradient(90deg, transparent, rgba(215,181,109,.95), transparent);
      opacity: .7;
    }

    .service-tag {
      display: inline-flex;
      padding: 8px 12px;
      border-radius: 999px;
      background: rgba(215,181,109,.12);
      border: 1px solid rgba(215,181,109,.18);
      color: var(--gold-2);
      font-size: .76rem;
      text-transform: uppercase;
      letter-spacing: .08em;
      font-weight: 700;
      margin-bottom: 16px;
    }

    .service-card h3 {
      font-size: 1.7rem;
      line-height: 1.05;
      letter-spacing: -.03em;
      margin-bottom: 12px;
    }

    .service-card p {
      color: var(--muted);
      margin-bottom: 18px;
    }

    .service-card ul {
      list-style: none;
      display: grid;
      gap: 10px;
      margin-bottom: 24px;
    }

    .service-card li {
      position: relative;
      padding-left: 18px;
      color: var(--text);
      font-size: .96rem;
    }

    .service-card li::before {
      content: "";
      position: absolute;
      left: 0;
      top: 10px;
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: var(--gold);
    }

    .service-link {
      color: var(--gold-2);
      font-weight: 700;
    }

    .split-grid {
      display: grid;
      grid-template-columns: 1.02fr .98fr;
      gap: 24px;
      align-items: stretch;
    }

    .feature-card,
    .feature-side {
      border-radius: 28px;
      border: 1px solid rgba(255,255,255,.08);
      background:
        linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03)),
        linear-gradient(160deg, #15151b, #101015);
      box-shadow: var(--shadow);
    }

    .feature-card {
      padding: 34px;
    }

    .feature-card h3 {
      font-size: clamp(1.8rem, 2.4vw, 2.6rem);
      line-height: 1.05;
      letter-spacing: -0.03em;
      margin-bottom: 14px;
    }

    .feature-card p {
      color: var(--muted);
      margin-bottom: 22px;
    }

    .feature-list {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 16px;
    }

    .feature-item {
      padding: 18px;
      border-radius: 18px;
      background: rgba(255,255,255,.04);
      border: 1px solid rgba(255,255,255,.08);
    }

    .feature-item strong {
      display: block;
      color: var(--gold-2);
      margin-bottom: 8px;
    }

    .feature-item span {
      color: var(--muted);
      font-size: .94rem;
    }

    .feature-side {
      padding: 26px;
      display: grid;
      gap: 18px;
      align-content: start;
    }

    .side-card {
      padding: 22px;
      border-radius: 22px;
      background: rgba(255,255,255,.04);
      border: 1px solid rgba(255,255,255,.08);
    }

    .side-card strong {
      display: block;
      color: var(--text);
      font-size: 1.08rem;
      margin-bottom: 10px;
    }

    .side-card p {
      color: var(--muted);
      font-size: .98rem;
    }

    .pill-row {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-top: 14px;
    }

    .pill {
      padding: 10px 14px;
      border-radius: 999px;
      background: rgba(255,255,255,.05);
      border: 1px solid rgba(255,255,255,.08);
      color: var(--text);
      font-size: .88rem;
      font-weight: 600;
    }

    .steps {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 18px;
    }

    .step-card {
      padding: 24px;
      border-radius: 24px;
      background: rgba(255,255,255,.04);
      border: 1px solid rgba(255,255,255,.08);
    }

    .step-number {
      width: 48px;
      height: 48px;
      display: grid;
      place-items: center;
      border-radius: 14px;
      background: rgba(215,181,109,.14);
      border: 1px solid rgba(215,181,109,.24);
      color: var(--gold-2);
      font-weight: 800;
      margin-bottom: 18px;
    }

    .step-card h3 {
      font-size: 1.16rem;
      margin-bottom: 10px;
    }

    .step-card p {
      color: var(--muted);
      font-size: .96rem;
    }

    .pricing-bridge {
      display: grid;
      grid-template-columns: 1.1fr .9fr;
      gap: 22px;
      align-items: stretch;
    }

    .pricing-card,
    .pricing-note {
      border-radius: 28px;
      padding: 30px;
      background:
        linear-gradient(180deg, rgba(255,255,255,.07), rgba(255,255,255,.03)),
        linear-gradient(160deg, #15151b, #101015);
      border: 1px solid rgba(255,255,255,.08);
      box-shadow: var(--shadow);
    }

    .pricing-card h3,
    .pricing-note h3 {
      font-size: 1.6rem;
      letter-spacing: -.03em;
      margin-bottom: 10px;
    }

    .pricing-card p,
    .pricing-note p {
      color: var(--muted);
      margin-bottom: 18px;
    }

    .pricing-list {
      list-style: none;
      display: grid;
      gap: 12px;
    }

    .pricing-list li {
      padding-left: 18px;
      position: relative;
      color: var(--text);
    }

    .pricing-list li::before {
      content: "";
      position: absolute;
      left: 0;
      top: 11px;
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: var(--gold);
    }

    .cta-panel {
      position: relative;
      overflow: hidden;
      padding: 38px;
      border-radius: 32px;
      background:
        radial-gradient(circle at top left, rgba(242,219,169,.18), transparent 28%),
        linear-gradient(135deg, rgba(255,255,255,.08), rgba(255,255,255,.04)),
        linear-gradient(160deg, #16161d, #0e0e13);
      border: 1px solid rgba(215,181,109,.18);
      box-shadow: var(--shadow);
    }

    .cta-panel h2 {
      font-size: clamp(2rem, 3vw, 3.2rem);
      line-height: 1.03;
      letter-spacing: -0.04em;
      margin-bottom: 12px;
      max-width: 860px;
    }

    .cta-panel p {
      color: var(--muted);
      max-width: 760px;
      margin-bottom: 24px;
      font-size: 1.04rem;
    }

    .cta-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 14px;
    }

    .footer {
      padding: 42px 0 54px;
      color: var(--soft);
    }

    .footer-wrap {
      border-top: 1px solid rgba(255,255,255,.08);
      padding-top: 26px;
      display: flex;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 18px;
    }

    @media (max-width: 1180px) {
      .hero-grid,
      .split-grid,
      .pricing-bridge {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 920px) {
      .nav {
        flex-wrap: wrap;
        padding: 16px 0;
      }

      .nav-links {
        order: 3;
        width: 100%;
        justify-content: center;
        flex-wrap: wrap;
        gap: 16px;
        padding-top: 4px;
      }

      .service-grid,
      .feature-list,
      .steps,
      .quick-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 640px) {
      .container {
        width: min(var(--max), calc(100% - 22px));
      }

      .hero-copy h1 {
        font-size: clamp(2.3rem, 11vw, 4rem);
      }

      .service-card,
      .feature-card,
      .feature-side,
      .hero-panel,
      .pricing-card,
      .pricing-note,
      .cta-panel {
        border-radius: 20px;
      }

      .nav-actions {
        width: 100%;
        justify-content: space-between;
      }

      .hide-mobile {
        display: none;
      }
    }
  </style>
</head>
<body>

  <header class="topbar">
    <div class="container nav">
      <a href="index.php" class="brand" aria-label="Doggie Dorian's home">
        <div class="brand-mark">DD</div>
        <div>
          <div class="brand-title">Doggie Dorian’s</div>
          <div class="brand-subtitle">Luxury Pet Care</div>
        </div>
      </a>

      <ul class="nav-links">
        <li><a href="index.php">Home</a></li>
        <li><a href="services.php">Services</a></li>
        <li><a href="pricing.php">Pricing</a></li>
        <li><a href="memberships.php">Memberships</a></li>
        <li><a href="book-walk.php">Book</a></li>
        <li><a href="contact.php">Contact</a></li>
      </ul>

      <div class="nav-actions">
        <?php if ($isLoggedIn): ?>
          <a href="dashboard.php" class="btn btn-secondary">Member Dashboard</a>
        <?php else: ?>
          <a href="login.php" class="btn btn-ghost hide-mobile">Member Login</a>
          <a href="book-walk.php" class="btn btn-primary">Book Premium Care</a>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <main>
    <section class="hero">
      <div class="container">
        <div class="service-area-strip">
          <div>
            <strong>Serving Manhattan’s Upper East Side</strong>
            <span>Expanded Manhattan coverage may be available when scheduled ahead of time.</span>
          </div>
          <a href="book-walk.php" class="btn btn-secondary">Check Availability</a>
        </div>

        <div class="hero-grid">
          <div class="hero-copy">
            <span class="eyebrow">Signature Services</span>
            <h1>
              Refined dog care,
              <span class="accent">designed to feel effortless.</span>
            </h1>
            <p>
              Doggie Dorian’s offers luxury dog walking, premium daycare, and boutique boarding for Manhattan dog parents who want a polished, personal, and dependable care experience from start to finish.
            </p>

            <div class="hero-actions">
              <a href="book-walk.php" class="btn btn-primary">Book a Service</a>
              <a href="pricing.php" class="btn btn-secondary">View Pricing</a>
            </div>

            <div class="hero-badges">
              <span class="hero-badge">Private Walks</span>
              <span class="hero-badge">Premium Daycare</span>
              <span class="hero-badge">Boutique Boarding</span>
              <span class="hero-badge">Premium Availability</span>
            </div>
          </div>

          <div class="hero-panel">
            <h3>Luxury care, built around trust</h3>
            <p>Your dog’s routine, comfort, and consistency come first — with service structured to feel smoother, more personal, and more elevated.</p>

            <div class="quick-grid">
              <div class="quick-box">
                <small>Walking</small>
                <strong>Tailored visits</strong>
                <span>Flexible durations to suit your dog’s routine</span>
              </div>
              <div class="quick-box">
                <small>Daycare</small>
                <strong>Structured daytime care</strong>
                <span>Ideal for busy schedules and active dogs</span>
              </div>
              <div class="quick-box">
                <small>Boarding</small>
                <strong>Overnight comfort</strong>
                <span>Personal attention in a more boutique setting</span>
              </div>
              <div class="quick-box">
                <small>Service area</small>
                <strong>Upper East Side</strong>
                <span>Expanded coverage with advance notice</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="container">
        <div class="section-head">
          <span class="eyebrow">Core Services</span>
          <h2>Luxury care options designed around your dog’s lifestyle.</h2>
          <p>
            This page is built to help clients understand the experience, the level of care, and the value behind each service. Exact rates and membership savings are available on the pricing page.
          </p>
        </div>

        <div class="service-grid">
          <article class="service-card">
            <span class="service-tag">Dog Walking</span>
            <h3>Luxury Walks</h3>
            <p>
              Reliable, refined walking services for dog parents who want daily care to feel smooth, consistent, and elevated rather than rushed or transactional.
            </p>
            <ul>
              <li>Flexible walk durations for different routines</li>
              <li>Ideal for weekday structure and recurring care</li>
              <li>Designed for busy Manhattan schedules</li>
            </ul>
            <a href="book-walk.php" class="service-link">Book a walk →</a>
          </article>

          <article class="service-card">
            <span class="service-tag">Daycare</span>
            <h3>Premium Daycare</h3>
            <p>
              A polished daytime care experience for clients who want dependable support, better routine coverage, and a more elevated standard of attention.
            </p>
            <ul>
              <li>Structured daytime care for active dogs</li>
              <li>Strong fit for repeat weekday schedules</li>
              <li>Member savings available for multi-day bookings</li>
            </ul>
            <a href="book-walk.php" class="service-link">Reserve daycare →</a>
          </article>

          <article class="service-card">
            <span class="service-tag">Boarding</span>
            <h3>Boutique Boarding</h3>
            <p>
              Overnight care for dog owners who want more comfort, confidence, and personal attention than a standard boarding setup usually provides.
            </p>
            <ul>
              <li>Comfort-focused overnight stays</li>
              <li>Ideal for travel and extended care needs</li>
              <li>Member savings available for longer stays</li>
            </ul>
            <a href="book-walk.php" class="service-link">Book boarding →</a>
          </article>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="container split-grid">
        <div class="feature-card">
          <span class="eyebrow">Why Clients Choose Doggie Dorian’s</span>
          <h3>More premium than a typical pet service. More personal than a big platform.</h3>
          <p>
            The goal is simple: make dog care feel easier, more elevated, and more dependable for Manhattan clients who expect a higher standard.
          </p>

          <div class="feature-list">
            <div class="feature-item">
              <strong>Personalized attention</strong>
              <span>Your dog’s comfort, rhythm, and routine stay central to the experience.</span>
            </div>
            <div class="feature-item">
              <strong>Premium convenience</strong>
              <span>Booking, scheduling, and repeat care are designed to feel smoother and more organized.</span>
            </div>
            <div class="feature-item">
              <strong>Selective capacity</strong>
              <span>Limited availability helps protect consistency, care quality, and flexibility.</span>
            </div>
            <div class="feature-item">
              <strong>Luxury positioning</strong>
              <span>The full brand experience is meant to feel polished, calm, and reassuring.</span>
            </div>
          </div>
        </div>

        <div class="feature-side">
          <div class="side-card">
            <strong>Best for busy Manhattan clients</strong>
            <p>
              Ideal for professionals, frequent travelers, and dog parents who want a more refined alternative to standard local options.
            </p>
          </div>

          <div class="side-card">
            <strong>Service area clarity</strong>
            <p>
              Upper East Side is the priority service area, while expanded Manhattan coverage may be available when planned ahead of time.
            </p>
            <div class="pill-row">
              <span class="pill">Upper East Side</span>
              <span class="pill">Advance Scheduling</span>
              <span class="pill">Premium Access</span>
            </div>
          </div>

          <div class="side-card">
            <strong>Membership-ready experience</strong>
            <p>
              Clients who book regularly can move into a more seamless long-term relationship through memberships and preferred pricing.
            </p>
          </div>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="container pricing-bridge">
        <div class="pricing-card">
          <span class="eyebrow">Pricing & Memberships</span>
          <h3>Transparent rates, with better value for members</h3>
          <p>
            Doggie Dorian’s pricing is structured to keep non-member booking simple while rewarding members with preferred pricing and volume discounts on qualifying daycare and boarding bookings.
          </p>

          <ul class="pricing-list">
            <li>Members receive preferred service rates</li>
            <li>Daycare discounts apply on qualifying 3+ day member bookings</li>
            <li>Boarding discounts apply on qualifying 5+ night member bookings</li>
            <li>Pricing is presented separately for maximum clarity</li>
          </ul>

          <div class="cta-actions" style="margin-top: 22px;">
            <a href="pricing.php" class="btn btn-primary">View Pricing</a>
            <a href="memberships.php" class="btn btn-secondary">Explore Memberships</a>
          </div>
        </div>

        <div class="pricing-note">
          <span class="eyebrow">Built for Repeat Care</span>
          <h3>Designed for clients who want more than occasional help</h3>
          <p>
            The overall experience is especially strong for clients who need repeat walking, recurring daycare, or dependable boarding support throughout the year.
          </p>
          <p>
            That is why services, pricing, and memberships are structured to work together instead of feeling disconnected.
          </p>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="container">
        <div class="section-head">
          <span class="eyebrow">How Booking Works</span>
          <h2>From first booking to repeat care, everything should feel simple.</h2>
          <p>
            Good service pages remove hesitation and lead visitors toward the next step with less friction.
          </p>
        </div>

        <div class="steps">
          <div class="step-card">
            <div class="step-number">1</div>
            <h3>Choose your service</h3>
            <p>Select walking, daycare, or boarding based on your dog’s needs and your schedule.</p>
          </div>
          <div class="step-card">
            <div class="step-number">2</div>
            <h3>Check pricing</h3>
            <p>Review member and non-member options clearly before moving forward.</p>
          </div>
          <div class="step-card">
            <div class="step-number">3</div>
            <h3>Request your date</h3>
            <p>Choose your preferred timing, with advance scheduling available for broader coverage.</p>
          </div>
          <div class="step-card">
            <div class="step-number">4</div>
            <h3>Book premium care</h3>
            <p>Complete your booking and enjoy a smoother, more elevated service experience.</p>
          </div>
        </div>
      </div>
    </section>

    <section class="section" style="padding-top: 20px;">
      <div class="container">
        <div class="cta-panel">
          <span class="eyebrow">Ready to Book?</span>
          <h2>Luxury dog care should feel effortless from the very first step.</h2>
          <p>
            Whether you need a premium walk, dependable daycare, or boutique-style boarding, Doggie Dorian’s is built for clients who want confidence, convenience, and a more elevated standard of care.
          </p>
          <div class="cta-actions">
            <a href="book-walk.php" class="btn btn-primary">Book Premium Care</a>
            <a href="pricing.php" class="btn btn-secondary">View Pricing</a>
            <?php if (!$isLoggedIn): ?>
              <a href="login.php" class="btn btn-ghost">Member Login</a>
            <?php else: ?>
              <a href="dashboard.php" class="btn btn-ghost">Go to Dashboard</a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </section>
  </main>

  <footer class="footer">
    <div class="container footer-wrap">
      <div>
        <strong style="color: var(--text);">Doggie Dorian’s</strong><br />
        Luxury dog walking, premium daycare & boutique boarding in Manhattan.
      </div>
      <div>
        <a href="services.php">Services</a> &nbsp;•&nbsp;
        <a href="pricing.php">Pricing</a> &nbsp;•&nbsp;
        <a href="memberships.php">Memberships</a> &nbsp;•&nbsp;
        <a href="contact.php">Contact</a>
      </div>
    </div>
  </footer>
</body>
</html>