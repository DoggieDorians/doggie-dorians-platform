<?php
declare(strict_types=1);

session_start();

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirectTo(string $url): never
{
    header('Location: ' . $url);
    exit;
}

$isLoggedIn = isset($_SESSION['member_id']) || isset($_SESSION['user_id']) || isset($_SESSION['user']) || isset($_SESSION['email']);

if (!$isLoggedIn) {
    $redirect = rawurlencode('memberships.php');
    redirectTo('login.php?redirect=' . $redirect);
}

$currentUserName = '';
foreach (['member_name', 'full_name', 'name', 'user_name', 'email'] as $sessionKey) {
    if (!empty($_SESSION[$sessionKey]) && is_string($_SESSION[$sessionKey])) {
        $currentUserName = trim($_SESSION[$sessionKey]);
        break;
    }
}

$tosVersion = '2026-04-07';

$plans = [
    [
        'slug' => 'founder-walk-club',
        'name' => 'Founder Walk Club',
        'price' => 250,
        'value' => 300,
        'tag' => 'Founding Walk Access',
        'summary' => 'Built for clients who mainly want recurring walks, premium booking access, and a cleaner high-touch membership experience.',
        'features' => [
            '12 included 30-minute walks each month',
            'Unused walks roll over into the following month only',
            'Priority scheduling access',
            'Reserved availability during peak demand',
            'Founder-only private contact path',
            '$250 annual service credit issued quarterly',
            'Locked-in founder pricing',
        ],
    ],
    [
        'slug' => 'founder-care-club',
        'name' => 'Founder Care Club',
        'price' => 499,
        'value' => 650,
        'tag' => 'Most Popular',
        'summary' => 'For clients who want stronger recurring support across walks, daycare, and drop-ins with founder-level priority.',
        'features' => [
            '16 included 30-minute walks each month',
            '2 included daycare days each month',
            '2 included drop-in visits each month',
            'Unused walks roll over into the following month only',
            '10% off boarding bookings',
            '$500 annual service credit issued quarterly',
            'Higher founder scheduling priority',
        ],
    ],
    [
        'slug' => 'founder-elite-club',
        'name' => 'Founder Elite Club',
        'price' => 899,
        'value' => 1100,
        'tag' => 'Highest Tier',
        'summary' => 'Your most exclusive founder package for premium recurring care, elevated flexibility, and top-tier access.',
        'features' => [
            '20 included 30-minute walks each month',
            '4 included daycare days each month',
            '4 included drop-in visits each month',
            '3 complimentary boarding nights',
            '20% off additional boarding bookings',
            '$750 annual service credit issued quarterly',
            'Highest founder scheduling priority',
        ],
    ],
];

$plansBySlug = [];
foreach ($plans as $plan) {
    $plansBySlug[$plan['slug']] = $plan;
}

$error = '';
$success = '';
$selectedPlanSlug = (string)($_GET['plan'] ?? '');
$checkoutReady = false;
$checkoutPayload = $_SESSION['pending_membership_checkout'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedPlanSlug = trim((string)($_POST['plan'] ?? ''));
    $tosAccepted = isset($_POST['agree_tos']) && (string)$_POST['agree_tos'] === '1';

    if (!isset($plansBySlug[$selectedPlanSlug])) {
        $error = 'Please choose a membership before continuing.';
    } elseif (!$tosAccepted) {
        $error = 'You must agree to the Membership Terms of Service before continuing.';
    } else {
        $plan = $plansBySlug[$selectedPlanSlug];

        $_SESSION['pending_membership_checkout'] = [
            'type' => 'membership',
            'plan_slug' => $plan['slug'],
            'plan_name' => $plan['name'],
            'monthly_price' => (int)$plan['price'],
            'tos_version' => $tosVersion,
            'tos_accepted' => true,
            'tos_accepted_at' => date('c'),
            'started_from' => 'memberships.php',
        ];

        $checkoutPayload = $_SESSION['pending_membership_checkout'];
        $checkoutReady = true;
        $success = 'Membership selected and Terms accepted. This account is now ready for Stripe checkout wiring in the next step.';
    }
}

