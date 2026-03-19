<?php
session_start();
$isLoggedIn = isset($_SESSION['member_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Memberships | Doggie Dorian's</title>
  <meta name="description" content="Explore Doggie Dorian's luxury memberships with preferred member pricing, a walk-only option, premium perks, complimentary gifts, and exclusive founder-level benefits.">

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
      --white: #ffffff;
      --shadow: 0 22px 65px rgba(0,0,0,0.38);
      --max: 1280px;
    }

    body {
      font-family: "Georgia", "Times New Roman", serif;
      background:
        radial-gradient(circle at top, rgba(215,178,106,0.10), transparent 25%),
        linear-gradient(180deg, #06070a 0%, #0b0d12 45%, #06070a 100%);
      color: var(--text);
      line-height: 1.6;
    }

    a {
      color: inherit;
      text-decoration: none;
    }

    .container {
      width: min(var(--max), calc(100% - 34px));
      margin: 0 auto;
    }

    .site-header {
      position: sticky;
      top: 0;
      z-index: 100;
      backdrop-filter: blur(14px);
      background: rgba(7, 8, 11, 0.78);
      border-bottom: 1px solid rgba(255,255,255,0.06);
    }

    .nav-wrap {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 20px;
      padding: 18px 0;
      flex-wrap: wrap;
    }

    .brand {
      font-size: 1.18rem;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--white);
      font-weight: 700;
    }

    .nav-links {
      display: flex;
      align-items: center;
      gap: 22px;
      flex-wrap: wrap;
    }

    .nav-links a {
      color: var(--muted);
      font-size: 0.95rem;
      transition: 0.22s ease;
    }

    .nav-links a:hover,
    .nav-links a.active {
      color: var(--gold);
    }

    .nav-actions {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 999px;
      padding: 13px 22px;
      font-size: 0.95rem;
      font-weight: 700;
      letter-spacing: 0.02em;
      transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease, background 0.22s ease;
      border: 1px solid transparent;
      cursor: pointer;
      text-align: center;
    }

    .btn:hover {
      transform: translateY(-2px);
    }

    .btn-gold {
      background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
      color: #15120d;
      box-shadow: 0 16px 38px rgba(215,178,106,0.22);
    }

    .btn-outline {
      border-color: rgba(215,178,106,0.45);
      background: rgba(255,255,255,0.02);
      color: var(--gold);
    }

    .btn-soft {
      border-color: rgba(255,255,255,0.08);
      background: rgba(255,255,255,0.03);
      color: var(--white);
    }

    .hero {
      padding: 82px 0 30px;
    }

    .hero-card {
      border-radius: 36px;
      border: 1px solid rgba(255,255,255,0.08);
      background:
        linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.02)),
        linear-gradient(135deg, rgba(215,178,106,0.10), rgba(255,255,255,0.02));
      box-shadow: var(--shadow);
      overflow: hidden;
    }

    .hero-grid {
      display: grid;
      grid-template-columns: 1.15fr 0.85fr;
      gap: 24px;
      padding: 56px;
    }

    .eyebrow {
      display: inline-block;
      padding: 8px 14px;
      border-radius: 999px;
      border: 1px solid rgba(215,178,106,0.30);
      background: rgba(215,178,106,0.08);
      color: #f2d9a8;
      font-size: 0.78rem;
      letter-spacing: 0.14em;
      text-transform: uppercase;
      margin-bottom: 18px;
    }

    h1 {
      font-size: clamp(2.6rem, 5vw, 5rem);
      line-height: 0.98;
      color: var(--white);
      margin-bottom: 18px;
    }

    .hero p {
      font-size: 1.07rem;
      color: var(--muted);
      max-width: 720px;
    }

    .hero-actions {
      display: flex;
      gap: 14px;
      flex-wrap: wrap;
      margin-top: 28px;
    }

    .hero-side {
      display: grid;
      gap: 14px;
      align-content: start;
    }

    .info-card {
      border-radius: 20px;
      border: 1px solid rgba(255,255,255,0.08);
      background: rgba(255,255,255,0.03);
      padding: 18px;
    }

    .info-card strong {
      display: block;
      color: var(--white);
      margin-bottom: 6px;
      font-size: 1rem;
    }

    .info-card span {
      color: var(--muted);
      font-size: 0.95rem;
    }

    .value-strip {
      padding: 22px 0 0;
    }

    .value-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 14px;
    }

    .value-tile {
      text-align: center;
      border: 1px solid rgba(215,178,106,0.18);
      background: rgba(215,178,106,0.08);
      border-radius: 18px;
      padding: 18px;
    }

    .value-tile strong {
      display: block;
      color: #f5ddaf;
      font-size: 1.3rem;
      margin-bottom: 5px;
    }

    .value-tile span {
      color: var(--muted);
      font-size: 0.92rem;
    }

    section {
      padding: 48px 0;
    }

    .section-head {
      max-width: 820px;
      margin-bottom: 28px;
    }

    .section-head h2 {
      font-size: clamp(1.9rem, 3vw, 3rem);
      line-height: 1.08;
      margin-bottom: 10px;
      color: var(--white);
    }

    .section-head p {
      color: var(--muted);
      font-size: 1rem;
    }

    .comparison-wrap {
      border-radius: 26px;
      overflow: hidden;
      border: 1px solid rgba(255,255,255,0.08);
      background: rgba(255,255,255,0.03);
      box-shadow: var(--shadow);
    }

    table {
      width: 100%;
      border-collapse: collapse;
    }

    th, td {
      padding: 18px 20px;
      text-align: left;
      border-bottom: 1px solid rgba(255,255,255,0.08);
      vertical-align: top;
    }

    th {
      background: rgba(255,255,255,0.03);
      color: var(--white);
      text-transform: uppercase;
      letter-spacing: 0.06em;
      font-size: 0.90rem;
    }

    td {
      color: var(--muted);
      font-size: 0.97rem;
    }

    tr:last-child td {
      border-bottom: none;
    }

    .price-badge {
      display: inline-block;
      padding: 6px 10px;
      border-radius: 999px;
      border: 1px solid rgba(215,178,106,0.22);
      background: rgba(215,178,106,0.10);
      color: #f2d9a8;
      font-weight: 700;
      font-size: 0.88rem;
    }

    .plans {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 20px;
      align-items: stretch;
    }

    .plan {
      border-radius: 28px;
      border: 1px solid rgba(255,255,255,0.08);
      background:
        linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.025));
      box-shadow: var(--shadow);
      overflow: hidden;
      display: flex;
      flex-direction: column;
      position: relative;
    }

    .plan.featured {
      border-color: rgba(215,178,106,0.36);
      transform: translateY(-4px);
      background:
        linear-gradient(180deg, rgba(215,178,106,0.12), rgba(255,255,255,0.03));
    }

    .plan-top {
      padding: 26px 24px 18px;
      border-bottom: 1px solid rgba(255,255,255,0.08);
    }

    .plan-tag {
      display: inline-block;
      padding: 7px 12px;
      border-radius: 999px;
      border: 1px solid rgba(215,178,106,0.30);
      background: rgba(215,178,106,0.10);
      color: #f3d9a8;
      font-size: 0.75rem;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      margin-bottom: 12px;
    }

    .plan h3 {
      font-size: 1.55rem;
      color: var(--white);
      margin-bottom: 8px;
    }

    .plan-sub {
      color: var(--muted);
      font-size: 0.95rem;
      min-height: 74px;
    }

    .price {
      display: flex;
      align-items: flex-end;
      gap: 8px;
      margin-top: 18px;
    }

    .price strong {
      font-size: 2.85rem;
      line-height: 1;
      color: var(--white);
    }

    .price span {
      color: var(--soft);
      font-size: 0.95rem;
      margin-bottom: 5px;
    }

    .plan-body {
      padding: 22px 24px 24px;
      display: flex;
      flex-direction: column;
      gap: 18px;
      height: 100%;
    }

    .savings-box {
      border-radius: 18px;
      padding: 15px;
      border: 1px solid rgba(215,178,106,0.18);
      background: rgba(215,178,106,0.08);
    }

    .savings-box strong {
      display: block;
      color: #f6ddb0;
      margin-bottom: 6px;
      font-size: 1rem;
    }

    .savings-box span {
      color: var(--muted);
      font-size: 0.93rem;
    }

    .plan ul {
      list-style: none;
      display: grid;
      gap: 11px;
    }

    .plan li {
      position: relative;
      padding-left: 22px;
      color: var(--muted);
      font-size: 0.96rem;
    }

    .plan li::before {
      content: "✦";
      position: absolute;
      left: 0;
      top: 0;
      color: var(--gold);
    }

    .plan-actions {
      margin-top: auto;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .micro {
      color: var(--soft);
      font-size: 0.88rem;
    }

    .founders-wrap {
      border-radius: 30px;
      padding: 30px;
      border: 1px solid rgba(215,178,106,0.20);
      background:
        linear-gradient(135deg, rgba(215,178,106,0.11), rgba(255,255,255,0.03));
      box-shadow: var(--shadow);
    }

    .founders-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
      margin-top: 22px;
    }

    .founder-card {
      border-radius: 24px;
      padding: 24px;
      border: 1px solid rgba(255,255,255,0.08);
      background: rgba(7,9,13,0.55);
    }

    .founder-card h3 {
      color: var(--white);
      font-size: 1.5rem;
      margin-bottom: 8px;
    }

    .founder-price {
      color: #f5dcaf;
      font-size: 2.2rem;
      font-weight: 700;
      margin-bottom: 8px;
    }

    .founder-card p {
      color: var(--muted);
      margin-bottom: 14px;
    }

    .founder-card ul {
      list-style: none;
      display: grid;
      gap: 11px;
    }

    .founder-card li {
      position: relative;
      padding-left: 22px;
      color: var(--muted);
    }

    .founder-card li::before {
      content: "◆";
      position: absolute;
      left: 0;
      top: 5px;
      color: var(--gold);
      font-size: 0.78rem;
    }

    .faq-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 18px;
    }

    .faq-item {
      border-radius: 22px;
      border: 1px solid rgba(255,255,255,0.08);
      background: rgba(255,255,255,0.03);
      padding: 22px;
    }

    .faq-item h3 {
      color: var(--white);
      font-size: 1.06rem;
      margin-bottom: 10px;
    }

    .faq-item p {
      color: var(--muted);
      font-size: 0.96rem;
    }

    .cta-box {
      border-radius: 30px;
      padding: 34px;
      border: 1px solid rgba(215,178,106,0.22);
      background:
        linear-gradient(135deg, rgba(215,178,106,0.12), rgba(255,255,255,0.03));
      box-shadow: var(--shadow);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 24px;
      flex-wrap: wrap;
    }

    .cta-box h2 {
      font-size: clamp(1.85rem, 3vw, 2.8rem);
      line-height: 1.06;
      margin-bottom: 8px;
      color: var(--white);
    }

    .cta-box p {
      color: var(--muted);
      max-width: 720px;
    }

    footer {
      padding: 28px 0 48px;
      text-align: center;
      color: var(--soft);
      font-size: 0.92rem;
    }

    @media (max-width: 1180px) {
      .plans {
        grid-template-columns: repeat(2, 1fr);
      }

      .value-grid {
        grid-template-columns: repeat(2, 1fr);
      }

      .hero-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 860px) {
      .founders-grid,
      .faq-grid,
      .plans,
      .value-grid {
        grid-template-columns: 1fr;
      }

      .plan.featured {
        transform: none;
      }

      .hero-grid {
        padding: 34px 24px;
      }

      th, td {
        padding: 14px;
      }

      .nav-wrap {
        flex-direction: column;
        align-items: flex-start;
      }
    }

    @media (max-width: 640px) {
      .container {
        width: min(var(--max), calc(100% - 20px));
      }

      .hero {
        padding-top: 56px;
      }

      .btn {
        width: 100%;
      }

      .hero-actions,
      .nav-actions {
        width: 100%;
      }

      .nav-actions a {
        flex: 1;
      }

      .cta-box,
      .founders-wrap,
      .faq-item,
      .plan-top,
      .plan-body,
      .founder-card {
        padding-left: 18px;
        padding-right: 18px;
      }
    }
  </style>
