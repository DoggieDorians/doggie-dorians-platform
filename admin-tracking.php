<?php
require_once __DIR__ . '/includes/member_config.php';

date_default_timezone_set('America/New_York');

$success = '';
$errors = [];

$allowedStatuses = [
    'Walker Assigned',
    'On The Way',
    'Arrived',
    'Walk Started',
    'Bathroom Break',
    'Walk Completed'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $walkId = (int)($_POST['walk_id'] ?? 0);
    $sessionStatus = trim($_POST['session_status'] ?? '');
    $etaMinutes = trim($_POST['eta_minutes'] ?? '');
    $currentLocation = trim($_POST['current_location'] ?? '');
    $lastUpdate = trim($_POST['last_update'] ?? '');
    $bathroomUpdate = trim($_POST['bathroom_update'] ?? '');
    $photoNote = trim($_POST['photo_note'] ?? '');
    $routeNote = trim($_POST['route_note'] ?? '');

    if ($walkId <= 0) {
        $errors[] = 'Invalid walk selected.';
    }

    if (!in_array($sessionStatus, $allowedStatuses, true)) {
        $errors[] = 'Invalid tracking status.';
    }

    if (!$errors) {
        $checkStmt = $pdo->prepare("SELECT id FROM walk_sessions WHERE walk_id = :walk_id LIMIT 1");
        $checkStmt->execute([':walk_id' => $walkId]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $update = $pdo->prepare("
                UPDATE walk_sessions
                SET session_status = :session_status,
                    eta_minutes = :eta_minutes,
                    current_location = :current_location,
                    last_update = :last_update,
                    bathroom_update = :bathroom_update,
                    photo_note = :photo_note,
                    route_note = :route_note,
                    started_at = CASE WHEN :session_status = 'Walk Started' AND started_at IS NULL THEN CURRENT_TIMESTAMP ELSE started_at END,
                    completed_at = CASE WHEN :session_status = 'Walk Completed' THEN CURRENT_TIMESTAMP ELSE completed_at END
                WHERE walk_id = :walk_id
            ");

            $update->execute([
                ':session_status' => $sessionStatus,
                ':eta_minutes' => $etaMinutes !== '' ? (int)$etaMinutes : null,
                ':current_location' => $currentLocation !== '' ? $currentLocation : null,
                ':last_update' => $lastUpdate !== '' ? $lastUpdate : null,
                ':bathroom_update' => $bathroomUpdate !== '' ? $bathroomUpdate : null,
                ':photo_note' => $photoNote !== '' ? $photoNote : null,
                ':route_note' => $routeNote !== '' ? $routeNote : null,
                ':walk_id' => $walkId
            ]);
        } else {
            $insert = $pdo->prepare("
                INSERT INTO walk_sessions (
                    walk_id,
                    session_status,
                    eta_minutes,
                    current_location,
                    last_update,
                    bathroom_update,
                    photo_note,
                    route_note,
                    started_at,
                    completed_at
                ) VALUES (
                    :walk_id,
                    :session_status,
                    :eta_minutes,
                    :current_location,
                    :last_update,
                    :bathroom_update,
                    :photo_note,
                    :route_note,
                    CASE WHEN :session_status = 'Walk Started' THEN CURRENT_TIMESTAMP ELSE NULL END,
                    CASE WHEN :session_status = 'Walk Completed' THEN CURRENT_TIMESTAMP ELSE NULL END
                )
            ");

            $insert->execute([
                ':walk_id' => $walkId,
                ':session_status' => $sessionStatus,
                ':eta_minutes' => $etaMinutes !== '' ? (int)$etaMinutes : null,
                ':current_location' => $currentLocation !== '' ? $currentLocation : null,
                ':last_update' => $lastUpdate !== '' ? $lastUpdate : null,
                ':bathroom_update' => $bathroomUpdate !== '' ? $bathroomUpdate : null,
                ':photo_note' => $photoNote !== '' ? $photoNote : null,
                ':route_note' => $routeNote !== '' ? $routeNote : null
            ]);
        }

        $success = 'Tracking session updated successfully at ' . date('g:i:s A');
    }
}

$stmt = $pdo->query("
    SELECT
        walks.id AS walk_id,
        walks.walk_date,
        walks.walk_time,
        walks.duration_minutes,
        walks.walker_name,
        dogs.dog_name,
        members.email AS member_email,
        members.username AS member_username,
        walk_sessions.session_status,
        walk_sessions.eta_minutes,
        walk_sessions.current_location,
        walk_sessions.last_update,
        walk_sessions.bathroom_update,
        walk_sessions.photo_note,
        walk_sessions.route_note
    FROM walks
    INNER JOIN dogs ON dogs.id = walks.dog_id
    INNER JOIN members ON members.id = walks.member_id
    LEFT JOIN walk_sessions ON walk_sessions.walk_id = walks.id
    ORDER BY walks.walk_date ASC, walks.walk_time ASC
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<?php include 'includes/header.php'; ?>
<?php include 'includes/nav.php'; ?>

<style>
.admin-page {
  background: #f4f1ea;
  min-height: calc(100vh - 120px);
  padding: 32px 20px 60px;
}
.admin-shell {
  max-width: 1320px;
  margin: 0 auto;
  display: grid;
  gap: 24px;
}
.admin-hero {
  background: linear-gradient(135deg, #111111 0%, #2b2414 100%);
  color: #ffffff;
  border-radius: 30px;
  padding: 34px;
  box-shadow: 0 14px 40px rgba(0,0,0,0.12);
}
.admin-hero h1 {
  margin: 0 0 10px;
  font-size: 38px;
}
.admin-hero p {
  margin: 0;
  color: rgba(255,255,255,0.82);
}
.message {
  border-radius: 14px;
  padding: 14px 16px;
}
.message.error {
  background: #fff3f3;
  color: #9b1c1c;
}
.message.success {
  background: #f4fbf2;
  color: #256029;
}
.admin-list {
  display: grid;
  gap: 20px;
}
.admin-card {
  background: #ffffff;
  border-radius: 24px;
  padding: 24px;
  box-shadow: 0 12px 30px rgba(0,0,0,0.07);
}
.admin-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
  margin-bottom: 18px;
}
.admin-box {
  background: #f7f4ee;
  border-radius: 16px;
  padding: 14px 16px;
}
.admin-box strong {
  display: block;
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: #777777;
  margin-bottom: 6px;
}
.assign-form {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
}
.assign-form .full {
  grid-column: 1 / -1;
}
.assign-form label {
  display: block;
  font-weight: 700;
  margin-bottom: 8px;
}
.assign-form input,
.assign-form select,
.assign-form textarea {
  width: 100%;
  padding: 13px 14px;
  border: 1px solid #ddd;
  border-radius: 14px;
  font-size: 15px;
  font-family: Arial, sans-serif;
}
.assign-form textarea {
  min-height: 100px;
  resize: vertical;
}
.assign-button {
  display: inline-block;
  background: #d4af37;
  color: #111111;
  border: none;
  border-radius: 999px;
  padding: 14px 20px;
  font-weight: 700;
  cursor: pointer;
}
.admin-tools {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
}
.admin-tools a {
  display: inline-block;
  background: #ffffff;
  color: #111111;
  border-radius: 999px;
  padding: 12px 16px;
  font-weight: 700;
}
@media (max-width: 900px) {
  .admin-grid,
  .assign-form {
    grid-template-columns: 1fr;
  }
  .admin-hero h1 {
    font-size: 30px;
  }
}
</style>

<main class="admin-page">
  <div class="admin-shell">

    <section class="admin-hero">
      <h1>GPS Tracking Admin</h1>
      <p>Update the live tracking session status, ETA, location, and walk updates.</p>
      <div class="admin-tools" style="margin-top:18px;">
        <a href="dashboard.php">Back to Dashboard</a>
      </div>
    </section>

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

    <section class="admin-list">
      <?php foreach ($rows as $row): ?>
        <div class="admin-card">
          <div class="admin-grid">
            <div class="admin-box">
              <strong>Dog</strong>
              <?= e($row['dog_name']) ?>
            </div>
            <div class="admin-box">
              <strong>Member</strong>
              <?= e($row['member_username'] ?: $row['member_email']) ?>
            </div>
            <div class="admin-box">
              <strong>Walk</strong>
              <?= e($row['walk_date']) ?> at <?= e($row['walk_time']) ?>
            </div>
            <div class="admin-box">
              <strong>Tracker Link</strong>
              <a href="live-tracking.php?walk_id=<?= e((string)$row['walk_id']) ?>">Open Live View</a>
            </div>
          </div>

          <form method="post" class="assign-form">
            <input type="hidden" name="walk_id" value="<?= e((string)$row['walk_id']) ?>">

            <div>
              <label>Session Status</label>
              <select name="session_status" required>
                <?php foreach ($allowedStatuses as $status): ?>
                  <option value="<?= e($status) ?>" <?= ($row['session_status'] ?? 'Walker Assigned') === $status ? 'selected' : '' ?>>
                    <?= e($status) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label>ETA Minutes</label>
              <input type="number" min="0" name="eta_minutes" value="<?= e((string)($row['eta_minutes'] ?? '')) ?>">
            </div>

            <div>
              <label>Current Location</label>
              <input type="text" name="current_location" value="<?= e($row['current_location'] ?? '') ?>" placeholder="Upper East Side">
            </div>

            <div>
              <label>Last Update</label>
              <input type="text" name="last_update" value="<?= e($row['last_update'] ?? '') ?>" placeholder="Walker is on the way">
            </div>

            <div>
              <label>Bathroom Update</label>
              <input type="text" name="bathroom_update" value="<?= e($row['bathroom_update'] ?? '') ?>" placeholder="Peed once, no poop yet">
            </div>

            <div>
              <label>Photo Update</label>
              <input type="text" name="photo_note" value="<?= e($row['photo_note'] ?? '') ?>" placeholder="Photo uploaded from the park">
            </div>

            <div class="full">
              <label>Route Note</label>
              <textarea name="route_note" placeholder="Route moved through Central Park South"><?= e($row['route_note'] ?? '') ?></textarea>
            </div>

            <div class="full">
              <button type="submit" class="assign-button">Update Tracking Session</button>
            </div>
          </form>
        </div>
      <?php endforeach; ?>
    </section>

  </div>
</main>

<?php include 'includes/footer.php'; ?>