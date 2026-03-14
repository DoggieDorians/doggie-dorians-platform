<?php
require_once __DIR__ . '/includes/member_config.php';

$planId = (int)($_GET['plan_id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM custom_plans WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $planId]);
$plan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$plan) {
    redirectTo('customize-plan.php');
}
?>
<?php include 'includes/header.php'; ?>
<?php include 'includes/nav.php'; ?>

<style>
.payment-page {
  background: #f4f1ea;
  min-height: calc(100vh - 120px);
  padding: 40px 20px 60px;
}
.payment-shell {
  max-width: 800px;
  margin: 0 auto;
}
.payment-card {
  background: #ffffff;
  border-radius: 28px;
  padding: 32px;
  box-shadow: 0 12px 30px rgba(0,0,0,0.08);
}
.payment-card h1 {
  margin-top: 0;
}
.payment-total {
  font-size: 42px;
  font-weight: 800;
  color: #d4af37;
  margin: 12px 0 18px;
}
.payment-box {
  background: #f7f4ee;
  border-radius: 18px;
  padding: 18px;
  margin-top: 18px;
}
.payment-actions {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  margin-top: 24px;
}
.payment-button {
  display: inline-block;
  background: #d4af37;
  color: #111111;
  padding: 14px 20px;
  border-radius: 999px;
  font-weight: 700;
}
.secondary-button {
  display: inline-block;
  background: #111111;
  color: #ffffff;
  padding: 14px 20px;
  border-radius: 999px;
  font-weight: 700;
}
</style>

<main class="payment-page">
  <div class="payment-shell">
    <div class="payment-card">
      <h1>Upfront Payment Portal</h1>
      <p>You selected upfront payment for this membership plan.</p>

      <div class="payment-total">$<?= e(number_format((float)$plan['monthly_total'], 2)) ?></div>

      <div class="payment-box">
        <strong>Plan Name</strong><br>
        <?= e($plan['plan_name']) ?>
      </div>

      <div class="payment-box">
        This is where your real Stripe, Square, or other payment checkout will go.
      </div>

      <div class="payment-actions">
        <a href="dashboard.php" class="payment-button">Return to Dashboard</a>
        <a href="customize-plan.php" class="secondary-button">Back to Plans</a>
      </div>
    </div>
  </div>
</main>

<?php include 'includes/footer.php'; ?>