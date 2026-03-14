<?php
require_once __DIR__ . '/includes/member_config.php';

$success = '';
$errors = [];

$allowedStatuses = [
    'Requested',
    'Accepted',
    'Walker Assigned',
    'In Progress',
    'Completed',
    'Cancelled'
];

$walkerStmt = $pdo->query("
    SELECT id, full_name, email, phone
    FROM walkers
    WHERE is_active = 1
    ORDER BY full_name ASC
");
$walkers = $walkerStmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $walkId = (int)($_POST['walk_id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    $walkerId = (int)($_POST['walker_id'] ?? 0);
    $walkerNotes = trim($_POST['walker_notes'] ?? '');

    if ($walkId <= 0) {
        $errors[] = 'Invalid walk selected.';
    }

    if (!in_array($status, $allowedStatuses, true)) {
        $errors[] = 'Invalid walk status.';
    }

    $selectedWalker = null;

    if ($walkerId > 0) {
        foreach ($walkers as $walker) {
            if ((int)$walker['id'] === $walkerId) {
                $selectedWalker = $walker;
                break;
            }
        }

        if (!$selectedWalker) {
            $errors[] = 'Selected walker was not found.';
        }
    }

    if (!$errors) {
        $walkerName = null;
        $walkerPhone = null;
        $finalStatus = $status;

        if ($selectedWalker) {
            $walkerName = $selectedWalker['full_name'];
            $walkerPhone = $selectedWalker['phone'];

            if ($finalStatus === 'Requested') {
                $finalStatus = 'Walker Assigned';
            }
        }

        $update = $pdo->prepare("
            UPDATE walks
            SET status = :status,
                walker_id = :walker_id,
                walker_name = :walker_name,
                walker_phone = :walker_phone,
                walker_notes = :walker_notes
            WHERE id = :id
        ");

        $update->execute([
            ':status' => $finalStatus,
            ':walker_id' => $selectedWalker ? $selectedWalker['id'] : null,
            ':walker_name' => $walkerName,
            ':walker_phone' => $walkerPhone,
            ':walker_notes' => $walkerNotes !== '' ? $walkerNotes : null,
            ':id' => $walkId
        ]);

        $checkSession = $pdo->prepare("
            SELECT id
            FROM walk_sessions
            WHERE walk_id = :walk_id
            LIMIT 1
        ");
        $checkSession->execute([':walk_id' => $walkId]);
        $existingSession = $checkSession->fetch(PDO::FETCH_ASSOC);

        if ($selectedWalker) {
            $sessionStatus = match ($finalStatus) {
                'Accepted' => 'Accepted',
                'In Progress' => 'Walk Started',
                'Completed' => 'Walk Completed',
                default => 'Walker Assigned'
            };

            $lastUpdate = 'Walker assigned: ' . $walkerName;

            if ($existingSession) {
                $sessionUpdate = $pdo->prepare("
                    UPDATE walk_sessions
                    SET session_status = :session_status,
                        last_update = :last_update,
                        current_location = COALESCE(current_location, 'Walker assigned'),
                        route_note = COALESCE(route_note, 'Waiting for live GPS updates')
                    WHERE walk_id = :walk_id
                ");
                $sessionUpdate->execute([
                    ':session_status' => $sessionStatus,
                    ':last_update' => $lastUpdate,
                    ':walk_id' => $walkId
                ]);
            } else {
                $sessionInsert = $pdo->prepare("
                    INSERT INTO walk_sessions (
                        walk_id,
                        session_status,
                        current_location,
                        last_update,
                        bathroom_update,
                        photo_note,
                        route_note
                    ) VALUES (
                        :walk_id,
                        :session_status,
                        'Walker assigned',
                        :last_update,
                        'No bathroom update yet',
                        'No photo update yet',
                        'Waiting for live GPS updates'
                    )
                ");
                $sessionInsert->execute([
                    ':walk_id' => $walkId,
                    ':session_status' => $sessionStatus,
                    ':last_update' => $lastUpdate
                ]);
            }
        }

        $success = 'Walk assignment updated successfully.';
    }
}

$stmt = $pdo->query("
    SELECT
        walks.*,
        dogs.dog_name,
        members.email AS member_email,
        members.username AS member_username
    FROM walks
    INNER JOIN dogs ON dogs.id = walks.dog_id
    INNER JOIN members ON members.id = walks.member_id
    ORDER BY walks.walk_date ASC, walks.walk_time ASC
");
$walks = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
.empty-state {
  background: #ffffff;
  border-radius: 24px;
  padding: 28px;
  box-shadow: 0 12px 30px rgba(0,0,0,0.07);
  color: #666666;
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
.admin-top {
  display: flex;
  justify-content: space-between;
  gap: 18px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}
.admin-top h2 {
  margin: 0;
  font-size: 24px;
}
.admin-sub {
  color: #666666;
  margin-top: 8px;
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
.status-pill {
  display: inline-block;
  padding: 10px 14px;
  border-radius: 999px;
  background: #111111;
  color: #ffffff;
  font-size: 13px;
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
      <h1>Walker Assignment System</h1>
      <p>
        Assign each walk to a specific walker account and control service status updates.
      </p>
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
        No walk requests exist yet.<br><br>
        Go to <strong>book-walk.php</strong> and create a walk first, then come back here to assign it.
      </div>
    <?php else: ?>
      <section class="admin-list">
        <?php foreach ($walks as $walk): ?>
          <div class="admin-card">
            <div class="admin-top">
              <div>
                <h2><?= e($walk['dog_name']) ?></h2>
                <div class="admin-sub">
                  Member: <?= e($walk['member_username'] ?: $walk['member_email']) ?>
                </div>
              </div>
              <div>
                <span class="status-pill"><?= e($walk['status']) ?></span>
              </div>
            </div>

            <div class="admin-grid">
              <div class="admin-box">
                <strong>Date</strong>
                <?= e($walk['walk_date']) ?>
              </div>
              <div class="admin-box">
                <strong>Time</strong>
                <?= e($walk['walk_time']) ?>
              </div>
              <div class="admin-box">
                <strong>Duration</strong>
                <?= e((string)$walk['duration_minutes']) ?> Minutes
              </div>
              <div class="admin-box">
                <strong>Assigned Walker</strong>
                <?= e($walk['walker_name'] ?: 'Unassigned') ?>
              </div>
            </div>

            <form method="post" class="assign-form">
              <input type="hidden" name="walk_id" value="<?= e((string)$walk['id']) ?>">

              <div>
                <label>Status</label>
                <select name="status" required>
                  <?php foreach ($allowedStatuses as $status): ?>
                    <option value="<?= e($status) ?>" <?= $walk['status'] === $status ? 'selected' : '' ?>>
                      <?= e($status) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div>
                <label>Assign Walker</label>
                <select name="walker_id">
                  <option value="0">Unassigned</option>
                  <?php foreach ($walkers as $walker): ?>
                    <option value="<?= e((string)$walker['id']) ?>" <?= (int)($walk['walker_id'] ?? 0) === (int)$walker['id'] ? 'selected' : '' ?>>
                      <?= e($walker['full_name']) ?> — <?= e($walker['email']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="full">
                <label>Walker Notes</label>
                <textarea name="walker_notes" placeholder="Assigned to John for midday walk."><?= e($walk['walker_notes'] ?? '') ?></textarea>
              </div>

              <div class="full">
                <button type="submit" class="assign-button">Update Walk Assignment</button>
              </div>
            </form>
          </div>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>

  </div>
</main>

<?php include 'includes/footer.php'; ?>