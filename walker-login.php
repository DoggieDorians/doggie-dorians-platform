<?php
require_once __DIR__ . '/includes/member_config.php';

$errors = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $errors[] = 'Please enter your email and password.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM walkers
            WHERE email = :email
              AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([':email' => $email]);
        $walker = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$walker || !password_verify($password, $walker['password_hash'])) {
            $errors[] = 'Invalid walker login credentials.';
        } else {
            $_SESSION['walker_id'] = $walker['id'];
            redirectTo('walker-dashboard.php');
        }
    }
}
?>
<?php include 'includes/header.php'; ?>
<?php include 'includes/nav.php'; ?>

<style>
.walker-auth-page {
  background: #f4f1ea;
  min-height: calc(100vh - 120px);
  padding: 40px 20px 60px;
}
.walker-auth-shell {
  max-width: 720px;
  margin: 0 auto;
}
.walker-auth-card {
  background: #ffffff;
  border-radius: 28px;
  padding: 34px;
  box-shadow: 0 12px 30px rgba(0,0,0,0.08);
}
.walker-auth-card h1 {
  margin-top: 0;
}
.walker-auth-card p {
  color: #666666;
}
.message {
  border-radius: 14px;
  padding: 14px 16px;
  margin-bottom: 18px;
}
.message.error {
  background: #fff3f3;
  color: #9b1c1c;
}
.form-group {
  display: flex;
  flex-direction: column;
  margin-bottom: 16px;
}
.form-group label {
  font-weight: 700;
  margin-bottom: 8px;
}
.form-group input {
  padding: 14px 16px;
  border: 1px solid #ddd;
  border-radius: 16px;
  font-size: 15px;
}
.auth-button {
  display: inline-block;
  background: #d4af37;
  color: #111111;
  border: none;
  border-radius: 999px;
  padding: 14px 22px;
  font-weight: 700;
  cursor: pointer;
}
.demo-box {
  margin-top: 22px;
  background: #f7f4ee;
  border-radius: 18px;
  padding: 18px;
}
</style>

<main class="walker-auth-page">
  <div class="walker-auth-shell">
    <div class="walker-auth-card">
      <h1>Walker Portal Login</h1>
      <p>Log in to manage assigned walks, update tracking, and complete services.</p>

      <?php if ($errors): ?>
        <div class="message error">
          <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post">
        <div class="form-group">
          <label for="email">Walker Email</label>
          <input id="email" name="email" type="email" value="<?= e($email) ?>" required>
        </div>

        <div class="form-group">
          <label for="password">Password</label>
          <input id="password" name="password" type="password" required>
        </div>

        <button type="submit" class="auth-button">Log In</button>
      </form>

      <div class="demo-box">
        <strong>Demo walker login</strong><br>
        Email: walker@doggiedorians.com<br>
        Password: walker123
      </div>
    </div>
  </div>
</main>

<?php include 'includes/footer.php'; ?>