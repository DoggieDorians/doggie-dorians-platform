<?php
session_start();
require_once __DIR__ . '/includes/pricing.php';

$isLoggedIn = isset($_SESSION['member_id']);
$pricing = dd_pricing_matrix();

$bookingLink = $isLoggedIn ? 'book-service.php' : 'non-member-booking.php';

$walkNonMember = $pricing['walk']['non_member'];
$walkMember = $pricing['walk']['member'];

$daycareNonMember = $pricing['daycare']['non_member'];
$daycareMember = $pricing['daycare']['member'];

$dropInNonMember = $pricing['drop_in']['non_member'];
$dropInMember = $pricing['drop_in']['member'];

$sittingNonMember = $pricing['sitting']['non_member'];
$sittingMember = $pricing['sitting']['member'];

$boardingNonMember = $pricing['boarding']['non_member'];
$boardingMember = $pricing['boarding']['member'];

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Services | Doggie Dorian's</title>
  <meta
    name="description"
    content="Explore luxury dog walking, hourly drop-ins, premium daycare, in-home sitting, and boutique boarding at Doggie Dorian’s for Manhattan dog parents."
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
      --max: 1280px;
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
      grid-template-columns: 1.08fr .92fr;
      gap: 26px;
      align-items: start;
    }

    .hero-copy h1 {
      font-size: clamp(2.8rem, 5vw, 5.1rem);
      line-height: .94;
      letter-spacing: -.06em;
      margin: 18px 0 16px;
    }

    .hero-copy h1 .accent { color: var(--gold-2); }

    .hero-copy p {
      color: var(--muted);
      font-size: 1.08rem;
      max-width: 760px;
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
      grid-template-columns: repeat(5, minmax(0, 1fr));
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
      font-size: 1.55rem;
      line-height: 1.05;
      letter-spacing: -.03em;
      margin-bottom: 10px;
    }

    .price {
      color: var(--gold-2);
      font-size: 1.05rem;
      font-weight: 800;
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

    .brand-story-grid {
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
      .brand-story-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 1100px) {
      .service-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
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

      .feature-list,
      .steps,
      .quick-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 700px) {
      .service-grid {
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
        <li><a href="<?= h($bookingLink) ?>">Book</a></li>
        <li><a href="contact.php">Contact</a></li>
      </ul>

      <div class="nav-actions">
        <?php if ($isLoggedIn): ?>
          <a href="dashboard.php" class="btn btn-secondary">Member Dashboard</a>
        <?php else: ?>
          <a href="login.php" class="btn btn-ghost hide-mobile">Member Login</a>
          <a href="<?= h($bookingLink) ?>" class="btn btn-primary">Book Premium Care</a>
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
          <a href="<?= h($bookingLink) ?>" class="btn btn-secondary">Check Availability</a>
        </div>

        <div class="hero-grid">
          <div class="hero-copy">
            <span class="eyebrow">Signature Services</span>
            <h1>
              Refined dog care,
              <span class="accent">built for city clients who expect more.</span>
            </h1>
            <p>
              Doggie Dorian’s is designed for Manhattan dog parents who want more than basic pet care. The experience is meant to feel polished, dependable, and elevated from the first booking through ongoing service.
            </p>

            <div class="hero-actions">
              <a href="<?= h($bookingLink) ?>" class="btn btn-primary">Book a Service</a>
              <a href="pricing.php" class="btn btn-secondary">See Pricing</a>
            </div>

            <div class="hero-badges">
              <span class="hero-badge">Luxury Walks</span>
              <span class="hero-badge">Hourly Drop-Ins</span>
              <span class="hero-badge">Premium Daycare</span>
              <span class="hero-badge">Boutique Boarding</span>
            </div>
          </div>

          <div class="hero-panel">
            <h3>Service should feel calm, premium, and dependable</h3>
            <p>
              Every offering is structured to reduce friction for the pet parent while creating a more personal, premium care experience for the dog.
            </p>

            <div class="quick-grid">
              <div class="quick-box">
                <small>Consistency</small>
                <strong>Structured care</strong>
                <span>Booking, pricing, and service all follow one aligned system</span>
              </div>
              <div class="quick-box">
                <small>Convenience</small>
                <strong>City-friendly options</strong>
                <span>Services built around real Manhattan schedules</span>
              </div>
              <div class="quick-box">
                <small>Positioning</small>
                <strong>Premium by design</strong>
                <span>The experience is meant to feel polished from the start</span>
              </div>
              <div class="quick-box">
                <small>Care</small>
                <strong>Personal attention</strong>
                <span>More thoughtful than a generic app-based experience</span>
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
          <h2>Choose the service that fits your dog’s rhythm and your schedule.</h2>
          <p>
            This page is meant to help clients understand the experience behind each service. For exact prices and member comparisons, the pricing page remains the cleaner numbers page.
          </p>
        </div>

        <div class="service-grid">
          <article class="service-card">
            <span class="service-tag">Dog Walking</span>
            <h3>Luxury Walks</h3>
            <div class="price">From <?= h(dd_format_money((float)$walkNonMember[15])) ?></div>
            <p>
              Designed for daily structure, consistency, and a more premium routine for city dogs who thrive with dependable movement and attention.
            </p>
            <ul>
              <li>15, 20, 30, 45, and 60 minute options</li>
              <li>Ideal for recurring weekday care</li>
              <li>Member pricing creates stronger long-term value</li>
            </ul>
            <a href="<?= h($bookingLink) ?>" class="service-link">Book a walk →</a>
          </article>

          <article class="service-card">
            <span class="service-tag">Drop-In</span>
            <h3>Hourly Drop-Ins</h3>
            <div class="price">From <?= h(dd_format_money((float)$dropInNonMember['hourly_rate'])) ?>/hour</div>
            <p>
              Best for shorter care windows, check-ins, and clients who want flexible in-home support without moving into a longer session.
            </p>
            <ul>
              <li>1 or 2 hour options</li>
              <li>Optional 30-minute walk add-on</li>
              <li>Clean fit for shorter visits and quick support</li>
            </ul>
            <a href="<?= h($bookingLink) ?>" class="service-link">Book a drop-in →</a>
          </article>

          <article class="service-card">
            <span class="service-tag">Daycare</span>
            <h3>Premium Daycare</h3>
            <div class="price">6-hour session from <?= h(dd_format_money((float)$daycareNonMember['base_rate'])) ?></div>
            <p>
              Structured daytime care for clients who want a smoother, more elevated option than piecing together multiple short visits.
            </p>
            <ul>
              <li>6-hour premium session format</li>
              <li>1 complimentary 30-minute walk included</li>
              <li>Optional food and additional walk add-ons</li>
            </ul>
            <a href="<?= h($bookingLink) ?>" class="service-link">Reserve daycare →</a>
          </article>

          <article class="service-card">
            <span class="service-tag">In-Home Sitting</span>
            <h3>Luxury In-Home Sitting</h3>
            <div class="price">From <?= h(dd_format_money((float)$sittingNonMember['base_rate'])) ?></div>
            <p>
              Built for clients who want a longer premium care block provided directly in their apartment or home.
            </p>
            <ul>
              <li>Up to <?= h((string)$sittingNonMember['hours']) ?> hours</li>
              <li>1 complimentary 30-minute walk included</li>
              <li>More personal and present than a short visit</li>
            </ul>
            <a href="<?= h($bookingLink) ?>" class="service-link">Book in-home sitting →</a>
          </article>

          <article class="service-card">
            <span class="service-tag">Boarding</span>
            <h3>Boutique Boarding</h3>
            <div class="price">From <?= h(dd_format_money((float)$boardingNonMember['small'])) ?></div>
            <p>
              Overnight care positioned for clients who want more comfort, more reassurance, and a more refined overall experience.
            </p>
            <ul>
              <li>Boarding remains priced by dog size</li>
              <li>Member pricing starts lower right away</li>
              <li>Longer stays unlock better member value</li>
            </ul>
            <a href="<?= h($bookingLink) ?>" class="service-link">Book boarding →</a>
          </article>
        </div>
      </div>
    </section>

    <section class="section" style="padding-top: 0;">
      <div class="container brand-story-grid">
        <div class="feature-card">
          <span class="eyebrow">The Brand Difference</span>
          <h3>More elevated than a typical pet service. More personal than a marketplace.</h3>
          <p>
            Doggie Dorian’s is built around the idea that pet care should feel dependable, polished, and easy to trust. The goal is not just to offer services — it is to make the full experience feel higher-end from the client’s point of view.
          </p>

          <div class="feature-list">
            <div class="feature-item">
              <strong>Refined presentation</strong>
              <span>The brand, booking flow, and client touchpoints are meant to feel intentional and premium.</span>
            </div>
            <div class="feature-item">
              <strong>Clear service structure</strong>
              <span>Each service has a defined role so clients understand what fits best without confusion.</span>
            </div>
            <div class="feature-item">
              <strong>Consistency across the site</strong>
              <span>Services, pricing, and booking now align under one system instead of competing logic.</span>
            </div>
            <div class="feature-item">
              <strong>Built for repeat care</strong>
              <span>The experience is especially strong for clients who want smooth recurring service over time.</span>
            </div>
          </div>
        </div>

        <div class="feature-side">
          <div class="side-card">
            <strong>Best fit for busy Manhattan clients</strong>
            <p>
              Ideal for professionals, frequent travelers, and dog parents who value convenience, presentation, and dependable care.
            </p>
          </div>

          <div class="side-card">
            <strong>Service area clarity</strong>
            <p>
              The Upper East Side remains the priority service area, while expanded Manhattan coverage may be available when planned ahead.
            </p>
            <div class="pill-row">
              <span class="pill">Upper East Side</span>
              <span class="pill">Advance Scheduling</span>
              <span class="pill">Premium Access</span>
            </div>
          </div>

          <div class="side-card">
            <strong>Why the pricing page still matters</strong>
            <p>
              This services page is built to sell the experience. The pricing page stays cleaner and more exact for clients who want the numbers quickly.
            </p>
          </div>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="container">
        <div class="section-head">
          <span class="eyebrow">How Booking Works</span>
          <h2>From first booking to repeat care, the process should feel simple.</h2>
          <p>
            Good service pages reduce hesitation. They help the client understand the options, then point them toward a cleaner booking decision.
          </p>
        </div>

        <div class="steps">
          <div class="step-card">
            <div class="step-number">1</div>
            <h3>Choose your service</h3>
            <p>Select walking, drop-ins, daycare, in-home sitting, or boarding based on your dog’s needs and your schedule.</p>
          </div>
          <div class="step-card">
            <div class="step-number">2</div>
            <h3>Choose your date</h3>
            <p>Pick your preferred date and time, with add-ons where the service structure allows for them.</p>
          </div>
          <div class="step-card">
            <div class="step-number">3</div>
            <h3>Review the pricing</h3>
            <p>The pricing page and booking flow apply the correct member vs non-member structure automatically.</p>
          </div>
          <div class="step-card">
            <div class="step-number">4</div>
            <h3>Submit premium care</h3>
            <p>Complete your request and move into a smoother, more elevated care experience.</p>
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
            Whether you need a premium walk, quick drop-in, dependable daycare, in-home sitting, or boutique-style boarding, Doggie Dorian’s is built for clients who want confidence, convenience, and a more elevated standard of care.
          </p>
          <div class="cta-actions">
            <a href="<?= h($bookingLink) ?>" class="btn btn-primary">Book Premium Care</a>
            <a href="pricing.php" class="btn btn-secondary">See Full Pricing</a>
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
        Luxury dog walking, hourly drop-ins, premium daycare, in-home sitting & boutique boarding in Manhattan.
      </div>
      <div>
        <a href="pricing.php">Pricing</a> &nbsp;•&nbsp;
        <a href="<?= h($bookingLink) ?>">Book</a> &nbsp;•&nbsp;
        <a href="memberships.php">Memberships</a> &nbsp;•&nbsp;
        <a href="contact.php">Contact</a>
      </div>
    </div>
  </footer>
</body>
</html>