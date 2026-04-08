<?php
session_start();

$isLoggedIn = isset($_SESSION['member_id']);

/**
 * CHANGE THIS:
 * Put the inbox where you want founder applications sent.
 */
$founderNotificationEmail = 'admin@doggiedorians.com';
$fromEmail = 'admin@doggiedorians.com';
$siteName = "Doggie Dorian's";

$founderPlans = [
    'founder-walk-club' => [
        'name' => 'Founder Walk Club',
        'price' => 250,
        'value' => 300,
        'slots' => 20,
        'tag' => 'Founder Membership',
        'summary' => 'A limited founder package built for clients who mainly want recurring walks, premium access, and exclusive founder-only perks.',
        'highlights' => [
            '12 included 30-minute walks each month',
            '1-month rollover on unused walks',
            'Quarterly founder credit usable toward renewal',
            'Private founder contact access',
        ],
    ],
    'founder-care-club' => [
        'name' => 'Founder Care Club',
        'price' => 499,
        'value' => 650,
        'slots' => 10,
        'tag' => 'Founder Membership',
        'summary' => 'A premium recurring care membership for clients who want more coverage across walks, daycare, drop-ins, and boarding value.',
        'highlights' => [
            '16 walks, 2 daycare days, and 2 drop-ins each month',
            '10% off boarding bookings',
            'Birthday gift and quarterly founder credit',
            'Private founder contact access',
        ],
    ],
    'founder-elite-club' => [
        'name' => 'Founder Elite Club',
        'price' => 899,
        'value' => 1100,
        'slots' => 5,
        'tag' => 'Founder Membership',
        'summary' => 'Your most exclusive founder package for clients who want premium recurring care, boarding value, and top-tier access.',
        'highlights' => [
            '20 walks, 4 daycare days, and 4 drop-ins each month',
            '3 complimentary boarding nights + 20% off additional boarding',
            'Highest founder scheduling priority',
            'Private founder contact access',
        ],
    ],
];

$selectedPlanKey = isset($_GET['plan']) ? trim((string)$_GET['plan']) : 'founder-care-club';
if (!isset($founderPlans[$selectedPlanKey])) {
    $selectedPlanKey = 'founder-care-club';
}
$selectedPlan = $founderPlans[$selectedPlanKey];

$formErrors = [];
$formSuccess = false;

$fullName = '';
$email = '';
$phone = '';
$petName = '';
$petBreed = '';
$petAge = '';
$serviceNeeds = '';
$notes = '';
$agreed = false;
$agreedTos = false;

function dd_ensure_data_dir(string $dir): bool
{
    if (is_dir($dir)) {
        return true;
    }

    return @mkdir($dir, 0775, true);
}

