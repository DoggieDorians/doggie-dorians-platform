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

$walks = [];

if ((int)$member['id'] > 0) {
    $walkStmt = $pdo->prepare("
        SELECT
            walks.*,
            dogs.dog_name
        FROM walks
        INNER JOIN dogs ON dogs.id = walks.dog_id
        WHERE walks.member_id = :member_id
        ORDER BY walks.walk_date DESC, walks.walk_time DESC
    ");
    $walkStmt->execute([':member_id' => $member['id']]);
    $walks = $walkStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $walks = [
        [
            'dog_name' => 'Bentley',
            'walk_date' => date('Y-m-d', strtotime('+1 day')),
            'walk_time' => '13:00',
            'duration_minutes' => 30,
            'status' => 'Walker Assigned',
            'notes' => 'Preview walk request',
            'walker_name' => 'John',
            'walker_phone' => '(631) 555-8181',
            'walker_notes' => 'Walker assigned and ready.'
        ],
        [
            'dog_name' => 'Luna',
            'walk_date' => date('Y-m-d', strtotime('+2 days')),
            'walk_time' => '18:30',
            'duration_minutes' => 45,
            'status' => 'Requested',
            'notes' => 'Please use side entrance.',
            'walker_name' => '',
            'walker_phone' => '',
            'walker_notes' => ''
        ]
    ];
}

function walkStatusClass(string $status): string {
    $status = strtolower(trim($status));

    return match ($status) {
        'scheduled' => 'status-scheduled',
        'completed' => 'status-completed',
        'in progress' => 'status-progress',
        'accepted' => 'status-accepted',
        'walker assigned' => 'status-assigned',
        'cancelled' => 'status-cancelled',
        default => 'status-requested',
    };
}

function walkTimelineStep(string $status): array {
    $map = [
        'Requested' => 1,
        'Accepted' => 2,
        'Walker Assigned' => 3,
        'In Progress' => 4,
        'Completed' => 5,
        'Cancelled' => 0
    ];

    $current = $map[$status] ?? 1;

    return [
        ['label' => 'Requested', 'done' => $current >= 1],
        ['label' => 'Accepted', 'done' => $current >= 2],
        ['label' => 'Assigned', 'done' => $current >= 3],
        ['label' => 'In Progress', 'done' => $current >= 4],
        ['label' => 'Completed', 'done' => $current >= 5],
    ];
}
?>
<?php include 'includes/header.php'; ?>
<?php include 'includes/nav.php'; ?>

<style>
.my-walks-page {
  background: #f4f1ea;
  min-height: calc(100vh - 120px);
  padding: 32px 20px 60px;
}
.my-walks-shell {
  max-width: 1320px;
  margin: 0 auto;
  display: grid;
  gap: 24px;
}
.my-walks-hero {
  background: linear-gradient(135deg, #111111 0%, #2b2414 100%);
  color: #ffffff;
  border-radius: 30px;
  padding: 34px;
  box-shadow: 0 14px 40px rgba(0,0,0,0.12);
}
.my-walks-hero h1 {
  margin: 0 0 10px;
  font-size: 38px;
}
.my-walks-hero p {
  margin: 0;
  max-width: 760px;
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
.walks-card {
  background: #ffffff;
  border-radius: 26px;
  padding: 28px;
  box-shadow: 0 12px 30px rgba(0,0,0,0.07);
}
.walks-card h2 {
  margin-top: 0;
  margin-bottom: 18px;
}
.walk-history-list {
  display: grid;
  gap: 18px;
}
.walk-history-item {
  background: #f7f4ee;
  border-radius: 22px;
  padding: 22px;
}
.walk-history-top {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  align-items: flex-start;
  flex-wrap: wrap;
}
.walk-history-title {
  margin: 0;
  font-size: 24px;
}
.walk-history-sub {
  margin: 8px 0 0;
  color: #666666;
}
.walk-history-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
  margin-top: 18px;
}
.walk-info-box {
  background: #ffffff;
  border-radius: 16px;
  padding: 14px 16px;
}
.walk-info-box strong {
  display: block;
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: #777777;
  margin-bottom: 6px;
}
.walk-notes {
  margin-top: 16px;
  background: #ffffff;
  border-radius: 16px;
  padding: 16px;
}
.timeline {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 10px;
  margin-top: 18px;
}
.timeline-step {
  background: #ffffff;
  border-radius: 16px;
  padding: 12px 10px;
  text-align: center;
  font-size: 13px;
  color: #888888;
}
.timeline-step.done {
  background: #d4af37;
  color: #111111;
  font-weight: 700;
}
.status-pill {
  display: inline-block;
  padding: 10px 14px;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 700;
}
.status-requested { background: #111111; color: #ffffff; }
.status-accepted { background: #ede9fe; color: #6d28d9; }
.status-assigned { background: #fef3c7; color: #92400e; }
.status-scheduled { background: #d4af37; color: #111111; }
.status-progress { background: #dbeafe; color: #1d4ed8; }
.status-completed { background: #dcfce7; color: #166534; }
.status-cancelled { background: #fee2e2; color: #991b1b; }
.empty-state {
  background: #f7f4ee;
  border-radius: 20px;
  padding: 22px;
  color: #666666;
}
@media (max-width: 900px) {
  .walk-history-grid,
  .timeline {
    grid-template-columns: 1fr 1fr;
  }
}
@media (max-width: 700px) {
  .my-walks-hero h1 {
    font-size: 30px;
  }
  .walk-history-grid,
  .timeline {
    grid-template-columns: 1fr;
  }
}
</style>

<main class="my-walks-page">
  <div class="my-walks-shell">

    <section class="my-walks-hero">
      <h1>My Walks</h1>
      <p>
        Review your walk requests, assigned walker details, service progress,
        and completed walk history in one place.
      </p>

      <div class="hero-actions">
        <a href="dashboard.php" class="hero-link">Back to Dashboard</a>
        <a href="book-walk.php" class="hero-link">Book a Walk</a>
      </div>
    </section>

    <section class="walks-card">
      <h2>Walk History</h2>

      <?php if (!$walks): ?>
        <div class="empty-state">
          No walk requests yet. Once you submit walks, they’ll appear here.
        </div>
      <?php else: ?>
        <div class="walk-history-list">
          <?php foreach ($walks as $walk): ?>
            <div class="walk-history-item">

              <div class="walk-history-top">
                <div>
                  <h3 class="walk-history-title"><?= e($walk['dog_name']) ?></h3>
                  <p class="walk-history-sub"><?= e((string)$walk['duration_minutes']) ?> minute walk</p>
                </div>

                <span class="status-pill <?= e(walkStatusClass($walk['status'])) ?>">
                  <?= e($walk['status']) ?>
                </span>
              </div>

              <div class="timeline">
                <?php foreach (walkTimelineStep($walk['status']) as $step): ?>
                  <div class="timeline-step <?= $step['done'] ? 'done' : '' ?>">
                    <?= e($step['label']) ?>
                  </div>
                <?php endforeach; ?>
              </div>

              <div class="walk-history-grid">
                <div class="walk-info-box">
                  <strong>Date</strong>
                  <?= e($walk['walk_date']) ?>
                </div>

                <div class="walk-info-box">
                  <strong>Time</strong>
                  <?= e($walk['walk_time']) ?>
                </div>

                <div class="walk-info-box">
                  <strong>Walker</strong>
                  <?= e($walk['walker_name'] ?: 'Not assigned yet') ?>
                </div>

                <div class="walk-info-box">
                  <strong>Walker Phone</strong>
                  <?= e($walk['walker_phone'] ?: 'Not added yet') ?>
                </div>
              </div>

              <div class="walk-notes">
                <strong>Client Notes</strong><br>
                <?= e($walk['notes'] ?: 'No notes added') ?>
              </div>

              <div class="walk-notes">
                <strong>Walker Notes</strong><br>
                <?= e($walk['walker_notes'] ?: 'No walker notes yet') ?>
              </div>

            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

  </div>
</main>

<?php include 'includes/footer.php'; ?>