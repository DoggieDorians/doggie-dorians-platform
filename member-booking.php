<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/pricing.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection is not available.');
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect_to(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function current_user_role(): string
{
    $role = strtolower(trim((string) ($_SESSION['role'] ?? $_SESSION['user_role'] ?? '')));

    if ($role !== '') {
        return $role;
    }

    if (!empty($_SESSION['is_admin']) || !empty($_SESSION['admin_logged_in'])) {
        return 'admin';
    }

    if (!empty($_SESSION['walker_id']) || !empty($_SESSION['staff_id']) || !empty($_SESSION['employee_id'])) {
        return 'walker';
    }

    return 'member';
}

function current_user_id(): int
{
    foreach (['user_id', 'id', 'member_id', 'client_id'] as $key) {
        if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) {
            return (int) $_SESSION[$key];
        }
    }

    return 0;
}

function table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :name LIMIT 1");
        $stmt->execute([':name' => $table]);
        return $cache[$table] = (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        return $cache[$table] = false;
    }
}

function get_table_columns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!table_exists($pdo, $table)) {
        return $cache[$table] = [];
    }

    try {
        $safeTable = str_replace('"', '""', $table);
        $stmt = $pdo->query('PRAGMA table_info("' . $safeTable . '")');
        $columns = [];

        if ($stmt) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($row['name'])) {
                    $columns[] = (string) $row['name'];
                }
            }
        }

        return $cache[$table] = $columns;
    } catch (Throwable) {
        return $cache[$table] = [];
    }
}

function first_existing_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function booking_table(PDO $pdo): ?string
{
    foreach (['bookings', 'walks'] as $candidate) {
        if (table_exists($pdo, $candidate)) {
            return $candidate;
        }
    }

    return null;
}

function booking_table_map(PDO $pdo, string $table): array
{
    $columns = get_table_columns($pdo, $table);

    return [
        'user_id' => first_existing_column($columns, ['user_id', 'member_id', 'client_id']),
        'pet_id' => first_existing_column($columns, ['pet_id', 'dog_id']),
        'service_type' => first_existing_column($columns, ['service_type', 'type', 'booking_type', 'service']),
        'service_date' => first_existing_column($columns, ['service_date', 'booking_date', 'walk_date', 'date', 'scheduled_date', 'start_date']),
        'service_time' => first_existing_column($columns, ['service_time', 'booking_time', 'walk_time', 'time', 'scheduled_time', 'start_time']),
        'duration_minutes' => first_existing_column($columns, ['duration_minutes', 'duration', 'minutes']),
        'status' => first_existing_column($columns, ['status', 'booking_status', 'walk_status']),
        'price' => first_existing_column($columns, ['price', 'total_price', 'amount']),
        'notes' => first_existing_column($columns, ['client_notes', 'notes', 'special_instructions', 'instructions']),
        'created_at' => first_existing_column($columns, ['created_at']),
        'updated_at' => first_existing_column($columns, ['updated_at']),
        'pricing_type' => first_existing_column($columns, ['pricing_type']),
        'unit_price' => first_existing_column($columns, ['unit_price']),
        'discount_label' => first_existing_column($columns, ['discount_label']),
        'quantity' => first_existing_column($columns, ['quantity']),
        'assigned_walker_id' => first_existing_column($columns, ['assigned_walker_id', 'walker_id']),
        'walker_name' => first_existing_column($columns, ['walker_name']),
        'status_updated_by' => first_existing_column($columns, ['status_updated_by']),
        'status_updated_at' => first_existing_column($columns, ['status_updated_at']),
        'is_instant_booking' => first_existing_column($columns, ['is_instant_booking']),
    ];
}

function get_user_record(PDO $pdo, int $userId): ?array
{
    foreach (['users', 'members', 'client_profiles'] as $table) {
        if (!table_exists($pdo, $table)) {
            continue;
        }

        $columns = get_table_columns($pdo, $table);
        $idCol = first_existing_column($columns, ['id', 'user_id', 'member_id', 'client_id']);
        if ($idCol === null) {
            continue;
        }

        $select = [$idCol . ' AS record_id'];

        foreach (['email', 'full_name', 'name', 'first_name', 'last_name'] as $field) {
            if (in_array($field, $columns, true)) {
                $select[] = $field;
            }
        }

        $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM ' . $table . ' WHERE ' . $idCol . ' = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            return $row;
        }
    }

    return null;
}

