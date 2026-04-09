<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/pricing.php';

$isLoggedIn = isset($_SESSION['member_id']);
$bookingLink = $isLoggedIn ? 'book-service.php' : 'non-member-booking.php';

$pricing = dd_pricing_matrix();

$walkNonMember = $pricing['walk']['non_member'];
$walkMember = $pricing['walk']['member'];

$boardingNonMember = $pricing['boarding']['non_member'];
$boardingMember = $pricing['boarding']['member'];
$boardingMember5 = $pricing['boarding']['member_5plus'];

$daycareNonMember = $pricing['daycare']['non_member'];
$daycareMember = $pricing['daycare']['member'];

$dropInNonMember = $pricing['drop_in']['non_member'];
$dropInMember = $pricing['drop_in']['member'];

$sittingNonMember = $pricing['sitting']['non_member'];
$sittingMember = $pricing['sitting']['member'];

$groupWalkNonMember = 40;
$groupWalkMember = 30;

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
  <title>Pricing | Doggie Dorian's</title>
  <meta name="description" content="View current member and non-member pricing for dog walks, drop-ins, daycare, in-home sitting, group walks, and boarding at Doggie Dorian’s." />
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg: #09090c;
      --bg-2: #101016;
      --panel: rgba(255,255,255,0.05);
      --panel-2: rgba(255,255,255,0.08);
      --border: rgba(255,255,255,0.1);
      --text: #f7f3ec;
      --muted: #cbc3b7;
      --soft: #9d9486;
      --gold: #d7b56d;
      --gold-2: #f2dba9;
      --success: #9de3b1;
      --shadow: 0 24px 70px rgba(0,0,0,0.45);
      --max: 1260px;
      --radius: 28px;
    }

    body {
      font-family: "Inter", "Helvetica Neue", Arial, sans-serif;
      background:
        radial-gradient(circle at top left, rgba(215,181,109,0.16), transparent 24%),
        radial-gradient(circle at top right, rgba(242,219,169,0.08), transparent 20%),
        linear-gradient(180deg, #09090c 0%, #101016 34%, #09090c 100%);
      color: var(--text);
      line-height: 1.6;
      overflow-x: hidden;
    }

    a { color: inherit; text-decoration: none; }

    .container {
      width: min(var(--max), calc(100% - 28px));
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
      box-shadow: inset 0 1px 0 rgba(255,255,255,.16), 0 10px 30px rgba(0,0,0,.24);
    }

    .brand-title {
      font-size: 1.08rem;
      font-weight: 800;
      letter-spacing: -0.03em;
    }

    .brand-subtitle {
      font-size: 0.78rem;
      color: var(--soft);
      text-transform: uppercase;
      letter-spacing: 0.1em;
    }

    .nav-links {
      list-style: none;
      display: flex;
      align-items: center;
      gap: 26px;
      color: var(--muted);
      font-size: 0.98rem;
    }

    .nav-links a:hover { color: var(--text); }

    .nav-actions {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 50px;
      padding: 0 22px;
      border-radius: 999px;
      border: 1px solid transparent;
      font-size: 0.96rem;
      font-weight: 700;
      cursor: pointer;
      transition: .18s ease;
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

    .hero {
      padding: 42px 0 32px;
    }

    .hero-grid {
      display: grid;
      grid-template-columns: 1.1fr .9fr;
      gap: 22px;
      align-items: stretch;
    }

    .panel {
      border-radius: var(--radius);
      padding: 28px;
      background:
        linear-gradient(180deg, rgba(255,255,255,.07), rgba(255,255,255,.03)),
        linear-gradient(160deg, #15151b, #101015);
      border: 1px solid rgba(255,255,255,.08);
      box-shadow: var(--shadow);
    }

    .eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 10px 16px;
      border-radius: 999px;
      border: 1px solid rgba(215,181,109,.24);
      background: rgba(215,181,109,.08);
      color: var(--gold-2);
      font-size: 0.82rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      margin-bottom: 16px;
    }

    .eyebrow::before {
      content: "";
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: var(--gold);
      box-shadow: 0 0 14px rgba(215,181,109,.95);
    }

    h1 {
      font-size: clamp(2.5rem, 5vw, 4.8rem);
      line-height: .95;
      letter-spacing: -.06em;
      margin-bottom: 16px;
    }

    h1 span,
    h2,
    h3,
    .highlight {
      color: var(--gold-2);
    }

    .lead {
      color: var(--muted);
      font-size: 1.05rem;
      max-width: 760px;
      margin-bottom: 24px;
    }

    .hero-badges {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
    }

    .badge {
      padding: 10px 14px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,.1);
      background: rgba(255,255,255,.04);
      color: var(--text);
      font-size: .9rem;
      font-weight: 600;
    }

    .summary-list {
      display: grid;
      gap: 14px;
    }

    .summary-box {
      padding: 18px;
      border-radius: 18px;
      background: rgba(255,255,255,.04);
      border: 1px solid rgba(255,255,255,.08);
    }

    .summary-box strong {
      display: block;
      color: var(--gold-2);
      margin-bottom: 6px;
      font-size: 1.05rem;
    }

    .summary-box span {
      color: var(--muted);
      font-size: .94rem;
    }

    .section {
      padding: 0 0 28px;
    }

    .section-head {
      margin-bottom: 20px;
    }

    .section-head h2 {
      font-size: clamp(1.8rem, 3vw, 3rem);
      letter-spacing: -0.04em;
      margin-bottom: 10px;
    }

    .section-head p {
      color: var(--muted);
      max-width: 780px;
    }

    .pricing-grid {
      display: grid;
      gap: 22px;
    }

    .pricing-card {
      border-radius: 24px;
      padding: 24px;
      background:
        linear-gradient(180deg, rgba(255,255,255,.08), rgba(255,255,255,.03)),
        linear-gradient(160deg, #15151b, #101015);
      border: 1px solid rgba(255,255,255,.08);
      box-shadow: var(--shadow);
    }

    .pricing-card h3 {
      font-size: 1.5rem;
      margin-bottom: 8px;
      letter-spacing: -.03em;
    }

    .pricing-card p {
      color: var(--muted);
      margin-bottom: 18px;
    }

    .table-wrap {
      overflow-x: auto;
      border-radius: 18px;
      border: 1px solid rgba(255,255,255,.08);
    }

    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 720px;
      background: rgba(255,255,255,.02);
    }

    th, td {
      padding: 14px 16px;
      border-bottom: 1px solid rgba(255,255,255,.08);
      text-align: left;
    }

    th {
      color: var(--gold-2);
      font-size: .78rem;
      text-transform: uppercase;
      letter-spacing: .08em;
      background: rgba(255,255,255,.03);
    }

    td {
      color: var(--text);
      font-size: .96rem;
    }

    tr:last-child td {
      border-bottom: none;
    }

    .save {
      color: var(--success);
      font-weight: 700;
    }

    .note {
      margin-top: 14px;
      padding: 14px 16px;
      border-radius: 16px;
      background: rgba(215,181,109,.08);
      border: 1px solid rgba(215,181,109,.18);
      color: var(--gold-2);
    }

    .cta {
      padding-bottom: 60px;
    }

    .cta-panel {
      border-radius: 30px;
      padding: 34px;
      background:
        radial-gradient(circle at top left, rgba(242,219,169,.18), transparent 28%),
        linear-gradient(135deg, rgba(255,255,255,.08), rgba(255,255,255,.04)),
        linear-gradient(160deg, #16161d, #0e0e13);
      border: 1px solid rgba(215,181,109,.18);
      box-shadow: var(--shadow);
    }

    .cta-panel h2 {
      font-size: clamp(1.9rem, 3vw, 3rem);
      letter-spacing: -.04em;
      margin-bottom: 12px;
    }

    .cta-panel p {
      color: var(--muted);
      margin-bottom: 22px;
      max-width: 760px;
    }

    .cta-actions {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
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

    @media (max-width: 1100px) {
      .hero-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 920px) {
      .nav {
        flex-wrap: wrap;
        padding: 16px 0;
      }

      .nav-links {
        width: 100%;
        justify-content: center;
        flex-wrap: wrap;
        gap: 16px;
      }
    }

    @media (max-width: 640px) {
      .nav-actions {
        width: 100%;
        justify-content: space-between;
      }

      .hide-mobile {
        display: none;
      }

      .panel,
      .pricing-card,
      .cta-panel {
        border-radius: 20px;
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
        <a href="memberships.php" class="btn btn-primary">Become a Member</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<main>
  <section class="hero">
    <div class="container hero-grid">
      <div class="panel">
        <span class="eyebrow">Current Pricing</span>
        <h1>Clear pricing for <span>members and non-members.</span></h1>
        <p class="lead">
          Doggie Dorian’s pricing is built to feel premium, transparent, and easy to understand. Members receive preferred rates across walks, drop-ins, daycare, in-home sitting, and boarding.
        </p>

        <div class="note" style="margin-top: 0; margin-bottom: 24px;">
          Only <strong>50 regular memberships</strong> will be accepted. After that, new membership requests will move to a waitlist.
        </div>

        <div class="hero-badges">
          <span class="badge">Walk Member Savings</span>
          <span class="badge">Drop-In Member Savings</span>
          <span class="badge">6-Hour Daycare Sessions</span>
          <span class="badge">Boarding by Size</span>
        </div>
      </div>

      <div class="panel">
        <div class="summary-list">
          <div class="summary-box">
            <strong>Walks</strong>
            <span>Members save on every walk duration, from 15 to 60 minutes.</span>
          </div>
          <div class="summary-box">
            <strong>Drop-Ins</strong>
            <span>Hourly pricing with optional walk add-ons and a clean cap before daycare becomes the better fit.</span>
          </div>
          <div class="summary-box">
            <strong>Daycare</strong>
            <span>Now structured as a premium 6-hour session with optional food and additional walks.</span>
          </div>
          <div class="summary-box">
            <strong>Boarding</strong>
            <span>Boarding remains priced by dog size, with deeper member savings at 5 or more nights.</span>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="section">
    <div class="container pricing-grid">
      <div class="section-head">
        <h2>Walk Pricing</h2>
        <p>Preferred member rates apply automatically when a member is logged in and booking through the site.</p>
      </div>

      <div class="pricing-card">
        <h3>Private Walks</h3>
        <p>Choose the duration that best fits your dog’s routine.</p>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Duration</th>
                <th>Non-Member</th>
                <th>Member</th>
                <th>Member Savings</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>15 Minutes</td>
                <td><?= h(dd_format_money((float)$walkNonMember[15])) ?></td>
                <td><?= h(dd_format_money((float)$walkMember[15])) ?></td>
                <td class="save"><?= h(dd_format_money((float)$walkNonMember[15] - (float)$walkMember[15])) ?></td>
              </tr>
              <tr>
                <td>20 Minutes</td>
                <td><?= h(dd_format_money((float)$walkNonMember[20])) ?></td>
                <td><?= h(dd_format_money((float)$walkMember[20])) ?></td>
                <td class="save"><?= h(dd_format_money((float)$walkNonMember[20] - (float)$walkMember[20])) ?></td>
              </tr>
              <tr>
                <td>30 Minutes</td>
                <td><?= h(dd_format_money((float)$walkNonMember[30])) ?></td>
                <td><?= h(dd_format_money((float)$walkMember[30])) ?></td>
                <td class="save"><?= h(dd_format_money((float)$walkNonMember[30] - (float)$walkMember[30])) ?></td>
              </tr>
              <tr>
                <td>45 Minutes</td>
                <td><?= h(dd_format_money((float)$walkNonMember[45])) ?></td>
                <td><?= h(dd_format_money((float)$walkMember[45])) ?></td>
                <td class="save"><?= h(dd_format_money((float)$walkNonMember[45] - (float)$walkMember[45])) ?></td>
              </tr>
              <tr>
                <td>60 Minutes</td>
                <td><?= h(dd_format_money((float)$walkNonMember[60])) ?></td>
                <td><?= h(dd_format_money((float)$walkMember[60])) ?></td>
                <td class="save"><?= h(dd_format_money((float)$walkNonMember[60] - (float)$walkMember[60])) ?></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="pricing-card">
        <h3>Curated Group Walks</h3>
        <p>Structured small-group walks designed for socialization, consistency, and a controlled experience.</p>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Service</th>
                <th>Non-Member</th>
                <th>Member</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>60-Minute Group Walk</td>
                <td><?= h(dd_format_money((float)$groupWalkNonMember)) ?></td>
                <td><?= h(dd_format_money((float)$groupWalkMember)) ?></td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="note">
          Limited to <strong>5 dogs per group</strong>. Dogs are matched by temperament and energy level to ensure a controlled and enjoyable experience.
          <br><br>
          *Total service window may extend up to 75 minutes including pickup and drop-off coordination.*
        </div>
      </div>

      <div class="section-head">
        <h2>Drop-In Pricing</h2>
        <p>Drop-ins are billed hourly and capped at 2 hours. Anything beyond that is better structured as daycare.</p>
      </div>

      <div class="pricing-card">
        <h3>Hourly Drop-Ins</h3>
        <p>Ideal for quick care, check-ins, or a shorter in-home visit.</p>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Service</th>
                <th>Non-Member</th>
                <th>Member</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>1 Hour</td>
                <td><?= h(dd_format_money((float)$dropInNonMember['hourly_rate'])) ?></td>
                <td><?= h(dd_format_money((float)$dropInMember['hourly_rate'])) ?></td>
              </tr>
              <tr>
                <td>2 Hours</td>
                <td><?= h(dd_format_money((float)$dropInNonMember['hourly_rate'] * 2)) ?></td>
                <td><?= h(dd_format_money((float)$dropInMember['hourly_rate'] * 2)) ?></td>
              </tr>
              <tr>
                <td>Add 30-Minute Walk</td>
                <td>+<?= h(dd_format_money((float)$dropInNonMember['walk_add_on'])) ?></td>
                <td>+<?= h(dd_format_money((float)$dropInMember['walk_add_on'])) ?></td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="note">
          Drop-ins are capped at <strong><?= h((string)$dropInNonMember['max_hours']) ?> hours</strong>. For longer care windows, daycare is the better booking option.
        </div>
      </div>

      <div class="section-head">
        <h2>Daycare Pricing</h2>
        <p>Daycare is now structured as a premium 6-hour session rather than a size-based daily table.</p>
      </div>

      <div class="pricing-card">
        <h3>Premium Daycare</h3>
        <p>Each session includes one complimentary 30-minute walk, with optional food and additional walk add-ons.</p>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Service</th>
                <th>Non-Member</th>
                <th>Member</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>6-Hour Daycare Session</td>
                <td><?= h(dd_format_money((float)$daycareNonMember['base_rate'])) ?></td>
                <td><?= h(dd_format_money((float)$daycareMember['base_rate'])) ?></td>
              </tr>
              <tr>
                <td>Food Provided by Doggie Dorian’s</td>
                <td>+<?= h(dd_format_money((float)$daycareNonMember['food_fee'])) ?></td>
                <td>+<?= h(dd_format_money((float)$daycareMember['food_fee'])) ?></td>
              </tr>
              <tr>
                <td>Included Walk</td>
                <td><?= h((string)$daycareNonMember['included_walks']) ?> × <?= h((string)$daycareNonMember['included_walk_duration_minutes']) ?> min</td>
                <td><?= h((string)$daycareMember['included_walks']) ?> × <?= h((string)$daycareMember['included_walk_duration_minutes']) ?> min</td>
              </tr>
              <tr>
                <td>Additional Walk</td>
                <td>+<?= h(dd_format_money((float)$daycareNonMember['additional_walk_rate'])) ?></td>
                <td>+<?= h(dd_format_money((float)$daycareMember['additional_walk_rate'])) ?></td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="note">
          If the pet parent provides food, there is <strong>no food fee</strong>. Additional walks are billed per 30-minute walk.
        </div>
      </div>

      <div class="section-head">
        <h2>In-Home Sitting</h2>
        <p>Ideal for clients who want care in their apartment or home for a longer block of time.</p>
      </div>

      <div class="pricing-card">
        <h3>Luxury In-Home Sitting</h3>
        <p>Includes one complimentary 30-minute walk, with optional additional walks.</p>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Service</th>
                <th>Non-Member</th>
                <th>Member</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>Up to <?= h((string)$sittingNonMember['hours']) ?> Hours</td>
                <td><?= h(dd_format_money((float)$sittingNonMember['base_rate'])) ?></td>
                <td><?= h(dd_format_money((float)$sittingMember['base_rate'])) ?></td>
              </tr>
              <tr>
                <td>Included Walk</td>
                <td><?= h((string)$sittingNonMember['included_walks']) ?> × <?= h((string)$sittingNonMember['included_walk_duration_minutes']) ?> min</td>
                <td><?= h((string)$sittingMember['included_walks']) ?> × <?= h((string)$sittingMember['included_walk_duration_minutes']) ?> min</td>
              </tr>
              <tr>
                <td>Additional Walk</td>
                <td>+<?= h(dd_format_money((float)$sittingNonMember['additional_walk_rate'])) ?></td>
                <td>+<?= h(dd_format_money((float)$sittingMember['additional_walk_rate'])) ?></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="section-head">
        <h2>Boarding Pricing</h2>
        <p>Boarding pricing remains based on dog size, with deeper member savings once 5 or more nights are booked.</p>
      </div>

      <div class="pricing-card">
        <h3>Boutique Boarding</h3>
        <p>Member 5+ night pricing applies automatically when the booking qualifies.</p>

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
                <td>Small Dog</td>
                <td><?= h(dd_format_money((float)$boardingNonMember['small'])) ?></td>
                <td><?= h(dd_format_money((float)$boardingMember['small'])) ?></td>
                <td class="save"><?= h(dd_format_money((float)$boardingMember5['small'])) ?></td>
              </tr>
              <tr>
                <td>Medium Dog</td>
                <td><?= h(dd_format_money((float)$boardingNonMember['medium'])) ?></td>
                <td><?= h(dd_format_money((float)$boardingMember['medium'])) ?></td>
                <td class="save"><?= h(dd_format_money((float)$boardingMember5['medium'])) ?></td>
              </tr>
              <tr>
                <td>Large Dog</td>
                <td><?= h(dd_format_money((float)$boardingNonMember['large'])) ?></td>
                <td><?= h(dd_format_money((float)$boardingMember['large'])) ?></td>
                <td class="save"><?= h(dd_format_money((float)$boardingMember5['large'])) ?></td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="note">
          Boarding member discount tier activates at <strong>5 or more booked nights</strong>.
        </div>
      </div>
    </div>
  </section>

  <section class="cta">
    <div class="container">
      <div class="cta-panel">
        <h2>Ready to book with the current pricing structure?</h2>
        <p>
          Whether you are booking as a non-member or logging in for member pricing, the booking pages will automatically apply the correct rate for walks, drop-ins, daycare, in-home sitting, group walks, and boarding.
        </p>

        <div class="cta-actions">
          <a href="<?= h($bookingLink) ?>" class="btn btn-primary">Book Premium Care</a>
          <a href="memberships.php" class="btn btn-secondary">Explore Memberships</a>
          <?php if ($isLoggedIn): ?>
            <a href="dashboard.php" class="btn btn-ghost">Go to Dashboard</a>
          <?php else: ?>
            <a href="login.php" class="btn btn-ghost">Member Login</a>
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
      Luxury dog walking, group walks, drop-ins, premium daycare, in-home sitting & boutique boarding in Manhattan.
    </div>
    <div>
      <a href="services.php">Services</a> &nbsp;•&nbsp;
      <a href="memberships.php">Memberships</a> &nbsp;•&nbsp;
      <a href="<?= h($bookingLink) ?>">Book</a>
    </div>
  </div>
</footer>
</body>
</html>