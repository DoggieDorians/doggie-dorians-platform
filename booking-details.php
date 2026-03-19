<?php
session_start();
require_once __DIR__ . '/data/config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Member';
$bookingId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($bookingId <= 0) {
    header('Location: my-bookings.php');
    exit;
}

$booking = null;
$successMessage = '';
$errorMessage = '';

function getTableColumns(PDO $pdo, string $tableName): array
{
    $columns = [];

    try {
        $stmt = $pdo->query("PRAGMA table_info(" . $tableName . ")");
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($results as $column) {
            if (!empty($column['name'])) {
                $columns[] = $column['name'];
            }
        }
    } catch (Throwable $e) {
        return [];
    }

    return $columns;
}

function hasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function buildBookingDetailsSql(PDO $pdo): string
{
    $bookingColumns = getTableColumns($pdo, 'bookings');
    $petColumns = getTableColumns($pdo, 'pets');

    $hasPetId = hasColumn($bookingColumns, 'pet_id');
    $hasBookingPetName = hasColumn($bookingColumns, 'pet_name');
    $hasPetsTableId = hasColumn($petColumns, 'id');
    $hasPetsTablePetName = hasColumn($petColumns, 'pet_name');

    $petNameSelect = "NULL AS booking_pet_name";
    $joinClause = "";

    if ($hasPetId && $hasPetsTableId && $hasPetsTablePetName) {
        $petNameSelect = "p.pet_name AS booking_pet_name";
        $joinClause = " LEFT JOIN pets p ON b.pet_id = p.id ";
    } elseif ($hasBookingPetName) {
        $petNameSelect = "b.pet_name AS booking_pet_name";
    }

    return "
        SELECT
            b.*,
            {$petNameSelect}
        FROM bookings b
        {$joinClause}
        WHERE b.id = :booking_id
          AND b.user_id = :user_id
        LIMIT 1
    ";
}

function loadBooking(PDO $pdo, int $bookingId, int $userId): ?array
{
    $sql = buildBookingDetailsSql($pdo);
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'booking_id' => $bookingId,
        'user_id' => $userId,
    ]);

    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    return $booking ?: null;
}

function formatServiceName(string $service): string
{
    return ucwords(str_replace('-', ' ', $service));
}

function formatDisplayDate(?string $date): string
{
    $date = trim((string)$date);

    if ($date === '') {
        return 'N/A';
    }

    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return htmlspecialchars($date, ENT_QUOTES, 'UTF-8');
    }

    return date('F j, Y', $timestamp);
}

function formatDisplayDateTime(?string $dateTime): string
{
    $dateTime = trim((string)$dateTime);

    if ($dateTime === '') {
        return 'N/A';
    }

    $timestamp = strtotime($dateTime);
    if ($timestamp === false) {
        return htmlspecialchars($dateTime, ENT_QUOTES, 'UTF-8');
    }

    return date('F j, Y \a\t g:i A', $timestamp);
}

function formatDisplayTime(?string $time): string
{
    $time = trim((string)$time);

    if ($time === '') {
        return 'N/A';
    }

    $timestamp = strtotime($time);
    if ($timestamp === false) {
        return htmlspecialchars($time, ENT_QUOTES, 'UTF-8');
    }

    return date('g:i A', $timestamp);
}

function formatStatusClass(string $status): string
{
    $normalized = strtolower(trim($status));

    return match ($normalized) {
        'confirmed', 'completed', 'active', 'approved' => 'status-positive',
        'requested', 'pending', 'scheduled' => 'status-neutral',
        'cancelled', 'canceled' => 'status-negative',
        default => 'status-default',
    };
}

function bookingPetLabel(array $booking): string
{
    $petName = trim((string)($booking['booking_pet_name'] ?? ''));
    return $petName !== '' ? $petName : 'Pet not specified';
}

function oldValue(string $key): string
{
    return htmlspecialchars($_POST[$key] ?? '', ENT_QUOTES, 'UTF-8');
}

