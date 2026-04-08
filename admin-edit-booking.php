<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/database/setup.php';
require_once __DIR__ . '/admin-auth.php';

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1");
    $stmt->execute(['table' => $table]);
    return (bool) $stmt->fetchColumn();
}

function getColumns(PDO $pdo, string $table): array
{
    $columns = [];
    $stmt = $pdo->query("PRAGMA table_info($table)");
    if ($stmt) {
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[] = $column['name'];
        }
    }
    return $columns;
}

function hasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

if (!tableExists($pdo, 'bookings')) {
    die('Bookings table not found.');
}

$bookingId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($bookingId <= 0) {
    die('Invalid booking ID.');
}

$bookingColumns = getColumns($pdo, 'bookings');
$userColumns = tableExists($pdo, 'users') ? getColumns($pdo, 'users') : [];
$petColumns = tableExists($pdo, 'pets') ? getColumns($pdo, 'pets') : [];
$walkerColumns = tableExists($pdo, 'walkers') ? getColumns($pdo, 'walkers') : [];

$userNameSql = 'CAST(id AS TEXT)';
if ($userColumns) {
    if (hasColumn($userColumns, 'email')) {
        $userNameSql = "COALESCE(email, CAST(id AS TEXT))";
    } elseif (hasColumn($userColumns, 'name')) {
        $userNameSql = "COALESCE(name, CAST(id AS TEXT))";
    } elseif (hasColumn($userColumns, 'full_name')) {
        $userNameSql = "COALESCE(full_name, CAST(id AS TEXT))";
    } elseif (hasColumn($userColumns, 'username')) {
        $userNameSql = "COALESCE(username, CAST(id AS TEXT))";
    }
}

