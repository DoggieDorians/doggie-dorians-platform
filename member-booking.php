<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/database/setup.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$userId = (int) $_SESSION['user_id'];

$userStmt = $pdo->prepare("
    SELECT id, email
    FROM users
    WHERE id = :id
    LIMIT 1
");
$userStmt->execute(['id' => $userId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$petsStmt = $pdo->prepare("
    SELECT id, pet_name, breed, size
    FROM pets
    WHERE user_id = :user_id
    ORDER BY pet_name ASC
");
$petsStmt->execute(['user_id' => $userId]);
$pets = $petsStmt->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
$success = '';

$form = [
    'pet_id' => '',
    'service_type' => 'walk',
    'service_date' => '',
    'service_time' => '',
    'duration_minutes' => '30',
    'price' => '25.00',
    'notes' => '',
];

$memberWalkRates = [
    15 => 18.00,
    20 => 20.00,
    30 => 25.00,
    45 => 30.00,
    60 => 34.00,
];

$daycareRate = 55.00;
$boardingRates = [
    'Small' => 80.00,
    'Medium' => 100.00,
    'Large' => 120.00,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['pet_id'] = trim((string) ($_POST['pet_id'] ?? ''));
    $form['service_type'] = trim((string) ($_POST['service_type'] ?? 'walk'));
    $form['service_date'] = trim((string) ($_POST['service_date'] ?? ''));
    $form['service_time'] = trim((string) ($_POST['service_time'] ?? ''));
    $form['duration_minutes'] = trim((string) ($_POST['duration_minutes'] ?? ''));
    $form['notes'] = trim((string) ($_POST['notes'] ?? ''));

    $petId = (int) $form['pet_id'];
    $serviceType = $form['service_type'];
    $serviceDate = $form['service_date'];
    $serviceTime = $form['service_time'];
    $durationMinutes = $form['duration_minutes'] !== '' ? (int) $form['duration_minutes'] : 0;

    if ($petId <= 0) {
        $errors[] = 'Please select a pet.';
    }

    if (!in_array($serviceType, ['walk', 'daycare', 'boarding'], true)) {
        $errors[] = 'Please select a valid service.';
    }

    if ($serviceDate === '') {
        $errors[] = 'Please select a service date.';
    }

    if ($serviceTime === '') {
        $errors[] = 'Please select a service time.';
    }

    $selectedPet = null;
    foreach ($pets as $pet) {
        if ((int) $pet['id'] === $petId) {
            $selectedPet = $pet;
            break;
        }
    }

    if (!$selectedPet) {
        $errors[] = 'Selected pet was not found under your account.';
    }

    $price = 0.00;

    if ($serviceType === 'walk') {
        if (!array_key_exists($durationMinutes, $memberWalkRates)) {
            $errors[] = 'Please select a valid walk duration.';
        } else {
            $price = $memberWalkRates[$durationMinutes];
        }
    } elseif ($serviceType === 'daycare') {
        $durationMinutes = 480;
        $price = $daycareRate;
    } elseif ($serviceType === 'boarding') {
        $durationMinutes = 1440;
        $petSize = ucfirst(strtolower((string) ($selectedPet['size'] ?? 'Small')));
        if (!array_key_exists($petSize, $boardingRates)) {
            $petSize = 'Small';
        }
        $price = $boardingRates[$petSize];
    }

    $form['price'] = number_format($price, 2, '.', '');

    if (!$errors) {
        $insertStmt = $pdo->prepare("
            INSERT INTO bookings (
                user_id,
                pet_id,
                assigned_walker_id,
                service_type,
                service_date,
                service_time,
                duration_minutes,
                status,
                price,
                walker_name,
                status_updated_by,
                status_updated_at
            ) VALUES (
                :user_id,
                :pet_id,
                NULL,
                :service_type,
                :service_date,
                :service_time,
                :duration_minutes,
                'pending',
                :price,
                NULL,
                'member',
                CURRENT_TIMESTAMP
            )
        ");

        $insertStmt->execute([
            'user_id' => $userId,
            'pet_id' => $petId,
            'service_type' => $serviceType,
            'service_date' => $serviceDate,
            'service_time' => $serviceTime,
            'duration_minutes' => $durationMinutes,
            'price' => $price,
        ]);

        $success = 'Booking request submitted successfully.';
        $form = [
            'pet_id' => '',
            'service_type' => 'walk',
            'service_date' => '',
            'service_time' => '',
            'duration_minutes' => '30',
            'price' => '25.00',
            'notes' => '',
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Member Booking | Doggie Dorian’s</title>
  <style>
    * {
      box-sizing: border-box;
    }

    :root {
      --bg: #070810;
      --panel: #11131b;
      --line: rgba(255,255,255,0.08);
      --text: #f7f4ee;
      --muted: rgba(247,244,238,0.68);
      --gold: #d4af37;
      --success: #5ed39a;
      --danger: #ff9898;
      --shadow: 0 20px 60px rgba(0,0,0,0.35);
    }

    body {
      margin: 0;
      background:
        radial-gradient(circle at top left, rgba(212,175,55,0.08), transparent 28%),
        linear-gradient(180deg, #090b13 0%, #05060b 100%);
      color: var(--text);
      font-family: Arial, Helvetica, sans-serif;
    }

    .wrap {
      max-width: 980px;
      margin: 0 auto;
      padding: 34px 22px 60px;
    }

    .topbar {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 16px;
      flex-wrap: wrap;
      margin-bottom: 24px;
    }

    .eyebrow {
      color: var(--gold);
      letter-spacing: 0.14em;
      text-transform: uppercase;
      font-size: 12px;
      font-weight: 700;
      margin-bottom: 10px;
    }

    h1 {
      margin: 0;
      font-size: 40px;
      line-height: 1;
      letter-spacing: -0.03em;
    }

    .subtext {
      margin-top: 12px;
      color: var(--muted);
      font-size: 15px;
      max-width: 720px;
    }

    .back-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 46px;
      padding: 0 18px;
      border-radius: 999px;
      text-decoration: none;
      font-weight: 700;
      border: 1px solid var(--line);
      background: rgba(255,255,255,0.02);
      color: var(--text);
    }

    .panel {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 24px;
      box-shadow: var(--shadow);
      overflow: hidden;
    }

    .panel-inner {
      padding: 24px;
    }

    .alert {
      margin-bottom: 18px;
      padding: 14px 16px;
      border-radius: 14px;
      font-weight: 700;
    }

    .alert.error {
      background: rgba(255,152,152,0.12);
      border: 1px solid rgba(255,152,152,0.28);
      color: #ffd7d7;
    }

    .alert.success {
      background: rgba(94,211,154,0.12);
      border: 1px solid rgba(94,211,154,0.28);
      color: #d8ffe8;
    }

    .empty-box {
      padding: 18px;
      border-radius: 16px;
      border: 1px solid var(--line);
      background: rgba(255,255,255,0.02);
      color: var(--muted);
      margin-bottom: 18px;
    }

    .empty-box a {
      color: var(--gold);
      text-decoration: none;
      font-weight: 700;
    }

    .grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 18px;
    }

    .field.full {
      grid-column: 1 / -1;
    }

    label {
      display: block;
      margin-bottom: 9px;
      font-size: 13px;
      font-weight: 800;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: var(--muted);
    }

    input,
    select,
    textarea {
      width: 100%;
      min-height: 52px;
      border-radius: 14px;
      border: 1px solid var(--line);
      background: #0d1018;
      color: var(--text);
      padding: 0 14px;
      font-size: 15px;
      outline: none;
    }

    textarea {
      min-height: 120px;
      resize: vertical;
      padding: 14px;
    }

    input:focus,
    select:focus,
    textarea:focus {
      border-color: rgba(212,175,55,0.55);
      box-shadow: 0 0 0 3px rgba(212,175,55,0.08);
    }

    .helper {
      margin-top: 8px;
      color: var(--muted);
      font-size: 13px;
    }

    .price-box {
      display: flex;
      align-items: center;
      min-height: 52px;
      padding: 0 14px;
      border-radius: 14px;
      border: 1px solid var(--line);
      background: rgba(255,255,255,0.03);
      font-size: 18px;
      font-weight: 800;
      color: var(--gold);
    }

    .button-row {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      margin-top: 22px;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 48px;
      padding: 0 18px;
      border-radius: 12px;
      text-decoration: none;
      font-size: 14px;
      font-weight: 800;
      border: none;
      cursor: pointer;
    }

    .btn-primary {
      background: var(--gold);
      color: #0a0a0f;
    }

    .btn-secondary {
      background: rgba(255,255,255,0.05);
      color: var(--text);
      border: 1px solid var(--line);
    }

    @media (max-width: 760px) {
      .grid {
        grid-template-columns: 1fr;
      }

      h1 {
        font-size: 31px;
      }

      .wrap {
        padding: 24px 14px 46px;
      }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="topbar">
      <div>
        <div class="eyebrow">Doggie Dorian’s Member</div>
        <h1>Book a Service</h1>
        <div class="subtext">
          Submit a member booking request using the same coordinated booking system now used by the admin dashboard.
        </div>
      </div>

      <a href="dashboard.php" class="back-btn">Back to Dashboard</a>
    </div>

    <div class="panel">
      <div class="panel-inner">
        <?php if ($success !== ''): ?>
          <div class="alert success"><?php echo e($success); ?></div>
        <?php endif; ?>

        <?php if ($errors): ?>
          <div class="alert error">
            <?php foreach ($errors as $error): ?>
              <div><?php echo e($error); ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if (!$pets): ?>
          <div class="empty-box">
            You need to add a pet before creating a booking.
            <br><br>
            <a href="add-pet.php">Add a Pet</a>
          </div>
        <?php else: ?>
          <form method="post" id="member-booking-form">
            <div class="grid">
              <div class="field">
                <label for="pet_id">Pet</label>
                <select name="pet_id" id="pet_id" required>
                  <option value="">Select pet</option>
                  <?php foreach ($pets as $pet): ?>
                    <option
                      value="<?php echo (int) $pet['id']; ?>"
                      data-size="<?php echo e((string) ($pet['size'] ?? 'Small')); ?>"
                      <?php echo ((string) $pet['id'] === $form['pet_id']) ? 'selected' : ''; ?>
                    >
                      <?php echo e($pet['pet_name'] . (!empty($pet['breed']) ? ' — ' . $pet['breed'] : '')); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="field">
                <label for="service_type">Service</label>
                <select name="service_type" id="service_type" required>
                  <option value="walk" <?php echo $form['service_type'] === 'walk' ? 'selected' : ''; ?>>Walk</option>
                  <option value="daycare" <?php echo $form['service_type'] === 'daycare' ? 'selected' : ''; ?>>Daycare</option>
                  <option value="boarding" <?php echo $form['service_type'] === 'boarding' ? 'selected' : ''; ?>>Boarding</option>
                </select>
              </div>

              <div class="field" id="duration-field">
                <label for="duration_minutes">Walk Duration</label>
                <select name="duration_minutes" id="duration_minutes">
                  <option value="15" <?php echo $form['duration_minutes'] === '15' ? 'selected' : ''; ?>>15 min — $18</option>
                  <option value="20" <?php echo $form['duration_minutes'] === '20' ? 'selected' : ''; ?>>20 min — $20</option>
                  <option value="30" <?php echo $form['duration_minutes'] === '30' ? 'selected' : ''; ?>>30 min — $25</option>
                  <option value="45" <?php echo $form['duration_minutes'] === '45' ? 'selected' : ''; ?>>45 min — $30</option>
                  <option value="60" <?php echo $form['duration_minutes'] === '60' ? 'selected' : ''; ?>>60 min — $34</option>
                </select>
              </div>

              <div class="field">
                <label>Estimated Price</label>
                <div class="price-box" id="price-box">$<?php echo e($form['price']); ?></div>
              </div>

              <div class="field">
                <label for="service_date">Service Date</label>
                <input type="date" name="service_date" id="service_date" value="<?php echo e($form['service_date']); ?>" required>
              </div>

              <div class="field">
                <label for="service_time">Service Time</label>
                <input type="time" name="service_time" id="service_time" value="<?php echo e($form['service_time']); ?>" required>
              </div>

              <div class="field full">
                <label for="notes">Notes</label>
                <textarea name="notes" id="notes" placeholder="Optional notes for your booking request..."><?php echo e($form['notes']); ?></textarea>
                <div class="helper">Notes are currently for your reference on the form and can be wired into a notes field next.</div>
              </div>
            </div>

            <div class="button-row">
              <button type="submit" class="btn btn-primary">Submit Booking Request</button>
              <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <script>
    (function () {
      const serviceType = document.getElementById('service_type');
      const duration = document.getElementById('duration_minutes');
      const petSelect = document.getElementById('pet_id');
      const durationField = document.getElementById('duration-field');
      const priceBox = document.getElementById('price-box');

      if (!serviceType || !duration || !petSelect || !durationField || !priceBox) {
        return;
      }

      const walkRates = {
        15: 18.00,
        20: 20.00,
        30: 25.00,
        45: 30.00,
        60: 34.00
      };

      const daycareRate = 55.00;
      const boardingRates = {
        Small: 80.00,
        Medium: 100.00,
        Large: 120.00
      };

      function getSelectedPetSize() {
        const option = petSelect.options[petSelect.selectedIndex];
        if (!option) return 'Small';
        return (option.dataset.size || 'Small').trim();
      }

      function updatePrice() {
        const type = serviceType.value;
        let price = 0;

        if (type === 'walk') {
          durationField.style.display = '';
          price = walkRates[duration.value] || 0;
        } else if (type === 'daycare') {
          durationField.style.display = 'none';
          price = daycareRate;
        } else if (type === 'boarding') {
          durationField.style.display = 'none';
          const size = getSelectedPetSize();
          price = boardingRates[size] || boardingRates.Small;
        }

        priceBox.textContent = '$' + Number(price).toFixed(2);
      }

      serviceType.addEventListener('change', updatePrice);
      duration.addEventListener('change', updatePrice);
      petSelect.addEventListener('change', updatePrice);

      updatePrice();
    })();
  </script>
</body>
</html>