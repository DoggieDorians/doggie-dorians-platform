<?php
session_start();
$isLoggedIn = isset($_SESSION['member_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Pricing | Doggie Dorian's</title>
  <meta
    name="description"
    content="View member and non-member pricing for luxury dog walks, premium daycare, and boutique boarding from Doggie Dorian’s in Manhattan."
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
      max-width: 900px;
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

    .pricing-section {
      display: grid;
      gap: 22px;
    }

    .pricing-card {
      border-radius: 28px;
      overflow: hidden;
      background:
        linear-gradient(180deg, rgba(255,255,255,.08), rgba(255,255,255,.03)),
        linear-gradient(160deg, #15151b, #101015);
      border: 1px solid rgba(255,255,255,.08);
      box-shadow: var(--shadow);
    }

    .pricing-card-header {
      padding: 28px 28px 18px;
      border-bottom: 1px solid rgba(255,255,255,.08);
    }

    .pricing-card-header h3 {
      font-size: 1.7rem;
      letter-spacing: -.03em;
      margin-bottom: 8px;
    }

    .pricing-card-header p {
      color: var(--muted);
    }

    .table-wrap {
      overflow-x: auto;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 680px;
    }

    th, td {
      padding: 18px 20px;
      text-align: left;
      border-bottom: 1px solid rgba(255,255,255,.08);
    }

    th {
      color: var(--gold-2);
      font-size: .82rem;
      text-transform: uppercase;
      letter-spacing: .08em;
      background: rgba(255,255,255,.03);
    }

    td {
      color: var(--text);
      font-size: 1rem;
    }

    tr:last-child td {
      border-bottom: none;
    }

    .member-col {
      color: var(--gold-2);
      font-weight: 800;
    }

    .discount-col {
      color: #fff6e5;
      font-weight: 800;
    }

    .muted-copy {
      color: var(--muted);
    }

    .note-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 22px;
    }

    .note-card {
      border-radius: 28px;
      padding: 30px;
      background:
        linear-gradient(180deg, rgba(255,255,255,.07), rgba(255,255,255,.03)),
        linear-gradient(160deg, #15151b, #101015);
      border: 1px solid rgba(255,255,255,.08);
      box-shadow: var(--shadow);
    }

    .note-card h3 {
      font-size: 1.5rem;
      letter-spacing: -.03em;
      margin-bottom: 10px;
    }

    .note-card p {
      color: var(--muted);
      margin-bottom: 14px;
    }

    .note-card ul {
      list-style: none;
      display: grid;
      gap: 10px;
    }

    .note-card li {
      position: relative;
      padding-left: 18px;
      color: var(--text);
    }

    .note-card li::before {
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
      .note-grid {
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

      .hero-panel,
      .pricing-card,
      .note-card,
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
        <div class="hero-grid">
          <div class="hero-copy">
            <span class="eyebrow">Pricing</span>
            <h1>
              Transparent rates,
              <span class="accent">better value for members.</span>
            </h1>
            <p>
              Doggie Dorian’s pricing is designed to keep booking clear and simple while rewarding members with preferred rates and stronger value on qualifying multi-day daycare and longer boarding stays.
            </p>

            <div class="hero-actions">
              <a href="book-walk.php" class="btn btn-primary">Book Premium Care</a>
              <a href="memberships.php" class="btn btn-secondary">Explore Memberships</a>
            </div>

            <div class="hero-badges">
              <span class="hero-badge">Member Savings</span>
              <span class="hero-badge">Daycare 3+ Day Discounts</span>
              <span class="hero-badge">Boarding 5+ Night Discounts</span>
              <span class="hero-badge">Clear, Premium Pricing</span>
            </div>
          </div>

          <div class="hero-panel">
            <h3>How pricing is structured</h3>
            <p>Non-members can book directly, while members receive preferred pricing and added value on qualifying repeat-care bookings.</p>

            <div class="quick-grid">
              <div class="quick-box">
                <small>Walks</small>
                <strong>$23–$42</strong>
                <span>Member walk savings available across all durations</span>
              </div>
              <div class="quick-box">
                <small>Daycare</small>
                <strong>$65–$110</strong>
                <span>Member 3+ day pricing available</span>
              </div>
              <div class="quick-box">
                <small>Boarding</small>
                <strong>$90–$120</strong>
                <span>Member 5+ night pricing available</span>
              </div>
              <div class="quick-box">
                <small>Best value</small>
                <strong>Membership</strong>
                <span>Preferred rates for repeat clients</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="container">
        <div class="section-head">
          <span class="eyebrow">Walk Pricing</span>
          <h2>Luxury walks with clearer value for members.</h2>
          <p>
            Members receive preferred pricing across every walk duration, making repeat care more seamless and cost-effective over time.
          </p>
        </div>

        <div class="pricing-section">
          <div class="pricing-card">
            <div class="pricing-card-header">
              <h3>Dog Walks</h3>
              <p>Private walk options designed for different routines, energy levels, and scheduling needs.</p>
            </div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Duration</th>
                    <th>Non-Member</th>
                    <th>Member</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td>15 Minutes</td>
                    <td>$23</td>
                    <td class="member-col">$20</td>
                  </tr>
                  <tr>
                    <td>20 Minutes</td>
                    <td>$25</td>
                    <td class="member-col">$22</td>
                  </tr>
                  <tr>
                    <td>30 Minutes</td>
                    <td>$30</td>
                    <td class="member-col">$25</td>
                  </tr>
                  <tr>
                    <td>45 Minutes</td>
                    <td>$38</td>
                    <td class="member-col">$32</td>
                  </tr>
                  <tr>
                    <td>60 Minutes</td>
                    <td>$42</td>
                    <td class="member-col">$35</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="section" style="padding-top: 10px;">
      <div class="container">
        <div class="section-head">
          <span class="eyebrow">Daycare Pricing</span>
          <h2>Premium daytime care with better rates for repeat member bookings.</h2>
          <p>
            Standard member pricing applies to regular daycare bookings, with stronger per-day value available once a member books 3 or more days.
          </p>
        </div>

        <div class="pricing-section">
          <div class="pricing-card">
            <div class="pricing-card-header">
              <h3>Daycare</h3>
              <p>Structured daytime care priced by dog size, with a member volume discount at 3 or more booked days.</p>
            </div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Dog Size</th>
                    <th>Non-Member</th>
                    <th>Member</th>
                    <th>Member 3+ Days</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td>Small</td>
                    <td>$65</td>
                    <td class="member-col">$55</td>
                    <td class="discount-col">$50/day</td>
                  </tr>
                  <tr>
                    <td>Medium</td>
                    <td>$85</td>
                    <td class="member-col">$70</td>
                    <td class="discount-col">$65/day</td>
                  </tr>
                  <tr>
                    <td>Large</td>
                    <td>$110</td>
                    <td class="member-col">$90</td>
                    <td class="discount-col">$82/day</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="section" style="padding-top: 10px;">
      <div class="container">
        <div class="section-head">
          <span class="eyebrow">Boarding Pricing</span>
          <h2>Boutique overnight care with preferred member rates for longer stays.</h2>
          <p>
            Member pricing lowers the standard nightly rate, and qualifying stays of 5 or more nights receive an even stronger member boarding rate.
          </p>
        </div>

        <div class="pricing-section">
          <div class="pricing-card">
            <div class="pricing-card-header">
              <h3>Boarding</h3>
              <p>Overnight care priced by dog size, with a member volume discount at 5 or more nights.</p>
            </div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Dog Size</th>
                    <th>Non-Member</th>
                    <th>Member</th>
                    <th>Member 5+ Nights</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td>Small</td>
                    <td>$90</td>
                    <td class="member-col">$75</td>
                    <td class="discount-col">$68/night</td>
                  </tr>
                  <tr>
                    <td>Medium</td>
                    <td>$110</td>
                    <td class="member-col">$90</td>
                    <td class="discount-col">$82/night</td>
                  </tr>
                  <tr>
                    <td>Large</td>
                    <td>$120</td>
                    <td class="member-col">$100</td>
                    <td class="discount-col">$92/night</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="section" style="padding-top: 10px;">
      <div class="container note-grid">
        <div class="note-card">
          <span class="eyebrow">Member Advantage</span>
          <h3>Why membership creates better value</h3>
          <p>
            Membership is designed for clients who book more consistently and want pricing that better supports repeat care.
          </p>
          <ul>
            <li>Preferred pricing on walks, daycare, and boarding</li>
            <li>Daycare volume discount at 3 or more booked days</li>
            <li>Boarding volume discount at 5 or more booked nights</li>
            <li>A smoother long-term care experience for repeat clients</li>
          </ul>
        </div>

        <div class="note-card">
          <span class="eyebrow">Pricing Notes</span>
          <h3>Clear rules, fewer surprises</h3>
          <p>
            Rates shown here reflect the current standard pricing structure and are intended to keep member and non-member options easy to understand.
          </p>
          <ul>
            <li>Founders packages are not included on this page yet</li>
            <li>Member volume discounts apply only when the booking threshold is met</li>
            <li>Daycare discount applies at 3 or more booked days</li>
            <li>Boarding discount applies at 5 or more booked nights</li>
          </ul>
        </div>
      </div>
    </section>

    <section class="section" style="padding-top: 20px;">
      <div class="container">
        <div class="cta-panel">
          <span class="eyebrow">Ready to Move Forward?</span>
          <h2>Choose the booking style that fits your routine best.</h2>
          <p>
            Book as a non-member for direct access, or explore memberships for stronger pricing and better value across repeat care, daycare blocks, and longer boarding stays.
          </p>
          <div class="cta-actions">
            <a href="book-walk.php" class="btn btn-primary">Book Premium Care</a>
            <a href="memberships.php" class="btn btn-secondary">Explore Memberships</a>
            <a href="services.php" class="btn btn-ghost">View Services</a>
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