function dd_load_founder_applications(string $file): array
{
    if (!is_file($file)) {
        return [];
    }

    $raw = file_get_contents($file);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function dd_save_founder_applications(string $file, array $applications): bool
{
    return file_put_contents(
        $file,
        json_encode($applications, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    ) !== false;
}

function dd_send_email(string $to, string $subject, string $message, string $fromEmail): bool
{
    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-type: text/plain; charset=UTF-8';
    $headers[] = 'From: Doggie Dorian\'s <' . $fromEmail . '>';
    $headers[] = 'Reply-To: ' . $fromEmail;
    $headers[] = 'X-Mailer: PHP/' . phpversion();

    return @mail($to, $subject, $message, implode("\r\n", $headers));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedPlanKey = isset($_POST['founder_plan']) ? trim((string)$_POST['founder_plan']) : $selectedPlanKey;
    if (!isset($founderPlans[$selectedPlanKey])) {
        $selectedPlanKey = 'founder-care-club';
    }
    $selectedPlan = $founderPlans[$selectedPlanKey];

    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $petName = trim((string)($_POST['pet_name'] ?? ''));
    $petBreed = trim((string)($_POST['pet_breed'] ?? ''));
    $petAge = trim((string)($_POST['pet_age'] ?? ''));
    $serviceNeeds = trim((string)($_POST['service_needs'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $agreed = isset($_POST['agree_terms']) && $_POST['agree_terms'] === '1';
    $agreedTos = isset($_POST['agree_tos']) && $_POST['agree_tos'] === '1';

    if ($fullName === '') {
        $formErrors[] = 'Please enter your full name.';
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $formErrors[] = 'Please enter a valid email address.';
    }

    if ($phone === '') {
        $formErrors[] = 'Please enter your phone number.';
    }

    if ($petName === '') {
        $formErrors[] = 'Please enter your dog’s name.';
    }

    if ($serviceNeeds === '') {
        $formErrors[] = 'Please tell us how you expect to use the membership.';
    }

    if (!$agreed) {
        $formErrors[] = 'Please confirm that you understand founder memberships are limited and subject to review.';
    }

    if (!$agreedTos) {
        $formErrors[] = 'You must agree to the Terms of Service.';
    }

    if (empty($formErrors)) {
        $storageDir = __DIR__ . '/data';
        $storageFile = $storageDir . '/founder-applications.json';

        if (!dd_ensure_data_dir($storageDir)) {
            $formErrors[] = 'The application storage folder could not be created.';
        } else {
            $applications = dd_load_founder_applications($storageFile);

            $applicationId = uniqid('founder_', true);

            $applications[] = [
                'id' => $applicationId,
                'submitted_at' => date('Y-m-d H:i:s'),
                'plan_key' => $selectedPlanKey,
                'plan_name' => $selectedPlan['name'],
                'full_name' => $fullName,
                'email' => $email,
                'phone' => $phone,
                'pet_name' => $petName,
                'pet_breed' => $petBreed,
                'pet_age' => $petAge,
                'service_needs' => $serviceNeeds,
                'notes' => $notes,
                'status' => 'pending',
                'agree_terms' => true,
                'agree_tos' => true,
                'tos_version_date' => '2026-04-07',
            ];

            if (!dd_save_founder_applications($storageFile, $applications)) {
                $formErrors[] = 'Your application could not be saved right now. Please try again.';
            } else {
                $adminSubject = 'New Founder Membership Application - ' . $selectedPlan['name'];

                $adminMessage =
                    "A new founder membership application was submitted.\n\n" .
                    "Application ID: {$applicationId}\n" .
                    "Submitted: " . date('Y-m-d H:i:s') . "\n" .
                    "Plan: {$selectedPlan['name']}\n" .
                    "Applicant: {$fullName}\n" .
                    "Email: {$email}\n" .
                    "Phone: {$phone}\n" .
                    "Dog Name: {$petName}\n" .
                    "Breed: {$petBreed}\n" .
                    "Age: {$petAge}\n\n" .
                    "Service Needs:\n{$serviceNeeds}\n\n" .
                    "Notes:\n{$notes}\n";

                dd_send_email($founderNotificationEmail, $adminSubject, $adminMessage, $fromEmail);

                $clientSubject = 'Founder Membership Request Received - ' . $siteName;

                $clientMessage =
                    "Hi {$fullName},\n\n" .
                    "We received your founder membership request for {$selectedPlan['name']}.\n\n" .
                    "Application ID: {$applicationId}\n" .
                    "Dog: {$petName}\n" .
                    "Submitted: " . date('Y-m-d H:i:s') . "\n\n" .
                    "Our team will review your request and follow up with the next step.\n\n" .
                    "Thank you,\n{$siteName}";

                dd_send_email($email, $clientSubject, $clientMessage, $fromEmail);

                $formSuccess = true;
                $fullName = '';
                $email = '';
                $phone = '';
                $petName = '';
                $petBreed = '';
                $petAge = '';
                $serviceNeeds = '';
                $notes = '';
                $agreed = false;
                $agreedTos = false;
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
  <title>Founder Membership Application | Doggie Dorian's</title>
  <meta name="description" content="Apply for limited founder membership access at Doggie Dorian's. Submit your request for Founder Walk Club, Founder Care Club, or Founder Elite Club.">

  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg: #07080b;
      --bg-soft: #0d1016;
      --text: #f6f1e8;
      --muted: #c9c0af;
      --soft: #9d968a;
      --gold: #d7b26a;
      --gold-light: #f0d59f;
      --white: #ffffff;
      --shadow: 0 22px 65px rgba(0,0,0,0.38);
      --max: 1180px;
      --danger: #ffb3a7;
      --danger-bg: rgba(255, 91, 60, 0.12);
      --success: #d8f3c3;
      --success-bg: rgba(111, 214, 91, 0.12);
    }
    body {
      font-family: "Georgia", "Times New Roman", serif;
      background:
        radial-gradient(circle at top, rgba(215,178,106,0.10), transparent 25%),
        linear-gradient(180deg, #06070a 0%, #0b0d12 45%, #06070a 100%);
      color: var(--text);
      line-height: 1.6;
    }
    a { color: inherit; text-decoration: none; }
    .container { width: min(var(--max), calc(100% - 30px)); margin: 0 auto; }
    .site-header {
      position: sticky; top: 0; z-index: 100; backdrop-filter: blur(14px);
      background: rgba(7, 8, 11, 0.78); border-bottom: 1px solid rgba(255,255,255,0.06);
    }
    .nav-wrap {
      display: flex; align-items: center; justify-content: space-between;
      gap: 20px; padding: 18px 0; flex-wrap: wrap;
    }
    .brand {
      font-size: 1.18rem; letter-spacing: 0.08em; text-transform: uppercase;
      color: var(--white); font-weight: 700;
    }
    .nav-links { display: flex; align-items: center; gap: 22px; flex-wrap: wrap; }
    .nav-links a { color: var(--muted); font-size: 0.95rem; transition: 0.22s ease; }
    .nav-links a:hover, .nav-links a.active { color: var(--gold); }
    .nav-actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .btn {
      display: inline-flex; align-items: center; justify-content: center; border-radius: 999px;
      padding: 13px 22px; font-size: 0.95rem; font-weight: 700; letter-spacing: 0.02em;
      transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease, background 0.22s ease;
      border: 1px solid transparent; cursor: pointer; text-align: center;
    }
    .btn:hover { transform: translateY(-2px); }
    .btn-gold {
      background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
      color: #15120d; box-shadow: 0 16px 38px rgba(215,178,106,0.22);
    }
    .btn-outline {
      border-color: rgba(215,178,106,0.45); background: rgba(255,255,255,0.02); color: var(--gold);
    }
    .btn-soft {
      border-color: rgba(255,255,255,0.08); background: rgba(255,255,255,0.03); color: var(--white);
    }
    .hero { padding: 70px 0 28px; }
    .hero-card {
      border-radius: 34px; border: 1px solid rgba(255,255,255,0.08);
      background:
        linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.02)),
        linear-gradient(135deg, rgba(215,178,106,0.10), rgba(255,255,255,0.02));
      box-shadow: var(--shadow); overflow: hidden; padding: 42px;
    }
    .eyebrow {
      display: inline-block; padding: 8px 14px; border-radius: 999px;
      border: 1px solid rgba(215,178,106,0.30); background: rgba(215,178,106,0.08);
      color: #f2d9a8; font-size: 0.78rem; letter-spacing: 0.14em;
      text-transform: uppercase; margin-bottom: 18px;
    }
    h1 {
      font-size: clamp(2.4rem, 5vw, 4.5rem); line-height: 0.98;
      color: var(--white); margin-bottom: 16px;
    }
    .hero p { color: var(--muted); max-width: 840px; font-size: 1.05rem; }
    .application-section { padding: 18px 0 80px; }
    .application-grid {
      display: grid; grid-template-columns: 0.95fr 1.05fr; gap: 24px; align-items: start;
    }
    .panel {
      border-radius: 28px; border: 1px solid rgba(255,255,255,0.08);
      background: linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.025));
      box-shadow: var(--shadow); padding: 28px;
    }
    .panel h2 { font-size: 1.7rem; color: var(--white); margin-bottom: 10px; }
    .panel p { color: var(--muted); margin-bottom: 18px; }
    .selected-plan {
      border-radius: 22px; padding: 22px; border: 1px solid rgba(215,178,106,0.20);
      background: rgba(215,178,106,0.08); margin-bottom: 18px;
    }
    .selected-plan .tag {
      display: inline-block; padding: 6px 10px; border-radius: 999px;
      border: 1px solid rgba(215,178,106,0.30); color: #f3d9a8; font-size: 0.75rem;
      text-transform: uppercase; letter-spacing: 0.10em; margin-bottom: 10px;
    }
    .selected-plan h3 { color: var(--white); font-size: 1.5rem; margin-bottom: 6px; }
    .selected-plan .price {
      color: #f5dcaf; font-size: 2rem; font-weight: 700; line-height: 1; margin-bottom: 8px;
    }
    .selected-plan .value { color: var(--muted); font-size: 0.96rem; margin-bottom: 10px; }
    .selected-plan .value strong { color: var(--white); }
    .selected-plan .slots {
      display: inline-block; padding: 7px 11px; border-radius: 999px;
      background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.08);
      color: var(--white); font-size: 0.88rem; font-weight: 700;
    }
    .plan-list {
      list-style: none; display: grid; gap: 10px; margin-top: 14px;
    }
    .plan-list li {
      position: relative; padding-left: 20px; color: var(--muted); font-size: 0.95rem;
    }
    .plan-list li::before {
      content: "◆"; position: absolute; left: 0; top: 4px; color: var(--gold); font-size: 0.72rem;
    }
    .note-box {
      border-radius: 18px; padding: 16px 18px; background: rgba(255,255,255,0.03);
      border: 1px solid rgba(255,255,255,0.08); color: var(--muted); font-size: 0.95rem;
    }
    .alert {
      border-radius: 18px; padding: 16px 18px; margin-bottom: 18px;
      border: 1px solid transparent; font-size: 0.95rem;
    }
    .alert.error { background: var(--danger-bg); border-color: rgba(255, 91, 60, 0.22); color: var(--danger); }
    .alert.success { background: var(--success-bg); border-color: rgba(111, 214, 91, 0.22); color: var(--success); }
    .alert ul { padding-left: 18px; }
    form { display: grid; gap: 18px; }
    .field-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .field { display: grid; gap: 8px; }
    label { color: var(--white); font-size: 0.94rem; font-weight: 700; }
    input, select, textarea {
      width: 100%; border-radius: 16px; border: 1px solid rgba(255,255,255,0.10);
      background: rgba(255,255,255,0.04); color: var(--text); padding: 14px 15px;
      font-size: 0.96rem; outline: none; transition: border-color 0.2s ease, background 0.2s ease;
    }
    input:focus, select:focus, textarea:focus {
      border-color: rgba(215,178,106,0.55); background: rgba(255,255,255,0.06);
    }
    textarea { min-height: 120px; resize: vertical; }
    .checkbox-wrap {
      display: flex; gap: 12px; align-items: flex-start; border-radius: 18px;
      padding: 14px 16px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08);
    }
    .checkbox-wrap input {
      width: 18px; height: 18px; margin-top: 3px; flex: 0 0 auto;
    }
    .checkbox-wrap span {
      color: var(--muted); font-size: 0.94rem;
    }
    .checkbox-wrap span a {
      color: var(--gold-light);
      text-decoration: underline;
      text-underline-offset: 2px;
    }
    .micro { color: var(--soft); font-size: 0.88rem; }
    @media (max-width: 980px) {
      .application-grid, .field-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 640px) {
      .container { width: min(var(--max), calc(100% - 20px)); }
      .hero-card, .panel { padding: 22px 18px; }
      .btn { width: 100%; }
      .nav-wrap { flex-direction: column; align-items: flex-start; }
      .nav-actions, .nav-links { width: 100%; }
      .nav-actions a { flex: 1; }
    }
  </style>
</head>
<body>

  <header class="site-header">
    <div class="container nav-wrap">
      <a href="index.php" class="brand">Doggie Dorian's</a>

      <nav class="nav-links">
        <a href="index.php">Home</a>
        <a href="services.php">Services</a>
        <a href="memberships.php" class="active">Memberships</a>
        <a href="book-walk.php">Book</a>
        <a href="contact.php">Contact</a>
      </nav>

      <div class="nav-actions">
        <?php if ($isLoggedIn): ?>
          <a href="dashboard.php" class="btn btn-soft">Dashboard</a>
        <?php else: ?>
          <a href="login.php" class="btn btn-soft">Member Login</a>
        <?php endif; ?>
        <a href="book-walk.php" class="btn btn-gold">Book a Service</a>
      </div>
    </div>
  </header>

  <main>
    <section class="hero">
      <div class="container">
        <div class="hero-card">
          <div class="eyebrow">Founder Application</div>
          <h1>Apply for limited founder membership access.</h1>
          <p>
            Founder memberships are intentionally limited and reviewed before approval. Submit your request below,
            choose the founder tier that fits your routine, and we’ll review your application before moving you into the next step.
          </p>
        </div>
      </div>
    </section>

    <section class="application-section">
      <div class="container">
        <div class="application-grid">

          <div class="panel">
            <h2>Selected founder tier</h2>
            <p>Your application is currently set to the plan below. Changing the founder tier updates this panel instantly.</p>

            <div class="selected-plan">
              <div class="tag" id="selected-plan-tag"><?php echo htmlspecialchars($selectedPlan['tag']); ?></div>
              <h3 id="selected-plan-name"><?php echo htmlspecialchars($selectedPlan['name']); ?></h3>
              <div class="price" id="selected-plan-price">$<?php echo number_format($selectedPlan['price']); ?> / month</div>
              <div class="value" id="selected-plan-value">
                <strong>$<?php echo number_format($selectedPlan['value']); ?> value</strong>
                • Save $<?php echo number_format($selectedPlan['value'] - $selectedPlan['price']); ?>/month
              </div>
              <div class="slots" id="selected-plan-slots">Only <?php echo (int)$selectedPlan['slots']; ?> spots available</div>
              <p id="selected-plan-summary"><?php echo htmlspecialchars($selectedPlan['summary']); ?></p>

              <ul class="plan-list" id="selected-plan-highlights">
                <?php foreach ($selectedPlan['highlights'] as $highlight): ?>
                  <li><?php echo htmlspecialchars($highlight); ?></li>
                <?php endforeach; ?>
              </ul>
            </div>

            <div class="note-box">
              Applications are saved to your server, sent to your founder notification inbox, and can be reviewed later in your admin workflow.
            </div>
          </div>

          <div class="panel">
            <h2>Founder membership request</h2>
            <p>Tell us a bit about you, your dog, and how you expect to use your founder membership.</p>

            <?php if (!empty($formErrors)): ?>
              <div class="alert error">
                <ul>
                  <?php foreach ($formErrors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>

            <?php if ($formSuccess): ?>
              <div class="alert success">
                Your founder application has been submitted successfully. We’ll review it and follow up with the next step.
              </div>
            <?php endif; ?>

            <form method="post" id="founder-application-form" action="founder-application.php?plan=<?php echo urlencode($selectedPlanKey); ?>">
              <div class="field">
                <label for="founder_plan">Founder tier</label>
                <select name="founder_plan" id="founder_plan" required>
                  <?php foreach ($founderPlans as $planKey => $plan): ?>
                    <option value="<?php echo htmlspecialchars($planKey); ?>" <?php echo $selectedPlanKey === $planKey ? 'selected' : ''; ?>>
                      <?php echo htmlspecialchars($plan['name']); ?> — $<?php echo number_format($plan['price']); ?>/month
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="field-grid">
                <div class="field">
                  <label for="full_name">Full name</label>
                  <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($fullName); ?>" required>
                </div>

                <div class="field">
                  <label for="email">Email address</label>
                  <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
                </div>
              </div>

              <div class="field-grid">
                <div class="field">
                  <label for="phone">Phone number</label>
                  <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($phone); ?>" required>
                </div>

                <div class="field">
                  <label for="pet_name">Dog’s name</label>
                  <input type="text" id="pet_name" name="pet_name" value="<?php echo htmlspecialchars($petName); ?>" required>
                </div>
              </div>

              <div class="field-grid">
                <div class="field">
                  <label for="pet_breed">Breed</label>
                  <input type="text" id="pet_breed" name="pet_breed" value="<?php echo htmlspecialchars($petBreed); ?>">
                </div>

                <div class="field">
                  <label for="pet_age">Age</label>
                  <input type="text" id="pet_age" name="pet_age" value="<?php echo htmlspecialchars($petAge); ?>">
                </div>
              </div>

              <div class="field">
                <label for="service_needs">How do you expect to use this membership?</label>
                <textarea id="service_needs" name="service_needs" required><?php echo htmlspecialchars($serviceNeeds); ?></textarea>
              </div>

              <div class="field">
                <label for="notes">Anything else you want us to know?</label>
                <textarea id="notes" name="notes"><?php echo htmlspecialchars($notes); ?></textarea>
              </div>

              <label class="checkbox-wrap">
                <input type="checkbox" name="agree_terms" value="1" <?php echo $agreed ? 'checked' : ''; ?>>
                <span>I understand founder memberships are limited, subject to review, and not guaranteed until approved.</span>
              </label>

              <label class="checkbox-wrap">
                <input type="checkbox" name="agree_tos" value="1" <?php echo $agreedTos ? 'checked' : ''; ?> required>
                <span>I agree to the <a href="tos.php" target="_blank">Terms of Service</a>.</span>
              </label>

              <button type="submit" class="btn btn-gold">Submit Founder Request</button>
              <div class="micro">Next step after approval: founder onboarding and payment setup.</div>
            </form>
          </div>

        </div>
      </div>
    </section>
  </main>

  <script>
    const founderPlans = <?php echo json_encode($founderPlans, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

    const founderPlanSelect = document.getElementById('founder_plan');
    const applicationForm = document.getElementById('founder-application-form');

    const selectedPlanTag = document.getElementById('selected-plan-tag');
    const selectedPlanName = document.getElementById('selected-plan-name');
    const selectedPlanPrice = document.getElementById('selected-plan-price');
    const selectedPlanValue = document.getElementById('selected-plan-value');
    const selectedPlanSlots = document.getElementById('selected-plan-slots');
    const selectedPlanSummary = document.getElementById('selected-plan-summary');
    const selectedPlanHighlights = document.getElementById('selected-plan-highlights');

    function formatCurrency(value) {
      return '$' + Number(value).toLocaleString('en-US');
    }

    function renderSelectedPlan(planKey) {
      if (!founderPlans[planKey]) {
        return;
      }

      const plan = founderPlans[planKey];
      const savings = Number(plan.value) - Number(plan.price);

      selectedPlanTag.textContent = plan.tag;
      selectedPlanName.textContent = plan.name;
      selectedPlanPrice.textContent = formatCurrency(plan.price) + ' / month';
      selectedPlanValue.innerHTML = '<strong>' + formatCurrency(plan.value) + ' value</strong> • Save ' + formatCurrency(savings) + '/month';
      selectedPlanSlots.textContent = 'Only ' + plan.slots + ' spots available';
      selectedPlanSummary.textContent = plan.summary;

      selectedPlanHighlights.innerHTML = '';
      plan.highlights.forEach(function (highlight) {
        const li = document.createElement('li');
        li.textContent = highlight;
        selectedPlanHighlights.appendChild(li);
      });

      if (applicationForm) {
        applicationForm.action = 'founder-application.php?plan=' + encodeURIComponent(planKey);
      }

      const nextUrl = new URL(window.location.href);
      nextUrl.searchParams.set('plan', planKey);
      window.history.replaceState({}, '', nextUrl.toString());
    }

    if (founderPlanSelect) {
      founderPlanSelect.addEventListener('change', function () {
        renderSelectedPlan(this.value);
      });
    }
  </script>
</body>
</html>