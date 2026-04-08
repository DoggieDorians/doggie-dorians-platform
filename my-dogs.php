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

$dogName = '';
$breed = '';
$age = '';
$weight = '';
$temperament = '';
$feedingInstructions = '';
$medicationNotes = '';
$emergencyContact = '';
$vetName = '';
$vetPhone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dogName = trim($_POST['dog_name'] ?? '');
    $breed = trim($_POST['breed'] ?? '');
    $age = trim($_POST['age'] ?? '');
    $weight = trim($_POST['weight'] ?? '');
    $temperament = trim($_POST['temperament'] ?? '');
    $feedingInstructions = trim($_POST['feeding_instructions'] ?? '');
    $medicationNotes = trim($_POST['medication_notes'] ?? '');
    $emergencyContact = trim($_POST['emergency_contact'] ?? '');
    $vetName = trim($_POST['vet_name'] ?? '');
    $vetPhone = trim($_POST['vet_phone'] ?? '');

    if ($dogName === '') {
        $errors[] = 'Dog name is required.';
    }

    if (!$errors && (int)$member['id'] > 0) {
        $insert = $pdo->prepare("
            INSERT INTO dogs (
                member_id,
                dog_name,
                breed,
                age,
                weight,
                temperament,
                feeding_instructions,
                medication_notes,
                emergency_contact,
                vet_name,
                vet_phone
            ) VALUES (
                :member_id,
                :dog_name,
                :breed,
                :age,
                :weight,
                :temperament,
                :feeding_instructions,
                :medication_notes,
                :emergency_contact,
                :vet_name,
                :vet_phone
            )
        ");

        $insert->execute([
            ':member_id' => $member['id'],
            ':dog_name' => $dogName,
            ':breed' => $breed,
            ':age' => $age,
            ':weight' => $weight,
            ':temperament' => $temperament,
            ':feeding_instructions' => $feedingInstructions,
            ':medication_notes' => $medicationNotes,
            ':emergency_contact' => $emergencyContact,
            ':vet_name' => $vetName,
            ':vet_phone' => $vetPhone
        ]);

        $success = 'Dog profile added successfully.';

        $dogName = '';
        $breed = '';
        $age = '';
        $weight = '';
        $temperament = '';
        $feedingInstructions = '';
        $medicationNotes = '';
        $emergencyContact = '';
        $vetName = '';
        $vetPhone = '';
    } elseif (!$errors && (int)$member['id'] === 0) {
        $success = 'Preview mode only. Log in with a real account to save dog profiles.';
    }
}

$dogs = [];

if ((int)$member['id'] > 0) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM dogs
        WHERE member_id = :member_id
        ORDER BY created_at DESC
    ");
    $stmt->execute([':member_id' => $member['id']]);
    $dogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<?php include 'includes/header.php'; ?>
<?php include 'includes/nav.php'; ?>

<style>
.my-dogs-page {
  background: #f4f1ea;
  min-height: calc(100vh - 120px);
  padding: 32px 20px 60px;
}

.my-dogs-shell {
  max-width: 1300px;
  margin: 0 auto;
  display: grid;
  gap: 24px;
}

.my-dogs-hero {
  background: linear-gradient(135deg, #111111 0%, #2b2414 100%);
  color: #ffffff;
  border-radius: 30px;
  padding: 34px;
  box-shadow: 0 14px 40px rgba(0,0,0,0.12);
}

.my-dogs-hero h1 {
  margin: 0 0 10px;
  font-size: 38px;
}

.my-dogs-hero p {
  margin: 0;
  max-width: 760px;
  color: rgba(255,255,255,0.8);
}

.my-dogs-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 24px;
}

.dogs-card {
  background: #ffffff;
  border-radius: 26px;
  padding: 28px;
  box-shadow: 0 12px 30px rgba(0,0,0,0.07);
}