function get_pets_for_user(PDO $pdo, int $userId): array
{
    $results = [];

    foreach (['pets', 'dogs'] as $table) {
        if (!table_exists($pdo, $table)) {
            continue;
        }

        $columns = get_table_columns($pdo, $table);
        $idCol = first_existing_column($columns, ['id', 'pet_id', 'dog_id']);
        $ownerCol = first_existing_column($columns, ['user_id', 'member_id', 'owner_id', 'client_id']);
        $nameCol = first_existing_column($columns, ['pet_name', 'dog_name', 'name']);
        $breedCol = first_existing_column($columns, ['breed']);
        $sizeCol = first_existing_column($columns, ['size']);

        if ($idCol === null || $ownerCol === null || $nameCol === null) {
            continue;
        }

        $sql = "SELECT {$idCol} AS pet_id, {$nameCol} AS pet_name";
        $sql .= $breedCol !== null ? ", {$breedCol} AS breed" : ", '' AS breed";
        $sql .= $sizeCol !== null ? ", {$sizeCol} AS size" : ", '' AS size";
        $sql .= " FROM {$table} WHERE {$ownerCol} = :user_id ORDER BY {$nameCol} ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            foreach ($rows as $row) {
                $results[] = [
                    'pet_id' => (int) ($row['pet_id'] ?? 0),
                    'pet_name' => (string) ($row['pet_name'] ?? ''),
                    'breed' => (string) ($row['breed'] ?? ''),
                    'size' => strtolower(trim((string) ($row['size'] ?? ''))),
                ];
            }
            break;
        }
    }

    return $results;
}

function member_price_for_service(string $serviceType, int $durationMinutes, string $dogSize): float
{
    $pricing = dd_pricing_matrix();

    return match ($serviceType) {
        'walk' => (float) ($pricing['walk']['member'][$durationMinutes] ?? 0),
        'daycare' => (float) ($pricing['daycare']['member']['base_rate'] ?? 0),
        'boarding' => (float) ($pricing['boarding']['member'][$dogSize] ?? 0),
        default => 0.0,
    };
}

$userId = current_user_id();
$role = current_user_role();

if ($userId <= 0 || in_array($role, ['admin', 'walker', 'staff', 'employee'], true)) {
    redirect_to('login.php');
}

$user = get_user_record($pdo, $userId);
if (!$user) {
    $_SESSION = [];
    session_destroy();
    redirect_to('login.php');
}

$pets = get_pets_for_user($pdo, $userId);

$errors = [];
$success = '';

$form = [
    'pet_id' => '',
    'service_type' => 'walk',
    'service_date' => '',
    'service_time' => '',
    'duration_minutes' => '30',
    'notes' => '',
];