try {
    $booking = loadBooking($pdo, $bookingId, $userId);

    if (!$booking) {
        header('Location: my-bookings.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $requestType = strtolower(trim($_POST['request_type'] ?? ''));
        $requestedDate = trim($_POST['requested_date'] ?? '');
        $requestedTime = trim($_POST['requested_time'] ?? '');
        $requestNote = trim($_POST['request_note'] ?? '');

        $allowedRequestTypes = ['cancel', 'reschedule'];

        if (!in_array($requestType, $allowedRequestTypes, true)) {
            $errorMessage = 'Please choose a valid request type.';
        } elseif ($requestType === 'reschedule' && $requestedDate === '') {
            $errorMessage = 'Please choose a requested new date for rescheduling.';
        } elseif ($requestNote === '') {
            $errorMessage = 'Please include a short note for your request.';
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS booking_change_requests (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    booking_id INTEGER NOT NULL,
                    user_id INTEGER NOT NULL,
                    request_type TEXT NOT NULL,
                    current_service_date TEXT,
                    current_service_time TEXT,
                    requested_service_date TEXT,
                    requested_service_time TEXT,
                    note TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT 'Pending',
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
            ");

            $insert = $pdo->prepare("
                INSERT INTO booking_change_requests (
                    booking_id,
                    user_id,
                    request_type,
                    current_service_date,
                    current_service_time,
                    requested_service_date,
                    requested_service_time,
                    note
                ) VALUES (
                    :booking_id,
                    :user_id,
                    :request_type,
                    :current_service_date,
                    :current_service_time,
                    :requested_service_date,
                    :requested_service_time,
                    :note
                )
            ");

            $insert->execute([
                'booking_id' => $bookingId,
                'user_id' => $userId,
                'request_type' => $requestType,
                'current_service_date' => $booking['service_date'] ?? null,
                'current_service_time' => $booking['service_time'] ?? null,
                'requested_service_date' => $requestType === 'reschedule' ? $requestedDate : null,
                'requested_service_time' => $requestType === 'reschedule' && $requestedTime !== '' ? $requestedTime : null,
                'note' => $requestNote,
            ]);

            $successMessage = $requestType === 'cancel'
                ? 'Your cancellation request has been submitted for review.'
                : 'Your reschedule request has been submitted for review.';

            $_POST = [];
        }
    }
} catch (PDOException $e) {
    die('Booking details error: ' . $e->getMessage());
}

$serviceType = formatServiceName((string)($booking['service_type'] ?? 'Service'));
$petName = bookingPetLabel($booking);
$status = (string)($booking['status'] ?? 'Unknown');
$duration = $booking['duration_minutes'] !== null && $booking['duration_minutes'] !== ''
    ? (string)$booking['duration_minutes'] . ' mins'
    : 'N/A';
$price = isset($booking['price']) && $booking['price'] !== null && $booking['price'] !== ''
    ? '$' . number_format((float)$booking['price'], 2)
    : 'N/A';
$createdAt = formatDisplayDateTime($booking['created_at'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Details | Doggie Dorian's</title>
    <meta name="description" content="View your booking details with Doggie Dorian's.">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --bg: #07080b;
            --bg-soft: #0d1016;
            --panel: rgba(255,255,255,0.04);
            --line: rgba(255,255,255,0.10);
            --text: #f6f1e8;
            --muted: #c9c0af;
            --soft: #9d968a;
            --gold: #d7b26a;
            --gold-light: #f0d59f;
            --white: #ffffff;
            --success: #9fe0b1;
            --danger: #ff9d9d;
            --shadow: 0 22px 65px rgba(0,0,0,0.34);
            --max: 1180px;
        }

        body {
            font-family: "Georgia", "Times New Roman", serif;
            background:
                radial-gradient(circle at top, rgba(215,178,106,0.10), transparent 24%),
                linear-gradient(180deg, #06070a 0%, #0b0d12 45%, #06070a 100%);
            color: var(--text);
            line-height: 1.6;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .page {
            min-height: 100vh;
            padding: 30px 20px 64px;
        }

        .wrap {
            width: min(var(--max), 100%);
            margin: 0 auto;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 28px;
        }

        .brand {
            font-size: 1.7rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            color: var(--white);
        }

        .topnav {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .topnav a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 12px 16px;
            border-radius: 999px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.08);
            color: var(--white);
            font-weight: 700;
            transition: 0.22s ease;
        }

        .topnav a:hover,
        .topnav a.active {
            transform: translateY(-1px);
            border-color: rgba(215,178,106,0.28);
            color: var(--gold);
        }

        .hero {
            border-radius: 34px;
            border: 1px solid rgba(255,255,255,0.08);
            background:
                linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.02)),
                linear-gradient(135deg, rgba(215,178,106,0.10), rgba(255,255,255,0.02));
            box-shadow: var(--shadow);
            padding: 36px;
            margin-bottom: 24px;
        }

        .eyebrow {
            display: inline-block;
            padding: 8px 14px;
            border-radius: 999px;
            border: 1px solid rgba(215,178,106,0.30);
            background: rgba(215,178,106,0.08);
            color: #f2d9a8;
            font-size: 0.78rem;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            margin-bottom: 18px;
        }

        .hero-grid {
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            gap: 22px;
            align-items: start;
        }

        .hero h1 {
            margin: 0 0 10px;
            font-size: clamp(2.2rem, 5vw, 3.8rem);
            line-height: 0.96;
            color: var(--white);
        }

        .hero p {
            color: var(--muted);
            font-size: 1rem;
        }

        .hero-pet {
            margin-top: 10px;
            color: #f5ddaf;
            font-size: 1rem;
            font-weight: 700;
        }

        .status-badge {
            display: inline-block;
            margin-top: 18px;
            padding: 8px 11px;
            border-radius: 999px;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            border: 1px solid rgba(255,255,255,0.12);
        }

        .status-positive {
            background: rgba(159,224,177,0.10);
            color: var(--success);
            border-color: rgba(159,224,177,0.24);
        }

        .status-neutral {
            background: rgba(215,178,106,0.10);
            color: #f2d9a8;
            border-color: rgba(215,178,106,0.24);
        }

        .status-negative {
            background: rgba(255,157,157,0.10);
            color: var(--danger);
            border-color: rgba(255,157,157,0.24);
        }

        .status-default {
            background: rgba(255,255,255,0.06);
            color: var(--white);
            border-color: rgba(255,255,255,0.12);
        }

        .hero-summary {
            border-radius: 24px;
            padding: 22px;
            border: 1px solid rgba(215,178,106,0.22);
            background:
                linear-gradient(135deg, rgba(215,178,106,0.12), rgba(255,255,255,0.03));
            box-shadow: var(--shadow);
        }

        .hero-summary strong {
            display: block;
            color: #f5ddaf;
            font-size: 2rem;
            line-height: 1;
            margin-bottom: 8px;
        }

        .hero-summary span {
            color: var(--muted);
            font-size: 0.96rem;
        }

        .status-message {
            border-radius: 18px;
            padding: 15px 16px;
            margin-bottom: 18px;
            font-size: 0.96rem;
        }

        .status-message.success {
            background: rgba(159,224,177,0.10);
            border: 1px solid rgba(159,224,177,0.30);
            color: var(--success);
        }

        .status-message.error {
            background: rgba(255,141,141,0.10);
            border: 1px solid rgba(255,141,141,0.30);
            color: var(--danger);
        }

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 0.95fr;
            gap: 22px;
        }

        .card {
            border-radius: 26px;
            padding: 28px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.08);
            box-shadow: var(--shadow);
        }

        .card h2 {
            margin: 0 0 8px;
            font-size: 1.9rem;
            color: var(--white);
        }

        .card-subtext {
            margin: 0 0 20px;
            color: var(--muted);
            font-size: 0.97rem;
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .detail-box {
            border-radius: 18px;
            padding: 16px;
            background: rgba(255,255,255,0.02);
            border: 1px solid rgba(255,255,255,0.08);
        }

        .detail-box strong {
            display: block;
            color: #f5ddaf;
            font-size: 0.95rem;
            margin-bottom: 4px;
        }

        .detail-box span {
            color: var(--muted);
            font-size: 0.94rem;
        }

        .request-form {
            display: grid;
            gap: 16px;
        }

        .field {
            display: grid;
            gap: 8px;
        }

        label {
            color: var(--white);
            font-size: 0.94rem;
            font-weight: 700;
        }

        input,
        select,
        textarea {
            width: 100%;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.04);
            color: var(--text);
            border-radius: 16px;
            padding: 14px 15px;
            font: inherit;
            outline: none;
            transition: border-color 0.2s ease, background 0.2s ease;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: rgba(215,178,106,0.50);
            background: rgba(255,255,255,0.06);
        }

        textarea {
            min-height: 140px;
            resize: vertical;
        }

        .helper {
            color: var(--soft);
            font-size: 0.86rem;
            margin-top: -2px;
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 22px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 12px 18px;
            border-radius: 999px;
            font-weight: 700;
            border: 1px solid transparent;
            transition: 0.22s ease;
            cursor: pointer;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
            color: #17120d;
            box-shadow: 0 16px 38px rgba(215,178,106,0.22);
        }

        .btn-secondary {
            background: rgba(255,255,255,0.03);
            color: var(--white);
            border-color: rgba(255,255,255,0.08);
        }

        .note-box {
            border-radius: 20px;
            padding: 18px;
            border: 1px solid rgba(215,178,106,0.18);
            background: rgba(215,178,106,0.08);
            color: var(--muted);
            font-size: 0.95rem;
        }

        .note-box strong {
            display: block;
            color: var(--white);
            margin-bottom: 8px;
            font-size: 1rem;
        }

        @media (max-width: 980px) {
            .hero-grid,
            .content-grid,
            .details-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 720px) {
            .page {
                padding: 20px 14px 50px;
            }

            .hero,
            .card,
            .hero-summary {
                padding: 22px;
            }

            .hero h1 {
                font-size: 2rem;
            }

            .topnav {
                width: 100%;
            }

            .topnav a,
            .actions a,
            .actions button {
                flex: 1;
            }
        }
    </style>
    <script>
        function toggleRescheduleFields() {
            const requestType = document.getElementById('request_type');
            const rescheduleFields = document.getElementById('reschedule-fields');

            if (!requestType || !rescheduleFields) return;

            if (requestType.value === 'reschedule') {
                rescheduleFields.style.display = 'grid';
            } else {
                rescheduleFields.style.display = 'none';
            }
        }

        document.addEventListener('DOMContentLoaded', toggleRescheduleFields);
    </script>
</head>
<body>
    <div class="page">
        <div class="wrap">
            <div class="topbar">
                <div class="brand">Doggie Dorian’s</div>
                <div class="topnav">
                    <a href="dashboard.php">Dashboard</a>
                    <a href="my-bookings.php" class="active">My Bookings</a>
                    <a href="profile.php">Profile</a>
                    <a href="logout.php">Logout</a>
                </div>
            </div>

            <section class="hero">
                <div class="eyebrow">Booking Details</div>

                <div class="hero-grid">
                    <div>
                        <h1><?php echo htmlspecialchars($serviceType); ?></h1>
                        <p>Review your full booking details below.</p>
                        <div class="hero-pet">Pet: <?php echo htmlspecialchars($petName); ?></div>

                        <span class="status-badge <?php echo htmlspecialchars(formatStatusClass($status)); ?>">
                            <?php echo htmlspecialchars($status); ?>
                        </span>
                    </div>

                    <div class="hero-summary">
                        <strong><?php echo $price; ?></strong>
                        <span>Current booking price</span>
                    </div>
                </div>
            </section>

            <?php if ($successMessage !== ''): ?>
                <div class="status-message success"><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if ($errorMessage !== ''): ?>
                <div class="status-message error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <section class="content-grid">
                <div class="card">
                    <h2>Service Information</h2>
                    <p class="card-subtext">Your scheduled service details at a glance.</p>

                    <div class="details-grid">
                        <div class="detail-box">
                            <strong>Service</strong>
                            <span><?php echo htmlspecialchars($serviceType); ?></span>
                        </div>

                        <div class="detail-box">
                            <strong>Pet</strong>
                            <span><?php echo htmlspecialchars($petName); ?></span>
                        </div>

                        <div class="detail-box">
                            <strong>Date</strong>
                            <span><?php echo formatDisplayDate($booking['service_date'] ?? ''); ?></span>
                        </div>

                        <div class="detail-box">
                            <strong>Time</strong>
                            <span><?php echo formatDisplayTime($booking['service_time'] ?? ''); ?></span>
                        </div>

                        <div class="detail-box">
                            <strong>Duration</strong>
                            <span><?php echo htmlspecialchars($duration); ?></span>
                        </div>

                        <div class="detail-box">
                            <strong>Status</strong>
                            <span><?php echo htmlspecialchars($status); ?></span>
                        </div>

                        <div class="detail-box">
                            <strong>Price</strong>
                            <span><?php echo htmlspecialchars($price); ?></span>
                        </div>

                        <div class="detail-box">
                            <strong>Booked On</strong>
                            <span><?php echo htmlspecialchars($createdAt); ?></span>
                        </div>
                    </div>

                    <div class="actions">
                        <a href="my-bookings.php" class="btn btn-secondary">Back to My Bookings</a>
                        <a href="book-walk.php" class="btn btn-primary">Book Again</a>
                    </div>
                </div>

                <div class="card">
                    <h2>Request Cancel or Reschedule</h2>
                    <p class="card-subtext">Need to change this booking? Submit a request here for review.</p>

                    <form method="post" action="booking-details.php?id=<?php echo urlencode((string)$bookingId); ?>" class="request-form">
                        <div class="field">
                            <label for="request_type">Request Type</label>
                            <select id="request_type" name="request_type" onchange="toggleRescheduleFields()" required>
                                <option value="">Select request type</option>
                                <option value="cancel" <?php echo oldValue('request_type') === 'cancel' ? 'selected' : ''; ?>>Request Cancellation</option>
                                <option value="reschedule" <?php echo oldValue('request_type') === 'reschedule' ? 'selected' : ''; ?>>Request Reschedule</option>
                            </select>
                        </div>

                        <div id="reschedule-fields" style="display:none; gap:16px;">
                            <div class="field">
                                <label for="requested_date">Requested New Date</label>
                                <input type="date" id="requested_date" name="requested_date" value="<?php echo oldValue('requested_date'); ?>">
                            </div>

                            <div class="field">
                                <label for="requested_time">Requested New Time</label>
                                <input type="time" id="requested_time" name="requested_time" value="<?php echo oldValue('requested_time'); ?>">
                                <div class="helper">Optional for reschedule requests, but helpful if you know your preference.</div>
                            </div>
                        </div>

                        <div class="field">
                            <label for="request_note">Note</label>
                            <textarea id="request_note" name="request_note" placeholder="Please share the reason for your request or any preferred timing details."><?php echo oldValue('request_note'); ?></textarea>
                        </div>

                        <div class="actions" style="margin-top: 0;">
                            <button type="submit" class="btn btn-primary">Submit Request</button>
                        </div>
                    </form>

                    <div class="note-box" style="margin-top: 20px;">
                        <strong>How this works</strong>
                        Your request will be saved for review first rather than changing the booking instantly. This keeps your scheduling process more controlled and professional.
                    </div>

                    <div class="actions">
                        <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
                    </div>
                </div>
            </section>
        </div>
    </div>
</body>
</html>