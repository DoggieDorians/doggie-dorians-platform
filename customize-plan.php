<?php
require_once __DIR__ . '/includes/member_config.php';

$member = currentMember($pdo);

if (!$member) {
    $member = [
        'id' => 0,
        'username' => 'Preview Member',
        'email' => 'member@example.com',
        'phone' => '(631) 555-1234',
        'preferred_login' => 'email',
        'email_verified' => 1
    ];
}

$memberRates = [
    'walks_15' => 23.00,
    'walks_20' => 25.00,
    'walks_30' => 25.00,
    'walks_45' => 31.00,
    'walks_60' => 34.00,
    'daycare_days' => 45.00,
    'boarding_small' => 80.00,
    'boarding_medium' => 100.00,
    'boarding_large' => 120.00,
    'drop_ins' => 20.00,
];

$upfrontRates = [
    'walks_15' => 18.00,
    'walks_20' => 20.00,
    'walks_30' => 22.50,
    'walks_45' => 27.50,
    'walks_60' => 31.50,
    'daycare_days' => 45.00,
    'boarding_small' => 80.00,
    'boarding_medium' => 100.00,
    'boarding_large' => 120.00,
    'drop_ins' => 20.00,
];

$errors = [];
$planName = '';
$paymentMode = 'upfront';

$walks15 = 0;
$walks20 = 0;
$walks30 = 0;
$walks45 = 0;
$walks60 = 0;
$daycareDays = 0;
$boardingSmall = 0;
$boardingMedium = 0;
$boardingLarge = 0;
$dropIns = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $planName = trim($_POST['plan_name'] ?? '');
    $paymentMode = trim($_POST['payment_mode'] ?? 'upfront');

    $walks15 = max(0, (int)($_POST['walks_15'] ?? 0));
    $walks20 = max(0, (int)($_POST['walks_20'] ?? 0));
    $walks30 = max(0, (int)($_POST['walks_30'] ?? 0));
    $walks45 = max(0, (int)($_POST['walks_45'] ?? 0));
    $walks60 = max(0, (int)($_POST['walks_60'] ?? 0));
    $daycareDays = max(0, (int)($_POST['daycare_days'] ?? 0));
    $boardingSmall = max(0, (int)($_POST['boarding_small'] ?? 0));
    $boardingMedium = max(0, (int)($_POST['boarding_medium'] ?? 0));
    $boardingLarge = max(0, (int)($_POST['boarding_large'] ?? 0));
    $dropIns = max(0, (int)($_POST['drop_ins'] ?? 0));

    if ($planName === '') {
        $errors[] = 'Please enter a plan name.';
    }

    if (!in_array($paymentMode, ['upfront', 'payg'], true)) {
        $errors[] = 'Please choose a valid payment option.';
    }

    if (
        $walks15 === 0 &&
        $walks20 === 0 &&
        $walks30 === 0 &&
        $walks45 === 0 &&
        $walks60 === 0 &&
        $daycareDays === 0 &&
        $boardingSmall === 0 &&
        $boardingMedium === 0 &&
        $boardingLarge === 0 &&
        $dropIns === 0
    ) {
        $errors[] = 'Please add at least one service to your plan.';
    }

    $activeRates = $paymentMode === 'payg' ? $memberRates : $upfrontRates;

    $monthlyTotal =
        ($walks15 * $activeRates['walks_15']) +
        ($walks20 * $activeRates['walks_20']) +
        ($walks30 * $activeRates['walks_30']) +
        ($walks45 * $activeRates['walks_45']) +
        ($walks60 * $activeRates['walks_60']) +
        ($daycareDays * $activeRates['daycare_days']) +
        ($boardingSmall * $activeRates['boarding_small']) +
        ($boardingMedium * $activeRates['boarding_medium']) +
        ($boardingLarge * $activeRates['boarding_large']) +
        ($dropIns * $activeRates['drop_ins']);

    if (!$errors && (int)$member['id'] > 0) {
        $insert = $pdo->prepare("
            INSERT INTO custom_plans (
                member_id,
                plan_name,
                walks_15,
                walks_20,
                walks_30,
                walks_45,
                walks_60,
                daycare_days,
                boarding_nights,
                drop_ins,
                monthly_total,
                payment_mode,
                payment_status
            ) VALUES (
                :member_id,
                :plan_name,
                :walks_15,
                :walks_20,
                :walks_30,
                :walks_45,
                :walks_60,
                :daycare_days,
                :boarding_nights,
                :drop_ins,
                :monthly_total,
                :payment_mode,
                :payment_status
            )
        ");

        $totalBoardingNights = $boardingSmall + $boardingMedium + $boardingLarge;

        $insert->execute([
            ':member_id' => $member['id'],
            ':plan_name' => $planName,
            ':walks_15' => $walks15,
            ':walks_20' => $walks20,
            ':walks_30' => $walks30,
            ':walks_45' => $walks45,
            ':walks_60' => $walks60,
            ':daycare_days' => $daycareDays,
            ':boarding_nights' => $totalBoardingNights,
            ':drop_ins' => $dropIns,
            ':monthly_total' => $monthlyTotal,
            ':payment_mode' => $paymentMode,
            ':payment_status' => 'pending'
        ]);

        $planId = (int)$pdo->lastInsertId();

        if ($paymentMode === 'upfront') {
            redirectTo('payment-portal.php?plan_id=' . $planId);
        } else {
            redirectTo('pay-as-you-go.php?plan_id=' . $planId);
        }
    }
}

