<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/member_config.php';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function normalize_mode(string $value): string
{
    $value = strtolower(trim($value));

    return match ($value) {
        'custom_plan' => 'custom_plan',
        'service_overage' => 'service_overage',
        'non_member' => 'non_member',
        'membership' => 'membership',
        default => '',
    };
}

function current_member_id_safe(?PDO $pdo): int
{
    if (!$pdo instanceof PDO) {
        return 0;
    }

    if (!function_exists('currentMember')) {
        return 0;
    }

    try {
        $member = currentMember($pdo);
        return (int) ($member['id'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    } catch (Exception $e) {
        return 0;
    }
}

$pdoInstance = (isset($pdo) && $pdo instanceof PDO) ? $pdo : null;
$mode = normalize_mode((string) ($_GET['mode'] ?? ''));

/*
|--------------------------------------------------------------------------
| Defaults
|--------------------------------------------------------------------------
*/
$pageTitle = 'Checkout Cancelled';
$headline = 'No payment was completed';
$bodyText = 'Your checkout was canceled before Stripe confirmed payment.';
$infoValue = 'Payment remains unpaid';

$primaryHref = 'index.php';
$primaryLabel = 'Return Home';
$secondaryHref = 'index.php';
$secondaryLabel = 'Go Back';

$topLinks = [
    ['href' => 'index.php', 'label' => 'Home'],
    ['href' => 'contact.php', 'label' => 'Contact'],
];

/*
|--------------------------------------------------------------------------
| CUSTOM PLAN CANCEL
|--------------------------------------------------------------------------
*/
if ($mode === 'custom_plan') {
    $memberId = current_member_id_safe($pdoInstance);

    $headline = 'Custom plan checkout cancelled';
    $bodyText = 'Your custom plan checkout was canceled before Stripe confirmed payment.';
    $infoValue = 'Custom plan remains unpaid';

    if ($memberId <= 0) {
        $primaryHref = 'login.php';
        $primaryLabel = 'Member Login';
        $secondaryHref = 'customize-plan.php';
        $secondaryLabel = 'Back to Plans';
        $topLinks = [
            ['href' => 'login.php', 'label' => 'Login'],
            ['href' => 'customize-plan.php', 'label' => 'Plans'],
        ];
    } else {
        $planId = (int) ($_GET['plan_id'] ?? 0);

        $primaryHref = $planId > 0
            ? 'payment-portal.php?plan_id=' . $planId
            : 'customize-plan.php';

        $primaryLabel = 'Return to Payment Portal';
        $secondaryHref = 'dashboard.php';
        $secondaryLabel = 'Go to Dashboard';

        $topLinks = [
            ['href' => 'dashboard.php', 'label' => 'Dashboard'],
            ['href' => 'customize-plan.php', 'label' => 'Plans'],
        ];
    }
}

/*
|--------------------------------------------------------------------------
| MEMBER OVERAGE CANCEL
|--------------------------------------------------------------------------
*/
if ($mode === 'service_overage') {
    $memberId = current_member_id_safe($pdoInstance);

    $headline = 'Member overage checkout cancelled';
    $bodyText = 'Your member overage checkout was canceled before Stripe confirmed payment.';
    $infoValue = 'Overage balance remains unpaid';

    if ($memberId <= 0) {
        $primaryHref = 'login.php';
        $primaryLabel = 'Member Login';
        $secondaryHref = 'book-service.php';
        $secondaryLabel = 'Back to Booking';
        $topLinks = [
            ['href' => 'login.php', 'label' => 'Login'],
            ['href' => 'book-service.php', 'label' => 'Book Service'],
        ];
    } else {
        $bookingId = (int) ($_GET['booking_id'] ?? 0);

        $primaryHref = 'payment-portal.php';
        $primaryLabel = 'Return to Payment Portal';

        if ($bookingId > 0) {
            $primaryHref .= '?booking_id=' . $bookingId;
        }

        $secondaryHref = 'my-bookings.php';
        $secondaryLabel = 'View Bookings';

        $topLinks = [
            ['href' => 'dashboard.php', 'label' => 'Dashboard'],
            ['href' => 'my-bookings.php', 'label' => 'My Bookings'],
        ];
    }
}

/*
|--------------------------------------------------------------------------
| NON-MEMBER CANCEL
|--------------------------------------------------------------------------
*/
if ($mode === 'non_member') {
    $requestId = (int) ($_GET['request_id'] ?? 0);

    $headline = 'Non-member checkout cancelled';
    $bodyText = 'Your non-member booking checkout was canceled before Stripe confirmed payment.';
    $infoValue = 'Booking request remains unpaid';

    $primaryHref = 'non-member-payment-portal.php';
    $primaryLabel = 'Return to Payment Portal';

    if ($requestId > 0) {
        $primaryHref .= '?request_id=' . $requestId;
    }

    $secondaryHref = 'non-member-booking.php';
    $secondaryLabel = 'Back to Booking';

    $topLinks = [
        ['href' => 'non-member-booking.php', 'label' => 'Booking'],
        ['href' => 'contact.php', 'label' => 'Contact'],
    ];
}

/*
|--------------------------------------------------------------------------
| MEMBERSHIP CANCEL
|--------------------------------------------------------------------------
*/
if ($mode === 'membership') {
    $memberId = current_member_id_safe($pdoInstance);
    $selectedPlan = trim((string) ($_GET['plan'] ?? ''));

    $headline = 'Membership checkout cancelled';
    $bodyText = 'Your founder membership checkout was canceled before Stripe confirmed payment.';
    $infoValue = 'Membership remains inactive';

    $primaryHref = 'memberships.php';
    if ($selectedPlan !== '') {
        $primaryHref .= '?plan=' . rawurlencode($selectedPlan) . '#selection';
    }

    $primaryLabel = 'Return to Memberships';

    if ($memberId > 0) {
        $secondaryHref = 'dashboard.php';
        $secondaryLabel = 'Go to Dashboard';
        $topLinks = [
            ['href' => 'dashboard.php', 'label' => 'Dashboard'],
            ['href' => 'memberships.php', 'label' => 'Memberships'],
        ];
    } else {
        $secondaryHref = 'login.php';
        $secondaryLabel = 'Member Login';
        $topLinks = [
            ['href' => 'login.php', 'label' => 'Login'],
            ['href' => 'memberships.php', 'label' => 'Memberships'],
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?> | Doggie Dorian’s</title>
    <style>
        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            font-family: Georgia, "Times New Roman", serif;
            background:
                radial-gradient(circle at top, rgba(212, 175, 55, 0.14), transparent 35%),
                linear-gradient(180deg, #05060a 0%, #090b12 45%, #04050a 100%);
            color: #f4f1ea;
        }

        .cancel-page {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .topbar {
            width: 100%;
            padding: 20px 22px 0;
        }

        .topbar-inner {
            max-width: 1120px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .brand {
            color: #f4f1ea;
            text-decoration: none;
            font-size: 1.1rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .brand span {
            color: #d4af37;
        }

        .top-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .top-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: #f4f1ea;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.04);
            padding: 10px 14px;
            border-radius: 999px;
            font-size: 0.95rem;
            transition: 0.2s ease;
        }

        .top-link:hover {
            background: rgba(255,255,255,0.08);
        }

        .cancel-main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 34px 18px 72px;
        }

        .cancel-shell {
            width: 100%;
            max-width: 760px;
        }

        .cancel-card {
            position: relative;
            overflow: hidden;
            background: linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.03));
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 28px;
            padding: 34px 28px 30px;
            box-shadow: 0 24px 70px rgba(0,0,0,0.40);
            backdrop-filter: blur(8px);
        }

        .cancel-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(212,175,55,0.10), transparent 35%);
            pointer-events: none;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 14px;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 18px;
            position: relative;
            z-index: 1;
            background: rgba(255,92,92,0.12);
            color: #ffb3b3;
            border: 1px solid rgba(255,92,92,0.22);
        }

        .cancel-title {
            position: relative;
            z-index: 1;
            margin: 0 0 10px;
            font-size: 2.35rem;
            line-height: 1.08;
            color: #fff;
        }

        .cancel-text {
            position: relative;
            z-index: 1;
            margin: 0;
            color: rgba(244,241,234,0.78);
            line-height: 1.7;
            font-size: 1.02rem;
        }

        .info-box {
            position: relative;
            z-index: 1;
            margin-top: 24px;
            padding: 22px;
            border-radius: 22px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            text-align: center;
        }

        .info-label {
            font-size: 0.9rem;
            color: rgba(244,241,234,0.62);
            text-transform: uppercase;
            letter-spacing: 0.10em;
        }

        .info-value {
            margin-top: 10px;
            font-size: 1.2rem;
            font-weight: 800;
            color: #f2d471;
            line-height: 1.2;
        }

        .cancel-actions {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 28px;
        }

        .btn-primary,
        .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 180px;
            padding: 14px 20px;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 700;
            transition: 0.2s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, #e2c48d, #b9975b);
            color: #0b0b10;
            box-shadow: 0 10px 24px rgba(185,151,91,0.22);
        }

        .btn-primary:hover {
            filter: brightness(1.04);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: rgba(255,255,255,0.06);
            color: #ffffff;
            border: 1px solid rgba(255,255,255,0.08);
        }

        .btn-secondary:hover {
            background: rgba(255,255,255,0.10);
        }

        @media (max-width: 680px) {
            .topbar {
                padding: 16px 14px 0;
            }

            .topbar-inner {
                flex-direction: column;
                align-items: stretch;
            }

            .brand {
                text-align: center;
            }

            .top-actions {
                justify-content: center;
            }

            .cancel-main {
                align-items: flex-start;
                padding: 24px 14px 56px;
            }

            .cancel-card {
                padding: 26px 18px 24px;
                border-radius: 22px;
            }

            .cancel-title {
                font-size: 1.95rem;
                text-align: center;
            }

            .cancel-text {
                text-align: center;
            }

            .btn-primary,
            .btn-secondary {
                width: 100%;
                min-width: 0;
            }
        }
    </style>
</head>
<body>
    <div class="cancel-page">
        <div class="topbar">
            <div class="topbar-inner">
                <a href="index.php" class="brand">Doggie <span>Dorian’s</span></a>

                <div class="top-actions">
                    <?php foreach ($topLinks as $link): ?>
                        <a href="<?= h((string) $link['href']) ?>" class="top-link"><?= h((string) $link['label']) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <main class="cancel-main">
            <div class="cancel-shell">
                <section class="cancel-card">
                    <div class="status-badge">Checkout Cancelled</div>

                    <h1 class="cancel-title"><?= h($headline) ?></h1>

                    <p class="cancel-text">
                        <?= h($bodyText) ?> You can return and complete payment whenever you're ready.
                    </p>

                    <div class="info-box">
                        <span class="info-label">Status</span>
                        <div class="info-value"><?= h($infoValue) ?></div>
                    </div>

                    <div class="cancel-actions">
                        <a href="<?= h($primaryHref) ?>" class="btn-primary"><?= h($primaryLabel) ?></a>
                        <a href="<?= h($secondaryHref) ?>" class="btn-secondary"><?= h($secondaryLabel) ?></a>
                    </div>
                </section>
            </div>
        </main>
    </div>
</body>
</html>