$memberWalkRates = dd_pricing_matrix()['walk']['member'];
$memberDaycareBase = (float) dd_pricing_matrix()['daycare']['member']['base_rate'];
$memberBoardingRates = dd_pricing_matrix()['boarding']['member'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['pet_id'] = trim((string) ($_POST['pet_id'] ?? ''));
    $form['service_type'] = strtolower(trim((string) ($_POST['service_type'] ?? 'walk')));
    $form['service_date'] = trim((string) ($_POST['service_date'] ?? ''));
    $form['service_time'] = trim((string) ($_POST['service_time'] ?? ''));
    $form['duration_minutes'] = trim((string) ($_POST['duration_minutes'] ?? '30'));
    $form['notes'] = trim((string) ($_POST['notes'] ?? ''));

    $petId = (int) $form['pet_id'];
    $serviceType = $form['service_type'];
    $serviceDate = $form['service_date'];
    $serviceTime = $form['service_time'];
    $durationMinutes = (int) $form['duration_minutes'];

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
        if ((int) $pet['pet_id'] === $petId) {
            $selectedPet = $pet;
            break;
        }
    }

    if (!$selectedPet) {
        $errors[] = 'Selected pet was not found under your account.';
    }

    $dogSize = $selectedPet['size'] ?? 'small';
    if (!in_array($dogSize, ['small', 'medium', 'large'], true)) {
        $dogSize = 'small';
    }

    if ($serviceType === 'walk' && !array_key_exists($durationMinutes, $memberWalkRates)) {
        $errors[] = 'Please select a valid walk duration.';
    }

    if ($serviceType === 'daycare') {
        $durationMinutes = (int) (dd_pricing_matrix()['daycare']['member']['hours'] ?? 6) * 60;
    }

    if ($serviceType === 'boarding') {
        $durationMinutes = 1440;
    }

    $price = member_price_for_service($serviceType, $durationMinutes, $dogSize);

    if ($price <= 0) {
        $errors[] = 'Could not calculate pricing for this booking.';
    }

    $table = booking_table($pdo);
    if ($table === null) {
        $errors[] = 'No booking table was found in the database.';
    }

    if (!$errors && $table !== null) {
        $map = booking_table_map($pdo, $table);
        $insertData = [];

        if ($map['user_id'] !== null) {
            $insertData[$map['user_id']] = $userId;
        }
        if ($map['pet_id'] !== null) {
            $insertData[$map['pet_id']] = $petId;
        }
        if ($map['service_type'] !== null) {
            $insertData[$map['service_type']] = $serviceType;
        }
        if ($map['service_date'] !== null) {
            $insertData[$map['service_date']] = $serviceDate;
        }
        if ($map['service_time'] !== null) {
            $insertData[$map['service_time']] = $serviceTime;
        }
        if ($map['duration_minutes'] !== null) {
            $insertData[$map['duration_minutes']] = $durationMinutes;
        }
        if ($map['status'] !== null) {
            $insertData[$map['status']] = 'pending';
        }
        if ($map['price'] !== null) {
            $insertData[$map['price']] = $price;
        }
        if ($map['notes'] !== null) {
            $insertData[$map['notes']] = $form['notes'];
        }
        if ($map['created_at'] !== null) {
            $insertData[$map['created_at']] = date('Y-m-d H:i:s');
        }
        if ($map['updated_at'] !== null) {
            $insertData[$map['updated_at']] = date('Y-m-d H:i:s');
        }
        if ($map['pricing_type'] !== null) {
            $insertData[$map['pricing_type']] = 'member';
        }
        if ($map['unit_price'] !== null) {
            $insertData[$map['unit_price']] = $price;
        }
        if ($map['discount_label'] !== null) {
            $insertData[$map['discount_label']] = 'standard_member';
        }
        if ($map['quantity'] !== null) {
            $insertData[$map['quantity']] = 1;
        }
        if ($map['assigned_walker_id'] !== null) {
            $insertData[$map['assigned_walker_id']] = null;
        }
        if ($map['walker_name'] !== null) {
            $insertData[$map['walker_name']] = null;
        }
        if ($map['status_updated_by'] !== null) {
            $insertData[$map['status_updated_by']] = 'member';
        }
        if ($map['status_updated_at'] !== null) {
            $insertData[$map['status_updated_at']] = date('Y-m-d H:i:s');
        }
        if ($map['is_instant_booking'] !== null) {
            $insertData[$map['is_instant_booking']] = 0;
        }

        if (empty($insertData)) {
            $errors[] = 'Booking table mapping could not be built safely.';
        } else {
            $fields = array_keys($insertData);
            $placeholders = array_map(static fn(string $field): string => ':' . $field, $fields);
            $params = [];

            foreach ($insertData as $field => $value) {
                $params[':' . $field] = $value;
            }

            $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $success = 'Booking request submitted successfully.';
            $form = [
                'pet_id' => '',
                'service_type' => 'walk',
                'service_date' => '',
                'service_time' => '',
                'duration_minutes' => '30',
                'notes' => '',
            ];
        }
    }
}

$currentServiceType = $form['service_type'];
$currentDuration = (int) $form['duration_minutes'];
$currentPetId = (int) $form['pet_id'];
$currentDogSize = 'small';

foreach ($pets as $pet) {
    if ((int) $pet['pet_id'] === $currentPetId) {
        $candidateSize = strtolower(trim((string) ($pet['size'] ?? 'small')));
        if (in_array($candidateSize, ['small', 'medium', 'large'], true)) {
            $currentDogSize = $candidateSize;
        }
        break;
    }
}

