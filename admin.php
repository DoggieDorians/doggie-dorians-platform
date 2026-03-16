<?php
require_once __DIR__ . '/includes/member_config.php';

function loadInstantBookings(string $filePath): array {
    if (!file_exists($filePath)) {
        return [];
    }

    $json = file_get_contents($filePath);
    $decoded = json_decode((string)$json, true);

    return is_array($decoded) ? $decoded : [];
}

$memberCount = 0;
$walkerCount = 0;
$walkCount = 0;
$planCount = 0;

$recentWalks = [];
$recentPlans = [];
$recentInstantBookings = [];

try {
    $memberCount = (int)$pdo->query("SELECT COUNT(*) FROM members")->fetchColumn();
    $walkerCount = (int)$pdo->query("SELECT COUNT(*) FROM walkers")->fetchColumn();
    $walkCount = (int)$pdo->query("SELECT COUNT(*) FROM walks")->fetchColumn();
    $planCount = (int)$pdo->query("SELECT COUNT(*) FROM custom_plans")->fetchColumn();

    $walkStmt = $pdo->query("
        SELECT
            walks.id,
            walks.walk_date,
            walks.walk_time,
            walks.duration_minutes,
            walks.status,
            walks.walker_name,
            dogs.dog_name,
            members.email AS member_email,
            members.username AS member_username
        FROM walks
        INNER JOIN dogs ON dogs.id = walks.dog_id
        INNER JOIN members ON members.id = walks.member_id
        ORDER BY walks.created_at DESC
        LIMIT 8
    ");
    $recentWalks = $walkStmt->fetchAll(PDO::FETCH_ASSOC);

    $planStmt = $pdo->query("
        SELECT
            custom_plans.plan_name,
            custom_plans.monthly_total,
            custom_plans.payment_mode,
            custom_plans.payment_status,
            custom_plans.created_at,
            members.email AS member_email,
            members.username AS member_username
        FROM custom_plans
        INNER JOIN members ON members.id = custom_plans.member_id
        ORDER BY custom_plans.created_at DESC
        LIMIT 6
    ");
    $recentPlans = $planStmt->fetchAll(PDO::FETCH_ASSOC);

    $instantBookingFile = __DIR__ . '/data/instant_bookings.json';
    $recentInstantBookings = array_reverse(loadInstantBookings($instantBookingFile));
    $recentInstantBookings = array_slice($recentInstantBookings, 0, 8);
} catch (Throwable $e) {
    $recentWalks = [];
    $recentPlans = [];
    $recentInstantBookings = [];
}
?>
<?php include 'includes/header.php'; ?>
<?php include 'includes/nav.php'; ?>

<style>
.admin-dashboard-page{
  background:#f4f1ea;
  min-height:calc(100vh - 120px);
  padding:32px 18px 60px;
}
.admin-dashboard-shell{
  max-width:1380px;
  margin:0 auto;
  display:grid;
  gap:22px;
}
.admin-dashboard-hero{
  background:linear-gradient(135deg,#111111 0%,#2b2414 100%);
  color:#fff;
  border-radius:28px;
  padding:34px 28px;
  box-shadow:0 14px 40px rgba(0,0,0,0.12);
}
.admin-dashboard-hero h1{
  margin:0 0 10px;
  font-size:38px;
}
.admin-dashboard-hero p{
  margin:0;
  color:rgba(255,255,255,0.82);
  max-width:820px;
  line-height:1.6;
}
.quick-links{
  display:flex;
  gap:12px;
  flex-wrap:wrap;
  margin-top:18px;
}
.quick-link{
  display:inline-block;
  padding:12px 16px;
  border-radius:999px;
  font-weight:700;
  text-decoration:none;
  background:rgba(255,255,255,0.08);
  color:#fff;
  border:1px solid rgba(255,255,255,0.08);
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
  font-size:13px;
  text-transform:uppercase;
  letter-spacing:1px;
  margin-bottom:10px;
  font-weight:700;
}
.stat-card .value{
  font-size:34px;
  font-weight:800;
  color:#111;
}
.content-grid{
  display:grid;
  grid-template-columns:1.1fr .9fr;
  gap:22px;
}
.panel-card{
  background:#fff;
  border-radius:24px;
  padding:24px;
  box-shadow:0 12px 30px rgba(0,0,0,0.07);
}
.panel-card h2{
  margin:0 0 16px;
}
.record-list{
  display:grid;
  gap:14px;
}
.record-item{
  background:#f7f4ee;
  border-radius:18px;
  padding:16px;
}
.record-title{
  display:flex;
  justify-content:space-between;
  gap:12px;
  align-items:flex-start;
  margin-bottom:8px;
}
.record-title strong{
  color:#111;
}
.record-meta{
  color:#666;
  font-size:14px;
  line-height:1.5;
}
.status-pill{
  display:inline-block;
  padding:8px 12px;
  border-radius:999px;
  background:#111;
  color:#fff;
  font-size:12px;
  font-weight:700;
}
.empty-state{
  background:#f7f4ee;
  border-radius:18px;
  padding:18px;
  color:#666;
}
.admin-link-grid{
  display:grid;
  gap:12px;
}
.admin-link-box{
  background:#f7f4ee;
  border-radius:18px;
  padding:18px;
}
.admin-link-box strong{
  display:block;
  margin-bottom:8px;
  color:#111;
}
.admin-link-box p{
  margin:0 0 12px;
  color:#666;
  line-height:1.5;
}
.admin-link-box a{
  display:inline-block;
  text-decoration:none;
  background:#111;
  color:#fff;
  padding:10px 14px;
  border-radius:999px;
  font-weight:700;
}
@media (max-width:1100px){
  .stats-grid{
    grid-template-columns:repeat(2,1fr);
  }
  .content-grid{
    grid-template-columns:1fr;
  }
}
@media (max-width:700px){
  .stats-grid{
    grid-template-columns:1fr;
  }
  .admin-dashboard-hero h1{
    font-size:30px;
  }
  .admin-dashboard-hero,
  .panel-card,
  .stat-card{
    padding:20px;
  }
}
</style>

<main class="admin-dashboard-page">
  <div class="admin-dashboard-shell">

    <section class="admin-dashboard-hero">
      <h1>Admin Control Panel</h1>
      <p>
        Manage bookings, walkers, members, plans, and live operations from one dashboard.
      </p>

      <div class="quick-links">
        <a href="admin-bookings.php" class="quick-link">Admin Bookings</a>
        <a href="admin-walks.php" class="quick-link">Walker Assignments</a>
        <a href="admin-tracking.php" class="quick-link">Tracking Admin</a>
        <a href="walker-dashboard.php" class="quick-link">Walker Portal</a>
        <a href="instant-booking.php" class="quick-link">Instant Booking Page</a>
      </div>
    </section>

    <section class="stats-grid">
      <div class="stat-card">
        <div class="label">Members</div>
        <div class="value"><?= e((string)$memberCount) ?></div>
      </div>

      <div class="stat-card">
        <div class="label">Walkers</div>
        <div class="value"><?= e((string)$walkerCount) ?></div>
      </div>

      <div class="stat-card">
        <div class="label">Walk Requests</div>
        <div class="value"><?= e((string)$walkCount) ?></div>
      </div>

      <div class="stat-card">
        <div class="label">Saved Plans</div>
        <div class="value"><?= e((string)$planCount) ?></div>
      </div>
    </section>

    <section class="content-grid">

      <div class="panel-card">
        <h2>Recent Walk Requests</h2>

        <?php if (!$recentWalks): ?>
          <div class="empty-state">No walk requests found yet.</div>
        <?php else: ?>
          <div class="record-list">
            <?php foreach ($recentWalks as $walk): ?>
              <div class="record-item">
                <div class="record-title">
                  <strong><?= e($walk['dog_name']) ?></strong>
                  <span class="status-pill"><?= e($walk['status']) ?></span>
                </div>
                <div class="record-meta">
                  Member: <?= e($walk['member_username'] ?: $walk['member_email']) ?><br>
                  Date: <?= e($walk['walk_date']) ?> at <?= e($walk['walk_time']) ?><br>
                  Duration: <?= e((string)$walk['duration_minutes']) ?> minutes<br>
                  Walker: <?= e($walk['walker_name'] ?: 'Unassigned') ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="panel-card">
        <h2>Admin Tools</h2>

        <div class="admin-link-grid">
          <div class="admin-link-box">
            <strong>Admin Bookings</strong>
            <p>Review every walk request, update status, and assign walkers from one control center.</p>
            <a href="admin-bookings.php">Open</a>
          </div>

          <div class="admin-link-box">
            <strong>Walker Assignment</strong>
            <p>Assign walks to specific walkers and manage service status.</p>
            <a href="admin-walks.php">Open</a>
          </div>

          <div class="admin-link-box">
            <strong>Live Tracking Admin</strong>
            <p>Update active sessions, route notes, ETA, and walk progress.</p>
            <a href="admin-tracking.php">Open</a>
          </div>

          <div class="admin-link-box">
            <strong>Walker Portal</strong>
            <p>Preview the employee-side workflow and assigned walk updates.</p>
            <a href="walker-login.php">Open</a>
          </div>
        </div>
      </div>

    </section>

    <section class="content-grid">

      <div class="panel-card">
        <h2>Recent Instant Bookings</h2>

        <?php if (!$recentInstantBookings): ?>
          <div class="empty-state">No instant bookings submitted yet.</div>
        <?php else: ?>
          <div class="record-list">
            <?php foreach ($recentInstantBookings as $booking): ?>
              <div class="record-item">
                <div class="record-title">
                  <strong><?= e($booking['dog_name'] ?? 'Dog') ?></strong>
                  <span class="status-pill"><?= e($booking['service_label'] ?? 'Service') ?></span>
                </div>
                <div class="record-meta">
                  Owner: <?= e($booking['owner_name'] ?? '') ?><br>
                  Email: <?= e($booking['email'] ?? '') ?><br>
                  Phone: <?= e($booking['phone'] ?? '') ?><br>
                  Date: <?= e($booking['walk_date'] ?? '') ?> at <?= e($booking['walk_time'] ?? '') ?><br>
                  Price: $<?= e(number_format((float)($booking['price'] ?? 0), 2)) ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="panel-card">
        <h2>Recent Saved Plans</h2>

        <?php if (!$recentPlans): ?>
          <div class="empty-state">No saved plans found yet.</div>
        <?php else: ?>
          <div class="record-list">
            <?php foreach ($recentPlans as $plan): ?>
              <div class="record-item">
                <div class="record-title">
                  <strong><?= e($plan['plan_name']) ?></strong>
                  <span class="status-pill"><?= e($plan['payment_mode'] === 'upfront' ? 'Upfront' : 'Pay As You Go') ?></span>
                </div>
                <div class="record-meta">
                  Member: <?= e($plan['member_username'] ?: $plan['member_email']) ?><br>
                  Monthly Total: $<?= e(number_format((float)$plan['monthly_total'], 2)) ?><br>
                  Payment Status: <?= e(ucfirst($plan['payment_status'])) ?><br>
                  Created: <?= e($plan['created_at']) ?>
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