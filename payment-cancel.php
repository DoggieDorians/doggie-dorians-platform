<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/member_config.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$member = currentMember($pdo);

if (!$member || (int)($member['id'] ?? 0) <= 0) {
    redirectTo('signup.php');
}

$planId = (int)($_GET['plan_id'] ?? 0);

if ($planId > 0) {
    $stmt = $pdo->prepare("
        UPDATE custom_plans
        SET payment_status = :payment_status
        WHERE id = :id
          AND member_id = :member_id
    ");
    $stmt->execute([
        ':payment_status' => 'pending',
        ':id' => $planId,
        ':member_id' => (int)$member['id'],
    ]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout Cancelled | Doggie Dorian’s</title>
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
                radial-gradient(circle at top, rgba(212, 175, 55, 0.10), transparent 35%),
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
            background: linear-gradient(135deg, rgba(212,175,55,0.08), transparent 35%);
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
            padding: 20px;
            border-radius: 20px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            text-align: center;
        }

        .info-label {
            display: block;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.10em;
            color: rgba(244,241,234,0.58);
            margin-bottom: 8px;
        }

        .info-value {
            font-size: 1.05rem;
            font-weight: 700;
            color: #ffffff;
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
            min-width: 190px;
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
                    <a href="dashboard.php" class="top-link">Dashboard</a>
                    <a href="my-bookings.php" class="top-link">My Bookings</a>
                </div>
            </div>
        </div>

        <main class="cancel-main">
            <div class="cancel-shell">
                <section class="cancel-card">
                    <div class="status-badge">Checkout Cancelled</div>
                    <h1 class="cancel-title">No payment was completed</h1>
                    <p class="cancel-text">
                        Your checkout was canceled before Stripe confirmed payment. You can return to your payment portal and try again whenever you're ready.
                    </p>

                    <div class="info-box">
                        <span class="info-label">Status</span>
                        <div class="info-value">Payment remains pending</div>
                    </div>

                    <div class="cancel-actions">
                        <?php if ($planId > 0): ?>
                            <a href="payment-portal.php?plan_id=<?= (int)$planId ?>" class="btn-primary">Return to Payment Portal</a>
                        <?php endif; ?>
                        <a href="dashboard.php" class="btn-secondary">Go to Dashboard</a>
                    </div>
                </section>
            </div>
        </main>
    </div>
</body>
</html>