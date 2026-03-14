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

$displayName = $member['username'] ?: $member['email'];

$dogs = [];
$dogCount = 0;
$walkCount = 0;
$planCount = 0;
$latestWalkId = 0;

if ((int)$member['id'] > 0) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM dogs
        WHERE member_id = :member_id
        ORDER BY created_at DESC
        LIMIT 3
    ");
    $stmt->execute([':member_id' => $member['id']]);
    $dogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM dogs WHERE member_id = :member_id");
    $countStmt->execute([':member_id' => $member['id']]);
    $dogCount = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    $walkCountStmt = $pdo->prepare("SELECT COUNT(*) as total FROM walks WHERE member_id = :member_id");
    $walkCountStmt->execute([':member_id' => $member['id']]);
    $walkCount = (int)$walkCountStmt->fetch(PDO::FETCH_ASSOC)['total'];

    $planCountStmt = $pdo->prepare("SELECT COUNT(*) as total FROM custom_plans WHERE member_id = :member_id");
    $planCountStmt->execute([':member_id' => $member['id']]);
    $planCount = (int)$planCountStmt->fetch(PDO::FETCH_ASSOC)['total'];

    $latestWalkStmt = $pdo->prepare("
        SELECT id
        FROM walks
        WHERE member_id = :member_id
        ORDER BY walk_date DESC, walk_time DESC
        LIMIT 1
    ");
    $latestWalkStmt->execute([':member_id' => $member['id']]);
    $latestWalk = $latestWalkStmt->fetch(PDO::FETCH_ASSOC);
    $latestWalkId = $latestWalk ? (int)$latestWalk['id'] : 0;
} else {
    $dogs = [
        [
            'dog_name' => 'Bentley',
            'breed' => 'French Bulldog',
            'age' => '3 years',
            'weight' => '24 lbs',
            'temperament' => 'Friendly and energetic'
        ]
    ];
    $dogCount = 1;
    $walkCount = 2;
    $planCount = 1;
    $latestWalkId = 1;
}
?>
<?php include 'includes/header.php'; ?>
<?php include 'includes/nav.php'; ?>

