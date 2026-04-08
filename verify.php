<?php
require_once __DIR__ . '/includes/member_config.php';

$message = 'Invalid or expired verification link.';
$isSuccess = false;

$token = trim($_GET['token'] ?? '');

if ($token !== '') {
    $stmt = $pdo->prepare("SELECT id FROM members WHERE verification_token = :token LIMIT 1");
    $stmt->execute([':token' => $token]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($member) {
        $update = $pdo->prepare("
            UPDATE members
            SET email_verified = 1,
                verification_token = NULL
            WHERE id = :id
        ");
        $update->execute([':id' => $member['id']]);

        $message = 'Your email has been verified. You can now log in.';
        $isSuccess = true;
    }
}
?>
<?php include 'includes/header.php'; ?>
<?php include 'includes/nav.php'; ?>

<style>
.verify-wrap{
  max-width:700px;
  margin:60px auto;
  padding:0 20px;
}
.verify-card{
  background:#fff;
  border-radius:24px;
  padding:36px;
  box-shadow:0 12px 30px rgba(0,0,0,0.08);
  text-align:center;
}
.verify-card h1{
  margin-top:0;
}
.verify-message{
  padding:16px;
  border-radius:14px;
  margin:20px 0;
}
.verify-message.success{
  background:#f4fbf2;
  color:#256029;
}
.verify-message.error{
  background:#fff3f3;
  color:#9b1c1c;
}
.verify-link{
  display:inline-block;
  margin-top:12px;
  background:#d4af37;
  color:#111;
  padding:14px 22px;
  border-radius:999px;
  font-weight:700;
}
</style>

<main class="verify-wrap">
  <div class="verify-card">
    <h1>Email Verification</h1>
    <div class="verify-message <?= $isSuccess ? 'success' : 'error' ?>">
      <?= e($message) ?>
    </div>

    <?php if ($isSuccess): ?>
      <a class="verify-link" href="login.php">Go to Login</a>
    <?php endif; ?>
  </div>
</main>

<?php include 'includes/footer.php'; ?>