.dogs-card h2 {
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
.form-group textarea {
  padding: 14px 16px;
  border: 1px solid #ddd;
  border-radius: 16px;
  font-size: 15px;
  font-family: Arial, sans-serif;
}

.form-group textarea {
  min-height: 110px;
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

.dog-list {
  display: grid;
  gap: 18px;
}

.dog-card {
  background: #f7f4ee;
  border-radius: 22px;
  padding: 22px;
}

.dog-card h3 {
  margin-top: 0;
  margin-bottom: 10px;
  font-size: 24px;
}

.dog-meta {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px 18px;
  margin-bottom: 14px;
}

.dog-meta div {
  background: #ffffff;
  border-radius: 14px;
  padding: 12px 14px;
}

.dog-meta strong {
  display: block;
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: #777777;
  margin-bottom: 6px;
}

.dog-notes {
  display: grid;
  gap: 12px;
}

.dog-note-box {
  background: #ffffff;
  border-radius: 16px;
  padding: 14px 16px;
}

.empty-state {
  background: #f7f4ee;
  border-radius: 22px;
  padding: 24px;
  color: #666666;
}

.top-actions {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  margin-top: 18px;
}

.top-link {
  display: inline-block;
  background: rgba(255,255,255,0.08);
  color: #ffffff;
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 999px;
  padding: 12px 16px;
  font-weight: 700;
}

@media (max-width: 980px) {
  .my-dogs-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 700px) {
  .form-grid,
  .dog-meta {
    grid-template-columns: 1fr;
  }

  .my-dogs-hero h1 {
    font-size: 30px;
  }
}
</style>

<main class="my-dogs-page">
  <div class="my-dogs-shell">

    <section class="my-dogs-hero">
      <h1>My Dogs</h1>
      <p>
        Add and manage your dog profiles so your future walks, bookings, care notes,
        and live tracking features all connect to the right pet.
      </p>

      <div class="top-actions">
        <a href="dashboard.php" class="top-link">Back to Dashboard</a>
      </div>
    </section>

    <section class="my-dogs-grid">
      <div class="dogs-card">
        <h2>Add a Dog Profile</h2>

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

        <form method="post" action="">
          <div class="form-grid">

            <div class="form-group">
              <label for="dog_name">Dog Name</label>
              <input id="dog_name" name="dog_name" type="text" value="<?= e($dogName) ?>" required>
            </div>

            <div class="form-group">
              <label for="breed">Breed</label>
              <input id="breed" name="breed" type="text" value="<?= e($breed) ?>">
            </div>

            <div class="form-group">
              <label for="age">Age</label>
              <input id="age" name="age" type="text" value="<?= e($age) ?>" placeholder="e.g. 3 years">
            </div>

            <div class="form-group">
              <label for="weight">Weight</label>
              <input id="weight" name="weight" type="text" value="<?= e($weight) ?>" placeholder="e.g. 24 lbs">
            </div>

            <div class="form-group full">
              <label for="temperament">Temperament</label>
              <input id="temperament" name="temperament" type="text" value="<?= e($temperament) ?>" placeholder="Friendly, energetic, shy with strangers...">
            </div>

            <div class="form-group full">
              <label for="feeding_instructions">Feeding Instructions</label>
              <textarea id="feeding_instructions" name="feeding_instructions"><?= e($feedingInstructions) ?></textarea>
            </div>

            <div class="form-group full">
              <label for="medication_notes">Medication Notes</label>
              <textarea id="medication_notes" name="medication_notes"><?= e($medicationNotes) ?></textarea>
            </div>

            <div class="form-group">
              <label for="emergency_contact">Emergency Contact</label>
              <input id="emergency_contact" name="emergency_contact" type="text" value="<?= e($emergencyContact) ?>">
            </div>

            <div class="form-group">
              <label for="vet_name">Vet Name</label>
              <input id="vet_name" name="vet_name" type="text" value="<?= e($vetName) ?>">
            </div>

            <div class="form-group full">
              <label for="vet_phone">Vet Phone</label>
              <input id="vet_phone" name="vet_phone" type="text" value="<?= e($vetPhone) ?>">
            </div>

            <div class="form-group full">
              <button class="save-button" type="submit">Save Dog Profile</button>
            </div>

          </div>
        </form>
      </div>

      <div class="dogs-card">
        <h2>Saved Dogs</h2>

        <?php if (!$dogs): ?>
          <div class="empty-state">
            No dog profiles yet. Add your first dog to start building your member experience.
          </div>
        <?php else: ?>
          <div class="dog-list">
            <?php foreach ($dogs as $dog): ?>
              <div class="dog-card">
                <h3><?= e($dog['dog_name']) ?></h3>

                <div class="dog-meta">
                  <div>
                    <strong>Breed</strong>
                    <?= e($dog['breed'] ?: 'Not added') ?>
                  </div>
                  <div>
                    <strong>Age</strong>
                    <?= e($dog['age'] ?: 'Not added') ?>
                  </div>
                  <div>
                    <strong>Weight</strong>
                    <?= e($dog['weight'] ?: 'Not added') ?>
                  </div>
                  <div>
                    <strong>Temperament</strong>
                    <?= e($dog['temperament'] ?: 'Not added') ?>
                  </div>
                </div>

                <div class="dog-notes">
                  <div class="dog-note-box">
                    <strong>Feeding Instructions</strong><br>
                    <?= nl2br(e($dog['feeding_instructions'] ?: 'None added')) ?>
                  </div>

                  <div class="dog-note-box">
                    <strong>Medication Notes</strong><br>
                    <?= nl2br(e($dog['medication_notes'] ?: 'None added')) ?>
                  </div>

                  <div class="dog-note-box">
                    <strong>Emergency Contact</strong><br>
                    <?= e($dog['emergency_contact'] ?: 'None added') ?>
                  </div>

                  <div class="dog-note-box">
                    <strong>Vet</strong><br>
                    <?= e($dog['vet_name'] ?: 'Not added') ?>
                    <?= $dog['vet_phone'] ? ' — ' . e($dog['vet_phone']) : '' ?>
                  </div>
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