if (!$checkoutReady && is_array($checkoutPayload) && !empty($checkoutPayload['plan_slug']) && isset($plansBySlug[$checkoutPayload['plan_slug']])) {
    $selectedPlanSlug = (string)$checkoutPayload['plan_slug'];
    $checkoutReady = true;
}

$selectedPlan = ($selectedPlanSlug !== '' && isset($plansBySlug[$selectedPlanSlug]))
    ? $plansBySlug[$selectedPlanSlug]
    : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Memberships | Doggie Dorian’s</title>
    <meta name="description" content="Choose a Doggie Dorian’s membership, review premium founder plans, and accept the membership terms before checkout.">
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
            --danger: #ffcbc0;
            --danger-bg: rgba(201, 92, 71, 0.14);
            --success: #daf2c8;
            --success-bg: rgba(90, 148, 73, 0.14);
            --white: #ffffff;
            --shadow: 0 22px 65px rgba(0,0,0,0.38);
            --max: 1280px;
            --radius: 28px;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: "Georgia", "Times New Roman", serif;
            background:
                radial-gradient(circle at top, rgba(215,178,106,0.10), transparent 25%),
                linear-gradient(180deg, #06070a 0%, #0b0d12 45%, #06070a 100%);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        button,
        input {
            font: inherit;
        }

        .container {
            width: min(var(--max), calc(100% - 32px));
            margin: 0 auto;
        }

        .site-header {
            position: sticky;
            top: 0;
            z-index: 1000;
            backdrop-filter: blur(18px);
            background: rgba(7, 8, 11, 0.78);
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }

        .nav-wrap {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 18px 0;
            flex-wrap: wrap;
        }

        .brand {
            font-size: 1.14rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-weight: 700;
            color: var(--white);
        }

        .nav-links,
        .nav-actions {
            display: flex;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
        }

        .nav-links a {
            color: var(--muted);
            font-size: 0.95rem;
            transition: color 0.22s ease;
        }

        .nav-links a:hover,
        .nav-links a.active {
            color: var(--gold);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 50px;
            padding: 0 22px;
            border-radius: 999px;
            border: 1px solid transparent;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease, background 0.18s ease;
            white-space: nowrap;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-gold {
            background: linear-gradient(135deg, var(--gold), var(--gold-light));
            color: #171105;
            box-shadow: 0 16px 38px rgba(215,181,109,.28);
        }

        .btn-light {
            background: rgba(255,255,255,0.05);
            border-color: rgba(255,255,255,0.12);
            color: var(--text);
        }

        .btn-ghost {
            background: transparent;
            border-color: rgba(255,255,255,0.10);
            color: var(--muted);
        }

        .btn-block {
            width: 100%;
        }

        .page {
            padding: 34px 0 72px;
        }

        .hero {
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            gap: 24px;
            margin-bottom: 26px;
        }

        .card {
            background: linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.03));
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 28px;
            box-shadow: var(--shadow);
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 9px 15px;
            border-radius: 999px;
            border: 1px solid rgba(215,178,106,0.24);
            background: rgba(215,178,106,0.08);
            color: var(--gold-light);
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 16px;
        }

        .eyebrow::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--gold);
            box-shadow: 0 0 14px rgba(215,178,106,0.95);
        }

        h1, h2, h3 {
            line-height: 1.12;
        }

        h1 {
            font-size: clamp(2rem, 3vw, 3.4rem);
            margin-bottom: 16px;
        }

        h2 {
            font-size: clamp(1.55rem, 2vw, 2.2rem);
            margin-bottom: 12px;
        }

        h3 {
            font-size: 1.18rem;
            margin-bottom: 10px;
        }

        .sub,
        .lead {
            color: var(--muted);
            font-size: 1.02rem;
        }

        .lead {
            max-width: 760px;
        }

        .hero-pills,
        .mini-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 20px;
        }

        .pill {
            padding: 10px 14px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.04);
            color: var(--text);
            font-size: 0.92rem;
        }

        .welcome-list {
            display: grid;
            gap: 14px;
            margin-top: 18px;
        }

        .welcome-item {
            padding: 14px 16px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
        }

        .section-title {
            margin: 12px 0 18px;
        }

        .plans-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 22px;
            margin-top: 20px;
        }

        .plan-card {
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 18px;
            min-height: 100%;
        }

        .plan-card.selected {
            border-color: rgba(215,178,106,0.45);
            box-shadow: 0 26px 70px rgba(215,178,106,0.10), var(--shadow);
        }

        .plan-badge {
            display: inline-flex;
            align-self: flex-start;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.10);
            color: var(--gold-light);
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .plan-price {
            display: flex;
            align-items: baseline;
            gap: 10px;
        }

        .plan-price strong {
            font-size: 2.3rem;
            color: var(--white);
        }

        .plan-price span {
            color: var(--muted);
        }

        .value-note {
            color: var(--soft);
            font-size: 0.96rem;
        }

        .feature-list {
            list-style: none;
            display: grid;
            gap: 10px;
        }

        .feature-list li {
            position: relative;
            padding-left: 22px;
            color: var(--muted);
        }

        .feature-list li::before {
            content: "•";
            position: absolute;
            left: 0;
            top: 0;
            color: var(--gold);
            font-size: 1.2rem;
            line-height: 1;
        }

        .selection-panel {
            margin-top: 28px;
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 24px;
        }

        .selection-summary {
            display: grid;
            gap: 18px;
        }

        .summary-box,
        .tos-box,
        .ready-box {
            padding: 20px;
            border-radius: 22px;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.04);
        }

        .summary-rows {
            display: grid;
            gap: 10px;
            margin-top: 10px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            color: var(--muted);
        }

        .summary-row strong {
            color: var(--white);
        }

        .tos-box label {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            color: var(--muted);
            cursor: pointer;
        }

        .tos-box input[type="checkbox"] {
            margin-top: 5px;
            width: 18px;
            height: 18px;
            accent-color: #d7b26a;
            flex: 0 0 auto;
        }

        .tos-link {
            color: var(--gold-light);
            text-decoration: underline;
            text-underline-offset: 3px;
        }

        .stack {
            display: grid;
            gap: 16px;
        }

        .flash {
            margin-bottom: 22px;
            padding: 16px 18px;
            border-radius: 18px;
            font-weight: 700;
        }

        .flash.error {
            background: var(--danger-bg);
            border: 1px solid rgba(201, 92, 71, 0.30);
            color: var(--danger);
        }

        .flash.success {
            background: var(--success-bg);
            border: 1px solid rgba(90, 148, 73, 0.30);
            color: var(--success);
        }

        .helper {
            color: var(--soft);
            font-size: 0.94rem;
        }

        .ready-box {
            background:
                linear-gradient(180deg, rgba(215,178,106,0.10), rgba(255,255,255,0.03));
            border-color: rgba(215,178,106,0.22);
        }

        .ready-meta {
            display: grid;
            gap: 10px;
            margin-top: 14px;
            color: var(--muted);
        }

        .footer {
            margin-top: 34px;
            display: flex;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            color: var(--soft);
            font-size: 0.92rem;
        }

        .footer-links {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }

        .footer-links a:hover {
            color: var(--gold-light);
        }

        @media (max-width: 1100px) {
            .hero,
            .selection-panel,
            .plans-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 700px) {
            .page {
                padding: 22px 0 54px;
            }

            .card {
                padding: 20px;
                border-radius: 22px;
            }

            .nav-wrap {
                align-items: flex-start;
            }

            .nav-links,
            .nav-actions {
                width: 100%;
            }

            .nav-actions .btn {
                width: 100%;
            }

            .summary-row {
                flex-direction: column;
                gap: 4px;
            }

            .footer {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <header class="site-header">
        <div class="container nav-wrap">
            <a href="index.php" class="brand">Doggie Dorian’s</a>

            <nav class="nav-links">
                <a href="index.php">Home</a>
                <a href="pricing.php">Pricing</a>
                <a href="memberships.php" class="active">Memberships</a>
                <a href="group-walks.php">Group Walks</a>
                <a href="contact.php">Contact</a>
            </nav>

            <div class="nav-actions">
                <a href="dashboard.php" class="btn btn-light">Dashboard</a>
                <a href="book-service.php" class="btn btn-gold">Book Services</a>
            </div>
        </div>
    </header>

    <main class="page">
        <div class="container">
            <?php if ($error !== ''): ?>
                <div class="flash error"><?php echo h($error); ?></div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="flash success"><?php echo h($success); ?></div>
            <?php endif; ?>

            <section class="hero">
                <div class="card">
                    <div class="eyebrow">Members Only Access</div>
                    <h1>Choose your membership before checkout goes live.</h1>
                    <p class="lead">
                        This page is now locked to logged-in clients only. Choose your founder membership,
                        review what is included, and accept the Membership Terms before the Stripe step is connected.
                    </p>

                    <div class="hero-pills">
                        <div class="pill">Login required</div>
                        <div class="pill">Terms required before checkout</div>
                        <div class="pill">Stripe-ready session flow</div>
                        <div class="pill">Luxury member experience</div>
                    </div>
                </div>

                <div class="card">
                    <div class="eyebrow">Account Status</div>
                    <h2>Welcome<?php echo $currentUserName !== '' ? ', ' . h($currentUserName) : ''; ?></h2>
                    <p class="sub">
                        Your account is signed in and eligible to continue through the membership selection flow.
                    </p>

                    <div class="welcome-list">
                        <div class="welcome-item">
                            <strong>Step 1:</strong> choose the membership that matches your care routine.
                        </div>
                        <div class="welcome-item">
                            <strong>Step 2:</strong> agree to the Membership Terms of Service.
                        </div>
                        <div class="welcome-item">
                            <strong>Step 3:</strong> continue to Stripe when payment wiring is added next.
                        </div>
                    </div>
                </div>
            </section>

            <section class="stack">
                <div class="section-title">
                    <div class="eyebrow">Founder Collection</div>
                    <h2>Membership options</h2>
                    <p class="sub">
                        Premium recurring access, structured benefits, and a cleaner member booking experience.
                    </p>
                </div>

                <div class="plans-grid">
                    <?php foreach ($plans as $plan): ?>
                        <?php $isSelected = $selectedPlan !== null && $selectedPlan['slug'] === $plan['slug']; ?>
                        <div class="card plan-card<?php echo $isSelected ? ' selected' : ''; ?>" id="<?php echo h($plan['slug']); ?>">
                            <div class="plan-badge"><?php echo h($plan['tag']); ?></div>

                            <div>
                                <h3><?php echo h($plan['name']); ?></h3>
                                <p class="sub"><?php echo h($plan['summary']); ?></p>
                            </div>

                            <div class="plan-price">
                                <strong>$<?php echo number_format((int)$plan['price']); ?></strong>
                                <span>/ month</span>
                            </div>

                            <div class="value-note">Estimated membership value: $<?php echo number_format((int)$plan['value']); ?>+</div>

                            <ul class="feature-list">
                                <?php foreach ($plan['features'] as $feature): ?>
                                    <li><?php echo h($feature); ?></li>
                                <?php endforeach; ?>
                            </ul>

                            <a class="btn <?php echo $isSelected ? 'btn-gold' : 'btn-light'; ?> btn-block" href="memberships.php?plan=<?php echo rawurlencode($plan['slug']); ?>#selection">
                                <?php echo $isSelected ? 'Selected Plan' : 'Choose This Plan'; ?>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="selection-panel" id="selection">
                <div class="card selection-summary">
                    <div class="eyebrow">Membership Checkout Prep</div>
                    <h2><?php echo $selectedPlan ? 'Review your selected membership' : 'Choose a membership to continue'; ?></h2>
                    <p class="sub">
                        This section prepares the exact membership choice and Terms acceptance that Stripe checkout will use later.
                    </p>

                    <div class="summary-box">
                        <?php if ($selectedPlan): ?>
                            <h3><?php echo h($selectedPlan['name']); ?></h3>
                            <div class="summary-rows">
                                <div class="summary-row">
                                    <span>Membership</span>
                                    <strong><?php echo h($selectedPlan['name']); ?></strong>
                                </div>
                                <div class="summary-row">
                                    <span>Recurring price</span>
                                    <strong>$<?php echo number_format((int)$selectedPlan['price']); ?>/month</strong>
                                </div>
                                <div class="summary-row">
                                    <span>Terms version</span>
                                    <strong><?php echo h($tosVersion); ?></strong>
                                </div>
                                <div class="summary-row">
                                    <span>Status</span>
                                    <strong><?php echo $checkoutReady ? 'Ready for checkout integration' : 'Waiting for terms acceptance'; ?></strong>
                                </div>
                            </div>

                            <div class="mini-pills">
                                <div class="pill">Recurring billing later via Stripe</div>
                                <div class="pill">TOS acceptance stored in session</div>
                                <div class="pill">Plan locked for next step</div>
                            </div>
                        <?php else: ?>
                            <p class="sub">
                                Pick one of the founder plans above. Once selected, the terms agreement box and continue action will activate the pre-checkout flow.
                            </p>
                        <?php endif; ?>
                    </div>

                    <?php if ($checkoutReady && is_array($checkoutPayload) && $selectedPlan): ?>
                        <div class="ready-box">
                            <h3>Checkout session prep saved</h3>
                            <p class="sub">
                                The selected membership and Terms acceptance are already stored in the session, so the Stripe step can plug directly into this flow next.
                            </p>

                            <div class="ready-meta">
                                <div><strong>Plan:</strong> <?php echo h((string)$checkoutPayload['plan_name']); ?></div>
                                <div><strong>TOS Accepted:</strong> Yes</div>
                                <div><strong>TOS Version:</strong> <?php echo h((string)$checkoutPayload['tos_version']); ?></div>
                                <div><strong>Accepted At:</strong> <?php echo h((string)$checkoutPayload['tos_accepted_at']); ?></div>
                            </div>

                            <div style="margin-top:18px;">
                                <a href="dashboard.php" class="btn btn-light">Return to Dashboard</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="eyebrow">Terms Agreement</div>
                    <h2>Accept Terms before checkout</h2>
                    <p class="sub">
                        Before payment is connected, every member must review and accept the Membership Terms of Service.
                    </p>

                    <form method="post" action="memberships.php<?php echo $selectedPlan ? '?plan=' . rawurlencode($selectedPlan['slug']) : ''; ?>#selection" class="stack" style="margin-top: 18px;">
                        <input type="hidden" name="plan" value="<?php echo h($selectedPlan['slug'] ?? ''); ?>">

                        <div class="tos-box">
                            <label for="agree_tos">
                                <input type="checkbox" id="agree_tos" name="agree_tos" value="1" <?php echo $checkoutReady ? 'checked' : ''; ?>>
                                <span>
                                    I agree to the <a class="tos-link" href="tos.php" target="_blank" rel="noopener noreferrer">Doggie Dorian’s Membership Terms of Service</a>,
                                    including recurring billing terms, membership usage rules, cancellations, and founder membership conditions.
                                </span>
                            </label>
                        </div>

                        <div class="helper">
                            Selected plan:
                            <strong><?php echo $selectedPlan ? h($selectedPlan['name']) : 'None selected yet'; ?></strong>
                        </div>

                        <button type="submit" class="btn btn-gold btn-block">
                            Continue to Checkout Setup
                        </button>

                        <div class="helper">
                            Right now this stores the member’s selected plan + Terms acceptance safely in session so Stripe can be added next without rebuilding the flow.
                        </div>
                    </form>
                </div>
            </section>

            <footer class="footer">
                <div>
                    © <?php echo date('Y'); ?> Doggie Dorian’s — premium memberships, cleaner checkout flow, and a stronger luxury client experience.
                </div>

                <div class="footer-links">
                    <a href="tos.php">Terms of Service</a>
                    <a href="privacy-policy.php">Privacy Policy</a>
                    <a href="legal-notice.php">Legal Notice</a>
                </div>
            </footer>
        </div>
    </main>
</body>
</html>