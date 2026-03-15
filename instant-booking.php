<?php
require_once __DIR__ . '/includes/member_config.php';

$errors = [];
$success = '';

$ownerName = '';
$email = '';
$phone = '';
$dogName = '';
$serviceType = '';
$walkDate = '';
$walkTime = '';
$notes = '';

$services = [
    'walk_15' => ['label' => '15 Minute Walk', 'price' => 23],
    'walk_20' => ['label' => '20 Minute Walk', 'price' => 25],
    'walk_30' => ['label' => '30 Minute Walk', 'price' => 30],
    'walk_45' => ['label' => '45 Minute Walk', 'price' => 38],
    'walk_60' => ['label' => '60 Minute Walk', 'price' => 40],
    'drop_in_30' => ['label' => '30 Minute Drop-In', 'price' => 20],
    'daycare' => ['label' => 'Daycare Day', 'price' => 45],
    'boarding_small' => ['label' => 'Boarding Night — Small Dog', 'price' => 80],
    'boarding_medium' => ['label' => 'Boarding Night — Medium Dog', 'price' => 100],
    'boarding_large' => ['label' => 'Boarding Night — Large Dog', 'price' => 120],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ownerName = trim($_POST['owner_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $dogName = trim($_POST['dog_name'] ?? '');
    $serviceType = trim($_POST['service_type'] ?? '');
    $walkDate = trim($_POST['walk_date'] ?? '');
    $walkTime = trim($_POST['walk_time'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($ownerName === '') {
        $errors[] = 'Please enter your name.';
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($phone === '') {
        $errors[] = 'Please enter your phone number.';
    }

    if ($dogName === '') {
        $errors[] = 'Please enter your dog’s name.';
    }

    if (!array_key_exists($serviceType, $services)) {
        $errors[] = 'Please select a valid service.';
    }

    if ($walkDate === '') {
        $errors[] = 'Please select a date.';
    }

    if ($walkTime === '') {
        $errors[] = 'Please select a time.';
    }

    if (!$errors) {
        $price = $services[$serviceType]['price'];
        $label = $services[$serviceType]['label'];

        $dataDir = __DIR__ . '/data';
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        $bookingFile = $dataDir . '/instant_bookings.json';

        $existing = [];
        if (file_exists($bookingFile)) {
            $decoded = json_decode((string)file_get_contents($bookingFile), true);
            if (is_array($decoded)) {
                $existing = $decoded;
            }
        }

        $existing[] = [
            'owner_name' => $ownerName,
            'email' => $email,
            'phone' => $phone,
            'dog_name' => $dogName,
            'service_type' => $serviceType,
            'service_label' => $label,
            'price' => $price,
            'walk_date' => $walkDate,
            'walk_time' => $walkTime,
            'notes' => $notes,
            'created_at' => date('Y-m-d H:i:s')
        ];

        file_put_contents($bookingFile, json_encode($existing, JSON_PRETTY_PRINT));

        $success = 'Your instant booking request has been submitted successfully.';

        $ownerName = '';
        $email = '';
        $phone = '';
        $dogName = '';
        $serviceType = '';
        $walkDate = '';
        $walkTime = '';
        $notes = '';
    }
}
?>
<?php include 'includes/header.php'; ?>
<?php include 'includes/nav.php'; ?>

<style>
.instant-page{
  background:#f4f1ea;
  min-height:calc(100vh - 120px);
  padding:32px 18px 60px;
}
.instant-shell{
  max-width:1180px;
  margin:0 auto;
  display:grid;
  gap:22px;
}
.instant-hero{
  background:linear-gradient(135deg,#111111 0%,#2b2414 100%);
  color:#fff;
  border-radius:28px;
  padding:34px 28px;
  box-shadow:0 14px 40px rgba(0,0,0,0.12);
}
.instant-hero h1{
  margin:0 0 10px;
  font-size:38px;
}
.instant-hero p{
  margin:0;
  max-width:780px;
  color:rgba(255,255,255,0.82);
  line-height:1.6;
}
.instant-grid{
  display:grid;
  grid-template-columns:1.05fr .95fr;
  gap:22px;
}
.instant-card{
  background:#fff;
  border-radius:24px;
  padding:24px;
  box-shadow:0 12px 30px rgba(0,0,0,0.07);
}
.instant-card h2{
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
.message.success{
  background:#f4fbf2;
  color:#256029;
}
.form-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:16px;
}
.form-group{
  display:flex;
  flex-direction:column;
}
.form-group.full{
  grid-column:1 / -1;
}
.form-group label{
  font-weight:700;
  margin-bottom:8px;
}
.form-group input,
.form-group select,
.form-group textarea{
  padding:14px 16px;
  border:1px solid #ddd;
  border-radius:16px;
  font-size:15px;
  font-family:Arial, sans-serif;
}
.form-group textarea{
  min-height:120px;
  resize:vertical;
}
.submit-button{
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
.price-list{
  display:grid;
  gap:12px;
}
.price-item{
  background:#f7f4ee;
  border-radius:18px;
  padding:14px 16px;
  display:flex;
  justify-content:space-between;
  gap:12px;
}
.price-item strong{
  color:#111;
}
.note-box{
  margin-top:18px;
  background:#111;
  color:#fff;
  border-radius:18px;
  padding:18px;
}
.note-box h3{
  margin:0 0 10px;
}
.note-box p{
  margin:0;
  color:rgba(255,255,255,0.82);
  line-height:1.6;
}
@media (max-width:960px){
  .instant-grid{
    grid-template-columns:1fr;
  }
}
@media (max-width:720px){
  .form-grid{
    grid-template-columns:1fr;
  }
  .instant-hero h1{
    font-size:30px;
  }
}
</style>

<main class="instant-page">
  <div class="instant-shell">

    <section class="instant-hero">
      <h1>Instant Booking for Non-Members</h1>
      <p>
        Need premium care without joining first? Submit a one-time booking request here.
        Choose your service, preferred date and time, and we’ll receive your request instantly.
      </p>
    </section>

    <section class="instant-grid">

      <div class="instant-card">
        <h2>Book Now</h2>

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
              <label for="owner_name">Your Name</label>
              <input id="owner_name" name="owner_name" type="text" value="<?= e($ownerName) ?>" required>
            </div>

            <div class="form-group">
              <label for="dog_name">Dog Name</label>
              <input id="dog_name" name="dog_name" type="text" value="<?= e($dogName) ?>" required>
            </div>

            <div class="form-group">
              <label for="email">Email</label>
              <input id="email" name="email" type="email" value="<?= e($email) ?>" required>
            </div>

            <div class="form-group">
              <label for="phone">Phone</label>
              <input id="phone" name="phone" type="text" value="<?= e($phone) ?>" required>
            </div>

            <div class="form-group full">
              <label for="service_type">Service</label>
              <select id="service_type" name="service_type" required>
                <option value="">Select a service</option>
                <?php foreach ($services as $key => $service): ?>
                  <option value="<?= e($key) ?>" <?= $serviceType === $key ? 'selected' : '' ?>>
                    <?= e($service['label']) ?> — $<?= e(number_format((float)$service['price'], 2)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label for="walk_date">Preferred Date</label>
              <input id="walk_date" name="walk_date" type="date" value="<?= e($walkDate) ?>" required>
            </div>

            <div class="form-group">
              <label for="walk_time">Preferred Time</label>
              <input id="walk_time" name="walk_time" type="time" value="<?= e($walkTime) ?>" required>
            </div>

            <div class="form-group full">
              <label for="notes">Notes</label>
              <textarea id="notes" name="notes" placeholder="Anything we should know about your dog, access instructions, temperament, or care needs."><?= e($notes) ?></textarea>
            </div>
          </div>

          <button type="submit" class="submit-button">Submit Instant Booking</button>
        </form>
      </div>

      <div class="instant-card">
        <h2>Non-Member Pricing</h2>

        <div class="price-list">
          <?php foreach ($services as $service): ?>
            <div class="price-item">
              <span><?= e($service['label']) ?></span>
              <strong>$<?= e(number_format((float)$service['price'], 2)) ?></strong>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="note-box">
          <h3>Want better pricing?</h3>
          <p>
            Members receive better rates and more flexible plan options. If you plan to book regularly,
            membership will usually give you stronger long-term value.
          </p>
        </div>
      </div>

    </section>

  </div>
</main>

<?php include 'includes/footer.php'; ?>