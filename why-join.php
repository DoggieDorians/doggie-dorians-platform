<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/pricing.php';

$pageTitle = 'Why Join | Doggie Dorian’s';
$currentPage = 'why-join';

$isLoggedIn = isset($_SESSION['member_id']) && (int) $_SESSION['member_id'] > 0;

$membershipHref = $isLoggedIn ? 'memberships.php#founders' : 'signup.php';
$bookingHref = $isLoggedIn ? 'book-service.php' : 'non-member-booking.php';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money(float $amount): string
{
    return '$' . number_format($amount, 2);
}

function filterVisibleFeatures(array $features): array
{
    $filtered = [];

    foreach ($features as $feature) {
        $line = (string) $feature;
        if (stripos($line, 'daycare') !== false) {
            continue;
        }
        $filtered[] = $line;
    }

    return $filtered;
}

$walk30Member = dd_get_walk_pricing(30, true);
$walk30NonMember = dd_get_walk_pricing(30, false);

$walk60Member = dd_get_walk_pricing(60, true);
$walk60NonMember = dd_get_walk_pricing(60, false);

$dropInMember = dd_get_drop_in_pricing(true, 1, false);
$dropInNonMember = dd_get_drop_in_pricing(false, 1, false);

$sittingMember = dd_get_sitting_pricing(true, 0);
$sittingNonMember = dd_get_sitting_pricing(false, 0);

$boardingSmallMember = dd_get_boarding_pricing('small', true, 1);
$boardingSmallNonMember = dd_get_boarding_pricing('small', false, 1);

$monthlyWalks = 12;
$monthlyWalkSavings = (($walk30NonMember['unit_price'] ?? 0) - ($walk30Member['unit_price'] ?? 0)) * $monthlyWalks;
$annualWalkSavings = $monthlyWalkSavings * 12;

$walkClub = dd_get_membership_pricing_summary('founder-walk-club');
$careClub = dd_get_membership_pricing_summary('founder-care-club');
$eliteClub = dd_get_membership_pricing_summary('founder-elite-club');

$walkClubPlan = dd_get_membership_plan('founder-walk-club');
$careClubPlan = dd_get_membership_plan('founder-care-club');
$eliteClubPlan = dd_get_membership_plan('founder-elite-club');

$walkClubFeatures = filterVisibleFeatures($walkClubPlan['features'] ?? []);
$careClubFeatures = filterVisibleFeatures($careClubPlan['features'] ?? []);
$eliteClubFeatures = filterVisibleFeatures($eliteClubPlan['features'] ?? []);

$walkClubIncludedWalks = (int) (($walkClub['included_services']['walk_30'] ?? 0));
$careClubIncludedWalks = (int) (($careClub['included_services']['walk_30'] ?? 0));
$eliteClubIncludedWalks = (int) (($eliteClub['included_services']['walk_30'] ?? 0));

