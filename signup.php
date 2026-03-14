<?php
require_once __DIR__ . '/includes/member_config.php';

$errors = [];
$success = '';
$username = '';
$email = '';
$phone = '';
$preferredLogin = 'email';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $preferredLogin = trim($_POST['preferred_login'] ?? 'email');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($preferredLogin === 'username' && $username === '') {
        $errors[] = 'Please enter a username if you want to log in with a username.';
    }

    if ($preferredLogin === 'phone' && $phone === '') {
        $errors[] = 'Please enter a phone number if you want to log in with a phone number.';
    }

    if (!in_array($preferredLogin, ['username', 'email', 'phone'], true)) {
        $errors[] = 'Please choose a valid login method.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }

    if ($phone !== '' && !preg_match('/^[0-9\-\+\(\)\s]{7,20}$/', $phone)) {
        $errors[] = 'Please enter a valid phone number.';
    }

    if (!$errors) {
        $check = $pdo->prepare("
            SELECT id FROM members
            WHERE email = :email
               OR (:username <> '' AND username = :username)
               OR (:phone <> '' AND phone = :phone)
            LIMIT 1
        ");
        $check->execute([
            ':email' => $email,
            ':username' => $username,
            ':phone' => $phone
        ]);

        if ($check->fetch()) {
            $errors[] = 'An account with that email, username, or phone already exists.';
        }
    }

    if (!$errors) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $insert = $pdo->prepare("
            INSERT INTO members (username, email, phone, preferred_login, password_hash, email_verified, verification_token)
            VALUES (:username, :email, :phone, :preferred_login, :password_hash, 1, NULL)
        ");

        $insert->execute([
            ':username' => $username !== '' ? $username : null,
            ':email' => $email,
            ':phone' => $phone !== '' ? $phone : null,
            ':preferred_login' => $preferredLogin,
            ':password_hash' => $passwordHash
        ]);

        $success = "Account created successfully. Your account is automatically verified in local development, so you can log in right away.";
        $username = '';
        $email = '';
        $phone = '';
        $preferredLogin = 'email';
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
.message.success{
  background:#f4fbf2;
  color:#256029;
}
@media (max-width:700px){
  .form-grid{
    grid-template-columns:1fr;
  }
}
</style>

<main class="member-auth-wrap">
  <div class="member-auth-card">
    <h1>Create Your Member Account</h1>
    <p class="lead">Sign up for your Doggie Dorian's member account.</p>

    <?php if ($errors): ?>
      <div class="message error">
        <?php foreach ($errors as $error): ?>
          <div><?= e($error) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div class="message success"><?= e($success) ?></div>
    <?php endif; ?>

    <form method="post" action="">
      <div class="form-grid">
        <div class="form-group">
          <label for="username">Username</label>
          <input id="username" name="username" type="text" value="<?= e($username) ?>" placeholder="Choose a username">
        </div>

        <div class="form-group">
          <label for="email">Email Address</label>
          <input id="email" name="email" type="email" value="<?= e($email) ?>" required placeholder="you@example.com">
        </div>

        <div class="form-group">
          <label for="phone">Phone Number</label>
          <input id="phone" name="phone" type="text" value="<?= e($phone) ?>" placeholder="(631) 555-1234">
        </div>

        <div class="form-group">
          <label for="preferred_login">Preferred Login Method</label>
          <select id="preferred_login" name="preferred_login" required>
            <option value="email" <?= $preferredLogin === 'email' ? 'selected' : '' ?>>Email</option>
            <option value="username" <?= $preferredLogin === 'username' ? 'selected' : '' ?>>Username</option>
            <option value="phone" <?= $preferredLogin === 'phone' ? 'selected' : '' ?>>Phone Number</option>
          </select>
        </div>

        <div class="form-group">
          <label for="password">Password</label>
          <input id="password" name="password" type="password" required placeholder="At least 8 characters">
        </div>

        <div class="form-group">
          <label for="confirm_password">Confirm Password</label>
          <input id="confirm_password" name="confirm_password" type="password" required placeholder="Enter it again">
        </div>

        <div class="form-group full">
          <button class="auth-button" type="submit">Create Account</button>
        </div>
      </div>
    </form>

    <div class="auth-links">
      Already have an account? <a href="login.php">Log in here</a>
    </div>
  </div>
</main>

<?php include 'includes/footer.php'; ?>