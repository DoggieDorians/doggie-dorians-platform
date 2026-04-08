<?php
require_once __DIR__ . '/includes/member_config.php';

$planId = (int)($_GET['plan_id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM custom_plans WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $planId]);
$plan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$plan) {
    redirectTo('customize-plan.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $choice = trim($_POST['pay_choice'] ?? '');

    if ($choice === 'now') {
        redirectTo('payment-portal.php?plan_id=' . $planId);
    }

    if ($choice === 'later') {
        $update = $pdo->prepare("
            UPDATE custom_plans
            SET payment_status = 'pay_later'
            WHERE id = :id
        ");
        $update->execute([':id' => $planId]);

        redirectTo('dashboard.php');
    }
}
?>
<?php include 'includes/header.php'; ?>
<?php include 'includes/nav.php'; ?>

<style>
.payg-page {
  background: #f4f1ea;
  min-height: calc(100vh - 120px);
  padding: 40px 20px 60px;
}
.payg-shell {
  max-width: 800px;
  margin: 0 auto;
}
.payg-card {
  background: #ffffff;
  border-radius: 28px;
  padding: 32px;
  box-shadow: 0 12px 30px rgba(0,0,0,0.08);
}
.payg-card h1 {
  margin-top: 0;
}
.payg-total {
  font-size: 42px;
  font-weight: 800;
  color: #d4af37;
  margin: 12px 0 18px;
}
.choice-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin-top: 24px;
}
.choice-box {
  background: #f7f4ee;
  border-radius: 20px;
  padding: 22px;
}
.choice-box h3 {
  margin-top: 0;
}
.choice-box button {
  margin-top: 14px;
  border: none;
  border-radius: 999px;
  padding: 14px 18px;
  font-weight: 700;
  cursor: pointer;
}
.pay-now {
  background: #d4af37;
  color: #111111;
}
.pay-later {
  background: #111111;
  color: #ffffff;
}
@media (max-width: 700px) {
  .choice-grid {
    grid-template-columns: 1fr;
  }
}
</style>

<main class="payg-page">
  <div class="payg-shell">
    <div class="payg-card">
      <h1>Pay As You Go</h1>
      <p>You selected pay as you go for this custom plan.</p>

      <div class="payg-total">$<?= e(number_format((float)$plan['monthly_total'], 2)) ?></div>

      <div class="choice-grid">
        <form method="post" class="choice-box">
          <h3>Pay Now</h3>
          <p>Go to the payment portal now and complete payment immediately.</p>
          <input type="hidden" name="pay_choice" value="now">
          <button type="submit" class="pay-now">Pay Now</button>
        </form>

        <form method="post" class="choice-box">
          <h3>Pay Later</h3>
          <p>Save the plan now and come back later to complete payment.</p>
          <input type="hidden" name="pay_choice" value="later">
          <button type="submit" class="pay-later">Pay Later</button>
        </form>
      </div>
    </div>
  </div>
</main>

<?php include 'includes/footer.php'; ?>