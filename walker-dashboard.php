<?php
require_once __DIR__ . '/includes/member_config.php';

function hasColumn(PDO $pdo, string $table, string $column): bool {
    static $cache = [];

    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    try {
        $stmt = $pdo->query("PRAGMA table_info($table)");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($columns as $col) {
            if (($col['name'] ?? '') === $column) {
                $cache[$key] = true;
                return true;
            }
        }
    } catch (Throwable $e) {
    }

    $cache[$key] = false;
    return false;
}

function normalizeWalkStatus(?string $status): string {
    $status = strtolower(trim((string)$status));

    $allowed = [
        'pending',
        'approved',
        'assigned',
        'arrived',
        'in_progress',
        'completed',
        'cancelled'
    ];

    return in_array($status, $allowed, true) ? $status : 'pending';
}

function statusLabel(string $status): string {
    return match ($status) {
        'pending' => 'Pending',
        'approved' => 'Approved',
        'assigned' => 'Assigned',
        'arrived' => 'Arrived',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        default => ucfirst(str_replace('_', ' ', $status))
    };
}

function statusClass(string $status): string {
    return match ($status) {
        'pending' => 'pending',
        'approved' => 'approved',
        'assigned' => 'assigned',
        'arrived' => 'arrived',
        'in_progress' => 'in-progress',
        'completed' => 'completed',
        'cancelled' => 'cancelled',
        default => 'pending'
    };
}

$walkerId = (int)($_SESSION['walker_id'] ?? 0);

if ($walkerId <= 0) {
    header('Location: walker-login.php');
    exit;
}

$walker = null;
$walkerName = 'Walker';
$walkerEmail = '';