$currentPrice = member_price_for_service(
    $currentServiceType,
    $currentServiceType === 'walk' ? max(15, $currentDuration) : ($currentServiceType === 'daycare' ? 360 : 1440),
    $currentDogSize
);
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
          Submit a member booking request using live member pricing from your central pricing engine.
        </div>
      </div>

      <a href="dashboard.php" class="back-btn">Back to Dashboard</a>
    </div>

    <div class="panel">
      <div class="panel-inner">
        <?php if ($success !== ''): ?>
          <div class="alert success"><?= e($success) ?></div>
        <?php endif; ?>

        <?php if ($errors): ?>
          <div class="alert error"><?= e(implode(' ', $errors)) ?></div>
        <?php endif; ?>

        <?php if (empty($pets)): ?>
          <div class="empty-box">
            No pets were found on your account yet. Please add your dog first, then come back here to book service.
            <br><br>
            <a href="add-pet.php">Add a pet →</a>
          </div>
        <?php else: ?>
          <form method="post" action="">
            <div class="grid">
              <div class="field">
                <label for="pet_id">Pet</label>
                <select name="pet_id" id="pet_id" required>
                  <option value="">Select your pet</option>
                  <?php foreach ($pets as $pet): ?>
                    <option
                      value="<?= (int) $pet['pet_id'] ?>"
                      data-size="<?= e($pet['size'] !== '' ? $pet['size'] : 'small') ?>"
                      <?= (int) $form['pet_id'] === (int) $pet['pet_id'] ? 'selected' : '' ?>
                    >
                      <?= e($pet['pet_name']) ?><?= $pet['breed'] !== '' ? ' • ' . e($pet['breed']) : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="field">
                <label for="service_type">Service</label>
                <select name="service_type" id="service_type" required>
                  <option value="walk" <?= $form['service_type'] === 'walk' ? 'selected' : '' ?>>Walk</option>
                  <option value="daycare" <?= $form['service_type'] === 'daycare' ? 'selected' : '' ?>>Daycare</option>
                  <option value="boarding" <?= $form['service_type'] === 'boarding' ? 'selected' : '' ?>>Boarding</option>
                </select>
              </div>

              <div class="field">
                <label for="service_date">Service Date</label>
                <input type="date" name="service_date" id="service_date" value="<?= e($form['service_date']) ?>" required>
              </div>

              <div class="field">
                <label for="service_time">Service Time</label>
                <input type="time" name="service_time" id="service_time" value="<?= e($form['service_time']) ?>" required>
              </div>

              <div class="field" id="durationField">
                <label for="duration_minutes">Walk Duration</label>
                <select name="duration_minutes" id="duration_minutes">
                  <option value="15" <?= $form['duration_minutes'] === '15' ? 'selected' : '' ?>>15 Minutes</option>
                  <option value="20" <?= $form['duration_minutes'] === '20' ? 'selected' : '' ?>>20 Minutes</option>
                  <option value="30" <?= $form['duration_minutes'] === '30' ? 'selected' : '' ?>>30 Minutes</option>
                  <option value="45" <?= $form['duration_minutes'] === '45' ? 'selected' : '' ?>>45 Minutes</option>
                  <option value="60" <?= $form['duration_minutes'] === '60' ? 'selected' : '' ?>>60 Minutes</option>
                </select>
                <div class="helper">Shown only for walks. Daycare and boarding use service-based pricing.</div>
              </div>

              <div class="field">
                <label>Estimated Member Price</label>
                <div class="price-box" id="priceBox">$<?= number_format($currentPrice, 2) ?></div>
              </div>

              <div class="field full">
                <label for="notes">Notes</label>
                <textarea name="notes" id="notes" placeholder="Anything important we should know for this booking."><?= e($form['notes']) ?></textarea>
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
      const walkRates = {
        15: <?= json_encode((float) ($memberWalkRates[15] ?? 0)) ?>,
        20: <?= json_encode((float) ($memberWalkRates[20] ?? 0)) ?>,
        30: <?= json_encode((float) ($memberWalkRates[30] ?? 0)) ?>,
        45: <?= json_encode((float) ($memberWalkRates[45] ?? 0)) ?>,
        60: <?= json_encode((float) ($memberWalkRates[60] ?? 0)) ?>
      };

      const daycarePrice = <?= json_encode((float) $memberDaycareBase) ?>;
      const boardingRates = {
        small: <?= json_encode((float) ($memberBoardingRates['small'] ?? 0)) ?>,
        medium: <?= json_encode((float) ($memberBoardingRates['medium'] ?? 0)) ?>,
        large: <?= json_encode((float) ($memberBoardingRates['large'] ?? 0)) ?>
      };

      const petSelect = document.getElementById('pet_id');
      const serviceSelect = document.getElementById('service_type');
      const durationSelect = document.getElementById('duration_minutes');
      const durationField = document.getElementById('durationField');
      const priceBox = document.getElementById('priceBox');

      function selectedDogSize() {
        const option = petSelect.options[petSelect.selectedIndex];
        const size = option ? String(option.dataset.size || 'small').toLowerCase() : 'small';
        return ['small', 'medium', 'large'].includes(size) ? size : 'small';
      }

      function updateVisibility() {
        const service = serviceSelect.value;
        durationField.style.display = service === 'walk' ? 'block' : 'none';
      }

      function updatePrice() {
        const service = serviceSelect.value;
        const duration = parseInt(durationSelect.value || '30', 10);
        const dogSize = selectedDogSize();

        let amount = 0;

        if (service === 'walk') {
          amount = walkRates[duration] || 0;
        } else if (service === 'daycare') {
          amount = daycarePrice;
        } else if (service === 'boarding') {
          amount = boardingRates[dogSize] || boardingRates.small || 0;
        }

        priceBox.textContent = '$' + amount.toFixed(2);
        updateVisibility();
      }

      petSelect.addEventListener('change', updatePrice);
      serviceSelect.addEventListener('change', updatePrice);
      durationSelect.addEventListener('change', updatePrice);

      updatePrice();
    })();
  </script>
</body>
</html>