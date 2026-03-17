<?php
session_start();
$isLoggedIn = isset($_SESSION['member_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Doggie Dorian's | Luxury Dog Walking, Daycare & Boarding</title>
  <meta name="description" content="Doggie Dorian's offers luxury dog walking, premium daycare, boarding, and membership experiences designed for devoted pet owners who expect exceptional care.">

  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    :root {
      --bg: #0b0b0e;
      --bg-soft: #121217;
      --panel: rgba(255,255,255,0.06);
      --panel-strong: rgba(255,255,255,0.09);
      --border: rgba(255,255,255,0.12);
      --gold: #d4af37;
      --gold-soft: #f0d77a;
      --cream: #f8f4ea;
      --muted: #b8b2a7;
      --white: #ffffff;
      --shadow: 0 20px 60px rgba(0,0,0,0.45);
      --radius-xl: 28px;
      --radius-lg: 20px;
      --radius-md: 14px;
      --max: 1240px;
    }

    html {
      scroll-behavior: smooth;
    }

    body {
      font-family: "Georgia", "Times New Roman", serif;
      background:
        radial-gradient(circle at top left, rgba(212,175,55,0.12), transparent 30%),
        radial-gradient(circle at top right, rgba(212,175,55,0.08), transparent 25%),
        linear-gradient(180deg, #0a0a0d 0%, #111116 100%);
      color: var(--white);
      line-height: 1.6;
    }

    a {
      text-decoration: none;
      color: inherit;
    }

    img {
      max-width: 100%;
      display: block;
    }

    .container {
      width: min(var(--max), calc(100% - 32px));
      margin: 0 auto;
    }

    .topbar {
      position: sticky;
      top: 0;
      z-index: 1000;
      backdrop-filter: blur(14px);
      background: rgba(10,10,13,0.72);
      border-bottom: 1px solid rgba(255,255,255,0.08);
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
      flex-direction: column;
      line-height: 1.05;
    }

    .brand-name {
      font-size: 1.5rem;
      font-weight: 700;
      letter-spacing: 0.5px;
      color: var(--cream);
    }

    .brand-tag {
      font-family: Arial, sans-serif;
      font-size: 0.74rem;
      text-transform: uppercase;
      letter-spacing: 2.8px;
      color: rgba(240,215,122,0.88);
      margin-top: 6px;
    }

    .nav-links {
      display: flex;
      align-items: center;
      gap: 14px;
      flex-wrap: wrap;
    }

    .nav-links a {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.88);
      font-size: 0.95rem;
      padding: 10px 14px;
      border-radius: 999px;
      transition: 0.25s ease;
    }

    .nav-links a:hover {
      background: rgba(255,255,255,0.06);
      color: var(--gold-soft);
    }

    .nav-cta {
      border: 1px solid rgba(212,175,55,0.4);
      background: linear-gradient(135deg, rgba(212,175,55,0.18), rgba(255,255,255,0.04));
      color: var(--cream) !important;
    }

    .hero {
      position: relative;
      overflow: hidden;
      padding: 72px 0 44px;
    }

    .hero-grid {
      display: grid;
      grid-template-columns: 1.1fr 0.9fr;
      gap: 28px;
      align-items: stretch;
    }

    .hero-copy,
    .hero-card {
      border: 1px solid var(--border);
      background: linear-gradient(180deg, rgba(255,255,255,0.045), rgba(255,255,255,0.02));
      box-shadow: var(--shadow);
      border-radius: var(--radius-xl);
    }

    .hero-copy {
      padding: 56px 44px;
      position: relative;
      overflow: hidden;
    }

    .eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      font-family: Arial, sans-serif;
      font-size: 0.8rem;
      letter-spacing: 2.5px;
      text-transform: uppercase;
      color: var(--gold-soft);
      margin-bottom: 20px;
    }

    .eyebrow::before {
      content: "";
      width: 38px;
      height: 1px;
      background: linear-gradient(90deg, var(--gold), transparent);
      display: inline-block;
    }

    .hero h1 {
      font-size: clamp(2.5rem, 5vw, 5rem);
      line-height: 0.98;
      color: var(--cream);
      margin-bottom: 20px;
      letter-spacing: -1.5px;
    }

    .hero h1 span {
      color: var(--gold-soft);
    }

    .hero p {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.8);
      font-size: 1.08rem;
      max-width: 700px;
      margin-bottom: 30px;
    }

    .hero-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 14px;
      margin-bottom: 30px;
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
      transition: transform 0.2s ease, opacity 0.2s ease, border-color 0.2s ease;
    }

    .btn:hover {
      transform: translateY(-2px);
      opacity: 0.96;
    }

    .btn-gold {
      background: linear-gradient(135deg, #f0d77a 0%, #d4af37 45%, #b9921f 100%);
      color: #18140a;
      box-shadow: 0 14px 30px rgba(212,175,55,0.2);
    }

    .btn-dark {
      border: 1px solid rgba(255,255,255,0.14);
      background: rgba(255,255,255,0.04);
      color: var(--white);
    }

    .hero-points {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 14px;
      margin-top: 8px;
    }

    .hero-point {
      border: 1px solid rgba(255,255,255,0.08);
      background: rgba(255,255,255,0.03);
      border-radius: 18px;
      padding: 16px;
    }

    .hero-point strong {
      display: block;
      font-size: 1rem;
      color: var(--cream);
      margin-bottom: 5px;
    }

    .hero-point span {
      font-family: Arial, sans-serif;
      font-size: 0.92rem;
      color: rgba(255,255,255,0.72);
    }

    .hero-card {
      padding: 24px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      min-height: 100%;
    }

    .hero-visual {
      min-height: 430px;
      border-radius: 22px;
      background:
        linear-gradient(180deg, rgba(0,0,0,0.08), rgba(0,0,0,0.35)),
        url('https://images.unsplash.com/photo-1517849845537-4d257902454a?auto=format&fit=crop&w=1200&q=80') center/cover no-repeat;
      position: relative;
      overflow: hidden;
      border: 1px solid rgba(255,255,255,0.08);
    }

    .hero-badge {
      position: absolute;
      left: 18px;
      bottom: 18px;
      right: 18px;
      background: rgba(8,8,10,0.72);
      backdrop-filter: blur(12px);
      border: 1px solid rgba(255,255,255,0.1);
      border-radius: 20px;
      padding: 18px;
      box-shadow: 0 12px 24px rgba(0,0,0,0.35);
    }

    .hero-badge small {
      display: block;
      font-family: Arial, sans-serif;
      font-size: 0.76rem;
      text-transform: uppercase;
      letter-spacing: 2px;
      color: var(--gold-soft);
      margin-bottom: 8px;
    }

    .hero-badge h3 {
      font-size: 1.45rem;
      color: var(--cream);
      margin-bottom: 6px;
    }

    .hero-badge p {
      font-family: Arial, sans-serif;
      font-size: 0.94rem;
      color: rgba(255,255,255,0.76);
      margin: 0;
    }

    .hero-stats {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 12px;
      margin-top: 18px;
    }

    .stat {
      padding: 16px 14px;
      border-radius: 18px;
      background: rgba(255,255,255,0.035);
      border: 1px solid rgba(255,255,255,0.08);
      text-align: center;
    }

    .stat strong {
      display: block;
      font-size: 1.2rem;
      color: var(--gold-soft);
      margin-bottom: 4px;
    }

    .stat span {
      font-family: Arial, sans-serif;
      font-size: 0.84rem;
      color: rgba(255,255,255,0.68);
    }

    section {
      padding: 42px 0;
    }

    .section-intro {
      text-align: center;
      margin-bottom: 26px;
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
      line-height: 1.05;
      color: var(--cream);
      margin-bottom: 12px;
      letter-spacing: -1px;
    }

    .section-intro p {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.74);
      max-width: 760px;
      margin: 0 auto;
      font-size: 1rem;
    }

    .services-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 18px;
    }

    .service-card {
      border-radius: 24px;
      padding: 24px;
      border: 1px solid rgba(255,255,255,0.08);
      background: linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.025));
      box-shadow: var(--shadow);
      transition: transform 0.2s ease, border-color 0.2s ease;
    }

    .service-card:hover {
      transform: translateY(-4px);
      border-color: rgba(212,175,55,0.28);
    }

    .service-number {
      width: 42px;
      height: 42px;
      border-radius: 50%;
      display: grid;
      place-items: center;
      font-family: Arial, sans-serif;
      font-size: 0.9rem;
      font-weight: 700;
      color: #1b1407;
      background: linear-gradient(135deg, #f0d77a, #d4af37);
      margin-bottom: 18px;
    }

    .service-card h3 {
      color: var(--cream);
      font-size: 1.35rem;
      margin-bottom: 10px;
    }

    .service-card p {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.72);
      font-size: 0.95rem;
      margin-bottom: 14px;
    }

    .service-card ul {
      list-style: none;
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.8);
      font-size: 0.92rem;
      display: grid;
      gap: 8px;
    }

    .service-card li::before {
      content: "•";
      color: var(--gold-soft);
      margin-right: 8px;
    }

    .membership-wrap {
      display: grid;
      grid-template-columns: 0.95fr 1.05fr;
      gap: 22px;
      align-items: stretch;
    }

    .membership-panel,
    .membership-featured {
      border-radius: 28px;
      border: 1px solid rgba(255,255,255,0.08);
      box-shadow: var(--shadow);
    }

    .membership-panel {
      background:
        linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.03)),
        radial-gradient(circle at top left, rgba(212,175,55,0.12), transparent 35%);
      padding: 34px;
    }

    .membership-panel h3 {
      font-size: 2.2rem;
      line-height: 1.05;
      color: var(--cream);
      margin-bottom: 16px;
    }

    .membership-panel p {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.76);
      margin-bottom: 22px;
      font-size: 1rem;
    }

    .membership-list {
      display: grid;
      gap: 12px;
      margin-bottom: 24px;
    }

    .membership-list div {
      border-radius: 16px;
      padding: 14px 16px;
      background: rgba(255,255,255,0.04);
      border: 1px solid rgba(255,255,255,0.06);
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.84);
    }

    .membership-featured {
      padding: 20px;
      background: linear-gradient(180deg, rgba(17,17,21,0.98), rgba(12,12,16,0.96));
    }

    .featured-box {
      height: 100%;
      border-radius: 24px;
      padding: 28px;
      border: 1px solid rgba(212,175,55,0.18);
      background:
        linear-gradient(180deg, rgba(212,175,55,0.10), rgba(255,255,255,0.03)),
        rgba(255,255,255,0.02);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }

    .featured-label {
      font-family: Arial, sans-serif;
      text-transform: uppercase;
      letter-spacing: 2.5px;
      color: var(--gold-soft);
      font-size: 0.76rem;
      margin-bottom: 14px;
    }

    .featured-box h3 {
      font-size: 1.95rem;
      color: var(--cream);
      margin-bottom: 12px;
    }

    .featured-box p {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.77);
      font-size: 0.98rem;
      margin-bottom: 18px;
    }

    .featured-perks {
      display: grid;
      gap: 10px;
      margin-bottom: 20px;
    }

    .featured-perks div {
      padding: 13px 14px;
      border-radius: 15px;
      background: rgba(255,255,255,0.045);
      border: 1px solid rgba(255,255,255,0.08);
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.86);
    }

    .experience-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 18px;
    }

    .experience-card {
      padding: 26px;
      border-radius: 24px;
      background: linear-gradient(180deg, rgba(255,255,255,0.045), rgba(255,255,255,0.02));
      border: 1px solid rgba(255,255,255,0.08);
      box-shadow: var(--shadow);
    }

    .experience-card h3 {
      color: var(--cream);
      font-size: 1.3rem;
      margin-bottom: 10px;
    }

    .experience-card p {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.73);
      font-size: 0.96rem;
    }

    .cta-band {
      padding: 26px;
      border-radius: 28px;
      background:
        linear-gradient(135deg, rgba(212,175,55,0.16), rgba(255,255,255,0.04)),
        rgba(255,255,255,0.025);
      border: 1px solid rgba(212,175,55,0.18);
      box-shadow: var(--shadow);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 18px;
      flex-wrap: wrap;
    }

    .cta-band h3 {
      font-size: 2rem;
      color: var(--cream);
      margin-bottom: 8px;
    }

    .cta-band p {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.75);
      max-width: 760px;
    }

    footer {
      padding: 38px 0 50px;
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
      .membership-wrap,
      .services-grid,
      .experience-grid {
        grid-template-columns: 1fr;
      }

      .hero-points,
      .hero-stats {
        grid-template-columns: 1fr;
      }

      .hero-visual {
        min-height: 340px;
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
        padding: 34px 24px;
      }

      .hero h1 {
        font-size: 2.5rem;
      }

      .section-intro h2,
      .membership-panel h3,
      .cta-band h3 {
        font-size: 2rem;
      }

      .service-card,
      .experience-card,
      .membership-panel,
      .featured-box {
        padding: 22px;
      }
    }

    @media (max-width: 560px) {
      .container {
        width: min(var(--max), calc(100% - 20px));
      }

      .hero {
        padding-top: 32px;
      }

      .hero-actions {
        flex-direction: column;
      }

      .btn {
        width: 100%;
      }

      .brand-name {
        font-size: 1.28rem;
      }

      .brand-tag {
        letter-spacing: 2px;
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
      <div class="container hero-grid">
        <div class="hero-copy">
          <div class="eyebrow">Premium Dog Walking • Daycare • Boarding</div>

          <h1>
            A more <span>luxurious</span><br>
            standard of care for the dogs you love most.
          </h1>

          <p>
            Doggie Dorian’s delivers elevated dog walking, refined daycare, premium boarding, and exclusive membership experiences for clients who want more than ordinary pet care. We created a service designed to feel personal, polished, dependable, and exceptional.
          </p>

          <div class="hero-actions">
            <a href="book-walk.php" class="btn btn-gold">Book a Service</a>
            <a href="memberships.php" class="btn btn-dark">Explore Memberships</a>
          </div>

          <div class="hero-points">
            <div class="hero-point">
              <strong>Private Client Feel</strong>
              <span>Designed to feel tailored, attentive, and discreet.</span>
            </div>
            <div class="hero-point">
              <strong>Premium Reliability</strong>
              <span>Dependable scheduling, thoughtful communication, and polished care.</span>
            </div>
            <div class="hero-point">
              <strong>Luxury Experience</strong>
              <span>For owners who expect a higher standard for their dogs.</span>
            </div>
          </div>
        </div>

        <div class="hero-card">
          <div class="hero-visual">
            <div class="hero-badge">
              <small>Signature Experience</small>
              <h3>Dog care that feels elevated from the very first visit.</h3>
              <p>
                Whether it is a routine walk, premium daycare, or extended boarding, every service is designed to reflect trust, detail, and a luxury brand experience.
              </p>
            </div>
          </div>

          <div class="hero-stats">
            <div class="stat">
              <strong>Luxury</strong>
              <span>Brand Feel</span>
            </div>
            <div class="stat">
              <strong>VIP</strong>
              <span>Membership Access</span>
            </div>
            <div class="stat">
              <strong>Premium</strong>
              <span>Client Experience</span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section>
      <div class="container">
        <div class="section-intro">
          <div class="mini">Services</div>
          <h2>Built for clients who want exceptional care.</h2>
          <p>
            Every offering is crafted to feel refined, convenient, and worthy of the trust you place in us.
          </p>
        </div>

        <div class="services-grid">
          <div class="service-card">
            <div class="service-number">01</div>
            <h3>Luxury Walks</h3>
            <p>
              Structured, dependable walks delivered with professionalism and personal attention.
            </p>
            <ul>
              <li>Short and extended walk options</li>
              <li>Premium client communication</li>
              <li>Consistent care and attention</li>
            </ul>
          </div>

          <div class="service-card">
            <div class="service-number">02</div>
            <h3>Premium Daycare</h3>
            <p>
              A trusted daytime experience for dogs who benefit from social engagement, care, and oversight.
            </p>
            <ul>
              <li>Comfort-focused experience</li>
              <li>Structured, attentive environment</li>
              <li>Ideal for busy schedules</li>
            </ul>
          </div>

          <div class="service-card">
            <div class="service-number">03</div>
            <h3>Upscale Boarding</h3>
            <p>
              Overnight care designed to feel secure, elevated, and supportive for both pets and owners.
            </p>
            <ul>
              <li>More personal than standard boarding</li>
              <li>Comfort and consistency prioritized</li>
              <li>Peace of mind while away</li>
            </ul>
          </div>

          <div class="service-card">
            <div class="service-number">04</div>
            <h3>Exclusive Memberships</h3>
            <p>
              Recurring care plans with premium value, priority access, and a more elevated client experience.
            </p>
            <ul>
              <li>Priority scheduling</li>
              <li>Bundled monthly value</li>
              <li>VIP-style benefits and perks</li>
            </ul>
          </div>
        </div>
      </div>
    </section>

    <section>
      <div class="container membership-wrap">
        <div class="membership-panel">
          <div class="mini" style="font-family: Arial, sans-serif; text-transform: uppercase; letter-spacing: 2.5px; color: var(--gold-soft); margin-bottom: 10px;">Membership Experience</div>
          <h3>More than dog care. A premium relationship.</h3>
          <p>
            Our memberships are designed for clients who want consistency, convenience, savings, and a more elevated way to care for their dogs month after month.
          </p>

          <div class="membership-list">
            <div>Priority booking and preferred scheduling access</div>
            <div>Premium monthly value across walks, daycare, and boarding</div>
            <div>Exclusive member benefits and future luxury perks</div>
            <div>A more seamless, concierge-style client experience</div>
          </div>

          <a href="memberships.php" class="btn btn-dark">View Membership Options</a>
        </div>

        <div class="membership-featured">
          <div class="featured-box">
            <div>
              <div class="featured-label">Founding Tier Spotlight</div>
              <h3>Signature membership designed to stand out.</h3>
              <p>
                Perfect for early loyal clients who want high-end value, recurring benefits, and premium access as Doggie Dorian’s grows.
              </p>
            </div>

            <div class="featured-perks">
              <div>Luxury monthly walk allotments</div>
              <div>Premium daycare and boarding value</div>
              <div>Quarterly member credit opportunities</div>
              <div>Priority access and exclusive founding status</div>
            </div>

            <a href="customize-plan.php" class="btn btn-gold">Build Your Custom Plan</a>
          </div>
        </div>
      </div>
    </section>

    <section>
      <div class="container">
        <div class="section-intro">
          <div class="mini">Why Doggie Dorian’s</div>
          <h2>The experience should feel as premium as the care.</h2>
          <p>
            The difference is not just what we do. It is how the brand feels, how the service is delivered, and how much trust the experience creates.
          </p>
        </div>

        <div class="experience-grid">
          <div class="experience-card">
            <h3>Luxury Presentation</h3>
            <p>
              Every touchpoint should feel polished, intentional, and worthy of a premium pet care brand.
            </p>
          </div>

          <div class="experience-card">
            <h3>Trust & Professionalism</h3>
            <p>
              Clients should feel secure knowing their dogs are being cared for with consistency, maturity, and attention to detail.
            </p>
          </div>

          <div class="experience-card">
            <h3>High-End Brand Direction</h3>
            <p>
              We are building something that feels premium from the website to the memberships to the everyday client experience.
            </p>
          </div>
        </div>
      </div>
    </section>

    <section>
      <div class="container">
        <div class="cta-band">
          <div>
            <h3>Ready to elevate your dog’s care?</h3>
            <p>
              Book a service, explore memberships, or build a custom plan that fits your lifestyle and the level of care you want.
            </p>
          </div>

          <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <a href="book-walk.php" class="btn btn-gold">Book Now</a>
            <a href="contact.php" class="btn btn-dark">Contact Us</a>
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