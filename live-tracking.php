<?php
require_once __DIR__ . '/includes/member_config.php';

$member = currentMember($pdo);

if (!$member) {
    $member = [
        'id' => 0,
        'username' => 'Preview Member',
        'email' => 'member@example.com',
        'phone' => '(631) 555-1234',
        'preferred_login' => 'email',
        'email_verified' => 1
    ];
}

$selectedWalkId = (int)($_GET['walk_id'] ?? 0);
$walkOptions = [];
$walk = null;
$session = null;

if ((int)$member['id'] > 0) {
    $walkListStmt = $pdo->prepare("
        SELECT
            walks.id,
            walks.walk_date,
            walks.walk_time,
            walks.status,
            dogs.dog_name
        FROM walks
        INNER JOIN dogs ON dogs.id = walks.dog_id
        WHERE walks.member_id = :member_id
        ORDER BY walks.walk_date DESC, walks.walk_time DESC
    ");
    $walkListStmt->execute([':member_id' => $member['id']]);
    $walkOptions = $walkListStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($selectedWalkId <= 0 && !empty($walkOptions)) {
        $selectedWalkId = (int)$walkOptions[0]['id'];
    }

    if ($selectedWalkId > 0) {
        $stmt = $pdo->prepare("
            SELECT
                walks.*,
                dogs.dog_name
            FROM walks
            INNER JOIN dogs ON dogs.id = walks.dog_id
            WHERE walks.id = :walk_id
              AND walks.member_id = :member_id
            LIMIT 1
        ");
        $stmt->execute([
            ':walk_id' => $selectedWalkId,
            ':member_id' => $member['id']
        ]);
        $walk = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($walk) {
            $sessionStmt = $pdo->prepare("
                SELECT *
                FROM walk_sessions
                WHERE walk_id = :walk_id
                LIMIT 1
            ");
            $sessionStmt->execute([':walk_id' => $selectedWalkId]);
            $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
        }
    }
}

if (!$walk) {
    $selectedWalkId = 1;
    $walkOptions = [
        [
            'id' => 1,
            'dog_name' => 'Bentley',
            'walk_date' => date('Y-m-d'),
            'walk_time' => '13:00',
            'status' => 'Walker Assigned'
        ]
    ];

    $walk = [
        'id' => 1,
        'dog_name' => 'Bentley',
        'walk_date' => date('Y-m-d'),
        'walk_time' => '13:00',
        'duration_minutes' => 30,
        'walker_name' => 'John Walker',
        'walker_phone' => '(631) 555-8181',
        'status' => 'Walker Assigned'
    ];

    $session = [
        'session_status' => 'Walker Assigned',
        'eta_minutes' => 10,
        'current_location' => 'Waiting for GPS updates',
        'current_lat' => 40.7829,
        'current_lng' => -73.9654,
        'last_update' => 'Waiting for real GPS updates.',
        'bathroom_update' => 'No bathroom update yet',
        'photo_note' => 'No photo update yet',
        'route_note' => 'No route yet',
        'route_points' => json_encode([
            ['lat' => 40.7829, 'lng' => -73.9654, 'at' => date('Y-m-d H:i:s')]
        ])
    ];
}

function trackingSteps(string $status): array {
    $map = [
        'Walker Assigned' => 1,
        'Accepted' => 1,
        'On The Way' => 2,
        'Arrived' => 3,
        'Walk Started' => 4,
        'Bathroom Break' => 5,
        'Walk Completed' => 6
    ];

    $current = $map[$status] ?? 1;

    return [
        ['label' => 'Assigned', 'done' => $current >= 1],
        ['label' => 'On The Way', 'done' => $current >= 2],
        ['label' => 'Arrived', 'done' => $current >= 3],
        ['label' => 'Walk Started', 'done' => $current >= 4],
        ['label' => 'Bathroom', 'done' => $current >= 5],
        ['label' => 'Completed', 'done' => $current >= 6],
    ];
}
?>
<?php include 'includes/header.php'; ?>
<?php include 'includes/nav.php'; ?>

<link
  rel="stylesheet"
  href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
  integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
  crossorigin=""
>
<script
  src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
  integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
  crossorigin=""
></script>

<style>
.tracking-page {
  background: #f4f1ea;
  min-height: calc(100vh - 120px);
  padding: 32px 20px 60px;
}
.tracking-shell {
  max-width: 1380px;
  margin: 0 auto;
  display: grid;
  gap: 24px;
}
.tracking-hero {
  background: linear-gradient(135deg, #111111 0%, #2b2414 100%);
  color: #ffffff;
  border-radius: 30px;
  padding: 34px;
  box-shadow: 0 14px 40px rgba(0,0,0,0.12);
}
.tracking-hero h1 {
  margin: 0 0 10px;
  font-size: 38px;
}
.tracking-hero p {
  margin: 0;
  max-width: 780px;
  color: rgba(255,255,255,0.82);
}
.hero-actions {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  margin-top: 18px;
}
.hero-link {
  display: inline-block;
  background: rgba(255,255,255,0.08);
  color: #ffffff;
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 999px;
  padding: 12px 16px;
  font-weight: 700;
}
.tracking-grid {
  display: grid;
  grid-template-columns: 1.15fr 0.85fr;
  gap: 24px;
}
.tracking-card {
  background: #ffffff;
  border-radius: 26px;
  padding: 28px;
  box-shadow: 0 12px 30px rgba(0,0,0,0.07);
}
.walk-switcher {
  display: flex;
  justify-content: space-between;
  gap: 14px;
  align-items: center;
  flex-wrap: wrap;
  margin-bottom: 18px;
}
.walk-switcher form {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  align-items: center;
}
.walk-switcher select {
  padding: 12px 14px;
  border: 1px solid #ddd;
  border-radius: 14px;
  font-size: 15px;
}
.walk-switcher button {
  background: #111111;
  color: #ffffff;
  border: none;
  border-radius: 999px;
  padding: 12px 16px;
  font-weight: 700;
  cursor: pointer;
}
.live-indicator {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: #dcfce7;
  color: #166534;
  border-radius: 999px;
  padding: 10px 14px;
  font-size: 13px;
  font-weight: 700;
}
.live-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: #16a34a;
}
#liveMap {
  height: 420px;
  border-radius: 18px;
  overflow: hidden;
}
.info-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 14px;
  margin-top: 18px;
}
.info-box {
  background: #f7f4ee;
  border-radius: 16px;
  padding: 14px 16px;
}
.info-box strong {
  display: block;
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: #777777;
  margin-bottom: 6px;
}
.timeline {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 10px;
  margin-top: 18px;
}
.timeline-step {
  background: #f7f4ee;
  border-radius: 16px;
  padding: 12px 8px;
  text-align: center;
  font-size: 13px;
  color: #888888;
}
.timeline-step.done {
  background: #d4af37;
  color: #111111;
  font-weight: 700;
}
.update-list {
  display: grid;
  gap: 14px;
}
.update-box {
  background: #f7f4ee;
  border-radius: 18px;
  padding: 16px;
}
.status-pill {
  display: inline-block;
  padding: 10px 14px;
  border-radius: 999px;
  background: #111111;
  color: #ffffff;
  font-size: 13px;
  font-weight: 700;
}
@media (max-width: 1000px) {
  .tracking-grid {
    grid-template-columns: 1fr;
  }
}
@media (max-width: 800px) {
  .timeline,
  .info-grid {
    grid-template-columns: 1fr 1fr;
  }
  .tracking-hero h1 {
    font-size: 30px;
  }
}
@media (max-width: 600px) {
  .timeline,
  .info-grid {
    grid-template-columns: 1fr;
  }
}
</style>

<main class="tracking-page">
  <div class="tracking-shell">

    <section class="tracking-hero">
      <h1>Live Walk Tracking</h1>
      <p>
        This view now supports a real moving map, route trail, and live GPS updates from the walker device.
      </p>

      <div class="hero-actions">
        <a href="dashboard.php" class="hero-link">Back to Dashboard</a>
        <a href="my-walks.php" class="hero-link">Back to My Walks</a>
      </div>
    </section>

    <section class="tracking-grid">

      <div class="tracking-card">
        <div class="walk-switcher">
          <form method="get">
            <select name="walk_id">
              <?php foreach ($walkOptions as $option): ?>
                <option value="<?= e((string)$option['id']) ?>" <?= (int)$option['id'] === (int)$selectedWalkId ? 'selected' : '' ?>>
                  <?= e($option['dog_name']) ?> — <?= e($option['walk_date']) ?> <?= e($option['walk_time']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button type="submit">Open Walk</button>
          </form>

          <div class="live-indicator">
            <span class="live-dot"></span>
            Polling live GPS every 5 seconds
          </div>
        </div>

        <h2>Live Map</h2>
        <div id="liveMap"></div>

        <div class="info-grid">
          <div class="info-box">
            <strong>Current Status</strong>
            <span id="sessionStatus"><?= e($session['session_status'] ?? 'Walker Assigned') ?></span>
          </div>

          <div class="info-box">
            <strong>ETA</strong>
            <span id="etaMinutes"><?= e((string)($session['eta_minutes'] ?? '')) ?></span>
          </div>

          <div class="info-box">
            <strong>Current Location</strong>
            <span id="currentLocation"><?= e($session['current_location'] ?? 'Waiting for GPS') ?></span>
          </div>

          <div class="info-box">
            <strong>Last GPS Ping</strong>
            <span id="lastGpsAt"><?= e($session['last_gps_at'] ?? 'Not yet') ?></span>
          </div>
        </div>

        <div class="timeline" id="timelineWrap">
          <?php foreach (trackingSteps($session['session_status'] ?? 'Walker Assigned') as $step): ?>
            <div class="timeline-step <?= $step['done'] ? 'done' : '' ?>">
              <?= e($step['label']) ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="tracking-card">
        <h3>Walk Details</h3>

        <div class="update-list">
          <div class="update-box">
            <strong>Dog</strong><br>
            <?= e($walk['dog_name']) ?>
          </div>

          <div class="update-box">
            <strong>Date & Time</strong><br>
            <?= e($walk['walk_date']) ?> at <?= e($walk['walk_time']) ?>
          </div>

          <div class="update-box">
            <strong>Duration</strong><br>
            <?= e((string)$walk['duration_minutes']) ?> Minutes
          </div>

          <div class="update-box">
            <strong>Walker</strong><br>
            <?= e($walk['walker_name'] ?: 'Not assigned yet') ?>
            <?php if (!empty($walk['walker_phone'])): ?>
              <br><?= e($walk['walker_phone']) ?>
            <?php endif; ?>
          </div>

          <div class="update-box">
            <strong>Last Update</strong><br>
            <span id="lastUpdate"><?= e($session['last_update'] ?? 'No updates yet') ?></span>
          </div>

          <div class="update-box">
            <strong>Bathroom Update</strong><br>
            <span id="bathroomUpdate"><?= e($session['bathroom_update'] ?? 'No bathroom update yet') ?></span>
          </div>

          <div class="update-box">
            <strong>Photo Update</strong><br>
            <span id="photoNote"><?= e($session['photo_note'] ?? 'No photo update yet') ?></span>
          </div>

          <div class="update-box">
            <strong>Route Note</strong><br>
            <span id="routeNote"><?= e($session['route_note'] ?? 'No route note yet') ?></span>
          </div>
        </div>
      </div>

    </section>

  </div>
</main>

<script>
const walkId = <?= (int)$selectedWalkId ?>;
const initialLat = <?= isset($session['current_lat']) && $session['current_lat'] !== null ? (float)$session['current_lat'] : 40.7829 ?>;
const initialLng = <?= isset($session['current_lng']) && $session['current_lng'] !== null ? (float)$session['current_lng'] : -73.9654 ?>;
const initialRoute = <?= !empty($session['route_points']) ? $session['route_points'] : json_encode([['lat' => (isset($session['current_lat']) && $session['current_lat'] !== null ? (float)$session['current_lat'] : 40.7829), 'lng' => (isset($session['current_lng']) && $session['current_lng'] !== null ? (float)$session['current_lng'] : -73.9654)]]) ?>;

const map = L.map('liveMap').setView([initialLat, initialLng], 15);

L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
  maxZoom: 19,
  attribution: '&copy; OpenStreetMap'
}).addTo(map);

