<?php
require_once __DIR__ . '/includes/member_config.php';

$errors = [];
$loginMethod = 'email';
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginMethod = trim($_POST['login_method'] ?? 'email');
    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!in_array($loginMethod, ['username', 'email', 'phone'], true)) {
        $errors[] = 'Please choose a valid login method.';
    }

    if ($identifier === '' || $password === '') {
        $errors[] = 'Please enter your login details.';
    }

    if (!$errors) {
        $column = match ($loginMethod) {
            'username' => 'username',
            'phone' => 'phone',
            default => 'email',
        };

        $stmt = $pdo->prepare("SELECT * FROM members WHERE {$column} = :identifier LIMIT 1");
        $stmt->execute([':identifier' => $identifier]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$member || !password_verify($password, $member['password_hash'])) {
            $errors[] = 'Invalid login credentials.';
        } elseif ((int)$member['email_verified'] !== 1) {
            $errors[] = 'Please verify your email before logging in.';
        } else {
            $_SESSION['member_id'] = $member['id'];
            redirectTo('dashboard.php');
        }
    }
}
?>
<?php include 'includes/header.php'; ?>
<?php include 'includes/nav.php'; ?>

<style>
.member-auth-wrap{
  max-width: 720px;
  margin: 60px auto;
  padding: 0 20px;
}
.member-auth-card{
  background:#fff;
  border-radius:24px;
  padding:36px;
  box-shadow:0 12px 30px rgba(0,0,0,0.08);
}
.member-auth-card h1{
  margin-top:0;
  font-size:36px;
}
.member-auth-card p.lead{
  color:#666;
  margin-bottom:24px;
}
.form-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:18px;
}
.form-group{
  display:flex;
  flex-direction:column;
}
.form-group.full{
  grid-column:1 / -1;
}
label{
  font-weight:600;
  margin-bottom:8px;
}
input, select{
  padding:14px 16px;
  border:1px solid #ddd;
  border-radius:14px;
  font-size:15px;
}
.auth-button{
  display:inline-block;
  background:#d4af37;
  color:#111;
  border:none;
  border-radius:999px;
  padding:14px 24px;
  font-weight:700;
  cursor:pointer;
}
.auth-links{
  margin-top:18px;
}
.auth-links a{
  color:#111;
  font-weight:600;
}
.message{
  border-radius:14px;
  padding:14px 16px;
  margin-bottom:18px;
}
.message.error{
  background:#fff3f3;
  color:#9b1c1c;
}
@media (max-width:700px){
  .form-grid{
    grid-template-columns:1fr;
  }
}
</style>

<main class="member-auth-wrap">
  <div class="member-auth-card">
    <h1>Member Login</h1>
    <p class="lead">Log in with the method you chose during signup.</p>

    <?php if ($errors): ?>
      <div class="message error">
        <?php foreach ($errors as $error): ?>
          <div><?= e($error) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" action="">
      <div class="form-grid">
        <div class="form-group">
          <label for="login_method">Log In With</label>
          <select id="login_method" name="login_method" required>
            <option value="email" <?= $loginMethod === 'email' ? 'selected' : '' ?>>Email</option>
            <option value="username" <?= $loginMethod === 'username' ? 'selected' : '' ?>>Username</option>
            <option value="phone" <?= $loginMethod === 'phone' ? 'selected' : '' ?>>Phone Number</option>
          </select>
        </div>

        <div class="form-group">
          <label for="identifier">Email / Username / Phone</label>
          <input id="identifier" name="identifier" type="text" value="<?= e($identifier) ?>" required>
        </div>

        <div class="form-group full">
          <label for="password">Password</label>
          <input id="password" name="password" type="password" required>
        </div>

        <div class="form-group full">
          <button class="auth-button" type="submit">Log In</button>
        </div>
      </div>
    </form>

    <div class="auth-links">
      Need an account? <a href="signup.php">Create one here</a>
    </div>
  </div>
</main>

<?php include 'includes/footer.php'; ?>