</head>
<body>

  <header class="site-header">
    <div class="container nav-wrap">
      <a href="index.php" class="brand">Doggie Dorian's</a>

      <nav class="nav-links">
        <a href="index.php">Home</a>
        <a href="services.php">Services</a>
        <a href="memberships.php" class="active">Memberships</a>
        <a href="book-walk.php">Book</a>
        <a href="contact.php">Contact</a>
      </nav>

      <div class="nav-actions">
        <?php if ($isLoggedIn): ?>
          <a href="dashboard.php" class="btn btn-soft">Dashboard</a>
        <?php else: ?>
          <a href="login.php" class="btn btn-soft">Member Login</a>
        <?php endif; ?>
        <a href="book-walk.php" class="btn btn-gold">Book a Service</a>
      </div>
    </div>
  </header>

  <main>
    <section class="hero">
      <div class="container">
        <div class="hero-card">
          <div class="hero-grid">
            <div>
              <div class="eyebrow">Preferred Pricing For Members</div>
              <h1>Luxury memberships built around preferred walk pricing.</h1>
              <p>
                Members receive 30-minute walks at a preferred rate of $25, while non-members book the same service at $30.
                Our memberships are designed for clients who want recurring care, premium service access, and thoughtful perks
                that make joining feel worthwhile.
              </p>

              <div class="hero-actions">
                <a href="#plans" class="btn btn-gold">View Membership Options</a>
                <a href="book-walk.php" class="btn btn-outline">Book Without Membership</a>
              </div>
            </div>

            <div class="hero-side">
              <div class="info-card">
                <strong>Member walk price</strong>
                <span>$25 for a 30-minute walk under member pricing.</span>
              </div>
              <div class="info-card">
                <strong>Non-member walk price</strong>
                <span>$30 for a 30-minute walk when booking without membership.</span>
              </div>
              <div class="info-card">
                <strong>Founder advantage</strong>
                <span>Only founder packages include rollover benefits along with deeper premium value.</span>
              </div>
            </div>
          </div>
        </div>

        <div class="value-strip">
          <div class="value-grid">
            <div class="value-tile">
              <strong>9 Walks</strong>
              <span>now included in Walk Club for stronger value</span>
            </div>
            <div class="value-tile">
              <strong>Free Gifts</strong>
              <span>included across standard memberships</span>
            </div>
            <div class="value-tile">
              <strong>Premium Perks</strong>
              <span>photo updates, add-ons, and priority access</span>
            </div>
            <div class="value-tile">
              <strong>Founder Rollover</strong>
              <span>reserved only for founder packages</span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section>
      <div class="container">
        <div class="section-head">
          <h2>Regular booking vs membership pricing</h2>
          <p>
            Standard memberships are structured around the $25 member walk rate and now include more visible premium value.
            Founder packages remain the more exclusive tier and are still the only plans that include rollover flexibility.
          </p>
        </div>

        <div class="comparison-wrap">
          <table>
            <thead>
              <tr>
                <th>Service</th>
                <th>Non-Member</th>
                <th>Member</th>
                <th>Difference</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>30-Minute Walk</td>
                <td><span class="price-badge">$30</span></td>
                <td><span class="price-badge">$25</span></td>
                <td>Members receive the preferred walk rate.</td>
              </tr>
              <tr>
                <td>Standard Membership Packages</td>
                <td>Pay-as-you-go</td>
                <td>Built around $25 walk value plus perks</td>
                <td>Better for clients who want recurring care and a higher-end experience.</td>
              </tr>
              <tr>
                <td>Free Gift</td>
                <td>Not included</td>
                <td>Included</td>
                <td>Each standard membership includes a complimentary gift.</td>
              </tr>
              <tr>
                <td>Premium Add-Ons</td>
                <td>Not included</td>
                <td>Included in select plans</td>
                <td>Photo updates, care add-ons, and service perks increase perceived value.</td>
              </tr>
              <tr>
                <td>Rollover Walks</td>
                <td>Not included</td>
                <td>Founder packages only</td>
                <td>Keeps founder memberships more exclusive.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <section id="plans">
      <div class="container">
        <div class="section-head">
          <h2>Membership options</h2>
          <p>
            These standard plans are based on the $25 member walk rate and now include extra perks that feel more premium
            and more giftable. Founder plans remain your exclusive rollover tier.
          </p>
        </div>

        <div class="plans">
          <article class="plan">
            <div class="plan-top">
              <div class="plan-tag">Walks Only</div>
              <h3>Walk Club</h3>
              <p class="plan-sub">
                A simple monthly option for clients who mainly want recurring 30-minute walks with a little extra value built in.
              </p>

              <div class="price">
                <strong>$200</strong>
                <span>/ month</span>
              </div>
            </div>

            <div class="plan-body">
              <div class="savings-box">
                <strong>Package walk value: $225</strong>
                <span>Based on 9 included walks at the $25 member rate.</span>
              </div>

              <ul>
                <li>9 included 30-minute walks each month</li>
                <li>Additional 30-minute walks at the $25 member rate</li>
                <li>Priority recurring scheduling</li>
                <li>1 complimentary welcome gift</li>
                <li>Best for clients who want a walk-only membership</li>
              </ul>

              <div class="plan-actions">
                <a href="signup.php?plan=walk-club" class="btn btn-outline">Choose Walk Club</a>
                <div class="micro">A stronger walk-focused plan with a built-in value boost.</div>
              </div>
            </div>
          </article>

          <article class="plan">
            <div class="plan-top">
              <div class="plan-tag">Light Recurring Care</div>
              <h3>Essential</h3>
              <p class="plan-sub">
                A flexible membership for clients who want recurring walks, broader service access, and a more polished monthly experience.
              </p>

              <div class="price">
                <strong>$250</strong>
                <span>/ month</span>
              </div>
            </div>

            <div class="plan-body">
              <div class="savings-box">
                <strong>Package walk value: $250+</strong>
                <span>Based on 10 included walks at the $25 member rate, plus added perks.</span>
              </div>

              <ul>
                <li>10 included 30-minute walks each month</li>
                <li>Additional 30-minute walks at the $25 member rate</li>
                <li>Priority booking access</li>
                <li>Member pricing access on qualifying services</li>
                <li>1 complimentary free gift</li>
                <li>1 complimentary treat or paw-care add-on each month</li>
              </ul>

              <div class="plan-actions">
                <a href="signup.php?plan=essential" class="btn btn-outline">Choose Essential</a>
                <div class="micro">A cleaner entry membership with a little more luxury built in.</div>
              </div>
            </div>
          </article>

          <article class="plan featured">
            <div class="plan-top">
              <div class="plan-tag">Most Popular</div>
              <h3>Premium</h3>
              <p class="plan-sub">
                The best balance of walk volume, premium perks, and recurring care for most ongoing clients.
              </p>

              <div class="price">
                <strong>$375</strong>
                <span>/ month</span>
              </div>
            </div>

            <div class="plan-body">
              <div class="savings-box">
                <strong>Package walk value: $375+</strong>
                <span>Based on 15 included walks at the $25 member rate, plus elevated extras.</span>
              </div>

              <ul>
                <li>15 included 30-minute walks each month</li>
                <li>1 complimentary daycare day each month</li>
                <li>Additional 30-minute walks at the $25 member rate</li>
                <li>Priority booking access</li>
                <li>Member-favored pricing on qualifying services</li>
                <li>1 complimentary premium gift</li>
                <li>1 photo or video update package each month</li>
              </ul>

              <div class="plan-actions">
                <a href="signup.php?plan=premium" class="btn btn-gold">Choose Premium</a>
                <div class="micro">The strongest all-around plan for recurring clients.</div>
              </div>
            </div>
          </article>

          <article class="plan">
            <div class="plan-top">
              <div class="plan-tag">Luxury Frequent Care</div>
              <h3>Elite</h3>
              <p class="plan-sub">
                A higher-touch membership for clients who want stronger volume, luxury perks, and premium service access.
              </p>

              <div class="price">
                <strong>$550</strong>
                <span>/ month</span>
              </div>
            </div>

            <div class="plan-body">
              <div class="savings-box">
                <strong>Package walk value: $550+</strong>
                <span>Based on 22 included walks at the $25 member rate before premium extras are counted.</span>
              </div>

              <ul>
                <li>22 included 30-minute walks each month</li>
                <li>2 complimentary daycare days each month</li>
                <li>1 complimentary boarding night each month</li>
                <li>Additional 30-minute walks at the $25 member rate</li>
                <li>VIP booking priority</li>
                <li>1 complimentary luxury gift</li>
                <li>Birthday gift for your dog</li>
                <li>Priority holiday scheduling request access</li>
                <li>Complimentary post-service photo or video updates</li>
              </ul>

              <div class="plan-actions">
                <a href="signup.php?plan=elite" class="btn btn-outline">Choose Elite</a>
                <div class="micro">Built for clients who want frequent care with a more elevated experience.</div>
              </div>
            </div>
          </article>
        </div>
      </div>
    </section>

    <section>
      <div class="container">
        <div class="founders-wrap">
          <div class="section-head" style="margin-bottom:0;">
            <h2>Founding Client Status</h2>
            <p>
              Founder packages remain your most exclusive offers. These are the only memberships that include rollover
              along with higher inclusions and stronger premium positioning.
            </p>
          </div>

          <div class="founders-grid">
            <div class="founder-card">
              <h3>Founding Silver</h3>
              <div class="founder-price">$500 / month</div>
              <p>
                A premium founder option that gives early clients strong recurring value, founder priority, and rollover flexibility.
              </p>
              <ul>
                <li>18 included 30-minute walks each month</li>
                <li>2 complimentary daycare days each month</li>
                <li>1 complimentary boarding night each month</li>
                <li>Quarterly service credit equal to one month of membership</li>
                <li>Rollover walks into the following month</li>
                <li>Founder priority access ahead of future standard members</li>
                <li>Exclusive founder gifting and recognition</li>
              </ul>
            </div>

            <div class="founder-card">
              <h3>Founding Gold</h3>
              <div class="founder-price">$800 / month</div>
              <p>
                Your highest founder tier for clients who want the strongest access, deepest service volume, and premium exclusivity.
              </p>
              <ul>
                <li>30 included 30-minute walks each month</li>
                <li>4 complimentary daycare days each month</li>
                <li>2 complimentary boarding nights each month</li>
                <li>Quarterly service credit equal to one month of membership</li>
                <li>Rollover walks into the following month</li>
                <li>Highest booking priority tier</li>
                <li>Founder-only premium gift package</li>
                <li>Locked founding status unavailable to future clients</li>
              </ul>
            </div>
          </div>

          <div style="margin-top:24px; display:flex; gap:12px; flex-wrap:wrap;">
            <a href="founders-memberships.php" class="btn btn-gold">View Founding Memberships</a>
            <a href="contact.php" class="btn btn-outline">Request Membership Guidance</a>
          </div>
        </div>
      </div>
    </section>

    <section>
      <div class="container">
        <div class="section-head">
          <h2>Membership questions</h2>
          <p>
            This section helps clients understand the difference between standard memberships and founder packages.
          </p>
        </div>

        <div class="faq-grid">
          <div class="faq-item">
            <h3>Do standard memberships include rollover?</h3>
            <p>
              No. Rollover is reserved only for founder packages to keep those plans more exclusive.
            </p>
          </div>

          <div class="faq-item">
            <h3>How are standard plans valued?</h3>
            <p>
              Standard memberships are based on the $25 member rate for 30-minute walks, then boosted with appealing perks and gifts.
            </p>
          </div>

          <div class="faq-item">
            <h3>What is the walk-only option?</h3>
            <p>
              Walk Club is the membership for clients who want recurring walks without needing a fuller care package.
            </p>
          </div>

          <div class="faq-item">
            <h3>What makes founder plans different?</h3>
            <p>
              Founder plans include rollover, stronger premium inclusions, and deeper exclusivity than standard memberships.
            </p>
          </div>
        </div>
      </div>
    </section>

    <section style="padding-top: 10px; padding-bottom: 80px;">
      <div class="container">
        <div class="cta-box">
          <div>
            <h2>Choose the membership style that fits your routine.</h2>
            <p>
              Join a standard membership for preferred walk pricing and premium perks, or step into a founder package
              for your most exclusive benefits and rollover flexibility.
            </p>
          </div>

          <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <a href="signup.php" class="btn btn-gold">Join a Membership</a>
            <a href="book-walk.php" class="btn btn-soft">Book as Non-Member</a>
          </div>
        </div>
      </div>
    </section>
  </main>

  <footer>
    <div class="container">
      &copy; <?php echo date('Y'); ?> Doggie Dorian's. Luxury dog care with preferred member pricing and premium recurring service.
    </div>
  </footer>

</body>
</html>