$clients = [];
if (tableExists($pdo, 'users')) {
    $clientsStmt = $pdo->query("
        SELECT id, {$userNameSql} AS label
        FROM users
        ORDER BY label ASC
    ");
    $clients = $clientsStmt ? $clientsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

$pets = [];
if (tableExists($pdo, 'pets') && hasColumn($petColumns, 'user_id')) {
    $petsStmt = $pdo->query("
        SELECT *
        FROM pets
        ORDER BY " . (hasColumn($petColumns, 'pet_name') ? 'pet_name ASC, ' : '') . "id ASC
    ");
    $pets = $petsStmt ? $petsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

$walkers = [];
if (tableExists($pdo, 'walkers')) {
    $walkerWhere = hasColumn($walkerColumns, 'is_active') ? 'WHERE is_active = 1' : '';
    $walkerNameSql = 'CAST(id AS TEXT)';
    if (hasColumn($walkerColumns, 'full_name')) {
        $walkerNameSql = "COALESCE(full_name, CAST(id AS TEXT))";
    } elseif (hasColumn($walkerColumns, 'name')) {
        $walkerNameSql = "COALESCE(name, CAST(id AS TEXT))";
    }

    $walkerSecondarySql = hasColumn($walkerColumns, 'email') ? 'email' : "''";

    $walkersStmt = $pdo->query("
        SELECT id, {$walkerNameSql} AS label, {$walkerSecondarySql} AS email
        FROM walkers
        {$walkerWhere}
        ORDER BY label ASC
    ");
    $walkers = $walkersStmt ? $walkersStmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

$selectParts = ['id'];
$optionalBookingColumns = [
    'user_id',
    'pet_id',
    'assigned_walker_id',
    'service_type',
    'service_date',
    'service_time',
    'duration_minutes',
    'status',
    'price',
    'walker_name',
    'admin_notes'
];

foreach ($optionalBookingColumns as $column) {
    if (hasColumn($bookingColumns, $column)) {
        $selectParts[] = $column;
    }
}

$bookingStmt = $pdo->prepare("
    SELECT " . implode(', ', $selectParts) . "
    FROM bookings
    WHERE id = :id
    LIMIT 1
");
$bookingStmt->execute(['id' => $bookingId]);
$booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    die('Booking not found.');
}

$serviceOptions = [
    'walk' => 'Walk',
    'daycare' => 'Daycare',
    'boarding' => 'Boarding',
    'drop-in' => 'Drop-In',
    'drop-in-walk' => 'Drop-In + Walk',
];

$statusOptions = [
    'pending' => 'Pending',
    'confirmed' => 'Confirmed',
    'in_progress' => 'In Progress',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];

$form = [
    'user_id' => (string) ($booking['user_id'] ?? ''),
    'pet_id' => (string) ($booking['pet_id'] ?? ''),
    'service_type' => (string) ($booking['service_type'] ?? 'walk'),
    'service_date' => (string) ($booking['service_date'] ?? ''),
    'service_time' => (string) ($booking['service_time'] ?? ''),
    'duration_minutes' => (string) ($booking['duration_minutes'] ?? '30'),
    'status' => (string) ($booking['status'] ?? 'pending'),
    'price' => isset($booking['price']) ? (string) $booking['price'] : '',
    'assigned_walker_id' => (string) ($booking['assigned_walker_id'] ?? ''),
    'admin_notes' => (string) ($booking['admin_notes'] ?? ''),
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $key => $default) {
        $form[$key] = trim((string) ($_POST[$key] ?? $default));
    }

    $userId = (int) $form['user_id'];
    $petId = (int) $form['pet_id'];
    $serviceType = $form['service_type'];
    $serviceDate = $form['service_date'];
    $serviceTime = $form['service_time'];
    $durationMinutes = $form['duration_minutes'] !== '' ? (int) $form['duration_minutes'] : 0;
    $status = $form['status'];
    $price = $form['price'] !== '' ? (float) $form['price'] : -1;
    $assignedWalkerId = $form['assigned_walker_id'] !== '' ? (int) $form['assigned_walker_id'] : 0;
    $adminNotes = $form['admin_notes'];

    if ($userId <= 0) {
        $errors[] = 'Please select a client.';
    }

    if ($petId <= 0) {
        $errors[] = 'Please select a pet.';
    }

    if (!isset($serviceOptions[$serviceType])) {
        $errors[] = 'Please select a valid service type.';
    }

    if ($serviceDate === '') {
        $errors[] = 'Please choose a service date.';
    }

    if ($serviceTime === '') {
        $errors[] = 'Please choose a service time.';
    }

    if ($durationMinutes <= 0) {
        $errors[] = 'Please enter a valid duration.';
    }

    if (!isset($statusOptions[$status])) {
        $errors[] = 'Please select a valid status.';
    }

    if ($price < 0) {
        $errors[] = 'Please enter a valid price.';
    }

    $selectedClient = null;
    foreach ($clients as $client) {
        if ((int) $client['id'] === $userId) {
            $selectedClient = $client;
            break;
        }
    }
    if (!$selectedClient) {
        $errors[] = 'Selected client was not found.';
    }

    $selectedPet = null;
    foreach ($pets as $pet) {
        if ((int) ($pet['id'] ?? 0) === $petId) {
            $selectedPet = $pet;
            break;
        }
    }
    if (!$selectedPet) {
        $errors[] = 'Selected pet was not found.';
    } elseif ((int) ($selectedPet['user_id'] ?? 0) !== $userId) {
        $errors[] = 'That pet does not belong to the selected client.';
    }

    $selectedWalker = null;
    if ($assignedWalkerId > 0) {
        foreach ($walkers as $walker) {
            if ((int) $walker['id'] === $assignedWalkerId) {
                $selectedWalker = $walker;
                break;
            }
        }
        if (!$selectedWalker) {
            $errors[] = 'Selected walker was not found.';
        }
    }

    if (!$errors) {
        if ($assignedWalkerId > 0 && $status === 'pending') {
            $status = 'confirmed';
        }

        $updateParts = [];
        $params = ['id' => $bookingId];

        $addUpdate = function (string $column, string $placeholder, $value) use (&$updateParts, &$params): void {
            $updateParts[] = $column . ' = ' . $placeholder;
            $params[ltrim($placeholder, ':')] = $value;
        };

        if (hasColumn($bookingColumns, 'user_id')) {
            $addUpdate('user_id', ':user_id', $userId);
        }
        if (hasColumn($bookingColumns, 'pet_id')) {
            $addUpdate('pet_id', ':pet_id', $petId);
        }
        if (hasColumn($bookingColumns, 'assigned_walker_id')) {
            $addUpdate('assigned_walker_id', ':assigned_walker_id', $assignedWalkerId > 0 ? $assignedWalkerId : null);
        }
        if (hasColumn($bookingColumns, 'service_type')) {
            $addUpdate('service_type', ':service_type', $serviceType);
        }
        if (hasColumn($bookingColumns, 'service_date')) {
            $addUpdate('service_date', ':service_date', $serviceDate);
        }
        if (hasColumn($bookingColumns, 'service_time')) {
            $addUpdate('service_time', ':service_time', $serviceTime);
        }
        if (hasColumn($bookingColumns, 'duration_minutes')) {
            $addUpdate('duration_minutes', ':duration_minutes', $durationMinutes);
        }
        if (hasColumn($bookingColumns, 'status')) {
            $addUpdate('status', ':status', $status);
        }
        if (hasColumn($bookingColumns, 'price')) {
            $addUpdate('price', ':price', $price);
        }
        if (hasColumn($bookingColumns, 'walker_name')) {
            $addUpdate('walker_name', ':walker_name', $selectedWalker['label'] ?? null);
        }
        if (hasColumn($bookingColumns, 'admin_notes')) {
            $addUpdate('admin_notes', ':admin_notes', $adminNotes !== '' ? $adminNotes : null);
        }
        if (hasColumn($bookingColumns, 'status_updated_by')) {
            $addUpdate('status_updated_by', ':status_updated_by', 'admin');
        }
        if (hasColumn($bookingColumns, 'status_updated_at')) {
            $updateParts[] = 'status_updated_at = CURRENT_TIMESTAMP';
        }

        $sql = "
            UPDATE bookings
            SET " . implode(', ', $updateParts) . "
            WHERE id = :id
        ";

        $stmt = $pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $placeholder = ':' . $key;
            if (is_int($value)) {
                $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
            } elseif ($value === null) {
                $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($placeholder, $value);
            }
        }

        $stmt->execute();

        header('Location: admin-bookings.php?highlight=' . $bookingId);
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Booking | Doggie Dorian’s Admin</title>
  <style>
    * { box-sizing: border-box; }

    :root {
      --bg: #070810;
      --panel: #11131b;
      --line: rgba(255,255,255,0.08);
      --text: #f7f4ee;
      --muted: rgba(247,244,238,0.68);
      --gold: #d4af37;
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
      max-width: 1040px;
      margin: 0 auto;
      padding: 34px 22px 60px;
    }

    .topbar {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 18px;
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
      max-width: 760px;
      line-height: 1.6;
    }

    .top-actions {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }

    .top-btn {
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

    .top-btn.primary {
      background: var(--gold);
      color: #0a0a0f;
      border-color: var(--gold);
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
      line-height: 1.5;
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
        <div class="eyebrow">Doggie Dorian’s Admin</div>
        <h1>Edit Booking #<?php echo (int) $bookingId; ?></h1>
        <div class="subtext">
          Update the client, pet, schedule, pricing, walker assignment, and service status for this booking.
        </div>
      </div>

      <div class="top-actions">
        <a href="admin-bookings.php" class="top-btn">Back to Bookings</a>
        <a href="admin-dashboard.php" class="top-btn primary">Admin Home</a>
      </div>
    </div>

    <div class="panel">
      <div class="panel-inner">
        <?php if ($errors): ?>
          <div class="alert error">
            <?php foreach ($errors as $error): ?>
              <div><?php echo e($error); ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="post" id="admin-edit-booking-form">
          <div class="grid">
            <div class="field">
              <label for="user_id">Client</label>
              <select name="user_id" id="user_id" required>
                <option value="">Select client</option>
                <?php foreach ($clients as $client): ?>
                  <option value="<?php echo (int) $client['id']; ?>" <?php echo ((string) $client['id'] === $form['user_id']) ? 'selected' : ''; ?>>
                    <?php echo e($client['label']); ?> (ID <?php echo (int) $client['id']; ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label for="pet_id">Pet</label>
              <select name="pet_id" id="pet_id" required>
                <option value="">Select pet</option>
                <?php foreach ($pets as $pet): ?>
                  <option
                    value="<?php echo (int) ($pet['id'] ?? 0); ?>"
                    data-user-id="<?php echo (int) ($pet['user_id'] ?? 0); ?>"
                    <?php echo ((string) ($pet['id'] ?? '') === $form['pet_id']) ? 'selected' : ''; ?>
                  >
                    <?php echo e(($pet['pet_name'] ?? 'Unnamed Pet') . ' (User ID ' . (int) ($pet['user_id'] ?? 0) . ')'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="helper">Only pets belonging to the selected client should be used for a booking.</div>
            </div>

            <div class="field">
              <label for="service_type">Service Type</label>
              <select name="service_type" id="service_type" required>
                <?php foreach ($serviceOptions as $value => $label): ?>
                  <option value="<?php echo e($value); ?>" <?php echo $form['service_type'] === $value ? 'selected' : ''; ?>>
                    <?php echo e($label); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label for="duration_minutes">Duration (Minutes)</label>
              <input type="number" name="duration_minutes" id="duration_minutes" min="1" value="<?php echo e($form['duration_minutes']); ?>" required>
            </div>

            <div class="field">
              <label for="service_date">Service Date</label>
              <input type="date" name="service_date" id="service_date" value="<?php echo e($form['service_date']); ?>" required>
            </div>

            <div class="field">
              <label for="service_time">Service Time</label>
              <input type="time" name="service_time" id="service_time" value="<?php echo e($form['service_time']); ?>" required>
            </div>

            <div class="field">
              <label for="price">Price</label>
              <input type="number" name="price" id="price" step="0.01" min="0" value="<?php echo e($form['price']); ?>" required>
            </div>

            <div class="field">
              <label for="status">Status</label>
              <select name="status" id="status" required>
                <?php foreach ($statusOptions as $value => $label): ?>
                  <option value="<?php echo e($value); ?>" <?php echo $form['status'] === $value ? 'selected' : ''; ?>>
                    <?php echo e($label); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field full">
              <label for="assigned_walker_id">Assign Walker (Optional)</label>
              <select name="assigned_walker_id" id="assigned_walker_id">
                <option value="">No walker assigned yet</option>
                <?php foreach ($walkers as $walker): ?>
                  <option value="<?php echo (int) $walker['id']; ?>" <?php echo ((string) $walker['id'] === $form['assigned_walker_id']) ? 'selected' : ''; ?>>
                    <?php echo e($walker['label'] . (!empty($walker['email']) ? ' — ' . $walker['email'] : '')); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="helper">If you assign a walker while status is still pending, the booking will automatically move to confirmed.</div>
            </div>

            <div class="field full">
              <label for="admin_notes">Admin Notes</label>
              <textarea name="admin_notes" id="admin_notes" placeholder="Optional internal notes for this booking"><?php echo e($form['admin_notes']); ?></textarea>
            </div>
          </div>

          <div class="button-row">
            <button type="submit" class="btn btn-primary">Save Booking Changes</button>
            <a href="admin-bookings.php" class="btn btn-secondary">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script>
    (function () {
      const userSelect = document.getElementById('user_id');
      const petSelect = document.getElementById('pet_id');

      if (!userSelect || !petSelect) return;

      const originalOptions = Array.from(petSelect.options).map(option => ({
        value: option.value,
        text: option.text,
        userId: option.getAttribute('data-user-id') || '',
        selected: option.selected
      }));

      function rebuildPets() {
        const selectedUserId = userSelect.value;
        const currentPetId = petSelect.value;

        petSelect.innerHTML = '';

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Select pet';
        petSelect.appendChild(placeholder);

        originalOptions.forEach(option => {
          if (option.value === '') return;
          if (selectedUserId !== '' && option.userId !== selectedUserId) return;

          const el = document.createElement('option');
          el.value = option.value;
          el.textContent = option.text;
          el.setAttribute('data-user-id', option.userId);

          if (option.value === currentPetId) {
            el.selected = true;
          }

          petSelect.appendChild(el);
        });
      }

      userSelect.addEventListener('change', function () {
        petSelect.value = '';
        rebuildPets();
      });

      rebuildPets();
    })();
  </script>
</body>
</html>