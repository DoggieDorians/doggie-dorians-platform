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
  <meta name="description" content="Doggie Dorian's offers luxury dog walking, daycare, boarding, and premium memberships with preferred pricing, exclusive perks, and elevated care.">

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
      --radius-xl: 34px;
      --radius-lg: 24px;
      --radius-md: 18px;
    }

    body {
      font-family: "Georgia", "Times New Roman", serif;
      background:
        radial-gradient(circle at top, rgba(215,178,106,0.10), transparent 24%),
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
      background: rgba(7, 8, 11, 0.80);
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
      min-height: 48px;
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
      padding: 72px 0 28px;
    }

    .hero-card {
      border-radius: 38px;
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
      gap: 28px;
      padding: 58px;
      align-items: center;
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
      font-size: clamp(2.8rem, 5vw, 5.2rem);
      line-height: 0.96;
      color: var(--white);
      margin-bottom: 18px;
      max-width: 760px;
    }

    .hero-copy p {
      font-size: 1.08rem;
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

    .spotlight-card {
      border-radius: 22px;
      border: 1px solid rgba(255,255,255,0.08);
      background: rgba(255,255,255,0.03);
      padding: 20px;
    }

    .spotlight-card strong {
      display: block;
      color: var(--white);
      font-size: 1.02rem;
      margin-bottom: 5px;
    }

    .spotlight-card span {
      color: var(--muted);
      font-size: 0.95rem;
    }

    .spotlight-card.highlight {
      border-color: rgba(215,178,106,0.26);
      background: rgba(215,178,106,0.10);
    }

    .spotlight-price {
      display: block;
      font-size: 2rem;
      color: #f5ddaf;
      font-weight: 700;
      line-height: 1;
      margin-bottom: 8px;
    }

    .quick-bar {
      padding-top: 22px;
    }

    .quick-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 14px;
    }

    .quick-tile {
      text-align: center;
      border: 1px solid rgba(215,178,106,0.18);
      background: rgba(215,178,106,0.08);
      border-radius: 18px;
      padding: 18px;
    }

    .quick-tile strong {
      display: block;
      color: #f5ddaf;
      font-size: 1.3rem;
      margin-bottom: 5px;
    }

    .quick-tile span {
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

    .feature-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 20px;
    }

    .feature-card {
      border-radius: 24px;
      border: 1px solid rgba(255,255,255,0.08);
      background: rgba(255,255,255,0.03);
      padding: 24px;
      box-shadow: var(--shadow);
    }

    .feature-card h3 {
      color: var(--white);
      font-size: 1.35rem;
      margin-bottom: 10px;
    }

    .feature-card p {
      color: var(--muted);
      font-size: 0.96rem;
      margin-bottom: 14px;
    }

    .feature-card ul {
      list-style: none;
      display: grid;
      gap: 10px;
    }

    .feature-card li {
      position: relative;
      padding-left: 22px;
      color: var(--muted);
      font-size: 0.95rem;
    }

    .feature-card li::before {
      content: "✦";
      position: absolute;
      left: 0;
      top: 0;
      color: var(--gold);
    }

    .pricing-wrap {
      border-radius: 28px;
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

    .badge {
      display: inline-block;
      padding: 6px 10px;
      border-radius: 999px;
      border: 1px solid rgba(215,178,106,0.22);
      background: rgba(215,178,106,0.10);
      color: #f2d9a8;
      font-weight: 700;
      font-size: 0.88rem;
    }

    .membership-banner {
      border-radius: 30px;
      padding: 30px;
      border: 1px solid rgba(215,178,106,0.22);
      background:
        linear-gradient(135deg, rgba(215,178,106,0.12), rgba(255,255,255,0.03));
      box-shadow: var(--shadow);
    }

    .membership-grid {
      display: grid;
      grid-template-columns: 1.1fr 0.9fr;
      gap: 24px;
      align-items: center;
    }

    .mini-cards {
      display: grid;
      gap: 14px;
    }

    .mini-card {
      border-radius: 20px;
      padding: 18px;
      background: rgba(8,10,14,0.48);
      border: 1px solid rgba(255,255,255,0.08);
    }

    .mini-card strong {
      display: block;
      color: var(--white);
      margin-bottom: 4px;
      font-size: 1rem;
    }

    .mini-card span {
      color: var(--muted);
      font-size: 0.94rem;
    }

    .services-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 20px;
    }

    .service-card {
      border-radius: 24px;
      border: 1px solid rgba(255,255,255,0.08);
      background: rgba(255,255,255,0.03);
      padding: 24px;
      box-shadow: var(--shadow);
    }

    .service-card h3 {
      color: var(--white);
      font-size: 1.32rem;
      margin-bottom: 10px;
    }

    .service-card p {
      color: var(--muted);
      margin-bottom: 14px;
      font-size: 0.96rem;
    }

    .service-price {
      display: inline-block;
      margin-bottom: 12px;
      color: #f5ddaf;
      font-weight: 700;
      font-size: 1rem;
    }

    .founders-box {
      border-radius: 30px;
      padding: 32px;
      border: 1px solid rgba(215,178,106,0.22);
      background:
        linear-gradient(135deg, rgba(215,178,106,0.11), rgba(255,255,255,0.03));
      box-shadow: var(--shadow);
      display: flex;
      justify-content: space-between;
      gap: 24px;
      flex-wrap: wrap;
      align-items: center;
    }

    .founders-copy {
      max-width: 760px;
    }

    .founders-copy h2 {
      color: var(--white);
      font-size: clamp(1.8rem, 3vw, 2.8rem);
      line-height: 1.08;
      margin-bottom: 10px;
    }

    .founders-copy p {
      color: var(--muted);
    }

    .testimonials {
      padding: 60px 0 100px;
    }

    .testimonial-grid {
      display: grid;
      gap: 30px;
    }

    .testimonial-card {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 30px;
      align-items: center;
      background: rgba(255,255,255,0.03);
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: 24px;
      padding: 28px;
      box-shadow: var(--shadow);
    }

    .testimonial-media img,
    .testimonial-media video {
      width: 100%;
      border-radius: 18px;
      object-fit: cover;
      max-height: 420px;
      display: block;
      background: #0b0b0e;
    }

    .testimonial-media video {
      height: 420px;
    }

    .testimonial-kicker {
      font-size: 0.75rem;
      letter-spacing: 0.1em;
      color: var(--gold);
      margin-bottom: 10px;
      text-transform: uppercase;
    }

    .testimonial-quote {
      font-size: 1.05rem;
      line-height: 1.7;
      color: var(--text);
    }

    .testimonial-author {
      margin-top: 14px;
      color: var(--gold);
      font-weight: 700;
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
      .hero-grid,
      .membership-grid,
      .feature-grid,
      .services-grid,
      .quick-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 980px) {
      .testimonial-card {
        grid-template-columns: 1fr;
      }

      .testimonial-media video {
        height: auto;
        max-height: 420px;
      }
    }

    @media (max-width: 860px) {
      .nav-wrap {
        flex-direction: column;
        align-items: flex-start;
      }

      .hero-grid {
        padding: 34px 24px;
      }

      th, td {
        padding: 14px;
      }
    }

    @media (max-width: 640px) {
      .container {
        width: min(var(--max), calc(100% - 20px));
      }

      .hero {
        padding-top: 54px;
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

      .feature-card,
      .service-card,
      .spotlight-card,
      .mini-card,
      .membership-banner,
      .founders-box,
      .cta-box,
      .testimonial-card {
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
        <a href="index.php" class="active">Home</a>
        <a href="services.php">Services</a>
        <a href="memberships.php">Memberships</a>
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
            <div class="hero-copy">
              <div class="eyebrow">Luxury Dog Care</div>
              <h1>Preferred member pricing. Elevated care. A more premium way to book.</h1>
              <p>
                Doggie Dorian’s offers luxury dog walking, daycare, boarding, and membership experiences designed for clients who want consistent care, premium service, and stronger ongoing value.
              </p>

              <div class="hero-actions">
                <a href="memberships.php" class="btn btn-gold">Explore Memberships</a>
                <a href="book-walk.php" class="btn btn-outline">Book as Non-Member</a>
              </div>
            </div>

            <div class="hero-side">
              <div class="spotlight-card highlight">
                <span class="spotlight-price">$25</span>
                <strong>Member price for a 30-minute walk</strong>
                <span>Preferred pricing for clients who join a membership.</span>
              </div>

              <div class="spotlight-card">
                <span class="spotlight-price">$30</span>
                <strong>Non-member price for a 30-minute walk</strong>
                <span>Standard booking rate for clients who book without membership.</span>
              </div>

              <div class="spotlight-card">
                <span class="spotlight-price">9 Walks</span>
                <strong>Walk Club starts at $200/month</strong>
                <span>Our walk-only membership includes a free gift and stronger recurring value.</span>
              </div>
            </div>
          </div>
        </div>

        <div class="quick-bar">
          <div class="quick-grid">
            <div class="quick-tile">
              <strong>$25</strong>
              <span>member walk pricing</span>
            </div>
            <div class="quick-tile">
              <strong>$30</strong>
              <span>non-member walk pricing</span>
            </div>
            <div class="quick-tile">
              <strong>9 Walks</strong>
              <span>included in Walk Club</span>
            </div>
            <div class="quick-tile">
              <strong>Free Gifts</strong>
              <span>included in memberships</span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section>
      <div class="container">
        <div class="section-head">
          <h2>Why clients choose membership</h2>
          <p>
            The homepage makes the value clear fast: lower walk pricing, stronger recurring value, premium perks, and a more polished booking experience.
          </p>
        </div>

        <div class="feature-grid">
          <article class="feature-card">
            <h3>Preferred Pricing</h3>
            <p>Members receive a lower 30-minute walk rate and stronger monthly value than clients booking one service at a time.</p>
            <ul>
              <li>$25 member walk pricing</li>
              <li>better fit for recurring care</li>
              <li>stronger ongoing value</li>
            </ul>
          </article>

          <article class="feature-card">
            <h3>Premium Perks</h3>
            <p>Memberships include more appealing value boosters that feel premium and help justify the upgrade.</p>
            <ul>
              <li>complimentary gifts</li>
              <li>photo or video updates</li>
              <li>care add-ons in select plans</li>
            </ul>
          </article>

          <article class="feature-card">
            <h3>Priority Access</h3>
            <p>Members receive preferred scheduling access, while founder clients unlock the most exclusive tier of benefits.</p>
            <ul>
              <li>priority recurring scheduling</li>
              <li>higher-tier booking advantages</li>
              <li>founder-only rollover benefits</li>
            </ul>
          </article>
        </div>
      </div>
    </section>

    <section>
      <div class="container">
        <div class="section-head">
          <h2>Quick pricing comparison</h2>
          <p>
            This makes the difference easy to understand immediately and gives people a reason to click into the memberships page.
          </p>
        </div>

        <div class="pricing-wrap">
          <table>
            <thead>
              <tr>
                <th>Option</th>
                <th>Price</th>
                <th>Best For</th>
                <th>Includes</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>Non-Member Walk</td>
                <td><span class="badge">$30</span></td>
                <td>Occasional bookings</td>
                <td>Standard 30-minute walk booking</td>
              </tr>
              <tr>
                <td>Member Walk Rate</td>
                <td><span class="badge">$25</span></td>
                <td>Recurring clients</td>
                <td>Preferred 30-minute walk pricing</td>
              </tr>
              <tr>
                <td>Walk Club</td>
                <td><span class="badge">$200 / month</span></td>
                <td>Walk-only clients</td>
                <td>9 walks + free gift + preferred rate on added walks</td>
              </tr>
              <tr>
                <td>Founder Packages</td>
                <td><span class="badge">Limited Access</span></td>
                <td>Premium early clients</td>
                <td>exclusive value, deeper perks, and rollover benefits</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <section>
      <div class="container">
        <div class="membership-banner">
          <div class="membership-grid">
            <div>
              <div class="section-head" style="margin-bottom:0;">
                <h2>Walk Club makes the entry decision easier.</h2>
                <p>
                  For clients who mostly need walks, Walk Club is the cleanest way to start: 9 included 30-minute walks, a complimentary gift, and access to the preferred $25 member rate on additional walks.
                </p>
              </div>

              <div style="margin-top:24px; display:flex; gap:12px; flex-wrap:wrap;">
                <a href="memberships.php" class="btn btn-gold">View Memberships</a>
                <a href="signup.php?plan=walk-club" class="btn btn-outline">Join Walk Club</a>
              </div>
            </div>

            <div class="mini-cards">
              <div class="mini-card">
                <strong>9 included walks</strong>
                <span>Structured to feel stronger than a basic starter plan.</span>
              </div>
              <div class="mini-card">
                <strong>Free gift included</strong>
                <span>Adds visible value without making the plan feel overcomplicated.</span>
              </div>
              <div class="mini-card">
                <strong>Preferred pricing continues</strong>
                <span>Additional 30-minute walks stay at the $25 member rate.</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section>
      <div class="container">
        <div class="section-head">
          <h2>Luxury services, whether you join or book as needed</h2>
          <p>
            Some clients will prefer pay-as-you-go. Others will get far more value through membership. The homepage supports both paths clearly.
          </p>
        </div>

        <div class="services-grid">
          <article class="service-card">
            <span class="service-price">From $25–$30</span>
            <h3>Dog Walking</h3>
            <p>Luxury 30-minute walks with member and non-member pricing options depending on how you book.</p>
            <a href="book-walk.php" class="btn btn-soft">Book Walks</a>
          </article>

          <article class="service-card">
            <span class="service-price">Premium Daycare</span>
            <h3>Daycare</h3>
            <p>Structured for clients who want attentive daytime care, consistency, and a more polished experience.</p>
            <a href="services.php" class="btn btn-soft">View Services</a>
          </article>

          <article class="service-card">
            <span class="service-price">Luxury Overnight Care</span>
            <h3>Boarding</h3>
            <p>Premium overnight support for clients who want comfort, trust, and elevated service for their dogs.</p>
            <a href="services.php" class="btn btn-soft">Explore Boarding</a>
          </article>
        </div>
      </div>
    </section>

    <section class="testimonials">
      <div class="container">
        <div class="section-head">
          <h2>Trusted by NYC dog owners</h2>
          <p>Real clients. Real dogs. Real trust.</p>
        </div>

        <div class="testimonial-grid">
          <article class="testimonial-card">
            <div class="testimonial-media">
              <img src="assets/images/lucy.jpg" alt="Lucy relaxing comfortably on a couch">
            </div>

            <div>
              <div class="testimonial-kicker">Trust & Connection</div>
              <div class="testimonial-quote">
                “Doggie Dorian’s is the only service we trust completely with Lucy. The level of reliability,
                communication, and genuine care is unmatched.
                <br><br>
                Dorian doesn’t just walk dogs — he truly connects with them. Lucy bonded with him immediately,
                and we’ve had complete peace of mind ever since.”
              </div>
              <div class="testimonial-author">— Jennifer Shatzky, NYC</div>
            </div>
          </article>

          <article class="testimonial-card">
            <div>
              <div class="testimonial-kicker">Safety & City Experience</div>
              <div class="testimonial-quote">
                “Dorian has taken exceptional care of my dog Lola throughout Manhattan, including busy sidewalks
                and Central Park.
                <br><br>
                He is thoughtful, reliable, and incredibly attentive — always prioritizing my dog’s comfort and
                safety. I trust him completely, even in challenging conditions.”
              </div>
              <div class="testimonial-author">— Eileen Goldenberg, NYC</div>
            </div>

            <div class="testimonial-media">
              <video autoplay muted loop playsinline controls preload="metadata">
                <source src="assets/videos/lola1.mp4" type="video/mp4">
                Your browser does not support the video tag.
              </video>
            </div>
          </article>

          <article class="testimonial-card">
            <div class="testimonial-media">
              <img src="assets/images/apple.jpg" alt="Apple outdoors near the water">
            </div>

            <div>
              <div class="testimonial-kicker">Reliability & Care</div>
              <div class="testimonial-quote">
                “Dorian is one of the most patient and dependable people I’ve ever worked with. My dog can be
                difficult, but with him she completely transforms.
                <br><br>
                He’s helped with medication and last-minute walks, always showing up with the same level of care
                and professionalism. I trust him completely.”
              </div>
              <div class="testimonial-author">— Linda, NYC</div>
            </div>
          </article>
        </div>
      </div>
    </section>

    <section>
      <div class="container">
        <div class="founders-box">
          <div class="founders-copy">
            <h2>Founding Client Status remains the exclusive tier.</h2>
            <p>
              Founder memberships are still your most premium offer. They include deeper value, stronger access, and rollover benefits that standard memberships do not include.
            </p>
          </div>

          <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <a href="founders-memberships.php" class="btn btn-gold">View Founder Packages</a>
            <a href="memberships.php" class="btn btn-outline">Compare All Memberships</a>
          </div>
        </div>
      </div>
    </section>

    <section style="padding-top: 10px; padding-bottom: 80px;">
      <div class="container">
        <div class="cta-box">
          <div>
            <h2>Book now, or move into a membership with stronger value.</h2>
            <p>
              Non-members can book anytime at standard pricing. Members get lower walk pricing, free gifts, better recurring value, and a more elevated overall experience.
            </p>
          </div>

          <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <a href="memberships.php" class="btn btn-gold">Explore Memberships</a>
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