<style>
.dashboard-app {
  background: #f4f1ea;
  min-height: calc(100vh - 120px);
  padding: 30px 20px 50px;
}
.dashboard-shell {
  max-width: 1400px;
  margin: 0 auto;
  display: grid;
  grid-template-columns: 280px 1fr;
  gap: 24px;
}
.dashboard-sidebar {
  background: #111111;
  color: #ffffff;
  border-radius: 28px;
  padding: 28px 22px;
  box-shadow: 0 14px 40px rgba(0, 0, 0, 0.12);
  height: fit-content;
  position: sticky;
  top: 24px;
}
.dashboard-brand {
  margin-bottom: 28px;
}
.dashboard-brand h2 {
  margin: 0 0 8px;
  font-size: 24px;
}
.dashboard-brand p {
  margin: 0;
  color: rgba(255,255,255,0.72);
  font-size: 14px;
}
.member-chip {
  display: inline-block;
  margin-top: 14px;
  padding: 10px 14px;
  border-radius: 999px;
  background: rgba(212, 175, 55, 0.16);
  color: #d4af37;
  font-weight: 700;
  font-size: 13px;
}
.sidebar-section-title {
  margin: 26px 0 12px;
  font-size: 12px;
  letter-spacing: 1.3px;
  text-transform: uppercase;
  color: rgba(255,255,255,0.55);
}
.sidebar-nav {
  display: grid;
  gap: 10px;
}
.sidebar-link {
  display: block;
  padding: 13px 15px;
  border-radius: 16px;
  color: #ffffff;
  background: rgba(255,255,255,0.04);
  font-weight: 600;
  transition: 0.2s ease;
}
.sidebar-link.active {
  background: #d4af37;
  color: #111111;
}
.sidebar-link:hover {
  background: rgba(255,255,255,0.12);
}
.sidebar-link.active:hover {
  background: #d4af37;
}
.sidebar-footer {
  margin-top: 28px;
  padding-top: 22px;
  border-top: 1px solid rgba(255,255,255,0.1);
}
.sidebar-footer a {
  display: inline-block;
  width: 100%;
  text-align: center;
  background: #ffffff;
  color: #111111;
  padding: 13px 16px;
  border-radius: 999px;
  font-weight: 700;
}
.dashboard-main {
  display: grid;
  gap: 24px;
}
.dashboard-hero {
  background: linear-gradient(135deg, #111111 0%, #2b2414 100%);
  color: #ffffff;
  border-radius: 30px;
  padding: 34px;
  box-shadow: 0 14px 40px rgba(0, 0, 0, 0.12);
}
.dashboard-hero h1 {
  margin: 0 0 10px;
  font-size: 38px;
  line-height: 1.1;
}
.dashboard-hero p {
  margin: 0;
  max-width: 760px;
  color: rgba(255,255,255,0.8);
}
.hero-badges {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  margin-top: 24px;
}
.hero-badge {
  background: rgba(255,255,255,0.08);
  border: 1px solid rgba(255,255,255,0.08);
  color: #ffffff;
  padding: 11px 14px;
  border-radius: 999px;
  font-size: 14px;
  font-weight: 600;
}
.hero-badge.gold {
  background: rgba(212, 175, 55, 0.16);
  color: #f2d471;
  border-color: rgba(212,175,55,0.2);
}
.dashboard-grid {
  display: grid;
  grid-template-columns: 1.3fr 0.9fr;
  gap: 24px;
}
.dashboard-column {
  display: grid;
  gap: 24px;
}
.dashboard-card {
  background: #ffffff;
  border-radius: 26px;
  padding: 28px;
  box-shadow: 0 12px 30px rgba(0, 0, 0, 0.07);
}
.dashboard-card h2,
.dashboard-card h3 {
  margin-top: 0;
}
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
  margin-bottom: 18px;
}
.card-header h2,
.card-header h3 {
  margin-bottom: 0;
}
.card-link {
  color: #111111;
  font-weight: 700;
}
.status-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 18px;
}
.status-box {
  background: #f7f4ee;
  border-radius: 20px;
  padding: 18px;
}
.status-label {
  margin: 0 0 8px;
  font-size: 13px;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: #777777;
}
.status-value {
  margin: 0;
  font-size: 28px;
  font-weight: 800;
  color: #111111;
}
.status-note {
  margin-top: 8px;
  font-size: 13px;
  color: #666666;
}
.quick-actions {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 14px;
}
.action-box {
  background: #f7f4ee;
  border-radius: 18px;
  padding: 18px;
}
.action-box h4 {
  margin: 0 0 8px;
  font-size: 17px;
}
.action-box p {
  margin: 0 0 12px;
  color: #666666;
  font-size: 14px;
}
.action-button {
  display: inline-block;
  background: #111111;
  color: #ffffff;
  padding: 11px 14px;
  border-radius: 999px;
  font-weight: 700;
  font-size: 14px;
}
.action-button.gold {
  background: #d4af37;
  color: #111111;
}
.dog-preview-list {
  display: grid;
  gap: 14px;
}
.dog-preview-item {
  background: #f7f4ee;
  border-radius: 18px;
  padding: 16px 18px;
}
.dog-preview-item h4 {
  margin: 0 0 8px;
  font-size: 18px;
}
.dog-preview-item p {
  margin: 0;
  color: #666666;
  font-size: 14px;
}
.empty-card {
  background: #f7f4ee;
  border-radius: 18px;
  padding: 18px;
  color: #666666;
}
.account-list {
  display: grid;
  gap: 12px;
}
.account-row {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  padding: 12px 0;
  border-bottom: 1px solid #efefef;
}
.account-row:last-child {
  border-bottom: 0;
}
.account-label {
  color: #666666;
}
.account-value {
  font-weight: 700;
  color: #111111;
  text-align: right;
}
.map-preview {
  background: linear-gradient(135deg, #ece4d0 0%, #f7f4ee 100%);
  border-radius: 22px;
  padding: 22px;
  min-height: 260px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}
.map-box {
  height: 160px;
  border-radius: 18px;
  background:
    linear-gradient(90deg, rgba(17,17,17,0.04) 1px, transparent 1px),
    linear-gradient(rgba(17,17,17,0.04) 1px, transparent 1px),
    #ffffff;
  background-size: 24px 24px;
  position: relative;
  overflow: hidden;
}
.route-line {
  position: absolute;
  width: 70%;
  height: 6px;
  background: #d4af37;
  top: 52%;
  left: 15%;
  border-radius: 999px;
  transform: rotate(-12deg);
}
.route-dot {
  position: absolute;
  width: 18px;
  height: 18px;
  background: #111111;
  border-radius: 50%;
  top: calc(52% - 6px);
  left: 67%;
  box-shadow: 0 0 0 6px rgba(212,175,55,0.18);
}
@media (max-width: 1150px) {
  .dashboard-shell {
    grid-template-columns: 1fr;
  }
  .dashboard-sidebar {
    position: static;
  }
  .dashboard-grid {
    grid-template-columns: 1fr;
  }
}
@media (max-width: 800px) {
  .status-grid,
  .quick-actions {
    grid-template-columns: 1fr;
  }
  .dashboard-hero h1 {
    font-size: 30px;
  }
}
</style>

<main class="dashboard-app">
  <div class="dashboard-shell">

    <aside class="dashboard-sidebar">
      <div class="dashboard-brand">
        <h2>Member Portal</h2>
        <p>Luxury care, organized in one place.</p>
        <span class="member-chip">Premium Member</span>
      </div>

      <div class="sidebar-section-title">Dashboard</div>
      <nav class="sidebar-nav">
        <a href="dashboard.php" class="sidebar-link active">Overview</a>
        <a href="my-dogs.php" class="sidebar-link">My Dogs</a>
        <a href="my-walks.php" class="sidebar-link">My Walks</a>
        <a href="book-walk.php" class="sidebar-link">Book a Walk</a>
        <a href="<?= $latestWalkId > 0 ? 'live-tracking.php?walk_id=' . $latestWalkId : 'live-tracking.php' ?>" class="sidebar-link">Live Tracking</a>
      </nav>

      <div class="sidebar-section-title">Membership</div>
      <nav class="sidebar-nav">
        <a href="customize-plan.php" class="sidebar-link">Customize Plan</a>
        <a href="#" class="sidebar-link">Billing</a>
        <a href="#" class="sidebar-link">Priority Booking</a>
      </nav>

      <div class="sidebar-section-title">Support</div>
      <nav class="sidebar-nav">
        <a href="walker-login.php" class="sidebar-link">Walker Portal</a>
        <a href="admin-walks.php" class="sidebar-link">Walker Admin</a>
        <a href="admin-tracking.php" class="sidebar-link">Tracking Admin</a>
        <a href="#" class="sidebar-link">Contact Support</a>
      </nav>

      <div class="sidebar-footer">
        <a href="logout.php">Log Out</a>
      </div>
    </aside>

    <section class="dashboard-main">

      <div class="dashboard-hero">
        <h1>Welcome back, <?= e($displayName) ?></h1>
        <p>
          Manage your dogs, review walks, build custom plans, and follow live service updates from one premium dashboard.
        </p>

        <div class="hero-badges">
          <span class="hero-badge gold">Membership Active</span>
          <span class="hero-badge"><?= $dogCount ?> Dog<?= $dogCount === 1 ? '' : 's' ?> Added</span>
          <span class="hero-badge"><?= $walkCount ?> Walk<?= $walkCount === 1 ? '' : 's' ?> Total</span>
          <span class="hero-badge"><?= $planCount ?> Plan<?= $planCount === 1 ? '' : 's' ?> Saved</span>
        </div>
      </div>

      <div class="dashboard-grid">

        <div class="dashboard-column">

          <div class="dashboard-card">
            <div class="card-header">
              <h2>Membership Snapshot</h2>
              <a href="customize-plan.php" class="card-link">Manage Plan</a>
            </div>

            <div class="status-grid">
              <div class="status-box">
                <p class="status-label">Current Tier</p>
                <p class="status-value">VIP</p>
                <p class="status-note">Priority support enabled</p>
              </div>

              <div class="status-box">
                <p class="status-label">Dog Profiles</p>
                <p class="status-value"><?= $dogCount ?></p>
                <p class="status-note">Saved in your account</p>
              </div>

              <div class="status-box">
                <p class="status-label">Walks</p>
                <p class="status-value"><?= $walkCount ?></p>
                <p class="status-note">Requested and scheduled</p>
              </div>

              <div class="status-box">
                <p class="status-label">Plans</p>
                <p class="status-value"><?= $planCount ?></p>
                <p class="status-note">Customized and saved</p>
              </div>
            </div>
          </div>

          <div class="dashboard-card">
            <div class="card-header">
              <h2>My Dogs</h2>
              <a href="my-dogs.php" class="card-link">Open My Dogs</a>
            </div>

            <?php if (!$dogs): ?>
              <div class="empty-card">
                No dogs added yet. Create your first dog profile to start booking walks.
              </div>
            <?php else: ?>
              <div class="dog-preview-list">
                <?php foreach ($dogs as $dog): ?>
                  <div class="dog-preview-item">
                    <h4><?= e($dog['dog_name']) ?></h4>
                    <p>
                      <?= e($dog['breed'] ?: 'Breed not added') ?>
                      <?php if (!empty($dog['age'])): ?>
                        · <?= e($dog['age']) ?>
                      <?php endif; ?>
                      <?php if (!empty($dog['weight'])): ?>
                        · <?= e($dog['weight']) ?>
                      <?php endif; ?>
                    </p>
                    <p style="margin-top:8px;">
                      <?= e($dog['temperament'] ?: 'Temperament not added yet') ?>
                    </p>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="dashboard-card">
            <div class="card-header">
              <h2>Quick Actions</h2>
              <a href="walker-login.php" class="card-link">Walker Portal</a>
            </div>

            <div class="quick-actions">
              <div class="action-box">
                <h4>Add a Dog</h4>
                <p>Create a dog profile for walks, bookings, and future tracking.</p>
                <a href="my-dogs.php" class="action-button gold">Add Dog</a>
              </div>

              <div class="action-box">
                <h4>Book a Walk</h4>
                <p>Submit a walk request with your preferred date, time, and duration.</p>
                <a href="book-walk.php" class="action-button">Book Now</a>
              </div>

              <div class="action-box">
                <h4>View Walks</h4>
                <p>See requested, assigned, in-progress, and completed walks.</p>
                <a href="my-walks.php" class="action-button">Open Walks</a>
              </div>

              <div class="action-box">
                <h4>Live Tracking</h4>
                <p>Open your active walk tracking view and watch updates in real time.</p>
                <a href="<?= $latestWalkId > 0 ? 'live-tracking.php?walk_id=' . $latestWalkId : 'live-tracking.php' ?>" class="action-button">Open Tracker</a>
              </div>
            </div>
          </div>

        </div>

        <div class="dashboard-column">

          <div class="dashboard-card">
            <div class="card-header">
              <h3>Live Walk Tracking</h3>
              <a href="<?= $latestWalkId > 0 ? 'live-tracking.php?walk_id=' . $latestWalkId : 'live-tracking.php' ?>" class="card-link">Open Tracker</a>
            </div>

            <div class="map-preview">
              <div class="map-box">
                <div class="route-line"></div>
                <div class="route-dot"></div>
              </div>
              <div>
                <strong>Tracking Preview</strong>
                <p style="margin:8px 0 0; color:#555555;">
                  Your live walk map, route details, ETA, and walker updates now connect directly to the walker portal.
                </p>
              </div>
            </div>
          </div>

          <div class="dashboard-card">
            <div class="card-header">
              <h3>Account Summary</h3>
              <a href="#" class="card-link">Edit</a>
            </div>

            <div class="account-list">
              <div class="account-row">
                <span class="account-label">Email</span>
                <span class="account-value"><?= e($member['email']) ?></span>
              </div>

              <div class="account-row">
                <span class="account-label">Phone</span>
                <span class="account-value"><?= e($member['phone'] ?: 'Not added') ?></span>
              </div>

              <div class="account-row">
                <span class="account-label">Username</span>
                <span class="account-value"><?= e($member['username'] ?: 'Not added') ?></span>
              </div>

              <div class="account-row">
                <span class="account-label">Preferred Login</span>
                <span class="account-value"><?= e(ucfirst($member['preferred_login'])) ?></span>
              </div>

              <div class="account-row">
                <span class="account-label">Verification</span>
                <span class="account-value"><?= (int)$member['email_verified'] === 1 ? 'Verified' : 'Pending' ?></span>
              </div>
            </div>
          </div>

        </div>

      </div>
    </section>
  </div>
</main>

<?php include 'includes/footer.php'; ?>