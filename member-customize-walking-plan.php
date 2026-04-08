<?php
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Member';

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function tableExists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1");
        $stmt->execute(['table' => $tableName]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function getPlanRate(int $duration, int $walksPerMonth): float
{
    $memberPricing = [
        15 => 18.00,
        20 => 20.00,
        30 => 25.00,
        45 => 30.00,
        60 => 34.00,
    ];

    if ($walksPerMonth >= 12) {
        if ($duration === 30) return 22.50;
        if ($duration === 45) return 27.50;
        if ($duration === 60) return 31.50;
    }

    return $memberPricing[$duration] ?? 0.00;
}

function getStandardMemberRate(int $duration): float
{
    $memberPricing = [
        15 => 18.00,
        20 => 20.00,
        30 => 25.00,
        45 => 30.00,
        60 => 34.00,
    ];

    return $memberPricing[$duration] ?? 0.00;
}

$success = '';
$error = '';

$selectedPetId = '';
$selectedDuration = '30';
$selectedWalks = '12';
$notes = '';

$ratePerWalk = getPlanRate((int) $selectedDuration, (int) $selectedWalks);
$monthlyTotal = $ratePerWalk * (int) $selectedWalks;
$standardRate = getStandardMemberRate((int) $selectedDuration);
$monthlySavings = max(0, ($standardRate - $ratePerWalk) * (int) $selectedWalks);

$pets = [];
try {
    $petsStmt = $pdo->prepare("
        SELECT id, pet_name
        FROM pets
        WHERE user_id = ?
        ORDER BY pet_name ASC, id DESC
    ");
    $petsStmt->execute([$userId]);
    $pets = $petsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = 'There was a problem loading your pets.';
}

if (!tableExists($pdo, 'membership_walk_plans')) {
    $error = 'The membership walk plans table has not been created yet.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $selectedPetId = trim((string) ($_POST['pet_id'] ?? ''));
    $selectedDuration = trim((string) ($_POST['duration'] ?? '30'));
    $selectedWalks = trim((string) ($_POST['walks_per_month'] ?? '12'));
    $notes = trim((string) ($_POST['notes'] ?? ''));

    $petId = (int) $selectedPetId;
    $duration = (int) $selectedDuration;
    $walksPerMonth = (int) $selectedWalks;

    $validDurations = [15, 20, 30, 45, 60];

    if (empty($pets)) {
        $error = 'Please add a pet to your account before creating a walking plan.';
    } elseif ($petId <= 0) {
        $error = 'Please choose a pet.';
    } elseif (!in_array($duration, $validDurations, true)) {
        $error = 'Please choose a valid walk duration.';
    } elseif ($walksPerMonth < 1 || $walksPerMonth > 60) {
        $error = 'Please enter a valid number of walks per month.';
    } else {
        try {
            $petCheck = $pdo->prepare("
                SELECT id, pet_name
                FROM pets
                WHERE id = ? AND user_id = ?
                LIMIT 1
            ");
            $petCheck->execute([$petId, $userId]);
            $pet = $petCheck->fetch(PDO::FETCH_ASSOC);

            if (!$pet) {
                $error = 'That pet could not be verified for your account.';
            } else {
                $ratePerWalk = getPlanRate($duration, $walksPerMonth);
                $monthlyTotal = $ratePerWalk * $walksPerMonth;
                $standardRate = getStandardMemberRate($duration);
                $monthlySavings = max(0, ($standardRate - $ratePerWalk) * $walksPerMonth);

                $insert = $pdo->prepare("
                    INSERT INTO membership_walk_plans (
                        member_id,
                        dog_id,
                        walk_duration,
                        walks_per_month,
                        rate_per_walk,
                        monthly_total,
                        notes,
                        status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending')
                ");

                $insert->execute([
                    $userId,
                    $petId,
                    $duration,
                    $walksPerMonth,
                    $ratePerWalk,
                    $monthlyTotal,
                    $notes
                ]);

                $success = 'Your custom walking plan has been saved successfully.';
                $selectedPetId = '';
                $selectedDuration = '30';
                $selectedWalks = '12';
                $notes = '';

                $ratePerWalk = getPlanRate(30, 12);
                $monthlyTotal = $ratePerWalk * 12;
                $standardRate = getStandardMemberRate(30);
                $monthlySavings = max(0, ($standardRate - $ratePerWalk) * 12);
            }
        } catch (Throwable $e) {
            $error = 'There was a problem saving your walking plan. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customize Walking Plan | Doggie Dorian's</title>
    <meta name="description" content="Build your custom monthly walking plan with Doggie Dorian's member pricing.">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg: #07080b;
            --text: #f6f1e8;
            --muted: #c9c0af;
            --soft: #9d968a;
            --gold: #d7b26a;
            --gold-light: #f0d59f;
            --white: #ffffff;
            --success: #9fe0b1;
            --danger: #ff9d9d;
            --shadow: 0 22px 65px rgba(0,0,0,0.34);
            --max: 1240px;
        }

        body {
            font-family: "Georgia", "Times New Roman", serif;
            background:
                radial-gradient(circle at top, rgba(215,178,106,0.10), transparent 24%),
                linear-gradient(180deg, #06070a 0%, #0b0d12 45%, #06070a 100%);
            color: var(--text);
            line-height: 1.6;
        }

        a { color: inherit; text-decoration: none; }

        .page { min-height: 100vh; padding: 30px 20px 60px; }
        .wrap { width: min(var(--max), 100%); margin: 0 auto; }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 28px;
        }

        .brand {
            font-size: 1.7rem;
            font-weight: 700;
            color: var(--white);
        }

        .topnav {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .topnav a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 12px 16px;
            border-radius: 999px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.08);
            color: var(--white);
            font-weight: 700;
        }

        .hero, .card {
            border-radius: 26px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.08);
            box-shadow: var(--shadow);
        }

        .hero {
            padding: 34px;
            margin-bottom: 24px;
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

        .hero h1 {
            margin: 0 0 12px;
            font-size: clamp(2.2rem, 5vw, 3.9rem);
            line-height: 0.96;
            color: var(--white);
        }

        .hero p, .card-subtext {
            color: var(--muted);
        }

        .content-grid {
            display: grid;
            grid-template-columns: 1.08fr 0.92fr;
            gap: 22px;
        }

        .card { padding: 28px; }
        .card h2 {
            margin: 0 0 8px;
            font-size: 2rem;
            color: var(--white);
        }

        .card-subtext {
            margin: 0 0 22px;
            font-size: 0.98rem;
        }

        .alert {
            margin-bottom: 18px;
            padding: 14px 16px;
            border-radius: 18px;
            font-size: 0.95rem;
        }

        .alert-success {
            background: rgba(159,224,177,0.10);
            color: var(--success);
            border: 1px solid rgba(159,224,177,0.22);
        }

        .alert-error {
            background: rgba(255,157,157,0.10);
            color: var(--danger);
            border: 1px solid rgba(255,157,157,0.22);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .field-full { grid-column: 1 / -1; }

        label {
            display: block;
            margin-bottom: 8px;
            color: var(--soft);
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        select, input, textarea {
            width: 100%;
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.03);
            color: var(--white);
            padding: 14px 16px;
            font-size: 1rem;
            outline: none;
            font-family: inherit;
        }

        textarea {
            min-height: 130px;
            resize: vertical;
        }

        .pricing-note, .summary-box {
            border-radius: 20px;
            padding: 18px;
            background: rgba(255,255,255,0.02);
            border: 1px solid rgba(255,255,255,0.08);
        }

        .pricing-note {
            margin-top: 18px;
            background: rgba(215,178,106,0.08);
            border-color: rgba(215,178,106,0.18);
            color: #f2d9a8;
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 22px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 48px;
            padding: 13px 20px;
            border-radius: 999px;
            font-weight: 700;
            border: 1px solid transparent;
            cursor: pointer;
            font-family: inherit;
            font-size: 1rem;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
            color: #17120d;
        }

        .btn-secondary {
            background: rgba(255,255,255,0.03);
            color: var(--white);
            border-color: rgba(255,255,255,0.08);
        }

        .summary-stack {
            display: grid;
            gap: 14px;
        }

        .summary-label {
            color: var(--soft);
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 8px;
        }

        .summary-value {
            font-size: 1.8rem;
            color: #f5ddaf;
            font-weight: 700;
            line-height: 1.1;
        }

        .summary-value.small {
            font-size: 1.15rem;
            color: var(--white);
        }

        .summary-total {
            background: rgba(215,178,106,0.08);
            border-color: rgba(215,178,106,0.22);
        }

        .empty-state {
            border: 1px dashed rgba(255,255,255,0.14);
            border-radius: 20px;
            padding: 24px;
            text-align: center;
            color: var(--muted);
            background: rgba(255,255,255,0.02);
        }

        .empty-state a {
            color: var(--gold);
            font-weight: 700;
        }

        @media (max-width: 980px) {
            .content-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 720px) {
            .page { padding: 20px 14px 50px; }
            .hero, .card { padding: 22px; }
            .form-grid { grid-template-columns: 1fr; }
            .topnav { width: 100%; }
            .topnav a { flex: 1; }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="wrap">
            <div class="topbar">
                <div class="brand">Doggie Dorian’s</div>
                <div class="topnav">
                    <a href="dashboard.php">Dashboard</a>
                    <a href="book-walk.php">Book a Service</a>
                    <a href="profile.php">Profile</a>
                    <a href="logout.php">Logout</a>
                </div>
            </div>

            <section class="hero">
                <div class="eyebrow">Custom Walking Membership</div>
                <h1>Build your ideal monthly walking plan, <?php echo h($fullName); ?></h1>
                <p>
                    Choose your pet, select your preferred walk duration, and set the number of walks you want each month.
                    Higher-volume monthly plans automatically unlock discounted member pricing for qualifying walk lengths.
                </p>
            </section>

            <section class="content-grid">
                <div class="card">
                    <h2>Create Your Plan</h2>
                    <p class="card-subtext">
                        Plans with 11 walks or fewer use your standard member rate.
                        Plans with 12 or more walks unlock discounted pricing for 30, 45, and 60 minute walks.
                    </p>

                    <?php if ($success !== ''): ?>
                        <div class="alert alert-success"><?php echo h($success); ?></div>
                    <?php endif; ?>

                    <?php if ($error !== ''): ?>
                        <div class="alert alert-error"><?php echo h($error); ?></div>
                    <?php endif; ?>

                    <?php if (empty($pets) && $error === ''): ?>
                        <div class="empty-state">
                            <p>You do not have any pets on your account yet.</p>
                            <p><a href="add-pet.php">Add a pet first</a></p>
                        </div>
                    <?php elseif ($error === '' || $success !== ''): ?>
                        <form method="POST" id="walkingPlanForm">
                            <div class="form-grid">
                                <div>
                                    <label for="pet_id">Select Pet</label>
                                    <select name="pet_id" id="pet_id" required>
                                        <option value="">Choose your pet</option>
                                        <?php foreach ($pets as $pet): ?>
                                            <option value="<?php echo (int) $pet['id']; ?>" <?php echo $selectedPetId === (string) $pet['id'] ? 'selected' : ''; ?>>
                                                <?php echo h((string) $pet['pet_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label for="duration">Walk Duration</label>
                                    <select name="duration" id="duration" required>
                                        <option value="15" <?php echo $selectedDuration === '15' ? 'selected' : ''; ?>>15 Minutes</option>
                                        <option value="20" <?php echo $selectedDuration === '20' ? 'selected' : ''; ?>>20 Minutes</option>
                                        <option value="30" <?php echo $selectedDuration === '30' ? 'selected' : ''; ?>>30 Minutes</option>
                                        <option value="45" <?php echo $selectedDuration === '45' ? 'selected' : ''; ?>>45 Minutes</option>
                                        <option value="60" <?php echo $selectedDuration === '60' ? 'selected' : ''; ?>>60 Minutes</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="walks_per_month">Walks Per Month</label>
                                    <input type="number" id="walks_per_month" name="walks_per_month" min="1" max="60" value="<?php echo h($selectedWalks); ?>" required>
                                </div>

                                <div>
                                    <label for="pricing_tier">Pricing Tier</label>
                                    <input type="text" id="pricing_tier" value="" readonly>
                                </div>

                                <div class="field-full">
                                    <label for="notes">Notes</label>
                                    <textarea name="notes" id="notes" placeholder="Add any notes about your preferred routine, frequency, or anything helpful for your plan."><?php echo h($notes); ?></textarea>
                                </div>
                            </div>

                            <div class="pricing-note">
                                30-minute walks are $25 at the standard member rate and $22.50 at 12+ walks per month.
                                45-minute walks are $30 standard and $27.50 at 12+.
                                60-minute walks are $34 standard and $31.50 at 12+.
                            </div>

                            <div class="actions">
                                <button type="submit" class="btn btn-primary">Save My Walking Plan</button>
                                <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <h2>Monthly Summary</h2>
                    <p class="card-subtext">
                        Your pricing updates automatically as you change the walk length and monthly quantity.
                    </p>

                    <div class="summary-stack">
                        <div class="summary-box">
                            <div class="summary-label">Standard Member Rate</div>
                            <div class="summary-value small" id="standardRateDisplay">$<?php echo number_format($standardRate, 2); ?></div>
                        </div>

                        <div class="summary-box">
                            <div class="summary-label">Your Rate Per Walk</div>
                            <div class="summary-value" id="ratePerWalkDisplay">$<?php echo number_format($ratePerWalk, 2); ?></div>
                        </div>

                        <div class="summary-box">
                            <div class="summary-label">Walks Per Month</div>
                            <div class="summary-value small" id="walksPerMonthDisplay"><?php echo (int) $selectedWalks; ?></div>
                        </div>

                        <div class="summary-box">
                            <div class="summary-label">Monthly Savings</div>
                            <div class="summary-value small" id="monthlySavingsDisplay">$<?php echo number_format($monthlySavings, 2); ?></div>
                        </div>

                        <div class="summary-box summary-total">
                            <div class="summary-label">Monthly Total</div>
                            <div class="summary-value" id="monthlyTotalDisplay">$<?php echo number_format($monthlyTotal, 2); ?></div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <script>
        (function () {
            const durationEl = document.getElementById('duration');
            const walksEl = document.getElementById('walks_per_month');
            const pricingTierEl = document.getElementById('pricing_tier');
            const standardRateDisplay = document.getElementById('standardRateDisplay');
            const ratePerWalkDisplay = document.getElementById('ratePerWalkDisplay');
            const walksPerMonthDisplay = document.getElementById('walksPerMonthDisplay');
            const monthlySavingsDisplay = document.getElementById('monthlySavingsDisplay');
            const monthlyTotalDisplay = document.getElementById('monthlyTotalDisplay');

            if (!durationEl || !walksEl) return;

            const standardMemberPricing = {
                15: 18.00,
                20: 20.00,
                30: 25.00,
                45: 30.00,
                60: 34.00
            };

            function getRate(duration, walksPerMonth) {
                if (walksPerMonth >= 12) {
                    if (duration === 30) return 22.50;
                    if (duration === 45) return 27.50;
                    if (duration === 60) return 31.50;
                }
                return standardMemberPricing[duration] || 0;
            }

            function formatMoney(amount) {
                return '$' + Number(amount).toFixed(2);
            }

            function updatePricing() {
                const duration = parseInt(durationEl.value || '30', 10);
                const walksPerMonth = parseInt(walksEl.value || '0', 10);

                const standardRate = standardMemberPricing[duration] || 0;
                const rate = getRate(duration, walksPerMonth);
                const total = rate * walksPerMonth;
                const savings = Math.max(0, (standardRate - rate) * walksPerMonth);

                standardRateDisplay.textContent = formatMoney(standardRate);
                ratePerWalkDisplay.textContent = formatMoney(rate);
                walksPerMonthDisplay.textContent = String(walksPerMonth > 0 ? walksPerMonth : 0);
                monthlySavingsDisplay.textContent = formatMoney(savings);
                monthlyTotalDisplay.textContent = formatMoney(total);

                if (walksPerMonth >= 12 && (duration === 30 || duration === 45 || duration === 60)) {
                    pricingTierEl.value = 'High-Volume Member Rate';
                } else {
                    pricingTierEl.value = 'Standard Member Rate';
                }
            }

            durationEl.addEventListener('change', updatePricing);
            walksEl.addEventListener('input', updatePricing);
            updatePricing();
        })();
    </script>
</body>
</html>