let marker = L.marker([initialLat, initialLng]).addTo(map);
let polyline = L.polyline(
  (initialRoute || []).map(p => [p.lat, p.lng]),
  { weight: 5 }
).addTo(map);

function renderTimeline(status) {
  const order = {
    'Walker Assigned': 1,
    'Accepted': 1,
    'On The Way': 2,
    'Arrived': 3,
    'Walk Started': 4,
    'Bathroom Break': 5,
    'Walk Completed': 6
  };

  const current = order[status] || 1;
  const labels = ['Assigned', 'On The Way', 'Arrived', 'Walk Started', 'Bathroom', 'Completed'];
  const wrap = document.getElementById('timelineWrap');

  wrap.innerHTML = '';

  labels.forEach((label, index) => {
    const div = document.createElement('div');
    div.className = 'timeline-step' + ((index + 1) <= current ? ' done' : '');
    div.textContent = label;
    wrap.appendChild(div);
  });
}

function updateMap(data) {
  if (!data || !data.ok) return;

  const session = data.session || {};
  const lat = session.current_lat;
  const lng = session.current_lng;
  const route = Array.isArray(session.route_points) ? session.route_points : [];

  document.getElementById('sessionStatus').textContent = session.session_status || 'Walker Assigned';
  document.getElementById('etaMinutes').textContent = session.eta_minutes !== null && session.eta_minutes !== undefined ? `${session.eta_minutes} minutes` : 'Not set';
  document.getElementById('currentLocation').textContent = session.current_location || 'Waiting for GPS';
  document.getElementById('lastGpsAt').textContent = session.last_gps_at || 'Not yet';
  document.getElementById('lastUpdate').textContent = session.last_update || 'No updates yet';
  document.getElementById('bathroomUpdate').textContent = session.bathroom_update || 'No bathroom update yet';
  document.getElementById('photoNote').textContent = session.photo_note || 'No photo update yet';
  document.getElementById('routeNote').textContent = session.route_note || 'No route note yet';

  renderTimeline(session.session_status || 'Walker Assigned');

  if (lat !== null && lng !== null) {
    marker.setLatLng([lat, lng]);
    map.setView([lat, lng], map.getZoom());
  }

  if (route.length > 0) {
    polyline.setLatLngs(route.map(point => [point.lat, point.lng]));
  }
}

async function pollTracking() {
  try {
    const response = await fetch(`tracking-data.php?walk_id=${walkId}&_=${Date.now()}`);
    const data = await response.json();
    updateMap(data);
  } catch (error) {
    console.error('Tracking poll failed:', error);
  }
}

setInterval(pollTracking, 5000);
</script>

<?php include 'includes/footer.php'; ?>