$plans = [];

if ((int)$member['id'] > 0) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM custom_plans
        WHERE member_id = :member_id
        ORDER BY created_at DESC
        LIMIT 8
    ");
    $stmt->execute([':member_id' => $member['id']]);
    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<?php include 'includes/header.php'; ?>
<?php include 'includes/nav.php'; ?>

<style>
.plan-page{
  background:#f4f1ea;
  min-height:calc(100vh - 120px);
  padding:32px 18px 60px;
}
.plan-shell{
  max-width:1280px;
  margin:0 auto;
  display:grid;
  gap:22px;
}
.plan-hero{
  background:linear-gradient(135deg,#111111 0%,#2b2414 100%);
  color:#fff;
  border-radius:28px;
  padding:34px 28px;
  box-shadow:0 14px 40px rgba(0,0,0,0.12);
}
.plan-hero h1{
  margin:0 0 10px;
  font-size:36px;
}
.plan-hero p{
  margin:0;
  max-width:820px;
  color:rgba(255,255,255,0.82);
  line-height:1.6;
}
.hero-actions{
  display:flex;
  gap:12px;
  flex-wrap:wrap;
  margin-top:18px;
}
.hero-link{
  display:inline-block;
  background:rgba(255,255,255,0.08);
  color:#fff;
  border:1px solid rgba(255,255,255,0.08);
  border-radius:999px;
  padding:12px 16px;
  font-weight:700;
  text-decoration:none;
}
.plan-grid{
  display:grid;
  grid-template-columns:1.15fr .85fr;
  gap:22px;
}
.plan-card{
  background:#fff;
  border-radius:24px;
  padding:24px;
  box-shadow:0 12px 30px rgba(0,0,0,0.07);
}
.plan-card h2{
  margin:0 0 16px;
}
.message{
  border-radius:14px;
  padding:14px 16px;
  margin-bottom:16px;
}
.message.error{
  background:#fff3f3;
  color:#9b1c1c;
}
.form-group{
  display:flex;
  flex-direction:column;
  margin-bottom:16px;
}
.form-group label{
  font-weight:700;
  margin-bottom:8px;
}
.form-group input{
  padding:14px 16px;
  border:1px solid #ddd;
  border-radius:16px;
  font-size:15px;
}
.section-label{
  margin:20px 0 10px;
  font-size:13px;
  text-transform:uppercase;
  letter-spacing:1px;
  color:#777;
  font-weight:700;
}
.service-card{
  background:#f7f4ee;
  border-radius:18px;
  padding:16px;
  margin-bottom:12px;
}
.service-top{
  margin-bottom:12px;
}
.service-top strong{
  display:block;
  color:#111;
  margin-bottom:4px;
}
.service-top span{
  color:#666;
  font-size:14px;
}
.service-prices{
  display:grid;
  grid-template-columns:1fr 1fr auto;
  gap:10px;
  align-items:center;
}
.price-box{
  background:#fff;
  border-radius:14px;
  padding:10px 12px;
  text-align:center;
}
.price-box small{
  display:block;
  color:#777;
  font-size:12px;
  margin-bottom:4px;
}
.price-box b{
  color:#111;
  font-size:15px;
}
.qty-input input{
  width:100%;
  padding:12px 14px;
  border:1px solid #ddd;
  border-radius:14px;
  font-size:15px;
}
.payment-choice{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:14px;
  margin-top:14px;
}
.payment-option{
  background:#f7f4ee;
  border-radius:18px;
  padding:18px;
  border:2px solid transparent;
}
.payment-option.active{
  border-color:#d4af37;
  background:#fff8df;
}
.payment-option input{
  margin-right:8px;
}
.payment-option-title{
  font-weight:700;
  color:#111;
}
.payment-option p{
  margin:8px 0 0;
  color:#666;
  font-size:14px;
}
.save-button{
  display:inline-block;
  background:#d4af37;
  color:#111;
  border:none;
  border-radius:999px;
  padding:14px 22px;
  font-weight:700;
  cursor:pointer;
  margin-top:18px;
}
.summary-box{
  background:#111;
  color:#fff;
  border-radius:22px;
  padding:22px;
  margin-bottom:18px;
}
.summary-box h3{
  margin-top:0;
}
.summary-total{
  font-size:40px;
  font-weight:800;
  color:#f2d471;
  margin:8px 0;
}
.summary-sub{
  color:rgba(255,255,255,0.82);
}
.summary-list{
  display:grid;
  gap:10px;
  margin-top:18px;
}
.summary-item{
  display:flex;
  justify-content:space-between;
  gap:12px;
  padding-bottom:10px;
  border-bottom:1px solid rgba(255,255,255,0.1);
}
.compare-box{
  background:#f7f4ee;
  border-radius:18px;
  padding:18px;
  margin-bottom:18px;
}
.compare-box h3{
  margin:0 0 10px;
}
.compare-line{
  display:flex;
  justify-content:space-between;
  gap:12px;
  padding:10px 0;
  border-bottom:1px solid #e7ded0;
  font-size:14px;
}
.compare-line:last-child{
  border-bottom:0;
}
.plan-list{
  display:grid;
  gap:14px;
}
.saved-plan{
  background:#f7f4ee;
  border-radius:18px;
  padding:16px;
}
.saved-plan h3{
  margin:0 0 10px;
  font-size:18px;
}
.saved-plan-grid{
  display:grid;
  grid-template-columns:repeat(2,1fr);
  gap:10px 12px;
}
.saved-plan-box{
  background:#fff;
  border-radius:14px;
  padding:12px 14px;
}
.saved-plan-box strong{
  display:block;
  font-size:12px;
  text-transform:uppercase;
  letter-spacing:1px;
  color:#777;
  margin-bottom:6px;
}
.empty-state{
  background:#f7f4ee;
  border-radius:18px;
  padding:18px;
  color:#666;
}
@media (max-width:1080px){
  .plan-grid{
    grid-template-columns:1fr;
  }
}
@media (max-width:760px){
  .payment-choice,
  .saved-plan-grid,
  .service-prices{
    grid-template-columns:1fr;
  }
  .plan-hero h1{
    font-size:30px;
  }
  .plan-hero,
  .plan-card{
    padding:20px;
  }
}
</style>

<main class="plan-page">
  <div class="plan-shell">

    <section class="plan-hero">
      <h1>Customize Your Plan</h1>
      <p>
        Build a personalized monthly membership plan with your preferred walk intervals,
        daycare days, boarding options by dog size, and 30-minute drop-ins.
      </p>

      <div class="hero-actions">
        <a href="dashboard.php" class="hero-link">Back to Dashboard</a>
        <a href="book-walk.php" class="hero-link">Book a Walk</a>
      </div>
    </section>

    <section class="plan-grid">

      <div class="plan-card">
        <h2>Build Your Monthly Plan</h2>

        <?php if ($errors): ?>
          <div class="message error">
            <?php foreach ($errors as $error): ?>
              <div><?= e($error) ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="post" action="" id="planForm">
          <div class="form-group">
            <label for="plan_name">Plan Name</label>
            <input id="plan_name" name="plan_name" type="text" value="<?= e($planName) ?>" placeholder="Example: Bentley VIP Monthly Plan" required>
          </div>

          <div class="section-label">Walk Intervals</div>

          <div class="service-card">
            <div class="service-top">
              <strong>15 Minute Walks</strong>
              <span>Quick relief and short support visits</span>
            </div>
            <div class="service-prices">
              <div class="price-box"><small>Upfront</small><b>$18.00</b></div>
              <div class="price-box"><small>As You Go</small><b>$23.00</b></div>
              <div class="qty-input"><input type="number" min="0" name="walks_15" id="walks_15" value="<?= e((string)$walks15) ?>" placeholder="Qty"></div>
            </div>
          </div>

          <div class="service-card">
            <div class="service-top">
              <strong>20 Minute Walks</strong>
              <span>Short daily care and routine support</span>
            </div>
            <div class="service-prices">
              <div class="price-box"><small>Upfront</small><b>$20.00</b></div>
              <div class="price-box"><small>As You Go</small><b>$25.00</b></div>
              <div class="qty-input"><input type="number" min="0" name="walks_20" id="walks_20" value="<?= e((string)$walks20) ?>" placeholder="Qty"></div>
            </div>
          </div>

          <div class="service-card">
            <div class="service-top">
              <strong>30 Minute Walks</strong>
              <span>Balanced exercise and stimulation</span>
            </div>
            <div class="service-prices">
              <div class="price-box"><small>Upfront</small><b>$22.50</b></div>
              <div class="price-box"><small>As You Go</small><b>$25.00</b></div>
              <div class="qty-input"><input type="number" min="0" name="walks_30" id="walks_30" value="<?= e((string)$walks30) ?>" placeholder="Qty"></div>
            </div>
          </div>

          <div class="service-card">
            <div class="service-top">
              <strong>45 Minute Walks</strong>
              <span>Extended walk for higher energy dogs</span>
            </div>
            <div class="service-prices">
              <div class="price-box"><small>Upfront</small><b>$27.50</b></div>
              <div class="price-box"><small>As You Go</small><b>$31.00</b></div>
              <div class="qty-input"><input type="number" min="0" name="walks_45" id="walks_45" value="<?= e((string)$walks45) ?>" placeholder="Qty"></div>
            </div>
          </div>

          <div class="service-card">
            <div class="service-top">
              <strong>60 Minute Walks</strong>
              <span>Premium full-hour walk experience</span>
            </div>
            <div class="service-prices">
              <div class="price-box"><small>Upfront</small><b>$31.50</b></div>
              <div class="price-box"><small>As You Go</small><b>$34.00</b></div>
              <div class="qty-input"><input type="number" min="0" name="walks_60" id="walks_60" value="<?= e((string)$walks60) ?>" placeholder="Qty"></div>
            </div>
          </div>

          <div class="section-label">Additional Services</div>

          <div class="service-card">
            <div class="service-top">
              <strong>Daycare Days</strong>
              <span>Structured daytime care</span>
            </div>
            <div class="service-prices">
              <div class="price-box"><small>Upfront</small><b>$45.00</b></div>
              <div class="price-box"><small>As You Go</small><b>$45.00</b></div>
              <div class="qty-input"><input type="number" min="0" name="daycare_days" id="daycare_days" value="<?= e((string)$daycareDays) ?>" placeholder="Qty"></div>
            </div>
          </div>

          <div class="service-card">
            <div class="service-top">
              <strong>Boarding Nights — Small Dog</strong>
              <span>Overnight care for small dogs</span>
            </div>
            <div class="service-prices">
              <div class="price-box"><small>Upfront</small><b>$80.00</b></div>
              <div class="price-box"><small>As You Go</small><b>$80.00</b></div>
              <div class="qty-input"><input type="number" min="0" name="boarding_small" id="boarding_small" value="<?= e((string)$boardingSmall) ?>" placeholder="Qty"></div>
            </div>
          </div>

          <div class="service-card">
            <div class="service-top">
              <strong>Boarding Nights — Medium Dog</strong>
              <span>Overnight care for medium dogs</span>
            </div>
            <div class="service-prices">
              <div class="price-box"><small>Upfront</small><b>$100.00</b></div>
              <div class="price-box"><small>As You Go</small><b>$100.00</b></div>
              <div class="qty-input"><input type="number" min="0" name="boarding_medium" id="boarding_medium" value="<?= e((string)$boardingMedium) ?>" placeholder="Qty"></div>
            </div>
          </div>

          <div class="service-card">
            <div class="service-top">
              <strong>Boarding Nights — Large Dog</strong>
              <span>Overnight care for large dogs</span>
            </div>
            <div class="service-prices">
              <div class="price-box"><small>Upfront</small><b>$120.00</b></div>
              <div class="price-box"><small>As You Go</small><b>$120.00</b></div>
              <div class="qty-input"><input type="number" min="0" name="boarding_large" id="boarding_large" value="<?= e((string)$boardingLarge) ?>" placeholder="Qty"></div>
            </div>
          </div>

          <div class="service-card">
            <div class="service-top">
              <strong>Drop-In Visits</strong>
              <span>30 minute visit for check-ins and care</span>
            </div>
            <div class="service-prices">
              <div class="price-box"><small>Upfront</small><b>$20.00</b></div>
              <div class="price-box"><small>As You Go</small><b>$20.00</b></div>
              <div class="qty-input"><input type="number" min="0" name="drop_ins" id="drop_ins" value="<?= e((string)$dropIns) ?>" placeholder="Qty"></div>
            </div>
          </div>

          <div class="section-label">Payment Option</div>

          <div class="payment-choice">
            <label class="payment-option <?= $paymentMode === 'upfront' ? 'active' : '' ?>">
              <div>
                <input type="radio" name="payment_mode" value="upfront" <?= $paymentMode === 'upfront' ? 'checked' : '' ?>>
                <span class="payment-option-title">Upfront Payment</span>
              </div>
              <p>Uses the lower upfront pricing and continues to the payment portal.</p>
            </label>

            <label class="payment-option <?= $paymentMode === 'payg' ? 'active' : '' ?>">
              <div>
                <input type="radio" name="payment_mode" value="payg" <?= $paymentMode === 'payg' ? 'checked' : '' ?>>
                <span class="payment-option-title">Pay As You Go</span>
              </div>
              <p>Uses regular member pricing and lets you choose pay now or later.</p>
            </label>
          </div>

          <button class="save-button" type="submit">Continue</button>
        </form>
      </div>

      <div class="plan-card">
        <div class="summary-box">
          <h3>Live Monthly Estimate</h3>
          <div class="summary-total" id="monthlyTotal">$0.00</div>
          <div class="summary-sub" id="summaryModeText">Based on upfront pricing.</div>
          <div class="summary-list" id="summaryList"></div>
        </div>

        <div class="compare-box">
          <h3>Quick Price Comparison</h3>
          <div class="compare-line">
            <span>15 Minute Walk</span>
            <strong>$18.00 upfront / $23.00 as you go</strong>
          </div>
          <div class="compare-line">
            <span>20 Minute Walk</span>
            <strong>$20.00 upfront / $25.00 as you go</strong>
          </div>
          <div class="compare-line">
            <span>30 Minute Walk</span>
            <strong>$22.50 upfront / $25.00 as you go</strong>
          </div>
          <div class="compare-line">
            <span>45 Minute Walk</span>
            <strong>$27.50 upfront / $31.00 as you go</strong>
          </div>
          <div class="compare-line">
            <span>60 Minute Walk</span>
            <strong>$31.50 upfront / $34.00 as you go</strong>
          </div>
        </div>

        <h2>Saved Plans</h2>

        <?php if (!$plans): ?>
          <div class="empty-state">
            No saved plans yet. Build your first customized membership plan.
          </div>
        <?php else: ?>
          <div class="plan-list">
            <?php foreach ($plans as $plan): ?>
              <div class="saved-plan">
                <h3><?= e($plan['plan_name']) ?></h3>

                <div class="saved-plan-grid">
                  <div class="saved-plan-box">
                    <strong>Payment Mode</strong>
                    <?= e($plan['payment_mode'] === 'upfront' ? 'Upfront' : 'Pay As You Go') ?>
                  </div>
                  <div class="saved-plan-box">
                    <strong>Payment Status</strong>
                    <?= e(ucfirst($plan['payment_status'])) ?>
                  </div>
                  <div class="saved-plan-box">
                    <strong>15 Min Walks</strong>
                    <?= e((string)$plan['walks_15']) ?>
                  </div>
                  <div class="saved-plan-box">
                    <strong>20 Min Walks</strong>
                    <?= e((string)$plan['walks_20']) ?>
                  </div>
                  <div class="saved-plan-box">
                    <strong>30 Min Walks</strong>
                    <?= e((string)$plan['walks_30']) ?>
                  </div>
                  <div class="saved-plan-box">
                    <strong>45 Min Walks</strong>
                    <?= e((string)$plan['walks_45']) ?>
                  </div>
                  <div class="saved-plan-box">
                    <strong>60 Min Walks</strong>
                    <?= e((string)$plan['walks_60']) ?>
                  </div>
                  <div class="saved-plan-box">
                    <strong>Daycare Days</strong>
                    <?= e((string)$plan['daycare_days']) ?>
                  </div>
                  <div class="saved-plan-box">
                    <strong>Boarding Nights</strong>
                    <?= e((string)$plan['boarding_nights']) ?>
                  </div>
                  <div class="saved-plan-box">
                    <strong>Drop-Ins</strong>
                    <?= e((string)$plan['drop_ins']) ?>
                  </div>
                  <div class="saved-plan-box">
                    <strong>Monthly Total</strong>
                    $<?= e(number_format((float)$plan['monthly_total'], 2)) ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

    </section>

  </div>
</main>

<script>
const memberRates = {
  walks_15: 23,
  walks_20: 25,
  walks_30: 25,
  walks_45: 31,
  walks_60: 34,
  daycare_days: 45,
  boarding_small: 80,
  boarding_medium: 100,
  boarding_large: 120,
  drop_ins: 20
};

const upfrontRates = {
  walks_15: 18,
  walks_20: 20,
  walks_30: 22.5,
  walks_45: 27.5,
  walks_60: 31.5,
  daycare_days: 45,
  boarding_small: 80,
  boarding_medium: 100,
  boarding_large: 120,
  drop_ins: 20
};

const labels = {
  walks_15: '15 Minute Walks',
  walks_20: '20 Minute Walks',
  walks_30: '30 Minute Walks',
  walks_45: '45 Minute Walks',
  walks_60: '60 Minute Walks',
  daycare_days: 'Daycare Days',
  boarding_small: 'Boarding Nights — Small Dog',
  boarding_medium: 'Boarding Nights — Medium Dog',
  boarding_large: 'Boarding Nights — Large Dog',
  drop_ins: 'Drop-In Visits'
};

function getActiveRates() {
  const selectedMode = document.querySelector('input[name="payment_mode"]:checked');
  return selectedMode && selectedMode.value === 'payg' ? memberRates : upfrontRates;
}

function updatePaymentCards() {
  document.querySelectorAll('.payment-option').forEach((card) => card.classList.remove('active'));
  const checked = document.querySelector('input[name="payment_mode"]:checked');
  if (checked) {
    checked.closest('.payment-option').classList.add('active');
  }
}

function updatePlanSummary() {
  const activeRates = getActiveRates();
  let total = 0;
  const summaryList = document.getElementById('summaryList');
  const monthlyTotal = document.getElementById('monthlyTotal');
  const summaryModeText = document.getElementById('summaryModeText');

  summaryList.innerHTML = '';

  Object.keys(activeRates).forEach((key) => {
    const input = document.getElementById(key);
    const qty = parseInt(input.value || '0', 10) || 0;

    if (qty > 0) {
      const lineTotal = qty * activeRates[key];
      total += lineTotal;

      const item = document.createElement('div');
      item.className = 'summary-item';
      item.innerHTML = `
        <span>${labels[key]} × ${qty}</span>
        <strong>$${lineTotal.toFixed(2)}</strong>
      `;
      summaryList.appendChild(item);
    }
  });

  if (summaryList.innerHTML === '') {
    summaryList.innerHTML = '<div class="summary-item"><span>No services selected yet</span><strong>$0.00</strong></div>';
  }

  const selectedMode = document.querySelector('input[name="payment_mode"]:checked');
  summaryModeText.textContent = selectedMode && selectedMode.value === 'payg'
    ? 'Based on regular member pricing.'
    : 'Based on upfront pricing.';

  monthlyTotal.textContent = '$' + total.toFixed(2);
  updatePaymentCards();
}

document.querySelectorAll('#planForm input[type="number"]').forEach((input) => {
  input.addEventListener('input', updatePlanSummary);
});

document.querySelectorAll('input[name="payment_mode"]').forEach((input) => {
  input.addEventListener('change', updatePlanSummary);
});

updatePlanSummary();
</script>

<?php include 'includes/footer.php'; ?>