try {
    $walkerStmt = $pdo->prepare("
        SELECT id, name, email, is_active
        FROM walkers
        WHERE id = :id
        LIMIT 1
    ");
    $walkerStmt->execute([':id' => $walkerId]);
    $walker = $walkerStmt->fetch(PDO::FETCH_ASSOC);

    if (!$walker || (isset($walker['is_active']) && (int)$walker['is_active'] !== 1)) {
        unset($_SESSION['walker_id']);
        header('Location: walker-login.php');
        exit;
    }

    $walkerName = trim((string)($walker['name'] ?? ''));
    $walkerEmail = trim((string)($walker['email'] ?? ''));

    if ($walkerName === '') {
        $walkerName = 'Walker';
    }
} catch (Throwable $e) {
    unset($_SESSION['walker_id']);
    header('Location: walker-login.php');
    exit;
}

$walksTableHasWalkerId = hasColumn($pdo, 'walks', 'walker_id');
$walksTableHasNotes = hasColumn($pdo, 'walks', 'notes');

$flashMessage = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $walkId = (int)($_POST['walk_id'] ?? 0);
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($walkId > 0) {
        try {
            $whereParts = ["id = :id"];
            $params = [':id' => $walkId];

            if ($walksTableHasWalkerId) {
                $whereParts[] = "walker_id = :walker_id";
                $params[':walker_id'] = $walkerId;
            } else {
                $whereParts[] = "walker_name = :walker_name";
                $params[':walker_name'] = $walkerName;
            }

            $whereSql = implode(' AND ', $whereParts);

            if ($action === 'mark_arrived') {
                if ($walksTableHasNotes) {
                    $stmt = $pdo->prepare("
                        UPDATE walks
                        SET status = 'arrived',
                            notes = :notes
                        WHERE $whereSql
                    ");
                    $stmt->execute($params + [':notes' => $notes]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE walks
                        SET status = 'arrived'
                        WHERE $whereSql
                    ");
                    $stmt->execute($params);
                }

                $flashMessage = 'Walk marked as arrived.';
            }

            if ($action === 'start_walk') {
                if ($walksTableHasNotes) {
                    $stmt = $pdo->prepare("
                        UPDATE walks
                        SET status = 'in_progress',
                            notes = :notes
                        WHERE $whereSql
                    ");
                    $stmt->execute($params + [':notes' => $notes]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE walks
                        SET status = 'in_progress'
                        WHERE $whereSql
                    ");
                    $stmt->execute($params);
                }

                $flashMessage = 'Walk started successfully.';
            }

            if ($action === 'complete_walk') {
                if ($walksTableHasNotes) {
                    $stmt = $pdo->prepare("
                        UPDATE walks
                        SET status = 'completed',
                            notes = :notes
                        WHERE $whereSql
                    ");
                    $stmt->execute($params + [':notes' => $notes]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE walks
                        SET status = 'completed'
                        WHERE $whereSql
                    ");
                    $stmt->execute($params);
                }

                $flashMessage = 'Walk marked as completed.';
            }

            if ($action === 'save_notes' && $walksTableHasNotes) {
                $stmt = $pdo->prepare("
                    UPDATE walks
                    SET notes = :notes
                    WHERE $whereSql
                ");
                $stmt->execute($params + [':notes' => $notes]);

                $flashMessage = 'Walk notes updated.';
            }
        } catch (Throwable $e) {
            $flashType = 'error';
            $flashMessage = 'Unable to update this walk right now.';
        }
    }
}

$assignedWalks = [];
$todayWalks = [];
$completedWalks = [];

try {
    if ($walksTableHasWalkerId) {
        $baseWhere = "walks.walker_id = :walker_id";
        $baseParams = [':walker_id' => $walkerId];
    } else {
        $baseWhere = "walks.walker_name = :walker_name";
        $baseParams = [':walker_name' => $walkerName];
    }

    $commonSelect = "
        SELECT
            walks.*,
            dogs.dog_name,
            dogs.breed,
            dogs.size,
            members.email AS member_email,
            members.username AS member_username
        FROM walks
        INNER JOIN dogs ON dogs.id = walks.dog_id
        INNER JOIN members ON members.id = walks.member_id
    ";

    $assignedStmt = $pdo->prepare("
        $commonSelect
        WHERE $baseWhere
          AND walks.status IN ('approved', 'assigned', 'arrived', 'in_progress')
        ORDER BY walks.walk_date ASC, walks.walk_time ASC, walks.created_at DESC
    ");
    $assignedStmt->execute($baseParams);
    $assignedWalks = $assignedStmt->fetchAll(PDO::FETCH_ASSOC);

    $todayStmt = $pdo->prepare("
        $commonSelect
        WHERE $baseWhere
          AND walks.walk_date = :today
        ORDER BY walks.walk_time ASC, walks.created_at DESC
    ");
    $todayStmt->execute($baseParams + [':today' => date('Y-m-d')]);
    $todayWalks = $todayStmt->fetchAll(PDO::FETCH_ASSOC);

    $completedStmt = $pdo->prepare("
        $commonSelect
        WHERE $baseWhere
          AND walks.status = 'completed'
        ORDER BY walks.walk_date DESC, walks.walk_time DESC, walks.created_at DESC
        LIMIT 8
    ");
    $completedStmt->execute($baseParams);
    $completedWalks = $completedStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $assignedWalks = [];
    $todayWalks = [];
    $completedWalks = [];
    if ($flashMessage === '') {
        $flashType = 'error';
        $flashMessage = 'Unable to load walker assignments right now.';
    }
}

$stats = [
    'assigned' => 0,
    'today' => 0,
    'in_progress' => 0,
    'completed' => 0,
];

$stats['assigned'] = count($assignedWalks);
$stats['today'] = count($todayWalks);

foreach ($assignedWalks as $walk) {
    if (normalizeWalkStatus($walk['status'] ?? '') === 'in_progress') {
        $stats['in_progress']++;
    }
}

$stats['completed'] = count($completedWalks);
?>
<?php include 'includes/header.php'; ?>
<?php include 'includes/nav.php'; ?>

<style>
.walker-dashboard-page{
  background:#f4f1ea;
  min-height:calc(100vh - 120px);
  padding:32px 18px 60px;
}
.walker-dashboard-shell{
  max-width:1440px;
  margin:0 auto;
  display:grid;
  gap:22px;
}
.walker-hero{
  background:linear-gradient(135deg,#111111 0%,#2b2414 100%);
  color:#fff;
  border-radius:28px;
  padding:34px 28px;
  box-shadow:0 14px 40px rgba(0,0,0,0.12);
}
.walker-hero h1{
  margin:0 0 10px;
  font-size:38px;
}
.walker-hero p{
  margin:0;
  color:rgba(255,255,255,0.82);
  max-width:920px;
  line-height:1.6;
}
.hero-links{
  display:flex;
  gap:12px;
  flex-wrap:wrap;
  margin-top:18px;
}
.hero-links a{
  display:inline-block;
  padding:12px 16px;
  border-radius:999px;
  font-weight:700;
  text-decoration:none;
  background:rgba(255,255,255,0.08);
  color:#fff;
  border:1px solid rgba(255,255,255,0.08);
}
.flash-message{
  border-radius:18px;
  padding:14px 16px;
  font-weight:700;
}
.flash-message.success{
  background:#eaf6ef;
  color:#1f6b40;
  border:1px solid #cfe8d8;
}
.flash-message.error{
  background:#fff0ee;
  color:#8f2e25;
  border:1px solid #f1d2cc;
}
.stats-grid{
  display:grid;
  grid-template-columns:repeat(4,1fr);
  gap:16px;
}
.stat-card{
  background:#fff;
  border-radius:22px;
  padding:22px;
  box-shadow:0 12px 30px rgba(0,0,0,0.07);
}
.stat-card .label{
  color:#777;
  font-size:12px;
  text-transform:uppercase;
  letter-spacing:1px;
  margin-bottom:10px;
  font-weight:700;
}
.stat-card .value{
  font-size:32px;
  font-weight:800;
  color:#111;
}
.section-card{
  background:#fff;
  border-radius:24px;
  padding:24px;
  box-shadow:0 12px 30px rgba(0,0,0,0.07);
}
.section-card h2{
  margin:0 0 16px;
}
.walk-list{
  display:grid;
  gap:18px;
}
.walk-card{
  background:#f7f4ee;
  border-radius:22px;
  overflow:hidden;
}
.walk-head{
  padding:18px 20px;
  background:linear-gradient(180deg,#f8f4ec 0%,#f7f4ee 100%);
  border-bottom:1px solid #eadfce;
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:16px;
  flex-wrap:wrap;
}
.walk-head h3{
  margin:0 0 8px;
  font-size:24px;
}
.walk-sub{
  color:#666;
  font-size:14px;
  line-height:1.6;
}
.status-pill{
  display:inline-block;
  padding:9px 13px;
  border-radius:999px;
  color:#fff;
  font-size:12px;
  font-weight:700;
  text-transform:uppercase;
  letter-spacing:.7px;
}
.status-pill.pending{ background:#8a6a2f; }
.status-pill.approved{ background:#2e7d4f; }
.status-pill.assigned{ background:#2f5f94; }
.status-pill.arrived{ background:#8d5ca8; }
.status-pill.in-progress{ background:#5b48a0; }
.status-pill.completed{ background:#1f6f5f; }
.status-pill.cancelled{ background:#9d3b35; }

.walk-body{
  padding:20px;
  display:grid;
  gap:16px;
}
.info-grid{
  display:grid;
  grid-template-columns:repeat(3,1fr);
  gap:16px;
}
.info-card{
  background:#fff;
  border-radius:18px;
  padding:18px;
}
.info-card h4{
  margin:0 0 12px;
  color:#111;
}
.meta{
  color:#666;
  line-height:1.7;
  font-size:14px;
}
.meta strong{
  color:#111;
}
.actions-grid{
  display:grid;
  grid-template-columns:repeat(3,1fr);
  gap:16px;
}
.action-card{
  background:#fff;
  border-radius:18px;
  padding:18px;
}
.action-card h4{
  margin:0 0 14px;
}
.action-card form{
  display:grid;
  gap:12px;
}
.action-card textarea{
  width:100%;
  border:1px solid #ddd4c6;
  background:#fff;
  border-radius:14px;
  padding:12px 14px;
  font-size:14px;
  min-height:96px;
  resize:vertical;
}
.action-buttons{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
}
.btn-dark,
.btn-gold,
.btn-light,
.btn-green,
.btn-blue{
  border:none;
  border-radius:999px;
  padding:11px 16px;
  font-weight:700;
  text-decoration:none;
  cursor:pointer;
  display:inline-block;
  text-align:center;
}
.btn-dark{ background:#111; color:#fff; }
.btn-gold{ background:#b8955f; color:#fff; }
.btn-light{ background:#efebe2; color:#111; }
.btn-green{ background:#2e7d4f; color:#fff; }
.btn-blue{ background:#2f5f94; color:#fff; }

.summary-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:22px;
}
.simple-list{
  display:grid;
  gap:12px;
}
.simple-item{
  background:#f7f4ee;
  border-radius:18px;
  padding:16px;
}
.simple-item strong{
  display:block;
  color:#111;
  margin-bottom:6px;
}
.simple-item .small-meta{
  color:#666;
  line-height:1.6;
  font-size:14px;
}
.empty-state{
  background:#f7f4ee;
  border-radius:18px;
  padding:18px;
  color:#666;
}
@media (max-width:1200px){
  .info-grid,
  .actions-grid,
  .summary-grid{
    grid-template-columns:1fr;
  }
}
@media (max-width:900px){
  .stats-grid{
    grid-template-columns:repeat(2,1fr);
  }
}
@media (max-width:700px){
  .stats-grid{
    grid-template-columns:1fr;
  }
  .walker-hero h1{
    font-size:30px;
  }
  .walker-hero,
  .section-card,
  .stat-card,
  .walk-body,
  .walk-head{
    padding:20px;
  }
}
</style>

<main class="walker-dashboard-page">
  <div class="walker-dashboard-shell">

    <section class="walker-hero">
      <h1>Walker Operations Dashboard</h1>
      <p>
        Welcome, <?= e($walkerName) ?>. View assigned walks, mark arrival, start active services, complete visits, and keep luxury client care smooth and organized.
      </p>

      <div class="hero-links">
        <a href="live-tracking.php">Live Tracking</a>
        <a href="admin-bookings.php">Admin Bookings</a>
        <a href="walker-logout.php">Log Out</a>
      </div>
    </section>

    <?php if ($flashMessage !== ''): ?>
      <div class="flash-message <?= e($flashType) ?>">
        <?= e($flashMessage) ?>
      </div>
    <?php endif; ?>

    <section class="stats-grid">
      <div class="stat-card">
        <div class="label">Assigned Queue</div>
        <div class="value"><?= e((string)$stats['assigned']) ?></div>
      </div>
      <div class="stat-card">
        <div class="label">Today's Walks</div>
        <div class="value"><?= e((string)$stats['today']) ?></div>
      </div>
      <div class="stat-card">
        <div class="label">In Progress</div>
        <div class="value"><?= e((string)$stats['in_progress']) ?></div>
      </div>
      <div class="stat-card">
        <div class="label">Completed</div>
        <div class="value"><?= e((string)$stats['completed']) ?></div>
      </div>
    </section>

    <section class="section-card">
      <h2>Active & Assigned Walks</h2>

      <?php if (!$assignedWalks): ?>
        <div class="empty-state">No assigned walks found right now.</div>
      <?php else: ?>
        <div class="walk-list">
          <?php foreach ($assignedWalks as $walk): ?>
            <?php
              $currentStatus = normalizeWalkStatus($walk['status'] ?? 'pending');
              $currentNotes = (string)($walk['notes'] ?? '');
            ?>
            <article class="walk-card">
              <div class="walk-head">
                <div>
                  <h3><?= e($walk['dog_name']) ?> — Walk #<?= e((string)$walk['id']) ?></h3>
                  <div class="walk-sub">
                    Scheduled: <?= e($walk['walk_date']) ?> at <?= e($walk['walk_time']) ?><br>
                    Member: <?= e($walk['member_username'] ?: $walk['member_email']) ?>
                  </div>
                </div>

                <span class="status-pill <?= e(statusClass($currentStatus)) ?>">
                  <?= e(statusLabel($currentStatus)) ?>
                </span>
              </div>

              <div class="walk-body">
                <div class="info-grid">
                  <div class="info-card">
                    <h4>Client</h4>
                    <div class="meta">
                      <strong>Username:</strong> <?= e($walk['member_username'] ?: 'N/A') ?><br>
                      <strong>Email:</strong> <?= e($walk['member_email']) ?><br>
                      <strong>Member ID:</strong> <?= e((string)$walk['member_id']) ?>
                    </div>
                  </div>

                  <div class="info-card">
                    <h4>Dog Details</h4>
                    <div class="meta">
                      <strong>Name:</strong> <?= e($walk['dog_name']) ?><br>
                      <strong>Breed:</strong> <?= e($walk['breed'] ?: 'N/A') ?><br>
                      <strong>Size:</strong> <?= e($walk['size'] ?: 'N/A') ?><br>
                      <strong>Duration:</strong> <?= e((string)$walk['duration_minutes']) ?> minutes
                    </div>
                  </div>

                  <div class="info-card">
                    <h4>Service Flow</h4>
                    <div class="meta">
                      <strong>Walker:</strong> <?= e($walk['walker_name'] ?: $walkerName) ?><br>
                      <strong>Date:</strong> <?= e($walk['walk_date']) ?><br>
                      <strong>Time:</strong> <?= e($walk['walk_time']) ?><br>
                      <strong>Status:</strong> <?= e(statusLabel($currentStatus)) ?>
                    </div>
                  </div>
                </div>

                <div class="actions-grid">
                  <div class="action-card">
                    <h4>Mark Arrived</h4>
                    <form method="POST">
                      <input type="hidden" name="action" value="mark_arrived">
                      <input type="hidden" name="walk_id" value="<?= e((string)$walk['id']) ?>">

                      <?php if ($walksTableHasNotes): ?>
                        <textarea name="notes" placeholder="Arrival note, access note, lobby update, or greeting details..."><?= e($currentNotes) ?></textarea>
                      <?php endif; ?>

                      <div class="action-buttons">
                        <button type="submit" class="btn-gold">Mark Arrived</button>
                      </div>
                    </form>
                  </div>

                  <div class="action-card">
                    <h4>Start Walk</h4>
                    <form method="POST">
                      <input type="hidden" name="action" value="start_walk">
                      <input type="hidden" name="walk_id" value="<?= e((string)$walk['id']) ?>">

                      <?php if ($walksTableHasNotes): ?>
                        <textarea name="notes" placeholder="Starting walk note, temperament update, route plan, or quick observation..."><?= e($currentNotes) ?></textarea>
                      <?php endif; ?>

                      <div class="action-buttons">
                        <button type="submit" class="btn-blue">Start Walk</button>
                        <a href="live-tracking.php" class="btn-light">Open Tracking</a>
                      </div>
                    </form>
                  </div>

                  <div class="action-card">
                    <h4>Complete Walk</h4>
                    <form method="POST">
                      <input type="hidden" name="action" value="complete_walk">
                      <input type="hidden" name="walk_id" value="<?= e((string)$walk['id']) ?>">

                      <?php if ($walksTableHasNotes): ?>
                        <textarea name="notes" placeholder="Completion note, potty update, mood, behavior, or premium service summary..."><?= e($currentNotes) ?></textarea>
                      <?php endif; ?>

                      <div class="action-buttons">
                        <button type="submit" class="btn-green">Complete Walk</button>
                      </div>
                    </form>
                  </div>
                </div>

                <?php if ($walksTableHasNotes): ?>
                  <div class="action-card">
                    <h4>Save Notes Only</h4>
                    <form method="POST">
                      <input type="hidden" name="action" value="save_notes">
                      <input type="hidden" name="walk_id" value="<?= e((string)$walk['id']) ?>">
                      <textarea name="notes" placeholder="General service notes..."><?= e($currentNotes) ?></textarea>
                      <div class="action-buttons">
                        <button type="submit" class="btn-dark">Save Notes</button>
                      </div>
                    </form>
                  </div>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="summary-grid">
      <div class="section-card">
        <h2>Today's Schedule</h2>

        <?php if (!$todayWalks): ?>
          <div class="empty-state">No walks scheduled for today.</div>
        <?php else: ?>
          <div class="simple-list">
            <?php foreach ($todayWalks as $walk): ?>
              <?php $currentStatus = normalizeWalkStatus($walk['status'] ?? 'pending'); ?>
              <div class="simple-item">
                <strong><?= e($walk['walk_time']) ?> — <?= e($walk['dog_name']) ?></strong>
                <div class="small-meta">
                  <?= e($walk['member_username'] ?: $walk['member_email']) ?><br>
                  <?= e((string)$walk['duration_minutes']) ?> minutes • <?= e(statusLabel($currentStatus)) ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="section-card">
        <h2>Recent Completed Walks</h2>

        <?php if (!$completedWalks): ?>
          <div class="empty-state">No completed walks yet.</div>
        <?php else: ?>
          <div class="simple-list">
            <?php foreach ($completedWalks as $walk): ?>
              <div class="simple-item">
                <strong><?= e($walk['dog_name']) ?> — <?= e($walk['walk_date']) ?></strong>
                <div class="small-meta">
                  <?= e($walk['member_username'] ?: $walk['member_email']) ?><br>
                  <?= e($walk['walk_time']) ?> • <?= e((string)$walk['duration_minutes']) ?> minutes
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>

  </div>
</main>

<?php include 'includes/footer.php'; ?>