$careClubDropIns = (int) (($careClub['included_services']['drop_in'] ?? 0));
$eliteClubDropIns = (int) (($eliteClub['included_services']['drop_in'] ?? 0));
$eliteClubBoardingNights = (int) (($eliteClub['included_services']['boarding_nights'] ?? 0));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?></title>
    <meta name="description" content="Discover why Manhattan dog owners join Doggie Dorian’s for better pricing, founder membership value, priority access, and a more premium dog care experience.">

    <?php if (file_exists(__DIR__ . '/includes/header.php')): ?>
        <?php require_once __DIR__ . '/includes/header.php'; ?>
    <?php endif; ?>

    <style>
        :root {
            --bg: #0a0a0a;
            --bg-soft: #121212;
            --panel: #151515;
            --panel-2: #1c1c1c;
            --line: rgba(212, 175, 55, 0.18);
            --line-strong: rgba(212, 175, 55, 0.35);
            --gold: #d4af37;
            --gold-soft: #f1df9a;
            --text: #f5f1e8;
            --muted: #c7bfa8;
            --muted-2: #9e957d;
            --shadow: 0 20px 60px rgba(0, 0, 0, 0.45);
            --radius-xl: 28px;
            --radius-lg: 22px;
            --radius-md: 16px;
            --max: 1280px;
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            background:
                radial-gradient(circle at top, rgba(212, 175, 55, 0.08), transparent 28%),
                linear-gradient(180deg, #070707 0%, #0d0d0d 35%, #101010 100%);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "Segoe UI", sans-serif;
            line-height: 1.6;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .why-join-page {
            overflow: hidden;
        }

        .container {
            width: min(var(--max), calc(100% - 32px));
            margin: 0 auto;
        }

        .hero {
            position: relative;
            padding: 88px 0 56px;
        }

        .hero::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                linear-gradient(180deg, rgba(212, 175, 55, 0.05), rgba(212, 175, 55, 0)),
                radial-gradient(circle at 85% 15%, rgba(212, 175, 55, 0.08), transparent 24%);
            pointer-events: none;
        }

        .hero-grid {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            gap: 28px;
            align-items: stretch;
        }

        .hero-copy,
        .hero-card,
        .comparison-card,
        .benefit-card,
        .plan-card,
        .faq-item,
        .closing-card {
            background: linear-gradient(180deg, rgba(23, 23, 23, 0.92), rgba(14, 14, 14, 0.96));
            border: 1px solid var(--line);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow);
        }

        .hero-copy,
        .hero-card,
        .comparison-card,
        .benefit-card,
        .plan-card,
        .faq-item,
        .closing-card {
            padding: 28px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 8px 14px;
            border-radius: 999px;
            border: 1px solid var(--line-strong);
            background: rgba(212, 175, 55, 0.08);
            color: var(--gold-soft);
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            margin-bottom: 20px;
        }

        .hero h1 {
            margin: 0 0 18px;
            font-size: clamp(2.4rem, 5vw, 4.6rem);
            line-height: 1.04;
            letter-spacing: -0.04em;
        }

        .hero h1 .gold {
            color: var(--gold);
        }

        .hero p.lead,
        .section-header p,
        .muted,
        .faq-item p,
        .closing-card p,
        .plan-summary,
        .feature-list li,
        .comparison-note,
        .price-meta {
            color: var(--muted);
        }

        .hero p.lead {
            margin: 0 0 26px;
            font-size: 1.08rem;
            max-width: 760px;
        }

        .cta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin: 26px 0 10px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 54px;
            padding: 0 22px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 0.98rem;
            letter-spacing: 0.01em;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease, background 0.2s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
        }

        .btn-primary {
            background: linear-gradient(135deg, #d4af37, #f1df9a);
            color: #17120a;
            box-shadow: 0 14px 30px rgba(212, 175, 55, 0.18);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--line-strong);
            color: var(--text);
        }

        .mini-points {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-top: 24px;
        }

        .mini-point {
            padding: 16px 18px;
            border-radius: var(--radius-md);
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .mini-point strong {
            display: block;
            color: var(--gold-soft);
            font-size: 0.94rem;
            margin-bottom: 4px;
        }

        .mini-point span {
            display: block;
            color: var(--muted-2);
            font-size: 0.92rem;
        }

        .hero-card h2,
        .comparison-card h3,
        .benefit-card h3,
        .plan-card h3,
        .faq-item h3 {
            margin: 0 0 10px;
            letter-spacing: -0.02em;
        }

        .hero-card h2 {
            font-size: 1.35rem;
        }

        .price-highlight,
        .savings-example-grid,
        .comparison-grid,
        .benefits-grid,
        .plans-grid,
        .faq-grid {
            display: grid;
            gap: 18px;
        }

        .price-line,
        .savings-example,
        .comparison-row {
            padding: 15px 16px;
            border-radius: 16px;
            background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.02));
            border: 1px solid rgba(255,255,255,0.05);
        }

        .price-line {
            display: flex;
            justify-content: space-between;
            gap: 16px;
        }

        .price-line span {
            color: var(--muted);
        }

        .price-line strong {
            color: var(--text);
            text-align: right;
        }

        .savings-card {
            padding: 18px;
            border-radius: 18px;
            background: rgba(212, 175, 55, 0.09);
            border: 1px solid var(--line-strong);
        }

        .savings-card small,
        .price-badge {
            display: inline-block;
            color: var(--gold-soft);
            text-transform: uppercase;
            letter-spacing: 0.12em;
            font-size: 0.74rem;
            font-weight: 700;
        }

        .savings-card strong {
            display: block;
            font-size: 2rem;
            line-height: 1.1;
            color: var(--gold);
            margin: 8px 0 6px;
        }

        .savings-card p {
            margin: 0;
            color: var(--muted);
        }

        section {
            padding: 28px 0 34px;
        }

        .section-header {
            max-width: 780px;
            margin-bottom: 26px;
        }

        .section-header h2 {
            margin: 0 0 12px;
            font-size: clamp(1.9rem, 3vw, 3rem);
            letter-spacing: -0.03em;
            line-height: 1.08;
        }

        .benefits-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .benefit-card .icon {
            width: 48px;
            height: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            background: rgba(212, 175, 55, 0.08);
            border: 1px solid var(--line-strong);
            color: var(--gold);
            font-size: 1.25rem;
            margin-bottom: 16px;
        }

        .benefit-card p {
            margin: 0;
            color: var(--muted);
            font-size: 0.97rem;
        }

        .comparison-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .comparison-row strong {
            display: block;
            color: var(--gold-soft);
            margin-bottom: 6px;
            font-size: 0.95rem;
        }

        .comparison-values {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 6px;
        }

        .comparison-value {
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
            font-size: 0.9rem;
            font-weight: 700;
        }

        .comparison-value.member {
            color: var(--gold-soft);
        }

        .comparison-value.non-member {
            color: var(--text);
        }

        .plans-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
            align-items: stretch;
        }

        .plan-card {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .plan-top {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
        }

        .plan-tag {
            display: inline-flex;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(212, 175, 55, 0.08);
            border: 1px solid var(--line-strong);
            color: var(--gold-soft);
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .plan-price {
            font-size: 2rem;
            line-height: 1;
            font-weight: 800;
            color: var(--gold);
        }

        .price-meta {
            font-size: 0.92rem;
        }

        .plan-stat-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .plan-stat {
            padding: 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.05);
        }

        .plan-stat-label {
            color: var(--muted-2);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 6px;
        }

        .plan-stat-value {
            color: var(--text);
            font-size: 1.15rem;
            font-weight: 800;
        }

        .plan-summary {
            margin: 0;
            font-size: 0.96rem;
        }

        .feature-list {
            margin: 0;
            padding-left: 18px;
            display: grid;
            gap: 8px;
        }

        .feature-list li {
            font-size: 0.94rem;
        }

        .faq-grid {
            grid-template-columns: 1fr 1fr;
        }

        .closing-cta {
            padding: 56px 0 90px;
        }

        .closing-card {
            text-align: center;
            padding: 34px;
            border-radius: 30px;
            background:
                linear-gradient(135deg, rgba(212, 175, 55, 0.12), rgba(255,255,255,0.02)),
                linear-gradient(180deg, rgba(18,18,18,0.96), rgba(10,10,10,0.99));
            border: 1px solid var(--line-strong);
        }

        .closing-card h2 {
            margin: 0 0 12px;
            font-size: clamp(2rem, 3vw, 3.2rem);
            letter-spacing: -0.03em;
        }

        .closing-card p {
            margin: 0 auto 24px;
            max-width: 760px;
            font-size: 1.04rem;
        }

        .fine-print {
            margin-top: 16px;
            color: var(--muted-2);
            font-size: 0.86rem;
        }

        @media (max-width: 1120px) {
            .hero-grid,
            .benefits-grid,
            .comparison-grid,
            .plans-grid,
            .faq-grid,
            .mini-points {
                grid-template-columns: 1fr;
            }

            .plan-stat-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 720px) {
            .hero {
                padding-top: 66px;
            }

            .hero-copy,
            .hero-card,
            .comparison-card,
            .benefit-card,
            .plan-card,
            .faq-item,
            .closing-card {
                padding: 22px;
            }

            .container {
                width: min(var(--max), calc(100% - 20px));
            }

            .cta-row {
                flex-direction: column;
            }

            .btn,
            .plan-top {
                width: 100%;
            }

            .price-line {
                flex-direction: column;
            }

            .plan-stat-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<?php if (file_exists(__DIR__ . '/includes/nav.php')): ?>
    <?php require_once __DIR__ . '/includes/nav.php'; ?>
<?php endif; ?>

<main class="why-join-page">
    <section class="hero">
        <div class="container">
            <div class="hero-grid">
                <div class="hero-copy">
                    <div class="eyebrow">Memberships • Manhattan • Premium Care</div>
                    <h1>Why dog owners choose <span class="gold">Doggie Dorian’s</span></h1>
                    <p class="lead">
                        Membership is built for clients who want more than occasional booking. It gives your dog a more consistent routine,
                        gives you stronger pricing on recurring care, and opens the door to founder plans with real built-in value.
                    </p>

                    <div class="cta-row">
                        <a class="btn btn-primary" href="<?= h($membershipHref) ?>">Explore Membership Plans</a>
                        <a class="btn btn-secondary" href="<?= h($bookingHref) ?>">Book a First Service</a>
                    </div>

                    <div class="mini-points">
                        <div class="mini-point">
                            <strong>Better recurring value</strong>
                            <span>Member pricing makes regular care more efficient month after month.</span>
                        </div>
                        <div class="mini-point">
                            <strong>Priority access</strong>
                            <span>Membership supports a smoother, more premium scheduling experience.</span>
                        </div>
                        <div class="mini-point">
                            <strong>Founder plan upside</strong>
                            <span>Founder tiers package real service value into one monthly membership.</span>
                        </div>
                    </div>
                </div>

                <aside class="hero-card">
                    <h2>See the value of joining</h2>

                    <div class="price-highlight">
                        <div class="price-line">
                            <span>30-minute walk</span>
                            <strong>Member <?= money((float) $walk30Member['unit_price']) ?> • Non-member <?= money((float) $walk30NonMember['unit_price']) ?></strong>
                        </div>

                        <div class="price-line">
                            <span>60-minute walk</span>
                            <strong>Member <?= money((float) $walk60Member['unit_price']) ?> • Non-member <?= money((float) $walk60NonMember['unit_price']) ?></strong>
                        </div>

                        <div class="price-line">
                            <span>1-hour drop-in</span>
                            <strong>Member <?= money((float) $dropInMember['unit_price']) ?> • Non-member <?= money((float) $dropInNonMember['unit_price']) ?></strong>
                        </div>

                        <div class="price-line">
                            <span>4-hour sitting session</span>
                            <strong>Member <?= money((float) $sittingMember['unit_price']) ?> • Non-member <?= money((float) $sittingNonMember['unit_price']) ?></strong>
                        </div>

                        <div class="price-line">
                            <span>Small-dog boarding / night</span>
                            <strong>Member <?= money((float) $boardingSmallMember['unit_price']) ?> • Non-member <?= money((float) $boardingSmallNonMember['unit_price']) ?></strong>
                        </div>
                    </div>

                    <div class="savings-card">
                        <small>Example member value</small>
                        <strong><?= money($monthlyWalkSavings) ?>/month</strong>
                        <p>
                            A client booking 12 standard 30-minute walks in a month saves about
                            <strong style="color: var(--gold-soft);"><?= money($monthlyWalkSavings) ?> monthly</strong>,
                            or roughly <strong style="color: var(--gold-soft);"><?= money($annualWalkSavings) ?> yearly</strong>,
                            before factoring in founder-plan advantages and premium access.
                        </p>
                    </div>

                    <div class="savings-example-grid">
                        <div class="savings-example">
                            <span class="price-badge">Founder Walk Club</span>
                            <strong style="display:block; margin:8px 0 6px; color:var(--gold); font-size:1.35rem;">
                                <?= money((float) $walkClub['monthly_savings']) ?> built-in monthly value
                            </strong>
                            <div class="price-meta">
                                <?= $walkClubIncludedWalks ?> included 30-minute walks • Advertised value <?= money((float) $walkClub['advertised_value']) ?> • Monthly price <?= money((float) $walkClub['unit_price']) ?>
                            </div>
                        </div>

                        <div class="savings-example">
                            <span class="price-badge">Founder Care Club</span>
                            <strong style="display:block; margin:8px 0 6px; color:var(--gold); font-size:1.35rem;">
                                <?= money((float) $careClub['monthly_savings']) ?> built-in monthly value
                            </strong>
                            <div class="price-meta">
                                <?= $careClubIncludedWalks ?> walks • <?= $careClubDropIns ?> drop-ins • Advertised value <?= money((float) $careClub['advertised_value']) ?> • Monthly price <?= money((float) $careClub['unit_price']) ?>
                            </div>
                        </div>

                        <div class="savings-example">
                            <span class="price-badge">Founder Elite Club</span>
                            <strong style="display:block; margin:8px 0 6px; color:var(--gold); font-size:1.35rem;">
                                <?= money((float) $eliteClub['monthly_savings']) ?> built-in monthly value
                            </strong>
                            <div class="price-meta">
                                <?= $eliteClubIncludedWalks ?> walks • <?= $eliteClubDropIns ?> drop-ins • <?= $eliteClubBoardingNights ?> boarding nights • Advertised value <?= money((float) $eliteClub['advertised_value']) ?> • Monthly price <?= money((float) $eliteClub['unit_price']) ?>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </section>

    <section>
        <div class="container">
            <div class="section-header">
                <div class="eyebrow">What makes membership better</div>
                <h2>Built for owners who want more than one-off booking</h2>
                <p>
                    Doggie Dorian’s memberships are designed for clients who value consistency, convenience, premium communication,
                    and clearer long-term value than standard public booking.
                </p>
            </div>

            <div class="benefits-grid">
                <div class="benefit-card">
                    <div class="icon">◆</div>
                    <h3>Recurring pricing advantage</h3>
                    <p>
                        Member rates on walks, drop-ins, sitting, and boarding create meaningful savings when your dog needs care regularly.
                    </p>
                </div>

                <div class="benefit-card">
                    <div class="icon">◆</div>
                    <h3>Founder-plan value</h3>
                    <p>
                        Founder memberships package recurring services into one plan with advertised value that exceeds the monthly price.
                    </p>
                </div>

                <div class="benefit-card">
                    <div class="icon">◆</div>
                    <h3>Consistency for your dog</h3>
                    <p>
                        Dogs do better when care feels familiar, dependable, and structured instead of occasional and pieced together.
                    </p>
                </div>

                <div class="benefit-card">
                    <div class="icon">◆</div>
                    <h3>Premium service experience</h3>
                    <p>
                        Membership supports a more polished relationship, better continuity, and a stronger overall client experience.
                    </p>
                </div>

                <div class="benefit-card">
                    <div class="icon">◆</div>
                    <h3>Priority scheduling</h3>
                    <p>
                        Members are positioned more favorably when demand is high, which matters most for recurring clients.
                    </p>
                </div>

                <div class="benefit-card">
                    <div class="icon">◆</div>
                    <h3>Better long-term fit</h3>
                    <p>
                        If your dog needs ongoing walks or regular support, membership is the more worthwhile way to work with Doggie Dorian’s.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <section>
        <div class="container">
            <div class="section-header">
                <div class="eyebrow">Member pricing comparison</div>
                <h2>How membership pricing compares</h2>
                <p>
                    These examples come directly from your pricing structure and show how member pricing improves recurring care costs across the services shown here.
                </p>
            </div>

            <div class="comparison-grid">
                <div class="comparison-card">
                    <h3>Walk pricing</h3>

                    <div class="comparison-row">
                        <strong>30-minute walk</strong>
                        <div class="comparison-values">
                            <div class="comparison-value member">Member <?= money((float) $walk30Member['unit_price']) ?></div>
                            <div class="comparison-value non-member">Non-member <?= money((float) $walk30NonMember['unit_price']) ?></div>
                        </div>
                        <div class="comparison-note">
                            Save <?= money((float) $walk30NonMember['unit_price'] - (float) $walk30Member['unit_price']) ?> each time.
                        </div>
                    </div>

                    <div class="comparison-row">
                        <strong>60-minute walk</strong>
                        <div class="comparison-values">
                            <div class="comparison-value member">Member <?= money((float) $walk60Member['unit_price']) ?></div>
                            <div class="comparison-value non-member">Non-member <?= money((float) $walk60NonMember['unit_price']) ?></div>
                        </div>
                        <div class="comparison-note">
                            Save <?= money((float) $walk60NonMember['unit_price'] - (float) $walk60Member['unit_price']) ?> each time.
                        </div>
                    </div>
                </div>

                <div class="comparison-card">
                    <h3>In-home support pricing</h3>

                    <div class="comparison-row">
                        <strong>1-hour drop-in</strong>
                        <div class="comparison-values">
                            <div class="comparison-value member">Member <?= money((float) $dropInMember['unit_price']) ?></div>
                            <div class="comparison-value non-member">Non-member <?= money((float) $dropInNonMember['unit_price']) ?></div>
                        </div>
                        <div class="comparison-note">
                            Save <?= money((float) $dropInNonMember['unit_price'] - (float) $dropInMember['unit_price']) ?> each time.
                        </div>
                    </div>

                    <div class="comparison-row">
                        <strong>4-hour sitting session</strong>
                        <div class="comparison-values">
                            <div class="comparison-value member">Member <?= money((float) $sittingMember['unit_price']) ?></div>
                            <div class="comparison-value non-member">Non-member <?= money((float) $sittingNonMember['unit_price']) ?></div>
                        </div>
                        <div class="comparison-note">
                            Save <?= money((float) $sittingNonMember['unit_price'] - (float) $sittingMember['unit_price']) ?> each time.
                        </div>
                    </div>
                </div>

                <div class="comparison-card">
                    <h3>Boarding pricing</h3>

                    <div class="comparison-row">
                        <strong>Small-dog boarding / night</strong>
                        <div class="comparison-values">
                            <div class="comparison-value member">Member <?= money((float) $boardingSmallMember['unit_price']) ?></div>
                            <div class="comparison-value non-member">Non-member <?= money((float) $boardingSmallNonMember['unit_price']) ?></div>
                        </div>
                        <div class="comparison-note">
                            Save <?= money((float) $boardingSmallNonMember['unit_price'] - (float) $boardingSmallMember['unit_price']) ?> per night.
                        </div>
                    </div>

                    <div class="comparison-row">
                        <strong>Why this matters</strong>
                        <div class="comparison-note">
                            When care becomes recurring, the member side compounds into stronger monthly and yearly value.
                        </div>
                    </div>
                </div>

                <div class="comparison-card">
                    <h3>Founder membership comparison</h3>

                    <div class="comparison-row">
                        <strong>Founder Walk Club</strong>
                        <div class="comparison-values">
                            <div class="comparison-value member">Price <?= money((float) $walkClub['unit_price']) ?></div>
                            <div class="comparison-value non-member">Value <?= money((float) $walkClub['advertised_value']) ?></div>
                        </div>
                        <div class="comparison-note">
                            Built-in monthly value: <?= money((float) $walkClub['monthly_savings']) ?>.
                        </div>
                    </div>

                    <div class="comparison-row">
                        <strong>Founder Care Club</strong>
                        <div class="comparison-values">
                            <div class="comparison-value member">Price <?= money((float) $careClub['unit_price']) ?></div>
                            <div class="comparison-value non-member">Value <?= money((float) $careClub['advertised_value']) ?></div>
                        </div>
                        <div class="comparison-note">
                            Built-in monthly value: <?= money((float) $careClub['monthly_savings']) ?>.
                        </div>
                    </div>

                    <div class="comparison-row">
                        <strong>Founder Elite Club</strong>
                        <div class="comparison-values">
                            <div class="comparison-value member">Price <?= money((float) $eliteClub['unit_price']) ?></div>
                            <div class="comparison-value non-member">Value <?= money((float) $eliteClub['advertised_value']) ?></div>
                        </div>
                        <div class="comparison-note">
                            Built-in monthly value: <?= money((float) $eliteClub['monthly_savings']) ?>.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section>
        <div class="container">
            <div class="section-header">
                <div class="eyebrow">Founder plan breakdown</div>
                <h2>Compare the membership plans</h2>
                <p>
                    These plans show the monthly price, advertised value, and visible inclusions without displaying daycare on this page.
                </p>
            </div>

            <div class="plans-grid">
                <div class="plan-card">
                    <div class="plan-top">
                        <div>
                            <div class="plan-tag"><?= h((string) ($walkClubPlan['tag'] ?? 'Founder Plan')) ?></div>
                            <h3><?= h((string) ($walkClubPlan['name'] ?? 'Founder Walk Club')) ?></h3>
                        </div>
                        <div class="plan-price"><?= money((float) $walkClub['unit_price']) ?></div>
                    </div>

                    <div class="plan-stat-grid">
                        <div class="plan-stat">
                            <div class="plan-stat-label">Advertised value</div>
                            <div class="plan-stat-value"><?= money((float) $walkClub['advertised_value']) ?></div>
                        </div>
                        <div class="plan-stat">
                            <div class="plan-stat-label">Built-in monthly value</div>
                            <div class="plan-stat-value"><?= money((float) $walkClub['monthly_savings']) ?></div>
                        </div>
                        <div class="plan-stat">
                            <div class="plan-stat-label">Included 30-min walks</div>
                            <div class="plan-stat-value"><?= $walkClubIncludedWalks ?></div>
                        </div>
                        <div class="plan-stat">
                            <div class="plan-stat-label">Slots</div>
                            <div class="plan-stat-value"><?= (int) $walkClub['slots'] ?></div>
                        </div>
                    </div>

                    <p class="plan-summary"><?= h((string) ($walkClubPlan['summary'] ?? '')) ?></p>

                    <ul class="feature-list">
                        <?php foreach ($walkClubFeatures as $feature): ?>
                            <li><?= h($feature) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="plan-card">
                    <div class="plan-top">
                        <div>
                            <div class="plan-tag"><?= h((string) ($careClubPlan['tag'] ?? 'Founder Plan')) ?></div>
                            <h3><?= h((string) ($careClubPlan['name'] ?? 'Founder Care Club')) ?></h3>
                        </div>
                        <div class="plan-price"><?= money((float) $careClub['unit_price']) ?></div>
                    </div>

                    <div class="plan-stat-grid">
                        <div class="plan-stat">
                            <div class="plan-stat-label">Advertised value</div>
                            <div class="plan-stat-value"><?= money((float) $careClub['advertised_value']) ?></div>
                        </div>
                        <div class="plan-stat">
                            <div class="plan-stat-label">Built-in monthly value</div>
                            <div class="plan-stat-value"><?= money((float) $careClub['monthly_savings']) ?></div>
                        </div>
                        <div class="plan-stat">
                            <div class="plan-stat-label">Included walks</div>
                            <div class="plan-stat-value"><?= $careClubIncludedWalks ?></div>
                        </div>
                        <div class="plan-stat">
                            <div class="plan-stat-label">Included drop-ins</div>
                            <div class="plan-stat-value"><?= $careClubDropIns ?></div>
                        </div>
                    </div>

                    <p class="plan-summary"><?= h((string) ($careClubPlan['summary'] ?? '')) ?></p>

                    <ul class="feature-list">
                        <?php foreach ($careClubFeatures as $feature): ?>
                            <li><?= h($feature) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="plan-card">
                    <div class="plan-top">
                        <div>
                            <div class="plan-tag"><?= h((string) ($eliteClubPlan['tag'] ?? 'Founder Plan')) ?></div>
                            <h3><?= h((string) ($eliteClubPlan['name'] ?? 'Founder Elite Club')) ?></h3>
                        </div>
                        <div class="plan-price"><?= money((float) $eliteClub['unit_price']) ?></div>
                    </div>

                    <div class="plan-stat-grid">
                        <div class="plan-stat">
                            <div class="plan-stat-label">Advertised value</div>
                            <div class="plan-stat-value"><?= money((float) $eliteClub['advertised_value']) ?></div>
                        </div>
                        <div class="plan-stat">
                            <div class="plan-stat-label">Built-in monthly value</div>
                            <div class="plan-stat-value"><?= money((float) $eliteClub['monthly_savings']) ?></div>
                        </div>
                        <div class="plan-stat">
                            <div class="plan-stat-label">Included walks</div>
                            <div class="plan-stat-value"><?= $eliteClubIncludedWalks ?></div>
                        </div>
                        <div class="plan-stat">
                            <div class="plan-stat-label">Boarding nights</div>
                            <div class="plan-stat-value"><?= $eliteClubBoardingNights ?></div>
                        </div>
                    </div>

                    <p class="plan-summary"><?= h((string) ($eliteClubPlan['summary'] ?? '')) ?></p>

                    <ul class="feature-list">
                        <?php foreach ($eliteClubFeatures as $feature): ?>
                            <li><?= h($feature) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <section>
        <div class="container">
            <div class="section-header">
                <div class="eyebrow">Questions clients ask</div>
                <h2>Membership FAQ</h2>
                <p>
                    The goal is to make it easy for clients to understand whether standard member pricing or a founder plan is the best fit.
                </p>
            </div>

            <div class="faq-grid">
                <div class="faq-item">
                    <h3>Who should join?</h3>
                    <p>
                        Membership is ideal for owners who need recurring walks, regular in-home support, or a stronger long-term care setup.
                    </p>
                </div>

                <div class="faq-item">
                    <h3>What makes founder plans different?</h3>
                    <p>
                        Founder plans combine recurring service value, premium perks, and locked-in pricing into one structured monthly membership.
                    </p>
                </div>

                <div class="faq-item">
                    <h3>Is membership worth it if I only book sometimes?</h3>
                    <p>
                        Public booking may still make sense for occasional needs. Membership becomes much stronger when care is part of your regular routine.
                    </p>
                </div>

                <div class="faq-item">
                    <h3>Why show value against the monthly plan price?</h3>
                    <p>
                        It helps clients see the difference between what the included services are worth and what they actually pay each month.
                    </p>
                </div>

                <div class="faq-item">
                    <h3>Can I start with one service first?</h3>
                    <p>
                        Yes. Many clients begin with a booking, then move into membership once they see the value and want more consistency.
                    </p>
                </div>

                <div class="faq-item">
                    <h3>What is the biggest advantage besides price?</h3>
                    <p>
                        Consistency, premium service structure, and a smoother ongoing relationship built around your dog’s routine.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <section class="closing-cta">
        <div class="container">
            <div class="closing-card">
                <div class="eyebrow">Join the premium side of dog care</div>
                <h2>Choose the membership path that fits your routine</h2>
                <p>
                    Whether you want stronger member pricing or one of the founder plans with built-in value, Doggie Dorian’s memberships are designed
                    to make recurring care feel more worthwhile, more premium, and more consistent.
                </p>

                <div class="cta-row" style="justify-content:center;">
                    <a class="btn btn-primary" href="<?= h($membershipHref) ?>">Explore Membership Plans</a>
                    <a class="btn btn-secondary" href="<?= h($bookingHref) ?>">Book Your First Service</a>
                </div>

                <div class="fine-print">
                    Premium dog care. Clearer value. Better recurring pricing. A more thoughtful experience for both dog and owner.
                </div>
            </div>
        </div>
    </section>
</main>

<?php if (file_exists(__DIR__ . '/includes/footer.php')): ?>
    <?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php endif; ?>
</body>
</html>