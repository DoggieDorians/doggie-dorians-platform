<?php
require_once __DIR__ . '/includes/member_config.php';
requireWalkerLogin();

$walker = currentWalker($pdo);
if (!$walker) {
    session_destroy();
    redirectTo('walker-login.php');
}

$allowedStatuses = [
    'Accepted',
    'On The Way',
    'Arrived',
    'Walk Started',
    'Bathroom Break',
    'Walk Completed'
];

$success = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $walkId = (int)($_POST['walk_id'] ?? 0);
    $action = trim($_POST['action_type'] ?? '');
    $walkerNotes = trim($_POST['walker_notes'] ?? '');
    $bathroomUpdate = trim($_POST['bathroom_update'] ?? '');
    $photoNote = trim($_POST['photo_note'] ?? '');
    $currentLocation = trim($_POST['current_location'] ?? '');
    $etaMinutes = trim($_POST['eta_minutes'] ?? '');

    if ($walkId <= 0 || !in_array($action, $allowedStatuses, true)) {
        $errors[] = 'Invalid walk update.';
    }

    $ownedWalkStmt = $pdo->prepare("
        SELECT id
        FROM walks
        WHERE id = :walk_id
          AND walker_id = :walker_id
        LIMIT 1
    ");
    $ownedWalkStmt->execute([
        ':walk_id' => $walkId,
        ':walker_id' => $walker['id']
    ]);
    $ownedWalk = $ownedWalkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$ownedWalk) {
        $errors[] = 'This walk is not assigned to your walker account.';
    }

    if (!$errors) {
        $updateWalk = $pdo->prepare("
            UPDATE walks
            SET status = :status,
                walker_name = :walker_name,
                walker_phone = :walker_phone,
                walker_notes = :walker_notes
            WHERE id = :id
              AND walker_id = :walker_id
        ");
        $updateWalk->execute([
            ':status' => $action,
            ':walker_name' => $walker['full_name'],
            ':walker_phone' => $walker['phone'],
            ':walker_notes' => $walkerNotes !== '' ? $walkerNotes : null,
            ':id' => $walkId,
            ':walker_id' => $walker['id']
        ]);

        $checkSession = $pdo->prepare("
            SELECT id
            FROM walk_sessions
            WHERE walk_id = :walk_id
            LIMIT 1
        ");
        $checkSession->execute([':walk_id' => $walkId]);
        $existingSession = $checkSession->fetch(PDO::FETCH_ASSOC);

        $lastUpdate = match ($action) {
            'Accepted' => 'Walker accepted this walk.',
            'On The Way' => 'Walker is on the way.',
            'Arrived' => 'Walker has arrived.',
            'Walk Started' => 'The walk has started.',
            'Bathroom Break' => 'Bathroom update posted during walk.',
            'Walk Completed' => 'The walk has been completed.',
            default => 'Walk updated.'
        };

        if ($existingSession) {
            $updateSession = $pdo->prepare("
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
            $updateSession->execute([
                ':session_status' => $action,
                ':eta_minutes' => $etaMinutes !== '' ? (int)$etaMinutes : null,
                ':current_location' => $currentLocation !== '' ? $currentLocation : null,
                ':last_update' => $lastUpdate,
                ':bathroom_update' => $bathroomUpdate !== '' ? $bathroomUpdate : null,
                ':photo_note' => $photoNote !== '' ? $photoNote : null,
                ':route_note' => $currentLocation !== '' ? 'Walker updated location to ' . $currentLocation : null,
                ':walk_id' => $walkId
            ]);
        } else {
            $insertSession = $pdo->prepare("
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
            $insertSession->execute([
                ':walk_id' => $walkId,
                ':session_status' => $action,
                ':eta_minutes' => $etaMinutes !== '' ? (int)$etaMinutes : null,
                ':current_location' => $currentLocation !== '' ? $currentLocation : null,
                ':last_update' => $lastUpdate,
                ':bathroom_update' => $bathroomUpdate !== '' ? $bathroomUpdate : null,
                ':photo_note' => $photoNote !== '' ? $photoNote : null,
                ':route_note' => $currentLocation !== '' ? 'Walker updated location to ' . $currentLocation : null
            ]);
        }

        $success = 'Walk updated successfully.';
    }
}

$stmt = $pdo->prepare("
    SELECT
        walks.*,
        dogs.dog_name,
        dogs.temperament,
        members.email AS member_email,
        members.phone AS member_phone,
        members.username AS member_username,
        walk_sessions.session_status,
        walk_sessions.current_location,
        walk_sessions.bathroom_update,
        walk_sessions.photo_note
    FROM walks
    INNER JOIN dogs ON dogs.id = walks.dog_id
    INNER JOIN members ON members.id = walks.member_id
    LEFT JOIN walk_sessions ON walk_sessions.walk_id = walks.id
    WHERE walks.status <> 'Cancelled'
      AND walks.walker_id = :walker_id
    ORDER BY walks.walk_date ASC, walks.walk_time ASC
");
$stmt->execute([':walker_id' => $walker['id']]);
$walks = $stmt->fetchAll(PDO::FETCH_ASSOC);

$todayCount = count($walks);
?>
<?php include 'includes/header.php'; ?>
<?php include 'includes/nav.php'; ?>

<style>
.walker-page {
  background: #f4f1ea;
  min-height: calc(100vh - 120px);
  padding: 32px 20px 60px;
}
.walker-shell {
  max-width: 1380px;
  margin: 0 auto;
  display: grid;
  gap: 24px;
}
.walker-hero {
  background: linear-gradient(135deg, #111111 0%, #2b2414 100%);
  color: #ffffff;
  border-radius: 30px;
  padding: 34px;
  box-shadow: 0 14px 40px rgba(0,0,0,0.12);
}
.walker-hero h1 {
  margin: 0 0 10px;
  font-size: 38px;
}
.walker-hero p {
  margin: 0;
  color: rgba(255,255,255,0.82);
}
.hero-badges {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  margin-top: 20px;
}
.hero-badge {
  background: rgba(255,255,255,0.08);
  color: #ffffff;
  border-radius: 999px;
  padding: 10px 14px;
  font-weight: 700;
  font-size: 13px;
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
.walker-list {
  display: grid;
  gap: 20px;
}
.walker-card {
  background: #ffffff;
  border-radius: 24px;
  padding: 24px;
  box-shadow: 0 12px 30px rgba(0,0,0,0.07);
}
.walker-top {
  display: flex;
  justify-content: space-between;
  gap: 18px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}
.walker-top h2 {
  margin: 0;
  font-size: 24px;
}
.walker-sub {
  margin-top: 8px;
  color: #666666;
}
.walker-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
  margin-bottom: 18px;
}
.walker-box {
  background: #f7f4ee;
  border-radius: 16px;
  padding: 14px 16px;
}
.walker-box strong {
  display: block;
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: #777777;
  margin-bottom: 6px;
}
.status-pill {
  display: inline-block;
  padding: 10px 14px;
  border-radius: 999px;
  background: #d4af37;
  color: #111111;
  font-size: 13px;
  font-weight: 700;
}
.walker-form {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
}
.walker-form .full {
  grid-column: 1 / -1;
}
.walker-form label {
  display: block;
  font-weight: 700;
  margin-bottom: 8px;
}
.walker-form input,
.walker-form select,
.walker-form textarea {
  width: 100%;
  padding: 13px 14px;
  border: 1px solid #ddd;
  border-radius: 14px;
  font-size: 15px;
  font-family: Arial, sans-serif;
}
.walker-form textarea {
  min-height: 100px;
  resize: vertical;
}
.walker-button {
  display: inline-block;
  background: #111111;
  color: #ffffff;
  border: none;
  border-radius: 999px;
  padding: 14px 20px;
  font-weight: 700;
  cursor: pointer;
}
.logout-link {
  display: inline-block;
  background: #ffffff;
  color: #111111;
  padding: 12px 18px;
  border-radius: 999px;
  font-weight: 700;
}
.empty-state {
  background: #ffffff;
  border-radius: 24px;
  padding: 24px;
  box-shadow: 0 12px 30px rgba(0,0,0,0.07);
  color: #666666;
}
.gps-controls {
  margin-top: 16px;
  background: #111111;
  color: #ffffff;
  border-radius: 18px;
  padding: 18px;
}
.gps-controls h4 {
  margin: 0 0 12px;
}
.gps-buttons {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
}
.gps-button {
  border: none;
  border-radius: 999px;
  padding: 12px 16px;
  font-weight: 700;
  cursor: pointer;
}
.gps-start {
  background: #d4af37;
  color: #111111;
}
.gps-stop {
  background: #ffffff;
  color: #111111;
}
.gps-status {
  margin-top: 12px;
  font-size: 14px;
  color: rgba(255,255,255,0.85);
}
@media (max-width: 950px) {
  .walker-grid,
  .walker-form {
    grid-template-columns: 1fr;
  }
  .walker-hero h1 {
    font-size: 30px;
  }
}
</style>

<main class="walker-page">
  <div class="walker-shell">

    <section class="walker-hero">
      <h1>Walker Portal</h1>
      <p>Welcome, <?= e($walker['full_name']) ?>. You only see walks assigned to your account.</p>

      <div class="hero-badges">
        <span class="hero-badge">Active Walker</span>
        <span class="hero-badge"><?= $todayCount ?> Assigned Walk<?= $todayCount === 1 ? '' : 's' ?></span>
        <span class="hero-badge"><?= e($walker['email']) ?></span>
      </div>

      <div style="margin-top:18px;">
        <a href="walker-logout.php" class="logout-link">Log Out</a>
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

    <?php if (!$walks): ?>
      <div class="empty-state">
        No walks are currently assigned to your walker account.
      </div>
    <?php else: ?>
      <section class="walker-list">
        <?php foreach ($walks as $walk): ?>
          <div class="walker-card">
            <div class="walker-top">
              <div>
                <h2><?= e($walk['dog_name']) ?></h2>
                <div class="walker-sub">
                  Client: <?= e($walk['member_username'] ?: $walk['member_email']) ?>
                </div>
              </div>
              <div>
                <span class="status-pill"><?= e($walk['status']) ?></span>
              </div>
            </div>

            <div class="walker-grid">
              <div class="walker-box">
                <strong>Date</strong>
                <?= e($walk['walk_date']) ?>
              </div>
              <div class="walker-box">
                <strong>Time</strong>
                <?= e($walk['walk_time']) ?>
              </div>
              <div class="walker-box">
                <strong>Duration</strong>
                <?= e((string)$walk['duration_minutes']) ?> Minutes
              </div>
              <div class="walker-box">
                <strong>Temperament</strong>
                <?= e($walk['temperament'] ?: 'Not added') ?>
              </div>
              <div class="walker-box">
                <strong>Client Notes</strong>
                <?= e($walk['notes'] ?: 'No client notes') ?>
              </div>
              <div class="walker-box">
                <strong>Client Phone</strong>
                <?= e($walk['member_phone'] ?: 'Not added') ?>
              </div>
              <div class="walker-box">
                <strong>Current Location</strong>
                <?= e($walk['current_location'] ?: 'Not updated yet') ?>
              </div>
              <div class="walker-box">
                <strong>Bathroom Update</strong>
                <?= e($walk['bathroom_update'] ?: 'No update yet') ?>
              </div>
            </div>

            <form method="post" class="walker-form">
              <input type="hidden" name="walk_id" value="<?= e((string)$walk['id']) ?>">

              <div>
                <label>Status Action</label>
                <select name="action_type" required>
                  <?php foreach ($allowedStatuses as $status): ?>
                    <option value="<?= e($status) ?>" <?= $walk['status'] === $status ? 'selected' : '' ?>>
                      <?= e($status) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div>
                <label>ETA Minutes</label>
                <input type="number" min="0" name="eta_minutes" value="">
              </div>

              <div>
                <label>Current Location</label>
                <input type="text" name="current_location" placeholder="Central Park South">
              </div>

              <div>
                <label>Bathroom Update</label>
                <input type="text" name="bathroom_update" placeholder="Peed once, no poop yet">
              </div>

              <div>
                <label>Photo Note</label>
                <input type="text" name="photo_note" placeholder="Photo uploaded near park entrance">
              </div>

              <div class="full">
                <label>Walker Notes</label>
                <textarea name="walker_notes" placeholder="Dog was calm, traffic was light, great energy today..."><?= e($walk['walker_notes'] ?: '') ?></textarea>
              </div>

              <div class="full">
                <button type="submit" class="walker-button">Update Assigned Walk</button>
              </div>
            </form>

            <div class="gps-controls" data-walk-id="<?= e((string)$walk['id']) ?>">
              <h4>Real GPS Tracking</h4>
              <div class="gps-buttons">
                <button class="gps-button gps-start" type="button" onclick="startGps(<?= (int)$walk['id'] ?>)">Start Live GPS</button>
                <button class="gps-button gps-stop" type="button" onclick="stopGps(<?= (int)$walk['id'] ?>)">Stop Live GPS</button>
                <a class="gps-button gps-stop" href="live-tracking.php?walk_id=<?= (int)$walk['id'] ?>" style="text-decoration:none;">Open Live Map</a>
              </div>
              <div class="gps-status" id="gps-status-<?= (int)$walk['id'] ?>">GPS not running.</div>
            </div>
          </div>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>

  </div>
</main>

<script>
let gpsWatchId = null;
let activeWalkId = null;

function setGpsStatus(walkId, message) {
  const el = document.getElementById(`gps-status-${walkId}`);
  if (el) el.textContent = message;
}

async function sendGpsUpdate(walkId, lat, lng) {
  const formData = new FormData();
  formData.append('walk_id', walkId);
  formData.append('lat', lat);
  formData.append('lng', lng);
  formData.append('current_location', `Lat ${lat.toFixed(6)}, Lng ${lng.toFixed(6)}`);

  const response = await fetch('walker-location-update.php', {
    method: 'POST',
    body: formData
  });

  return response.json();
}

function startGps(walkId) {
  if (!navigator.geolocation) {
    setGpsStatus(walkId, 'Geolocation is not supported on this device.');
    return;
  }

  if (gpsWatchId !== null) {
    navigator.geolocation.clearWatch(gpsWatchId);
    gpsWatchId = null;
  }

  activeWalkId = walkId;
  setGpsStatus(walkId, 'Starting GPS... please allow location access.');

  gpsWatchId = navigator.geolocation.watchPosition(
    async (position) => {
      const lat = position.coords.latitude;
      const lng = position.coords.longitude;

      try {
        const result = await sendGpsUpdate(walkId, lat, lng);
        if (result.ok) {
          setGpsStatus(
            walkId,
            `Live GPS active. Last sent: ${lat.toFixed(6)}, ${lng.toFixed(6)}`
          );
        } else {
          setGpsStatus(walkId, 'GPS update failed: ' + (result.error || 'Unknown error'));
        }
      } catch (error) {
        setGpsStatus(walkId, 'GPS update failed. Check connection.');
      }
    },
    (error) => {
      setGpsStatus(walkId, 'Location error: ' + error.message);
    },
    {
      enableHighAccuracy: true,
      maximumAge: 0,
      timeout: 10000
    }
  );
}

function stopGps(walkId) {
  if (gpsWatchId !== null) {
    navigator.geolocation.clearWatch(gpsWatchId);
    gpsWatchId = null;
  }
  activeWalkId = null;
  setGpsStatus(walkId, 'GPS stopped.');
}
</script>

<?php include 'includes/footer.php'; ?>