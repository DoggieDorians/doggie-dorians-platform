<?php
require_once __DIR__ . '/includes/member_config.php';

$statusFilter = trim((string)($_GET['status'] ?? ''));
$walkerFilter = trim((string)($_GET['walker'] ?? ''));
$dateFilter   = trim((string)($_GET['date'] ?? ''));

$flashMessage = '';
$flashType = 'success';

function normalizeWalkStatus(?string $status): string {
    $status = strtolower(trim((string)$status));

    $allowed = [
        'pending',
        'approved',
        'assigned',
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
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        default => ucfirst($status)
    };
}

function statusClass(string $status): string {
    return match ($status) {
        'pending' => 'pending',
        'approved' => 'approved',
        'assigned' => 'assigned',
        'in_progress' => 'in-progress',
        'completed' => 'completed',
        'cancelled' => 'cancelled',
        default => 'pending'
    };
}

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

$walksTableHasWalkerId = hasColumn($pdo, 'walks', 'walker_id');
$walksTableHasNotes = hasColumn($pdo, 'walks', 'notes');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $walkId = (int)($_POST['walk_id'] ?? 0);
    $walkerName = trim((string)($_POST['walker_name'] ?? ''));
    $walkerId = (int)($_POST['walker_id'] ?? 0);
    $newStatus = normalizeWalkStatus($_POST['new_status'] ?? 'pending');
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($walkId > 0) {
        try {
            if ($action === 'update_status') {
                if ($walksTableHasNotes) {
                    $stmt = $pdo->prepare("
                        UPDATE walks
                        SET status = :status,
                            notes = :notes
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':status' => $newStatus,
                        ':notes' => $notes,
                        ':id' => $walkId
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE walks
                        SET status = :status
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':status' => $newStatus,
                        ':id' => $walkId
                    ]);
                }

                $flashMessage = 'Walk status updated successfully.';
            }

            if ($action === 'assign_walker') {
                if ($walksTableHasWalkerId && $walkerId > 0) {
                    if ($walksTableHasNotes) {
                        $stmt = $pdo->prepare("
                            UPDATE walks
                            SET walker_id = :walker_id,
                                walker_name = :walker_name,
                                status = 'assigned',
                                notes = :notes
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            ':walker_id' => $walkerId,
                            ':walker_name' => $walkerName,
                            ':notes' => $notes,
                            ':id' => $walkId
                        ]);
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE walks
                            SET walker_id = :walker_id,
                                walker_name = :walker_name,
                                status = 'assigned'
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            ':walker_id' => $walkerId,
                            ':walker_name' => $walkerName,
                            ':id' => $walkId
                        ]);
                    }
                } else {
                    if ($walksTableHasNotes) {
                        $stmt = $pdo->prepare("
                            UPDATE walks
                            SET walker_name = :walker_name,
                                status = 'assigned',
                                notes = :notes
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            ':walker_name' => $walkerName,
                            ':notes' => $notes,
                            ':id' => $walkId
                        ]);
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE walks
                            SET walker_name = :walker_name,
                                status = 'assigned'
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            ':walker_name' => $walkerName,
                            ':id' => $walkId
                        ]);
                    }
                }

                $flashMessage = 'Walker assigned successfully.';
            }
        } catch (Throwable $e) {
            $flashType = 'error';
            $flashMessage = 'Something went wrong while updating the booking.';
        }
    }
}

$walkerOptions = [];
$walkRows = [];

