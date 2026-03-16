<?php
session_start();
require_once __DIR__ . '/data/config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Member';

$success = '';
$error = '';
$pets = [];

try {
    $petsStmt = $pdo->prepare("
        SELECT id, pet_name, breed, status
        FROM pets
        WHERE user_id = ? AND status = 'active'
        ORDER BY pet_name ASC
    ");
    $petsStmt->execute([$userId]);
    $pets = $petsStmt->fetchAll();
} catch (PDOException $e) {
    die('Could not load pets: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $petId = trim($_POST['pet_id'] ?? '');
    $serviceType = trim($_POST['service_type'] ?? '');
    $serviceDate = trim($_POST['service_date'] ?? '');
    $serviceTime = trim($_POST['service_time'] ?? '');
    $durationMinutes = trim($_POST['duration_minutes'] ?? '');
    $dogSize = trim($_POST['dog_size'] ?? '');
    $accessNotes = trim($_POST['access_notes'] ?? '');
    $clientNotes = trim($_POST['client_notes'] ?? '');

    $allowedServices = ['walk', 'daycare', 'boarding', 'drop-in visit', 'pet taxi'];
    $allowedDurations = ['15', '20', '30', '45', '60'];
    $allowedSizes = ['small', 'medium', 'large'];

    if ($petId === '' || $serviceType === '' || $serviceDate === '' || $serviceTime === '') {
        $error = 'Please complete all required fields.';
    } elseif (!in_array($serviceType, $allowedServices, true)) {
        $error = 'Please choose a valid service.';
    } elseif ($serviceType === 'walk' && !in_array($durationMinutes, $allowedDurations, true)) {
        $error = 'Please choose a valid walk duration.';
    } elseif ($serviceType === 'boarding' && !in_array($dogSize, $allowedSizes, true)) {
        $error = 'Please choose a dog size for boarding.';
    } else {
        try {
            $petCheckStmt = $pdo->prepare("
                SELECT id, pet_name
                FROM pets
                WHERE id = ? AND user_id = ? AND status = 'active'
                LIMIT 1
            ");
            $petCheckStmt->execute([$petId, $userId]);
            $pet = $petCheckStmt->fetch();

            if (!$pet) {
                $error = 'That pet could not be found on your account.';
            } else {
                $price = 0;

                if ($serviceType === 'walk') {
                    $walkPricing = [
                        '15' => 18.00,
                        '20' => 22.00,
                        '30' => 27.00,
                        '45' => 36.00,
                        '60' => 45.00
                    ];
                    $price = $walkPricing[$durationMinutes] ?? 0;
                } elseif ($serviceType === 'boarding') {
                    $boardingPricing = [
                        'small' => 80.00,
                        'medium' => 100.00,
                        'large' => 120.00
                    ];
                    $price = $boardingPricing[$dogSize] ?? 0;
                    $durationMinutes = null;
                } elseif ($serviceType === 'daycare') {
                    $price = 45.00;
                    $durationMinutes = null;
                } elseif ($serviceType === 'drop-in visit') {
                    $price = 30.00;
                    $durationMinutes = null;
                } elseif ($serviceType === 'pet taxi') {
                    $price = 35.00;
                    $durationMinutes = null;
                }

                $extraNotes = $clientNotes;
                if ($serviceType === 'boarding' && $dogSize !== '') {
                    $sizeLabel = ucfirst($dogSize) . ' dog';
                    $extraNotes = "Boarding size: {$sizeLabel}" . ($clientNotes !== '' ? "\n\n" . $clientNotes : '');
                }

                $insertStmt = $pdo->prepare("
                    INSERT INTO bookings (
                        user_id,
                        pet_id,
                        assigned_walker_id,
                        service_type,
                        service_date,
                        service_time,
                        duration_minutes,
                        status,
                        access_notes,
                        client_notes,
                        price,
                        is_instant_booking
                    ) VALUES (?, ?, NULL, ?, ?, ?, ?, 'pending', ?, ?, ?, 0)
                ");

                $insertStmt->execute([
                    $userId,
                    $petId,
                    $serviceType,
                    $serviceDate,
                    $serviceTime,
                    $durationMinutes !== null && $durationMinutes !== '' ? (int)$durationMinutes : null,
                    $accessNotes !== '' ? $accessNotes : null,
                    $extraNotes !== '' ? $extraNotes : null,
                    $price
                ]);

                $success = 'Your booking request has been submitted successfully.';
                $_POST = [];
            }
        } catch (PDOException $e) {
            $error = 'Could not create booking: ' . $e->getMessage();
        }
    }
}

function oldValue(string $key): string
{
    return htmlspecialchars($_POST[$key] ?? '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book a Luxury Service | Doggie Dorian's</title>
    <style>
        * { box-sizing: border-box; }

        :root {
            --bg: #f4f1eb;
            --panel: rgba(255, 255, 255, 0.78);
            --panel-solid: #ffffff;
            --text: #171717;
            --muted: #6b655d;
            --line: rgba(27, 27, 27, 0.08);
            --gold: #b69152;
            --gold-deep: #8e6b34;
            --shadow: 0 24px 60px rgba(34, 28, 18, 0.12);
            --radius-xl: 28px;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(182, 145, 82, 0.12), transparent 32%),
                radial-gradient(circle at top right, rgba(0, 0, 0, 0.05), transparent 28%),
                linear-gradient(180deg, #f8f5ef 0%, #f1ede6 100%);
        }

        .page {
            min-height: 100vh;
            padding: 30px 18px 56px;
        }

        .wrap {
            max-width: 1240px;
            margin: 0 auto;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .brand-block {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .brand-kicker {
            font-size: 12px;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--gold-deep);
            font-weight: 700;
        }

        .brand {
            font-size: 30px;
            font-weight: 700;
            letter-spacing: -0.6px;
        }

        .nav-links {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .nav-links a {
            text-decoration: none;
            color: var(--text);
            background: rgba(255, 255, 255, 0.72);
            border: 1px solid rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(14px);
            padding: 12px 16px;
            border-radius: 999px;
            box-shadow: 0 10px 30px rgba(31, 25, 17, 0.08);
            font-weight: 700;
        }

        .hero {
            position: relative;
            overflow: hidden;
            border-radius: 34px;
            padding: 40px;
            margin-bottom: 24px;
            background:
                linear-gradient(135deg, rgba(15, 15, 15, 0.97) 0%, rgba(35, 31, 26, 0.95) 52%, rgba(92, 70, 38, 0.93) 100%);
            color: #fff;
            box-shadow: var(--shadow);
        }

        .hero-grid {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: 1.25fr 0.75fr;
            gap: 24px;
            align-items: end;
        }

        .eyebrow {
            display: inline-block;
            margin-bottom: 14px;
            padding: 8px 12px;
            border: 1px solid rgba(255,255,255,0.14);
            border-radius: 999px;
            background: rgba(255,255,255,0.08);
            color: rgba(255,255,255,0.9);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1.8px;
            text-transform: uppercase;
        }

        .hero h1 {
            margin: 0 0 12px;
            font-size: 46px;
            line-height: 1.02;
            letter-spacing: -1px;
            max-width: 700px;
        }

        .hero p {
            margin: 0;
            max-width: 700px;
            color: rgba(255, 255, 255, 0.84);
            line-height: 1.7;
            font-size: 16px;
        }

        .hero-panel {
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 24px;
            padding: 22px;
            backdrop-filter: blur(10px);
        }

        .hero-panel .mini-label {
            margin: 0 0 8px;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1.6px;
            color: rgba(255,255,255,0.74);
            font-weight: 700;
        }

        .hero-panel .mini-value {
            margin: 0 0 12px;
            font-size: 26px;
            font-weight: 700;
        }

        .hero-panel .mini-copy {
            margin: 0;
            color: rgba(255,255,255,0.8);
            line-height: 1.6;
            font-size: 14px;
        }

        .layout {
            display: grid;
            grid-template-columns: 1.12fr 0.88fr;
            gap: 24px;
        }

        .card {
            background: var(--panel);
            border: 1px solid rgba(255,255,255,0.8);
            backdrop-filter: blur(16px);
            border-radius: var(--radius-xl);
            padding: 30px;
            box-shadow: var(--shadow);
        }

        .card-dark {
            background:
                linear-gradient(180deg, rgba(27, 27, 27, 0.96) 0%, rgba(43, 38, 31, 0.95) 100%);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.08);
        }

        .card h2,
        .card-dark h2 {
            margin: 0 0 8px;
            font-size: 28px;
        }

        .card-subtext,
        .card-dark .card-subtext {
            margin: 0 0 24px;
            line-height: 1.7;
            font-size: 15px;
        }

        .card-subtext { color: var(--muted); }
        .card-dark .card-subtext { color: rgba(255,255,255,0.78); }

        .message {
            margin-bottom: 20px;
            padding: 15px 16px;
            border-radius: 16px;
            font-size: 14px;
            line-height: 1.6;
            font-weight: 700;
        }

        .error {
            background: rgba(180, 36, 36, 0.08);
            color: #8c1f1f;
            border: 1px solid rgba(180, 36, 36, 0.12);
        }

        .success {
            background: rgba(29, 126, 58, 0.08);
            color: #165f2d;
            border: 1px solid rgba(29, 126, 58, 0.12);
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .full { grid-column: 1 / -1; }

        .field {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        label {
            font-weight: 700;
            font-size: 14px;
        }

        .field-note {
            font-size: 12px;
            color: var(--muted);
            margin-top: -2px;
        }

        input,
        select,
        textarea {
            width: 100%;
            border: 1px solid var(--line);
            background: rgba(255,255,255,0.86);
            padding: 14px 16px;
            border-radius: 16px;
            font-size: 15px;
            color: var(--text);
            font-family: Arial, sans-serif;
        }

        textarea {
            min-height: 130px;
            resize: vertical;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: rgba(182, 145, 82, 0.8);
            box-shadow: 0 0 0 4px rgba(182, 145, 82, 0.14);
        }

        .service-accent {
            border-radius: 18px;
            padding: 16px 18px;
            background: linear-gradient(180deg, rgba(182,145,82,0.1), rgba(182,145,82,0.04));
            border: 1px solid rgba(182,145,82,0.16);
            margin-bottom: 20px;
        }

        .service-accent-title {
            margin: 0 0 6px;
            font-size: 15px;
            font-weight: 700;
            color: var(--gold-deep);
        }

        .service-accent-copy {
            margin: 0;
            color: var(--muted);
            line-height: 1.6;
            font-size: 14px;
        }

        .actions {
            display: flex;
            gap: 12px;
            margin-top: 28px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 52px;
            padding: 0 20px;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 700;
            border: none;
            cursor: pointer;
            font-size: 15px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--gold) 0%, #d8bc7b 100%);
            color: #171717;
            box-shadow: 0 14px 30px rgba(182, 145, 82, 0.24);
        }

        .btn-secondary {
            background: rgba(17,17,17,0.06);
            color: var(--text);
            border: 1px solid rgba(17,17,17,0.08);
        }

        .info-stack {
            display: grid;
            gap: 16px;
        }

        .price-box {
            border-radius: 22px;
            padding: 20px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.1);
        }

        .price-box h3 {
            margin: 0 0 10px;
            font-size: 18px;
        }

        .price-box p {
            margin: 0;
            line-height: 1.7;
            color: rgba(255,255,255,0.82);
            font-size: 14px;
        }

        .notice {
            border-radius: 20px;
            padding: 22px;
            background: rgba(17, 17, 17, 0.04);
            border: 1px dashed rgba(17, 17, 17, 0.14);
            color: var(--muted);
            line-height: 1.7;
        }

        .notice a {
            color: var(--gold-deep);
            font-weight: 700;
            text-decoration: none;
        }

        .pill-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
        }

        .pill {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.08);
            color: rgba(255,255,255,0.84);
            font-size: 13px;
            font-weight: 700;
        }

        @media (max-width: 1024px) {
            .hero-grid,
            .layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 760px) {
            .page { padding: 18px 14px 40px; }
            .hero { padding: 26px; border-radius: 26px; }
            .hero h1 { font-size: 34px; }
            .card, .card-dark { padding: 22px; }
            .grid { grid-template-columns: 1fr; }
            .brand { font-size: 24px; }
        }
    </style>
    <script>
        function toggleFields() {
            const serviceType = document.getElementById('service_type').value;
            const durationWrap = document.getElementById('duration-wrap');
            const boardingWrap = document.getElementById('boarding-wrap');

            durationWrap.style.display = serviceType === 'walk' ? 'block' : 'none';
            boardingWrap.style.display = serviceType === 'boarding' ? 'block' : 'none';

            if (serviceType !== 'walk') {
                document.getElementById('duration_minutes').value = '';
            }

            if (serviceType !== 'boarding') {
                document.getElementById('dog_size').value = '';
            }
        }

        window.addEventListener('DOMContentLoaded', toggleFields);
    </script>
</head>
<body>
    <div class="page">
        <div class="wrap">
            <div class="topbar">
                <div class="brand-block">
                    <div class="brand-kicker">Private Pet Concierge</div>
                    <div class="brand">Doggie Dorian’s</div>
                </div>

                <div class="nav-links">
                    <a href="dashboard.php">Dashboard</a>
                    <a href="add-pet.php">Add Pet</a>
                    <a href="profile.php">Profile</a>
                    <a href="logout.php">Logout</a>
                </div>
            </div>

            <section class="hero">
                <div class="hero-grid">
                    <div>
                        <span class="eyebrow">Luxury Booking Experience</span>
                        <h1>Book elevated care with confidence, detail, and discretion.</h1>
                        <p>
                            Welcome, <?php echo htmlspecialchars($fullName); ?>. Submit your request for a polished,
                            concierge-level service experience designed around convenience, trust, and premium care.
                        </p>

                        <div class="pill-row">
                            <span class="pill">Concierge presentation</span>
                            <span class="pill">Premium service request flow</span>
                            <span class="pill">Luxury pricing display</span>
                        </div>
                    </div>

                    <div class="hero-panel">
                        <p class="mini-label">Signature Boarding Pricing</p>
                        <p class="mini-value">Small $80 • Medium $100 • Large $120</p>
                        <p class="mini-copy">
                            Boarding reflects size-based pricing for a more tailored and professional service menu.
                        </p>
                    </div>
                </div>
            </section>

            <section class="layout">
                <div class="card">
                    <h2>Booking Request</h2>
                    <p class="card-subtext">
                        Complete the details below and we’ll save the request to your account with pending status for review.
                    </p>

                    <div class="service-accent">
                        <p class="service-accent-title">Concierge-Level Booking</p>
                        <p class="service-accent-copy">
                            Every request is reviewed with care, attention to detail, and a service-first approach designed to feel seamless and elevated.
                        </p>
                    </div>

                    <?php if ($error !== ''): ?>
                        <div class="message error"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>

                    <?php if ($success !== ''): ?>
                        <div class="message success"><?php echo htmlspecialchars($success); ?></div>
                    <?php endif; ?>

                    <?php if (count($pets) === 0): ?>
                        <div class="notice">
                            You need to add at least one dog before booking a service.<br><br>
                            <a href="add-pet.php">Add your first pet profile</a>
                        </div>
                    <?php else: ?>
                        <form method="POST" action="">
                            <div class="grid">
                                <div class="field full">
                                    <label for="pet_id">Select Dog</label>
                                    <select id="pet_id" name="pet_id" required>
                                        <option value="">Choose your dog</option>
                                        <?php foreach ($pets as $pet): ?>
                                            <option value="<?php echo htmlspecialchars((string)$pet['id']); ?>" <?php echo (($_POST['pet_id'] ?? '') == $pet['id']) ? 'selected' : ''; ?>>
                                                <?php
                                                $petLabel = $pet['pet_name'];
                                                if (!empty($pet['breed'])) {
                                                    $petLabel .= ' • ' . $pet['breed'];
                                                }
                                                echo htmlspecialchars($petLabel);
                                                ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="field">
                                    <label for="service_type">Service Type</label>
                                    <select id="service_type" name="service_type" onchange="toggleFields()" required>
                                        <option value="">Choose a service</option>
                                        <option value="walk" <?php echo (($_POST['service_type'] ?? '') === 'walk') ? 'selected' : ''; ?>>Walk</option>
                                        <option value="daycare" <?php echo (($_POST['service_type'] ?? '') === 'daycare') ? 'selected' : ''; ?>>Daycare</option>
                                        <option value="boarding" <?php echo (($_POST['service_type'] ?? '') === 'boarding') ? 'selected' : ''; ?>>Boarding</option>
                                        <option value="drop-in visit" <?php echo (($_POST['service_type'] ?? '') === 'drop-in visit') ? 'selected' : ''; ?>>Drop-In Visit</option>
                                        <option value="pet taxi" <?php echo (($_POST['service_type'] ?? '') === 'pet taxi') ? 'selected' : ''; ?>>Pet Taxi</option>
                                    </select>
                                </div>

                                <div class="field" id="duration-wrap">
                                    <label for="duration_minutes">Walk Duration</label>
                                    <select id="duration_minutes" name="duration_minutes">
                                        <option value="">Choose duration</option>
                                        <option value="15" <?php echo (($_POST['duration_minutes'] ?? '') === '15') ? 'selected' : ''; ?>>15 Minutes</option>
                                        <option value="20" <?php echo (($_POST['duration_minutes'] ?? '') === '20') ? 'selected' : ''; ?>>20 Minutes</option>
                                        <option value="30" <?php echo (($_POST['duration_minutes'] ?? '') === '30') ? 'selected' : ''; ?>>30 Minutes</option>
                                        <option value="45" <?php echo (($_POST['duration_minutes'] ?? '') === '45') ? 'selected' : ''; ?>>45 Minutes</option>
                                        <option value="60" <?php echo (($_POST['duration_minutes'] ?? '') === '60') ? 'selected' : ''; ?>>60 Minutes</option>
                                    </select>
                                    <div class="field-note">Walk pricing follows your current standard rate card.</div>
                                </div>

                                <div class="field" id="boarding-wrap">
                                    <label for="dog_size">Boarding Size</label>
                                    <select id="dog_size" name="dog_size">
                                        <option value="">Choose size</option>
                                        <option value="small" <?php echo (($_POST['dog_size'] ?? '') === 'small') ? 'selected' : ''; ?>>Small Dog — $80</option>
                                        <option value="medium" <?php echo (($_POST['dog_size'] ?? '') === 'medium') ? 'selected' : ''; ?>>Medium Dog — $100</option>
                                        <option value="large" <?php echo (($_POST['dog_size'] ?? '') === 'large') ? 'selected' : ''; ?>>Large Dog — $120</option>
                                    </select>
                                    <div class="field-note">Boarding is priced by dog size for a more tailored premium stay.</div>
                                </div>

                                <div class="field">
                                    <label for="service_date">Service Date</label>
                                    <input type="date" id="service_date" name="service_date" value="<?php echo oldValue('service_date'); ?>" required>
                                </div>

                                <div class="field">
                                    <label for="service_time">Service Time</label>
                                    <input type="time" id="service_time" name="service_time" value="<?php echo oldValue('service_time'); ?>" required>
                                </div>

                                <div class="field full">
                                    <label for="access_notes">Access Notes</label>
                                    <textarea id="access_notes" name="access_notes" placeholder="Building instructions, concierge notes, key handling, doorman details, home access, or arrival guidance."><?php echo oldValue('access_notes'); ?></textarea>
                                </div>

                                <div class="field full">
                                    <label for="client_notes">Care Notes</label>
                                    <textarea id="client_notes" name="client_notes" placeholder="Anything we should know to make this visit more personal, seamless, and comfortable for your dog."><?php echo oldValue('client_notes'); ?></textarea>
                                </div>
                            </div>

                            <div class="actions">
                                <button type="submit" class="btn btn-primary">Submit Premium Booking</button>
                                <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="info-stack">
                    <div class="card-dark">
                        <h2>Service Pricing</h2>
                        <p class="card-subtext">A cleaner, more premium rate presentation for your booking experience.</p>

                        <div class="price-box">
                            <h3>Walks</h3>
                            <p>15 min — $18<br>20 min — $22<br>30 min — $27<br>45 min — $36<br>60 min — $45</p>
                        </div>

                        <div class="price-box" style="margin-top:14px;">
                            <h3>Boarding</h3>
                            <p>Small dog — $80<br>Medium dog — $100<br>Large dog — $120</p>
                        </div>

                        <div class="price-box" style="margin-top:14px;">
                            <h3>Additional Services</h3>
                            <p>Daycare — $45<br>Drop-In Visit — $30<br>Pet Taxi — $35</p>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</body>
</html>