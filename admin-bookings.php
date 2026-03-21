<?php
session_start();
require_once __DIR__ . '/data/config/db.php';

if (!isset($_SESSION['member_id'])) {
    header('Location: login.php');
    exit;
}

$memberId = (int) $_SESSION['member_id'];

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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
        if ($duration === 30) {
            return 22.50;
        }
        if ($duration === 45) {
            return 27.50;
        }
        if ($duration === 60) {
            return 31.50;
        }
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

$dogsStmt = $pdo->prepare("
    SELECT id, name
    FROM dogs
    WHERE member_id = ?
    ORDER BY name ASC, id DESC
");
$dogsStmt->execute([$memberId]);
$dogs = $dogsStmt->fetchAll(PDO::FETCH_ASSOC);

$selectedDogId = '';
$selectedDuration = '30';
$selectedWalks = '12';
$notes = '';

$ratePerWalk = getPlanRate((int) $selectedDuration, (int) $selectedWalks);
$monthlyTotal = $ratePerWalk * (int) $selectedWalks;
$standardRate = getStandardMemberRate((int) $selectedDuration);
$monthlySavings = ($standardRate - $ratePerWalk) * (int) $selectedWalks;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedDogId = trim((string) ($_POST['dog_id'] ?? ''));
    $selectedDuration = trim((string) ($_POST['duration'] ?? '30'));
    $selectedWalks = trim((string) ($_POST['walks_per_month'] ?? '12'));
    $notes = trim((string) ($_POST['notes'] ?? ''));

    $dogId = (int) $selectedDogId;
    $duration = (int) $selectedDuration;
    $walksPerMonth = (int) $selectedWalks;

    $validDurations = [15, 20, 30, 45, 60];

    if (empty($dogs)) {
        $error = 'Please add a dog to your account before creating a walking plan.';
    } elseif ($dogId <= 0) {
        $error = 'Please choose a dog.';
    } elseif (!in_array($duration, $validDurations, true)) {
        $error = 'Please choose a valid walk duration.';
    } elseif ($walksPerMonth < 1 || $walksPerMonth > 60) {
        $error = 'Please enter a valid number of walks per month.';
    } else {
        $dogCheck = $pdo->prepare("
            SELECT id, name
            FROM dogs
            WHERE id = ? AND member_id = ?
            LIMIT 1
        ");
        $dogCheck->execute([$dogId, $memberId]);
        $dog = $dogCheck->fetch(PDO::FETCH_ASSOC);

        if (!$dog) {
            $error = 'That dog could not be verified for your account.';
        } else {
            $ratePerWalk = getPlanRate($duration, $walksPerMonth);
            $monthlyTotal = $ratePerWalk * $walksPerMonth;
            $standardRate = getStandardMemberRate($duration);
            $monthlySavings = max(0, ($standardRate - $ratePerWalk) * $walksPerMonth);

            try {
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
                    $memberId,
                    $dogId,
                    $duration,
                    $walksPerMonth,
                    $ratePerWalk,
                    $monthlyTotal,
                    $notes
                ]);

                $success = 'Your custom walking plan has been saved successfully.';
                $selectedDogId = '';
                $selectedDuration = '30';
                $selectedWalks = '12';
                $notes = '';

                $ratePerWalk = getPlanRate((int) $selectedDuration, (int) $selectedWalks);
                $monthlyTotal = $ratePerWalk * (int) $selectedWalks;
                $standardRate = getStandardMemberRate((int) $selectedDuration);
                $monthlySavings = ($standardRate - $ratePerWalk) * (int) $selectedWalks;
            } catch (Throwable $e) {
                $error = 'There was a problem saving your walking plan. Please try again.';
            }
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
    <style>
        * {
            box-sizing: border-box;
        }

        :root {
            --bg: #0b0b0e;
            --bg-soft: #10131a;
            --panel: #151922;
            --panel-2: #1b2130;
            --line: rgba(255, 255, 255, 0.08);
            --text: #f4f1ea;
            --muted: #a6adbb;
            --gold: #d4af37;
            --gold-soft: rgba(212, 175, 55, 0.15);
            --green: #1f8f5f;
            --red: #b84c4c;
            --shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
            --radius: 24px;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(212, 175, 55, 0.08), transparent 28%),
                linear-gradient(180deg, #090a0d 0%, #0d1016 100%);
        }

        .page {
            min-height: 100vh;
            padding: 38px 18px;
        }

        .wrap {
            max-width: 1180px;
            margin: 0 auto;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 18px;
            margin-bottom: 26px;
        }

        .heading h1 {
            margin: 0 0 10px;
            font-size: 38px;
            line-height: 1.05;
        }

        .heading p {
            margin: 0;
            color: var(--muted);
            max-width: 760px;
            font-size: 15px;
            line-height: 1.6;
        }

        .top-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            padding: 12px 16px;
            border-radius: 12px;
            border: 1px solid var(--line);
            font-weight: 700;
            font-size: 14px;
            transition: 0.2s ease;
            cursor: pointer;
        }

        .btn-primary {
            background: var(--gold);
            color: #111;
            border-color: transparent;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            filter: brightness(1.04);
        }

        .btn-secondary {
            background: var(--panel-2);
            color: var(--text);
        }

        .btn-secondary:hover {
            background: #242b38;
        }

        .grid {
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            gap: 24px;
        }

        .panel {
            background: linear-gradient(180deg, rgba(255,255,255,0.025), rgba(255,255,255,0.015));
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 26px;
        }

        .panel h2 {
            margin: 0 0 18px;
            font-size: 22px;
        }

        .panel p.subtext {
            margin: -4px 0 18px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }

        .alert {
            margin-bottom: 18px;
            padding: 14px 16px;
            border-radius: 14px;
            border: 1px solid var(--line);
            font-size: 14px;
        }

        .alert-success {
            background: rgba(31, 143, 95, 0.14);
            color: #a4e5c2;
            border-color: rgba(31, 143, 95, 0.3);
        }

        .alert-error {
            background: rgba(184, 76, 76, 0.14);
            color: #f1b7b7;
            border-color: rgba(184, 76, 76, 0.3);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .field {
            display: block;
        }

        .field-full {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: var(--muted);
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        select,
        input,
        textarea {
            width: 100%;
            border: 1px solid var(--line);
            background: var(--panel-2);
            color: var(--text);
            border-radius: 14px;
            padding: 14px 15px;
            outline: none;
            font-size: 15px;
        }

        select:focus,
        input:focus,
        textarea:focus {
            border-color: rgba(212, 175, 55, 0.42);
            box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.08);
        }

        textarea {
            min-height: 120px;
            resize: vertical;
        }

        .note-box {
            margin-top: 16px;
            padding: 14px 16px;
            background: rgba(212, 175, 55, 0.08);
            border: 1px solid rgba(212, 175, 55, 0.18);
            border-radius: 16px;
            color: #efe2ae;
            font-size: 13px;
            line-height: 1.6;
        }

        .summary-card {
            display: grid;
            gap: 16px;
        }

        .summary-hero {
            padding: 18px;
            border-radius: 20px;
            background:
                radial-gradient(circle at top left, rgba(212, 175, 55, 0.14), transparent 45%),
                linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.015));
            border: 1px solid var(--line);
        }

        .summary-hero h3 {
            margin: 0 0 8px;
            font-size: 22px;
        }

        .summary-hero p {
            margin: 0;
            color: var(--muted);
            line-height: 1.6;
            font-size: 14px;
        }

        .summary-list {
            display: grid;
            gap: 12px;
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 14px 16px;
            border-radius: 16px;
            background: var(--panel-2);
            border: 1px solid var(--line);
        }

        .summary-label {
            color: var(--muted);
            font-size: 13px;
        }

        .summary-value {
            font-size: 18px;
            font-weight: 700;
            text-align: right;
        }

        .summary-total {
            background: rgba(212, 175, 55, 0.08);
            border-color: rgba(212, 175, 55, 0.22);
        }

        .summary-total .summary-value {
            color: var(--gold);
            font-size: 24px;
        }

        .summary-footnote {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
            margin-top: 4px;
        }

        .submit-wrap {
            margin-top: 22px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .empty-dogs {
            padding: 18px;
            border-radius: 18px;
            background: rgba(184, 76, 76, 0.08);
            border: 1px solid rgba(184, 76, 76, 0.22);
            color: #efc0c0;
            line-height: 1.6;
        }

        @media (max-width: 980px) {
            .grid {
                grid-template-columns: 1fr;
            }

            .topbar {
                flex-direction: column;
            }
        }

        @media (max-width: 640px) {
            .heading h1 {
                font-size: 30px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .panel {
                padding: 20px;
                border-radius: 20px;
            }

            .page {
                padding: 20px 12px;
            }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="wrap">
        <div class="topbar">
            <div class="heading">
                <h1>Customize Your Walking Plan</h1>
                <p>
                    Build a monthly walking plan based on your dog’s needs. Member pricing is applied automatically,
                    and discounted rates are unlocked for qualifying higher-volume monthly plans.
                </p>
            </div>

            <div class="top-actions">
                <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
            </div>
        </div>

        <div class="grid">
            <section class="panel">
                <h2>Create Your Plan</h2>
                <p class="subtext">
                    Select your dog, choose the walk length you want, and decide how many walks you would like each month.
                </p>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success"><?php echo h($success); ?></div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-error"><?php echo h($error); ?></div>
                <?php endif; ?>

                <?php if (empty($dogs)): ?>
                    <div class="empty-dogs">
                        You do not have any dogs on your account yet. Please add a dog first before creating a custom walking plan.
                    </div>
                <?php else: ?>
                    <form method="POST" id="walkingPlanForm">
                        <div class="form-grid">
                            <div class="field">
                                <label for="dog_id">Select Dog</label>
                                <select name="dog_id" id="dog_id" required>
                                    <option value="">Choose your dog</option>
                                    <?php foreach ($dogs as $dog): ?>
                                        <option value="<?php echo (int) $dog['id']; ?>" <?php echo $selectedDogId === (string) $dog['id'] ? 'selected' : ''; ?>>
                                            <?php echo h((string) $dog['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="field">
                                <label for="duration">Walk Duration</label>
                                <select name="duration" id="duration" required>
                                    <option value="15" <?php echo $selectedDuration === '15' ? 'selected' : ''; ?>>15 Minutes</option>
                                    <option value="20" <?php echo $selectedDuration === '20' ? 'selected' : ''; ?>>20 Minutes</option>
                                    <option value="30" <?php echo $selectedDuration === '30' ? 'selected' : ''; ?>>30 Minutes</option>
                                    <option value="45" <?php echo $selectedDuration === '45' ? 'selected' : ''; ?>>45 Minutes</option>
                                    <option value="60" <?php echo $selectedDuration === '60' ? 'selected' : ''; ?>>60 Minutes</option>
                                </select>
                            </div>

                            <div class="field">
                                <label for="walks_per_month">Walks Per Month</label>
                                <input
                                    type="number"
                                    id="walks_per_month"
                                    name="walks_per_month"
                                    min="1"
                                    max="60"
                                    value="<?php echo h($selectedWalks); ?>"
                                    required
                                >
                            </div>

                            <div class="field">
                                <label for="pricing_status">Pricing Tier</label>
                                <input type="text" id="pricing_status" value="" readonly>
                            </div>

                            <div class="field field-full">
                                <label for="notes">Notes</label>
                                <textarea
                                    name="notes"
                                    id="notes"
                                    placeholder="Add any scheduling notes, preferences, or anything you want us to know."
                                ><?php echo h($notes); ?></textarea>
                            </div>
                        </div>

                        <div class="note-box">
                            Plans with fewer than 12 walks per month use standard member pricing.
                            Plans with 12 or more monthly walks unlock discounted pricing for 30, 45, and 60 minute walks.
                        </div>

                        <div class="submit-wrap">
                            <button type="submit" class="btn btn-primary">Save My Walking Plan</button>
                            <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                <?php endif; ?>
            </section>

            <aside class="panel">
                <div class="summary-card">
                    <div class="summary-hero">
                        <h3>Monthly Plan Summary</h3>
                        <p>
                            Your plan total updates automatically based on your selected walk duration and
                            the number of walks you want each month.
                        </p>
                    </div>

                    <div class="summary-list">
                        <div class="summary-item">
                            <div>
                                <div class="summary-label">Standard Member Rate</div>
                            </div>
                            <div class="summary-value" id="standardRateDisplay">
                                $<?php echo number_format($standardRate, 2); ?>
                            </div>
                        </div>

                        <div class="summary-item">
                            <div>
                                <div class="summary-label">Your Rate Per Walk</div>
                            </div>
                            <div class="summary-value" id="ratePerWalkDisplay">
                                $<?php echo number_format($ratePerWalk, 2); ?>
                            </div>
                        </div>

                        <div class="summary-item">
                            <div>
                                <div class="summary-label">Walks Per Month</div>
                            </div>
                            <div class="summary-value" id="walksPerMonthDisplay">
                                <?php echo (int) $selectedWalks; ?>
                            </div>
                        </div>

                        <div class="summary-item">
                            <div>
                                <div class="summary-label">Monthly Savings</div>
                            </div>
                            <div class="summary-value" id="monthlySavingsDisplay">
                                $<?php echo number_format(max(0, $monthlySavings), 2); ?>
                            </div>
                        </div>

                        <div class="summary-item summary-total">
                            <div>
                                <div class="summary-label">Monthly Total</div>
                            </div>
                            <div class="summary-value" id="monthlyTotalDisplay">
                                $<?php echo number_format($monthlyTotal, 2); ?>
                            </div>
                        </div>
                    </div>

                    <div class="summary-footnote">
                        Your submitted plan is saved to your account for review and future management inside the admin system.
                    </div>
                </div>
            </aside>
        </div>
    </div>
</div>

<script>
(function () {
    const durationEl = document.getElementById('duration');
    const walksEl = document.getElementById('walks_per_month');
    const ratePerWalkDisplay = document.getElementById('ratePerWalkDisplay');
    const standardRateDisplay = document.getElementById('standardRateDisplay');
    const walksPerMonthDisplay = document.getElementById('walksPerMonthDisplay');
    const monthlySavingsDisplay = document.getElementById('monthlySavingsDisplay');
    const monthlyTotalDisplay = document.getElementById('monthlyTotalDisplay');
    const pricingStatus = document.getElementById('pricing_status');

    if (!durationEl || !walksEl) {
        return;
    }

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
        walksPerMonthDisplay.textContent = walksPerMonth > 0 ? walksPerMonth : 0;
        monthlySavingsDisplay.textContent = formatMoney(savings);
        monthlyTotalDisplay.textContent = formatMoney(total);

        if (walksPerMonth >= 12 && (duration === 30 || duration === 45 || duration === 60)) {
            pricingStatus.value = 'High-Volume Member Rate';
        } else {
            pricingStatus.value = 'Standard Member Rate';
        }
    }

    durationEl.addEventListener('change', updatePricing);
    walksEl.addEventListener('input', updatePricing);

    updatePricing();
})();
</script>
</body>
</html>