try {
    $walkerStmt = $pdo->query("
        SELECT id, name
        FROM walkers
        ORDER BY name ASC
    ");
    $walkerOptions = $walkerStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $walkerOptions = [];
}

$where = [];
$params = [];

if ($statusFilter !== '') {
    $where[] = "walks.status = :status";
    $params[':status'] = $statusFilter;
}

if ($walkerFilter !== '') {
    $where[] = "walks.walker_name = :walker_name";
    $params[':walker_name'] = $walkerFilter;
}

if ($dateFilter !== '') {
    $where[] = "walks.walk_date = :walk_date";
    $params[':walk_date'] = $dateFilter;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $sql = "
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
        $whereSql
        ORDER BY walks.walk_date ASC, walks.walk_time ASC, walks.created_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $walkRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $walkRows = [];
    if ($flashMessage === '') {
        $flashType = 'error';
        $flashMessage = 'Unable to load bookings right now.';
    }
}

$stats = [
    'total' => 0,
    'pending' => 0,
    'approved' => 0,
    'assigned' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'cancelled' => 0,
];

try {
    $stats['total'] = (int)$pdo->query("SELECT COUNT(*) FROM walks")->fetchColumn();

    $statusStmt = $pdo->query("
        SELECT status, COUNT(*) AS total
        FROM walks
        GROUP BY status
    ");
    $statusRows = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($statusRows as $row) {
        $key = strtolower((string)($row['status'] ?? ''));
        if (isset($stats[$key])) {
            $stats[$key] = (int)$row['total'];
        }
    }
} catch (Throwable $e) {
}
?>
<?php include 'includes/header.php'; ?>
<?php include 'includes/nav.php'; ?>

<style>
.admin-bookings-page{
  background:#f4f1ea;
  min-height:calc(100vh - 120px);
  padding:32px 18px 60px;
}
.admin-bookings-shell{
  max-width:1440px;
  margin:0 auto;
  display:grid;
  gap:22px;
}
.admin-bookings-hero{
  background:linear-gradient(135deg,#111111 0%,#2b2414 100%);
  color:#fff;
  border-radius:28px;
  padding:34px 28px;
  box-shadow:0 14px 40px rgba(0,0,0,0.12);
}
.admin-bookings-hero h1{
  margin:0 0 10px;
  font-size:38px;
}
.admin-bookings-hero p{
  margin:0;
  color:rgba(255,255,255,0.82);
  max-width:900px;
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
  grid-template-columns:repeat(7,1fr);
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
  font-size:30px;
  font-weight:800;
  color:#111;
}
.filter-card{
  background:#fff;
  border-radius:24px;
  padding:24px;
  box-shadow:0 12px 30px rgba(0,0,0,0.07);
}
.filter-card h2{
  margin:0 0 16px;
}
.filters-grid{
  display:grid;
  grid-template-columns:repeat(4,1fr) auto auto;
  gap:14px;
  align-items:end;
}
.filters-grid label{
  display:block;
  margin-bottom:8px;
  color:#666;
  font-size:12px;
  font-weight:700;
  text-transform:uppercase;
  letter-spacing:1px;
}
.filters-grid select,
.filters-grid input{
  width:100%;
  border:1px solid #ddd4c6;
  background:#fff;
  border-radius:14px;
  padding:12px 14px;
  font-size:14px;
}
.btn-dark,
.btn-gold,
.btn-light,
.btn-danger,
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
.btn-danger{ background:#9d3b35; color:#fff; }
.btn-blue{ background:#2f5f94; color:#fff; }

.bookings-list{
  display:grid;
  gap:18px;
}
.booking-card{
  background:#fff;
  border-radius:24px;
  box-shadow:0 12px 30px rgba(0,0,0,0.07);
  overflow:hidden;
}
.booking-head{
  padding:20px 22px;
  background:linear-gradient(180deg,#f8f4ec 0%,#ffffff 100%);
  border-bottom:1px solid #eee4d7;
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:16px;
  flex-wrap:wrap;
}
.booking-head h3{
  margin:0 0 8px;
  font-size:24px;
}
.booking-sub{
  color:#666;
  line-height:1.6;
  font-size:14px;
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
.status-pill.in-progress{ background:#6b57a5; }
.status-pill.completed{ background:#1f6f5f; }
.status-pill.cancelled{ background:#9d3b35; }

.booking-body{
  padding:22px;
  display:grid;
  gap:18px;
}
.info-grid{
  display:grid;
  grid-template-columns:repeat(3,1fr);
  gap:16px;
}
.info-card{
  background:#f7f4ee;
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
  grid-template-columns:1.2fr 1fr;
  gap:16px;
}
.action-card{
  background:#f7f4ee;
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
.action-card select,
.action-card textarea{
  width:100%;
  border:1px solid #ddd4c6;
  background:#fff;
  border-radius:14px;
  padding:12px 14px;
  font-size:14px;
}
.action-card textarea{
  min-height:92px;
  resize:vertical;
}
.action-buttons{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
}
.empty-state{
  background:#fff;
  border-radius:24px;
  padding:24px;
  box-shadow:0 12px 30px rgba(0,0,0,0.07);
  color:#666;
}
@media (max-width:1200px){
  .stats-grid{
    grid-template-columns:repeat(3,1fr);
  }
  .filters-grid{
    grid-template-columns:repeat(2,1fr);
  }
  .info-grid,
  .actions-grid{
    grid-template-columns:1fr;
  }
}
@media (max-width:760px){
  .stats-grid{
    grid-template-columns:repeat(2,1fr);
  }
  .admin-bookings-hero h1{
    font-size:30px;
  }
  .admin-bookings-hero,
  .filter-card,
  .booking-body,
  .booking-head,
  .stat-card{
    padding:20px;
  }
}
@media (max-width:560px){
  .stats-grid,
  .filters-grid{
    grid-template-columns:1fr;
  }
}
</style>

<main class="admin-bookings-page">
  <div class="admin-bookings-shell">

    <section class="admin-bookings-hero">
      <h1>Admin Bookings</h1>
      <p>
        Review all walk requests, update service status, assign walkers, and keep daily operations polished and organized.
      </p>

      <div class="hero-links">
        <a href="admin.php">Back to Admin Panel</a>
        <a href="admin-walks.php">Walker Assignments</a>
        <a href="admin-tracking.php">Tracking Admin</a>
        <a href="walker-dashboard.php">Walker Portal</a>
      </div>
    </section>

    <?php if ($flashMessage !== ''): ?>
      <div class="flash-message <?= e($flashType) ?>">
        <?= e($flashMessage) ?>
      </div>
    <?php endif; ?>

    <section class="stats-grid">
      <div class="stat-card">
        <div class="label">Total Requests</div>
        <div class="value"><?= e((string)$stats['total']) ?></div>
      </div>
      <div class="stat-card">
        <div class="label">Pending</div>
        <div class="value"><?= e((string)$stats['pending']) ?></div>
      </div>
      <div class="stat-card">
        <div class="label">Approved</div>
        <div class="value"><?= e((string)$stats['approved']) ?></div>
      </div>
      <div class="stat-card">
        <div class="label">Assigned</div>
        <div class="value"><?= e((string)$stats['assigned']) ?></div>
      </div>
      <div class="stat-card">
        <div class="label">In Progress</div>
        <div class="value"><?= e((string)$stats['in_progress']) ?></div>
      </div>
      <div class="stat-card">
        <div class="label">Completed</div>
        <div class="value"><?= e((string)$stats['completed']) ?></div>
      </div>
      <div class="stat-card">
        <div class="label">Cancelled</div>
        <div class="value"><?= e((string)$stats['cancelled']) ?></div>
      </div>
    </section>

    <section class="filter-card">
      <h2>Filter Requests</h2>

      <form method="GET" class="filters-grid">
        <div>
          <label for="status">Status</label>
          <select name="status" id="status">
            <option value="">All Statuses</option>
            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
            <option value="assigned" <?= $statusFilter === 'assigned' ? 'selected' : '' ?>>Assigned</option>
            <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
            <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
            <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
          </select>
        </div>

        <div>
          <label for="walker">Walker</label>
          <select name="walker" id="walker">
            <option value="">All Walkers</option>
            <?php foreach ($walkerOptions as $walker): ?>
              <option value="<?= e($walker['name']) ?>" <?= $walkerFilter === (string)$walker['name'] ? 'selected' : '' ?>>
                <?= e($walker['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label for="date">Walk Date</label>
          <input type="date" name="date" id="date" value="<?= e($dateFilter) ?>">
        </div>

        <div></div>

        <button type="submit" class="btn-dark">Apply Filters</button>
        <a href="admin-bookings.php" class="btn-light">Reset</a>
      </form>
    </section>

    <?php if (!$walkRows): ?>
      <div class="empty-state">
        No walk requests found for the current filters.
      </div>
    <?php else: ?>
      <section class="bookings-list">
        <?php foreach ($walkRows as $walk): ?>
          <?php
            $currentStatus = normalizeWalkStatus($walk['status'] ?? 'pending');
            $currentNotes = $walk['notes'] ?? '';
          ?>
          <article class="booking-card">
            <div class="booking-head">
              <div>
                <h3><?= e($walk['dog_name']) ?> — Walk Request #<?= e((string)$walk['id']) ?></h3>
                <div class="booking-sub">
                  Member:
                  <?= e($walk['member_username'] ?: $walk['member_email']) ?>
                  <br>
                  Scheduled:
                  <?= e($walk['walk_date']) ?> at <?= e($walk['walk_time']) ?>
                </div>
              </div>

              <span class="status-pill <?= e(statusClass($currentStatus)) ?>">
                <?= e(statusLabel($currentStatus)) ?>
              </span>
            </div>

            <div class="booking-body">
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
                    <strong>Dog ID:</strong> <?= e((string)$walk['dog_id']) ?>
                  </div>
                </div>

                <div class="info-card">
                  <h4>Service</h4>
                  <div class="meta">
                    <strong>Date:</strong> <?= e($walk['walk_date']) ?><br>
                    <strong>Time:</strong> <?= e($walk['walk_time']) ?><br>
                    <strong>Duration:</strong> <?= e((string)$walk['duration_minutes']) ?> minutes<br>
                    <strong>Walker:</strong> <?= e($walk['walker_name'] ?: 'Unassigned') ?>
                  </div>
                </div>
              </div>

              <div class="actions-grid">
                <div class="action-card">
                  <h4>Update Status</h4>
                  <form method="POST">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="walk_id" value="<?= e((string)$walk['id']) ?>">

                    <select name="new_status" required>
                      <option value="pending" <?= $currentStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                      <option value="approved" <?= $currentStatus === 'approved' ? 'selected' : '' ?>>Approved</option>
                      <option value="assigned" <?= $currentStatus === 'assigned' ? 'selected' : '' ?>>Assigned</option>
                      <option value="in_progress" <?= $currentStatus === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                      <option value="completed" <?= $currentStatus === 'completed' ? 'selected' : '' ?>>Completed</option>
                      <option value="cancelled" <?= $currentStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>

                    <?php if ($walksTableHasNotes): ?>
                      <textarea name="notes" placeholder="Internal booking note..."><?= e((string)$currentNotes) ?></textarea>
                    <?php endif; ?>

                    <div class="action-buttons">
                      <button type="submit" class="btn-dark">Save Status</button>
                    </div>
                  </form>
                </div>

                <div class="action-card">
                  <h4>Assign Walker</h4>
                  <form method="POST">
                    <input type="hidden" name="action" value="assign_walker">
                    <input type="hidden" name="walk_id" value="<?= e((string)$walk['id']) ?>">

                    <select name="walker_id">
                      <option value="">Choose Walker</option>
                      <?php foreach ($walkerOptions as $walker): ?>
                        <option
                          value="<?= e((string)$walker['id']) ?>"
                          <?= ((string)($walk['walker_name'] ?? '') === (string)$walker['name']) ? 'selected' : '' ?>
                          data-name="<?= e($walker['name']) ?>"
                        >
                          <?= e($walker['name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>

                    <input
                      type="text"
                      name="walker_name"
                      value="<?= e((string)($walk['walker_name'] ?? '')) ?>"
                      placeholder="Walker name"
                      style="width:100%;border:1px solid #ddd4c6;background:#fff;border-radius:14px;padding:12px 14px;font-size:14px;"
                    >

                    <?php if ($walksTableHasNotes): ?>
                      <textarea name="notes" placeholder="Assignment note..."><?= e((string)$currentNotes) ?></textarea>
                    <?php endif; ?>

                    <div class="action-buttons">
                      <button type="submit" class="btn-blue">Assign Walker</button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>

  </div>
</main>

<script>
document.querySelectorAll('select[name="walker_id"]').forEach(function(select) {
  select.addEventListener('change', function() {
    var form = select.closest('form');
    var input = form ? form.querySelector('input[name="walker_name"]') : null;
    var selectedOption = select.options[select.selectedIndex];

    if (input && selectedOption && selectedOption.dataset.name) {
      input.value = selectedOption.dataset.name;
    }
  });
});
</script>

<?php include 'includes/footer.php'; ?>