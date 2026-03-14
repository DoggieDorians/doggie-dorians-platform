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

$errors = [];
$success = '';

$dogId = '';
$walkDate = '';
$walkTime = '';
$durationMinutes = '30';
$notes = '';

$dogs = [];
$walks = [];

if ((int)$member['id'] > 0) {
    $dogStmt = $pdo->prepare("
        SELECT *
        FROM dogs
        WHERE member_id = :member_id
        ORDER BY dog_name ASC
    ");
    $dogStmt->execute([':member_id' => $member['id']]);
    $dogs = $dogStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $dogs = [
        ['id' => 1, 'dog_name' => 'Bentley'],
        ['id' => 2, 'dog_name' => 'Luna']
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dogId = trim($_POST['dog_id'] ?? '');
    $walkDate = trim($_POST['walk_date'] ?? '');
    $walkTime = trim($_POST['walk_time'] ?? '');
    $durationMinutes = trim($_POST['duration_minutes'] ?? '30');
    $notes = trim($_POST['notes'] ?? '');

    if ($dogId === '') {
        $errors[] = 'Please choose a dog.';
    }

    if ($walkDate === '') {
        $errors[] = 'Please choose a walk date.';
    }

    if ($walkTime === '') {
        $errors[] = 'Please choose a walk time.';
    }

    if (!in_array($durationMinutes, ['15', '20', '30', '45', '60'], true)) {
        $errors[] = 'Please choose a valid walk duration.';
    }

    if ((int)$member['id'] > 0 && $dogId !== '') {
        $checkDog = $pdo->prepare("
            SELECT id
            FROM dogs
            WHERE id = :dog_id AND member_id = :member_id
            LIMIT 1
        ");
        $checkDog->execute([
            ':dog_id' => $dogId,
            ':member_id' => $member['id']
        ]);

        if (!$checkDog->fetch()) {
            $errors[] = 'That dog profile does not belong to this account.';
        }
    }

    if (!$errors && (int)$member['id'] > 0) {
        $insert = $pdo->prepare("
            INSERT INTO walks (
                member_id,
                dog_id,
                walk_date,
                walk_time,
                duration_minutes,
                notes,
                status
            ) VALUES (
                :member_id,
                :dog_id,
                :walk_date,
                :walk_time,
                :duration_minutes,
                :notes,
                'Requested'
            )
        ");

        $insert->execute([
            ':member_id' => $member['id'],
            ':dog_id' => $dogId,
            ':walk_date' => $walkDate,
            ':walk_time' => $walkTime,
            ':duration_minutes' => (int)$durationMinutes,
            ':notes' => $notes
        ]);

        $success = 'Walk request submitted successfully.';
        $dogId = '';
        $walkDate = '';
        $walkTime = '';
        $durationMinutes = '30';
        $notes = '';
    } elseif (!$errors && (int)$member['id'] === 0) {
        $success = 'Preview mode only. Log in with a real account to save walk requests.';
    }
}

if ((int)$member['id'] > 0) {
    $walkStmt = $pdo->prepare("
        SELECT
            walks.*,
            dogs.dog_name
        FROM walks
        INNER JOIN dogs ON dogs.id = walks.dog_id
        WHERE walks.member_id = :member_id
        ORDER BY walks.walk_date ASC, walks.walk_time ASC
        LIMIT 8
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
            'status' => 'Scheduled',
            'notes' => 'Preview walk request'
        ]
    ];
}
?>
<?php include 'includes/header.php'; ?>
<?php include 'includes/nav.php'; ?>

<style>
.book-walk-page {
  background: #f4f1ea;
  min-height: calc(100vh - 120px);
  padding: 32px 20px 60px;
}

.book-walk-shell {
  max-width: 1320px;
  margin: 0 auto;
  display: grid;
  gap: 24px;
}

.book-walk-hero {
  background: linear-gradient(135deg, #111111 0%, #2b2414 100%);
  color: #ffffff;
  border-radius: 30px;
  padding: 34px;
  box-shadow: 0 14px 40px rgba(0,0,0,0.12);
}

.book-walk-hero h1 {
  margin: 0 0 10px;
  font-size: 38px;
}

.book-walk-hero p {
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

.book-walk-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 24px;
}

.walk-card {
  background: #ffffff;
  border-radius: 26px;
  padding: 28px;
  box-shadow: 0 12px 30px rgba(0,0,0,0.07);
}

.walk-card h2 {
  margin-top: 0;
  margin-bottom: 18px;
}

.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}

.form-group {
  display: flex;
  flex-direction: column;
}

.form-group.full {
  grid-column: 1 / -1;
}

.form-group label {
  font-weight: 700;
  margin-bottom: 8px;
}

.form-group input,
.form-group select,
.form-group textarea {
  padding: 14px 16px;
  border: 1px solid #ddd;
  border-radius: 16px;
  font-size: 15px;
  font-family: Arial, sans-serif;
}

.form-group textarea {
  min-height: 120px;
  resize: vertical;
}

.save-button {
  display: inline-block;
  background: #d4af37;
  color: #111111;
  border: none;
  border-radius: 999px;
  padding: 14px 22px;
  font-weight: 700;
  cursor: pointer;
}

.message {
  border-radius: 14px;
  padding: 14px 16px;
  margin-bottom: 18px;
}

.message.error {
  background: #fff3f3;
  color: #9b1c1c;
}

.message.success {
  background: #f4fbf2;
  color: #256029;
}

.walk-request-list {
  display: grid;
  gap: 16px;
}

.walk-request-item {
  background: #f7f4ee;
  border-radius: 20px;
  padding: 18px;
}

.walk-request-item h3 {
  margin: 0 0 8px;
  font-size: 20px;
}

.walk-meta {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px 14px;
  margin-top: 14px;
}

.walk-meta-box {
  background: #ffffff;
  border-radius: 14px;
  padding: 12px 14px;
}

.walk-meta-box strong {
  display: block;
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: #777777;
  margin-bottom: 6px;
}

.status-pill {
  display: inline-block;
  margin-top: 10px;
  padding: 9px 12px;
  border-radius: 999px;
  background: #111111;
  color: #ffffff;
  font-size: 13px;
  font-weight: 700;
}

.empty-state {
  background: #f7f4ee;
  border-radius: 20px;
  padding: 22px;
  color: #666666;
}

@media (max-width: 980px) {
  .book-walk-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 700px) {
  .form-grid,
  .walk-meta {
    grid-template-columns: 1fr;
  }

  .book-walk-hero h1 {
    font-size: 30px;
  }
}
</style>

<main class="book-walk-page">
  <div class="book-walk-shell">

    <section class="book-walk-hero">
      <h1>Book a Walk</h1>
      <p>
        Request a walk for one of your dogs, choose the date and time, and add
        any special notes for your walker.
      </p>

      <div class="hero-actions">
        <a href="dashboard.php" class="hero-link">Back to Dashboard</a>
        <a href="my-dogs.php" class="hero-link">Manage Dogs</a>
      </div>
    </section>

    <section class="book-walk-grid">

      <div class="walk-card">
        <h2>New Walk Request</h2>

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

        <?php if (!$dogs): ?>
          <div class="empty-state">
            You need to add a dog profile before booking a walk.
            <br><br>
            <a href="my-dogs.php">Go to My Dogs</a>
          </div>
        <?php else: ?>
          <form method="post" action="">
            <div class="form-grid">

              <div class="form-group">
                <label for="dog_id">Choose Dog</label>
                <select id="dog_id" name="dog_id" required>
                  <option value="">Select a dog</option>
                  <?php foreach ($dogs as $dog): ?>
                    <option value="<?= e((string)$dog['id']) ?>" <?= $dogId === (string)$dog['id'] ? 'selected' : '' ?>>
                      <?= e($dog['dog_name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="form-group">
                <label for="duration_minutes">Walk Duration</label>
                <select id="duration_minutes" name="duration_minutes" required>
                  <option value="15" <?= $durationMinutes === '15' ? 'selected' : '' ?>>15 Minutes</option>
                  <option value="20" <?= $durationMinutes === '20' ? 'selected' : '' ?>>20 Minutes</option>
                  <option value="30" <?= $durationMinutes === '30' ? 'selected' : '' ?>>30 Minutes</option>
                  <option value="45" <?= $durationMinutes === '45' ? 'selected' : '' ?>>45 Minutes</option>
                  <option value="60" <?= $durationMinutes === '60' ? 'selected' : '' ?>>60 Minutes</option>
                </select>
              </div>

              <div class="form-group">
                <label for="walk_date">Walk Date</label>
                <input id="walk_date" name="walk_date" type="date" value="<?= e($walkDate) ?>" required>
              </div>

              <div class="form-group">
                <label for="walk_time">Preferred Time</label>
                <input id="walk_time" name="walk_time" type="time" value="<?= e($walkTime) ?>" required>
              </div>

              <div class="form-group full">
                <label for="notes">Special Notes</label>
                <textarea id="notes" name="notes" placeholder="Potty routine, leash notes, energy level, access instructions..."><?= e($notes) ?></textarea>
              </div>

              <div class="form-group full">
                <button class="save-button" type="submit">Submit Walk Request</button>
              </div>

            </div>
          </form>
        <?php endif; ?>
      </div>

      <div class="walk-card">
        <h2>Recent Walk Requests</h2>

        <?php if (!$walks): ?>
          <div class="empty-state">
            No walk requests yet. Your submitted walks will appear here.
          </div>
        <?php else: ?>
          <div class="walk-request-list">
            <?php foreach ($walks as $walk): ?>
              <div class="walk-request-item">
                <h3><?= e($walk['dog_name']) ?></h3>

                <div class="walk-meta">
                  <div class="walk-meta-box">
                    <strong>Date</strong>
                    <?= e($walk['walk_date']) ?>
                  </div>

                  <div class="walk-meta-box">
                    <strong>Time</strong>
                    <?= e($walk['walk_time']) ?>
                  </div>

                  <div class="walk-meta-box">
                    <strong>Duration</strong>
                    <?= e((string)$walk['duration_minutes']) ?> Minutes
                  </div>

                  <div class="walk-meta-box">
                    <strong>Notes</strong>
                    <?= e($walk['notes'] ?: 'No notes added') ?>
                  </div>
                </div>

                <span class="status-pill"><?= e($walk['status']) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

    </section>

  </div>
</main>

<?php include 'includes/footer.php'; ?>