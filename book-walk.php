<?php
session_start();

$isLoggedIn = isset($_SESSION['member_id']);

$dbPath = __DIR__ . '/data/members.sqlite';
$successMessage = '';
$errorMessage = '';

$ownerEmail = 'doggie.dorians@gmail.com';
$textAlertEmail = '6316035644@vtext.com';

function clean_input(string $value): string {
    return trim($value);
}

function old_value(string $key): string {
    return htmlspecialchars($_POST[$key] ?? '', ENT_QUOTES, 'UTF-8');
}

function send_alert_email(string $to, string $subject, string $message, string $replyTo = ''): bool {
    if ($to === '') {
        return false;
    }

    $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-type: text/plain; charset=UTF-8';
    $headers[] = 'From: Doggie Dorian\'s <no-reply@' . $domain . '>';

    if ($replyTo !== '') {
        $headers[] = 'Reply-To: ' . $replyTo;
    }

    return @mail($to, $subject, $message, implode("\r\n", $headers));
}

function is_valid_date(string $date): bool {
    if ($date === '') {
        return false;
    }

    $parts = explode('-', $date);
    if (count($parts) !== 3) {
        return false;
    }

    return checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
}

function format_service_label(string $serviceType): string {
    return match ($serviceType) {
        'walk' => 'Walk',
        'daycare' => 'Daycare',
        'boarding' => 'Boarding',
        default => 'Unknown'
    };
}

function get_estimated_price(string $serviceType, string $walkDuration, string $petSize): ?float {
    $walkPrices = [
        '15' => 23,
        '20' => 25,
        '30' => 30,
        '45' => 38,
        '60' => 42,
    ];

    $daycarePrices = [
        'small' => 65,
        'medium' => 85,
        'large' => 110,
    ];

    $boardingPrices = [
        'small' => 90,
        'medium' => 110,
        'large' => 120,
    ];

    if ($serviceType === 'walk' && isset($walkPrices[$walkDuration])) {
        return (float)$walkPrices[$walkDuration];
    }

    if ($serviceType === 'daycare' && isset($daycarePrices[$petSize])) {
        return (float)$daycarePrices[$petSize];
    }

    if ($serviceType === 'boarding' && isset($boardingPrices[$petSize])) {
        return (float)$boardingPrices[$petSize];
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = clean_input($_POST['full_name'] ?? '');
    $email = clean_input($_POST['email'] ?? '');
    $phone = clean_input($_POST['phone'] ?? '');
    $serviceType = clean_input($_POST['service_type'] ?? '');
    $walkDuration = clean_input($_POST['walk_duration'] ?? '');
    $petName = clean_input($_POST['pet_name'] ?? '');
    $petSize = clean_input($_POST['pet_size'] ?? '');
    $preferredDate = clean_input($_POST['preferred_date'] ?? '');
    $preferredTime = clean_input($_POST['preferred_time'] ?? '');
    $dropoffTime = clean_input($_POST['dropoff_time'] ?? '');
    $pickupTime = clean_input($_POST['pickup_time'] ?? '');
    $checkinDate = clean_input($_POST['checkin_date'] ?? '');
    $checkoutDate = clean_input($_POST['checkout_date'] ?? '');
    $checkinTime = clean_input($_POST['checkin_time'] ?? '');
    $checkoutTime = clean_input($_POST['checkout_time'] ?? '');
    $feedingSchedule = clean_input($_POST['feeding_schedule'] ?? '');
    $notes = clean_input($_POST['notes'] ?? '');

    $allowedServices = ['walk', 'daycare', 'boarding'];
    $allowedDurations = ['15', '20', '30', '45', '60', ''];
    $allowedSizes = ['small', 'medium', 'large', ''];

    $today = date('Y-m-d');

    if (
        $fullName === '' ||
        $email === '' ||
        $phone === '' ||
        $serviceType === '' ||
        $petName === '' ||
        $petSize === ''
    ) {
        $errorMessage = 'Please complete all required fields before submitting your request.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'Please enter a valid email address.';
    } elseif (!in_array($serviceType, $allowedServices, true)) {
        $errorMessage = 'Please choose a valid service type.';
    } elseif (!in_array($walkDuration, $allowedDurations, true)) {
        $errorMessage = 'Please choose a valid walk duration.';
    } elseif (!in_array($petSize, $allowedSizes, true)) {
        $errorMessage = 'Please choose a valid dog size.';
    } elseif ($serviceType === 'walk' && $walkDuration === '') {
        $errorMessage = 'Please select a walk duration for walk bookings.';
    } elseif ($serviceType === 'walk' && $preferredDate === '') {
        $errorMessage = 'Please select a preferred date for your walk request.';
    } elseif ($serviceType === 'walk' && !is_valid_date($preferredDate)) {
        $errorMessage = 'Please enter a valid walk date.';
    } elseif ($serviceType === 'walk' && $preferredDate < $today) {
        $errorMessage = 'Walk requests cannot be submitted for a past date.';
    } elseif ($serviceType === 'daycare' && $preferredDate === '') {
        $errorMessage = 'Please select a date for your daycare request.';
    } elseif ($serviceType === 'daycare' && !is_valid_date($preferredDate)) {
        $errorMessage = 'Please enter a valid daycare date.';
    } elseif ($serviceType === 'daycare' && $preferredDate < $today) {
        $errorMessage = 'Daycare requests cannot be submitted for a past date.';
    } elseif ($serviceType === 'boarding' && ($checkinDate === '' || $checkoutDate === '')) {
        $errorMessage = 'Please select both check-in and check-out dates for boarding.';
    } elseif ($serviceType === 'boarding' && (!is_valid_date($checkinDate) || !is_valid_date($checkoutDate))) {
        $errorMessage = 'Please enter valid boarding dates.';
    } elseif ($serviceType === 'boarding' && $checkinDate < $today) {
        $errorMessage = 'Boarding check-in cannot be in the past.';
    } elseif ($serviceType === 'boarding' && $checkoutDate < $checkinDate) {
        $errorMessage = 'Boarding check-out date must be the same day or later than check-in.';
    } else {
        try {
            if (!is_dir(__DIR__ . '/data')) {
                mkdir(__DIR__ . '/data', 0775, true);
            }

            $db = new SQLite3($dbPath);
            $db->busyTimeout(5000);

            $db->exec("
                CREATE TABLE IF NOT EXISTS public_booking_requests (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    full_name TEXT NOT NULL,
                    email TEXT NOT NULL,
                    phone TEXT NOT NULL,
                    service_type TEXT NOT NULL,
                    walk_duration INTEGER,
                    pet_name TEXT NOT NULL,
                    pet_size TEXT NOT NULL,
                    preferred_date TEXT,
                    preferred_time TEXT,
                    dropoff_time TEXT,
                    pickup_time TEXT,
                    checkin_date TEXT,
                    checkout_date TEXT,
                    checkin_time TEXT,
                    checkout_time TEXT,
                    feeding_schedule TEXT,
                    notes TEXT,
                    estimated_price REAL,
                    source TEXT NOT NULL DEFAULT 'public_booking_page',
                    status TEXT NOT NULL DEFAULT 'New',
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
            ");

            $existingColumns = [];
            $columnsResult = $db->query("PRAGMA table_info(public_booking_requests)");
            while ($column = $columnsResult->fetchArray(SQLITE3_ASSOC)) {
                $existingColumns[] = $column['name'];
            }

            $columnsToAdd = [
                'dropoff_time' => 'ALTER TABLE public_booking_requests ADD COLUMN dropoff_time TEXT',
                'pickup_time' => 'ALTER TABLE public_booking_requests ADD COLUMN pickup_time TEXT',
                'checkin_date' => 'ALTER TABLE public_booking_requests ADD COLUMN checkin_date TEXT',
                'checkout_date' => 'ALTER TABLE public_booking_requests ADD COLUMN checkout_date TEXT',
                'checkin_time' => 'ALTER TABLE public_booking_requests ADD COLUMN checkin_time TEXT',
                'checkout_time' => 'ALTER TABLE public_booking_requests ADD COLUMN checkout_time TEXT',
                'estimated_price' => 'ALTER TABLE public_booking_requests ADD COLUMN estimated_price REAL',
            ];

            foreach ($columnsToAdd as $columnName => $sql) {
                if (!in_array($columnName, $existingColumns, true)) {
                    $db->exec($sql);
                }
            }

            $estimatedPrice = get_estimated_price($serviceType, $walkDuration, $petSize);
            $walkDurationValue = $walkDuration === '' ? null : (int)$walkDuration;

            $storedPreferredDate = null;
            $storedPreferredTime = null;

            if ($serviceType === 'walk') {
                $storedPreferredDate = $preferredDate;
                $storedPreferredTime = $preferredTime;
            } elseif ($serviceType === 'daycare') {
                $storedPreferredDate = $preferredDate;
            }

            $stmt = $db->prepare("
                INSERT INTO public_booking_requests (
                    full_name,
                    email,
                    phone,
                    service_type,
                    walk_duration,
                    pet_name,
                    pet_size,
                    preferred_date,
                    preferred_time,
                    dropoff_time,
                    pickup_time,
                    checkin_date,
                    checkout_date,
                    checkin_time,
                    checkout_time,
                    feeding_schedule,
                    notes,
                    estimated_price
                ) VALUES (
                    :full_name,
                    :email,
                    :phone,
                    :service_type,
                    :walk_duration,
                    :pet_name,
                    :pet_size,
                    :preferred_date,
                    :preferred_time,
                    :dropoff_time,
                    :pickup_time,
                    :checkin_date,
                    :checkout_date,
                    :checkin_time,
                    :checkout_time,
                    :feeding_schedule,
                    :notes,
                    :estimated_price
                )
            ");

            $stmt->bindValue(':full_name', $fullName, SQLITE3_TEXT);
            $stmt->bindValue(':email', $email, SQLITE3_TEXT);
            $stmt->bindValue(':phone', $phone, SQLITE3_TEXT);
            $stmt->bindValue(':service_type', $serviceType, SQLITE3_TEXT);

            if ($walkDurationValue === null) {
                $stmt->bindValue(':walk_duration', null, SQLITE3_NULL);
            } else {
                $stmt->bindValue(':walk_duration', $walkDurationValue, SQLITE3_INTEGER);
            }

            $stmt->bindValue(':pet_name', $petName, SQLITE3_TEXT);
            $stmt->bindValue(':pet_size', $petSize, SQLITE3_TEXT);

            if ($storedPreferredDate === null) {
                $stmt->bindValue(':preferred_date', null, SQLITE3_NULL);
            } else {
                $stmt->bindValue(':preferred_date', $storedPreferredDate, SQLITE3_TEXT);
            }

            if ($storedPreferredTime === null || $storedPreferredTime === '') {
                $stmt->bindValue(':preferred_time', null, SQLITE3_NULL);
            } else {
                $stmt->bindValue(':preferred_time', $storedPreferredTime, SQLITE3_TEXT);
            }

            $stmt->bindValue(':dropoff_time', $dropoffTime !== '' ? $dropoffTime : null, $dropoffTime !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
            $stmt->bindValue(':pickup_time', $pickupTime !== '' ? $pickupTime : null, $pickupTime !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
            $stmt->bindValue(':checkin_date', $checkinDate !== '' ? $checkinDate : null, $checkinDate !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
            $stmt->bindValue(':checkout_date', $checkoutDate !== '' ? $checkoutDate : null, $checkoutDate !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
            $stmt->bindValue(':checkin_time', $checkinTime !== '' ? $checkinTime : null, $checkinTime !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
            $stmt->bindValue(':checkout_time', $checkoutTime !== '' ? $checkoutTime : null, $checkoutTime !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
            $stmt->bindValue(':feeding_schedule', $feedingSchedule !== '' ? $feedingSchedule : null, $feedingSchedule !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
            $stmt->bindValue(':notes', $notes !== '' ? $notes : null, $notes !== '' ? SQLITE3_TEXT : SQLITE3_NULL);

            if ($estimatedPrice === null) {
                $stmt->bindValue(':estimated_price', null, SQLITE3_NULL);
            } else {
                $stmt->bindValue(':estimated_price', $estimatedPrice, SQLITE3_FLOAT);
            }

            $result = $stmt->execute();

            if ($result) {
                $bookingId = $db->lastInsertRowID();

                $serviceLabel = format_service_label($serviceType);
                $durationLabel = ($serviceType === 'walk' && $walkDuration !== '') ? $walkDuration . '-minute walk' : 'N/A';
                $preferredTimeLabel = $preferredTime !== '' ? $preferredTime : 'Not provided';
                $dropoffLabel = $dropoffTime !== '' ? $dropoffTime : 'Not provided';
                $pickupLabel = $pickupTime !== '' ? $pickupTime : 'Not provided';
                $checkinDateLabel = $checkinDate !== '' ? $checkinDate : 'Not provided';
                $checkoutDateLabel = $checkoutDate !== '' ? $checkoutDate : 'Not provided';
                $checkinTimeLabel = $checkinTime !== '' ? $checkinTime : 'Not provided';
                $checkoutTimeLabel = $checkoutTime !== '' ? $checkoutTime : 'Not provided';
                $feedingLabel = $feedingSchedule !== '' ? $feedingSchedule : 'Not provided';
                $notesLabel = $notes !== '' ? $notes : 'None';
                $priceLabel = $estimatedPrice !== null ? '$' . number_format($estimatedPrice, 2) : 'Not available';

                $emailSubject = 'New Doggie Dorian\'s Booking Request #' . $bookingId;
                $emailBody = "A new public booking request was submitted.\n\n"
                    . "Booking ID: {$bookingId}\n"
                    . "Client Name: {$fullName}\n"
                    . "Email: {$email}\n"
                    . "Phone: {$phone}\n"
                    . "Dog Name: {$petName}\n"
                    . "Dog Size: {$petSize}\n"
                    . "Service Type: {$serviceLabel}\n"
                    . "Walk Duration: {$durationLabel}\n"
                    . "Preferred Date: " . ($storedPreferredDate ?? 'Not provided') . "\n"
                    . "Preferred Time: {$preferredTimeLabel}\n"
                    . "Daycare Drop-Off Time: {$dropoffLabel}\n"
                    . "Daycare Pick-Up Time: {$pickupLabel}\n"
                    . "Boarding Check-In Date: {$checkinDateLabel}\n"
                    . "Boarding Check-Out Date: {$checkoutDateLabel}\n"
                    . "Boarding Check-In Time: {$checkinTimeLabel}\n"
                    . "Boarding Check-Out Time: {$checkoutTimeLabel}\n"
                    . "Feeding Schedule: {$feedingLabel}\n"
                    . "Estimated Price: {$priceLabel}\n"
                    . "Notes: {$notesLabel}\n";

                send_alert_email($ownerEmail, $emailSubject, $emailBody, $email);

                if ($textAlertEmail !== '') {
                    $textBody = "New booking #{$bookingId}: {$fullName}, {$serviceLabel}";
                    if ($serviceType === 'walk' && $walkDuration !== '') {
                        $textBody .= ", {$walkDuration} min";
                    }
                    if ($serviceType === 'walk' && $storedPreferredDate !== null) {
                        $textBody .= ", {$storedPreferredDate}";
                    }
                    if ($serviceType === 'daycare' && $storedPreferredDate !== null) {
                        $textBody .= ", {$storedPreferredDate}";
                    }
                    if ($serviceType === 'boarding' && $checkinDate !== '' && $checkoutDate !== '') {
                        $textBody .= ", {$checkinDate} to {$checkoutDate}";
                    }
                    send_alert_email($textAlertEmail, 'New Booking Alert', $textBody, $email);
                }

                $successMessage = 'Thank you — your booking request has been received. We will review it and reach out shortly to confirm availability and details.';
                $_POST = [];
            } else {
                $errorMessage = 'Something went wrong while saving your request. Please try again.';
            }

            $db->close();
        } catch (Throwable $e) {
            $errorMessage = 'Something went wrong while saving your request. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Book a Service | Doggie Dorian's</title>
  <meta name="description" content="Book dog walking, daycare, or boarding with Doggie Dorian’s. Premium dog care, clear pricing, and an easy luxury booking experience.">

  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    :root {
      --bg: #07080b;
      --bg-soft: #0d1016;
      --panel: rgba(255,255,255,0.05);
      --panel-strong: rgba(255,255,255,0.08);
      --line: rgba(255,255,255,0.10);
      --text: #f6f1e8;
      --muted: #c9c0af;
      --soft: #9d968a;
      --gold: #d7b26a;
      --gold-light: #f0d59f;
      --white: #ffffff;
      --danger: #ff8d8d;
      --success: #9fe0b1;
      --shadow: 0 22px 65px rgba(0,0,0,0.38);
      --max: 1280px;
    }

    body {
      font-family: "Georgia", "Times New Roman", serif;
      background:
        radial-gradient(circle at top, rgba(215,178,106,0.10), transparent 25%),
        linear-gradient(180deg, #06070a 0%, #0b0d12 45%, #06070a 100%);
      color: var(--text);
      line-height: 1.6;
    }

    a {
      color: inherit;
      text-decoration: none;
    }

    .container {
      width: min(var(--max), calc(100% - 34px));
      margin: 0 auto;
    }

    .site-header {
      position: sticky;
      top: 0;
      z-index: 100;
      backdrop-filter: blur(14px);
      background: rgba(7, 8, 11, 0.80);
      border-bottom: 1px solid rgba(255,255,255,0.06);
    }

    .nav-wrap {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 20px;
      padding: 18px 0;
      flex-wrap: wrap;
    }

    .brand {
      font-size: 1.18rem;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--white);
      font-weight: 700;
    }

    .nav-links {
      display: flex;
      align-items: center;
      gap: 22px;
      flex-wrap: wrap;
    }

    .nav-links a {
      color: var(--muted);
      font-size: 0.95rem;
      transition: 0.22s ease;
    }

    .nav-links a:hover,
    .nav-links a.active {
      color: var(--gold);
    }

    .nav-actions {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 999px;
      padding: 13px 22px;
      font-size: 0.95rem;
      font-weight: 700;
      letter-spacing: 0.02em;
      transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease, background 0.22s ease;
      border: 1px solid transparent;
      cursor: pointer;
      text-align: center;
      min-height: 48px;
    }

    .btn:hover {
      transform: translateY(-2px);
    }

    .btn-gold {
      background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
      color: #15120d;
      box-shadow: 0 16px 38px rgba(215,178,106,0.22);
    }

    .btn-outline {
      border-color: rgba(215,178,106,0.45);
      background: rgba(255,255,255,0.02);
      color: var(--gold);
    }

    .btn-soft {
      border-color: rgba(255,255,255,0.08);
      background: rgba(255,255,255,0.03);
      color: var(--white);
    }

    .hero {
      padding: 72px 0 26px;
    }

    .hero-card {
      border-radius: 38px;
      border: 1px solid rgba(255,255,255,0.08);
      background:
        linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.02)),
        linear-gradient(135deg, rgba(215,178,106,0.10), rgba(255,255,255,0.02));
      box-shadow: var(--shadow);
      overflow: hidden;
    }

    .hero-grid {
      display: grid;
      grid-template-columns: 1.1fr 0.9fr;
      gap: 28px;
      padding: 56px;
      align-items: start;
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

    h1 {
      font-size: clamp(2.6rem, 5vw, 5rem);
      line-height: 0.96;
      color: var(--white);
      margin-bottom: 18px;
      max-width: 760px;
    }

    .hero-copy p {
      font-size: 1.08rem;
      color: var(--muted);
      max-width: 720px;
    }

    .hero-actions {
      display: flex;
      gap: 14px;
      flex-wrap: wrap;
      margin-top: 28px;
    }

    .hero-side {
      display: grid;
      gap: 14px;
    }

    .spotlight-card {
      border-radius: 22px;
      border: 1px solid rgba(255,255,255,0.08);
      background: rgba(255,255,255,0.03);
      padding: 20px;
    }

    .spotlight-card strong {
      display: block;
      color: var(--white);
      font-size: 1.02rem;
      margin-bottom: 5px;
    }

    .spotlight-card span {
      color: var(--muted);
      font-size: 0.95rem;
    }

    .spotlight-card.highlight {
      border-color: rgba(215,178,106,0.26);
      background: rgba(215,178,106,0.10);
    }

    .spotlight-price {
      display: block;
      font-size: 2rem;
      color: #f5ddaf;
      font-weight: 700;
      line-height: 1;
      margin-bottom: 8px;
    }

    section {
      padding: 46px 0;
    }

    .section-head {
      max-width: 820px;
      margin-bottom: 28px;
    }

    .section-head h2 {
      font-size: clamp(1.9rem, 3vw, 3rem);
      line-height: 1.08;
      margin-bottom: 10px;
      color: var(--white);
    }

    .section-head p {
      color: var(--muted);
      font-size: 1rem;
    }

    .pricing-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 20px;
    }

    .pricing-card {
      border-radius: 24px;
      border: 1px solid rgba(255,255,255,0.08);
      background: rgba(255,255,255,0.03);
      padding: 24px;
      box-shadow: var(--shadow);
    }

    .pricing-card h3 {
      color: var(--white);
      font-size: 1.35rem;
      margin-bottom: 12px;
    }

    .pricing-list {
      display: grid;
      gap: 10px;
    }

    .pricing-row {
      display: flex;
      justify-content: space-between;
      gap: 14px;
      padding: 12px 0;
      border-bottom: 1px solid rgba(255,255,255,0.08);
      color: var(--muted);
      font-size: 0.96rem;
    }

    .pricing-row:last-child {
      border-bottom: none;
    }

    .pricing-row strong {
      color: #f5ddaf;
      white-space: nowrap;
    }

    .booking-wrap {
      display: grid;
      grid-template-columns: 0.95fr 1.05fr;
      gap: 24px;
      align-items: start;
    }

    .info-panel,
    .form-panel {
      border-radius: 28px;
      border: 1px solid rgba(255,255,255,0.08);
      background: rgba(255,255,255,0.03);
      box-shadow: var(--shadow);
      padding: 28px;
    }

    .info-panel h3,
    .form-panel h3 {
      color: var(--white);
      font-size: 1.6rem;
      margin-bottom: 12px;
    }

    .info-panel p,
    .form-panel p {
      color: var(--muted);
      margin-bottom: 18px;
    }

    .info-list {
      display: grid;
      gap: 12px;
      margin-top: 18px;
    }

    .info-item {
      border-radius: 18px;
      padding: 16px;
      background: rgba(215,178,106,0.08);
      border: 1px solid rgba(215,178,106,0.16);
    }

    .info-item strong {
      display: block;
      color: var(--white);
      margin-bottom: 4px;
    }

    .info-item span {
      color: var(--muted);
      font-size: 0.95rem;
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

    .estimate-box {
      border-radius: 20px;
      padding: 16px;
      margin-bottom: 18px;
      background: rgba(215,178,106,0.08);
      border: 1px solid rgba(215,178,106,0.18);
    }

    .estimate-box strong {
      display: block;
      color: var(--white);
      font-size: 1rem;
      margin-bottom: 4px;
    }

    .estimate-box span {
      color: var(--muted);
      font-size: 0.95rem;
    }

    .estimate-price {
      display: block;
      font-size: 1.8rem;
      line-height: 1;
      color: #f5ddaf;
      margin-bottom: 8px;
      font-weight: 700;
    }

    .form-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }

    .field {
      display: grid;
      gap: 8px;
    }

    .field.full {
      grid-column: 1 / -1;
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

    .member-banner {
      border-radius: 26px;
      padding: 26px;
      border: 1px solid rgba(215,178,106,0.22);
      background:
        linear-gradient(135deg, rgba(215,178,106,0.12), rgba(255,255,255,0.03));
      box-shadow: var(--shadow);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 20px;
      flex-wrap: wrap;
    }

    .member-banner h2 {
      color: var(--white);
      font-size: clamp(1.7rem, 3vw, 2.5rem);
      line-height: 1.08;
      margin-bottom: 8px;
    }

    .member-banner p {
      color: var(--muted);
      max-width: 760px;
    }

    .hidden-service-field {
      display: none;
    }

    footer {
      padding: 28px 0 48px;
      text-align: center;
      color: var(--soft);
      font-size: 0.92rem;
    }

    @media (max-width: 1180px) {
      .hero-grid,
      .booking-wrap,
      .pricing-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 860px) {
      .nav-wrap {
        flex-direction: column;
        align-items: flex-start;
      }

      .hero-grid {
        padding: 34px 24px;
      }

      .form-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 640px) {
      .container {
        width: min(var(--max), calc(100% - 20px));
      }

      .hero {
        padding-top: 54px;
      }

      .btn {
        width: 100%;
      }

      .hero-actions,
      .nav-actions {
        width: 100%;
      }

      .nav-actions a {
        flex: 1;
      }

      .pricing-card,
      .info-panel,
      .form-panel,
      .member-banner,
      .spotlight-card {
        padding-left: 18px;
        padding-right: 18px;
      }
    }
  </style>
</head>
<body>

  <header class="site-header">
    <div class="container nav-wrap">
      <a href="index.php" class="brand">Doggie Dorian's</a>

      <nav class="nav-links">
        <a href="index.php">Home</a>
        <a href="services.php">Services</a>
        <a href="memberships.php">Memberships</a>
        <a href="book-walk.php" class="active">Book</a>
        <a href="contact.php">Contact</a>
      </nav>

      <div class="nav-actions">
        <?php if ($isLoggedIn): ?>
          <a href="dashboard.php" class="btn btn-soft">Dashboard</a>
        <?php else: ?>
          <a href="login.php" class="btn btn-soft">Member Login</a>
        <?php endif; ?>
        <a href="memberships.php" class="btn btn-gold">View Memberships</a>
      </div>
    </div>
  </header>

  <main>
    <section class="hero">
      <div class="container">
        <div class="hero-card">
          <div class="hero-grid">
            <div class="hero-copy">
              <div class="eyebrow">Public Booking</div>
              <h1>Book premium dog care with a clearer, more tailored request experience.</h1>
              <p>
                Request dog walking, daycare, or boarding without creating an account first. We’ll review your request, confirm availability, and follow up with next steps shortly.
              </p>

              <div class="hero-actions">
                <a href="#booking-form" class="btn btn-gold">Request a Booking</a>
                <a href="memberships.php" class="btn btn-outline">See Member Pricing</a>
              </div>
            </div>

            <div class="hero-side">
              <div class="spotlight-card highlight">
                <span class="spotlight-price">$30</span>
                <strong>30-minute non-member walk</strong>
                <span>Public bookings are available without requiring a membership.</span>
              </div>

              <div class="spotlight-card">
                <span class="spotlight-price">$25</span>
                <strong>Member 30-minute walk rate</strong>
                <span>Members receive preferred pricing and stronger recurring value.</span>
              </div>

              <div class="spotlight-card">
                <span class="spotlight-price">Fast Follow-Up</span>
                <strong>We confirm details directly</strong>
                <span>After your request is submitted, we review availability and reach out to finalize care details.</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <?php if ($isLoggedIn): ?>
      <section style="padding-top: 0;">
        <div class="container">
          <div class="member-banner">
            <div>
              <h2>Already a member?</h2>
              <p>
                You can still use this public request form, but your dashboard is the better place for recurring care, account-specific requests, and member access.
              </p>
            </div>
            <div style="display:flex; gap:12px; flex-wrap:wrap;">
              <a href="dashboard.php" class="btn btn-gold">Go to Dashboard</a>
              <a href="#booking-form" class="btn btn-soft">Use Public Form</a>
            </div>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <section>
      <div class="container">
        <div class="section-head">
          <h2>Public pricing</h2>
          <p>
            These are your non-member booking rates. Clients who join a membership receive preferred pricing and stronger ongoing value.
          </p>
        </div>

        <div class="pricing-grid">
          <article class="pricing-card">
            <h3>Walks</h3>
            <div class="pricing-list">
              <div class="pricing-row"><span>15-minute walk</span><strong>$23</strong></div>
              <div class="pricing-row"><span>20-minute walk</span><strong>$25</strong></div>
              <div class="pricing-row"><span>30-minute walk</span><strong>$30</strong></div>
              <div class="pricing-row"><span>45-minute walk</span><strong>$38</strong></div>
              <div class="pricing-row"><span>60-minute walk</span><strong>$42</strong></div>
            </div>
          </article>

          <article class="pricing-card">
            <h3>Daycare</h3>
            <div class="pricing-list">
              <div class="pricing-row"><span>Small dog</span><strong>$65</strong></div>
              <div class="pricing-row"><span>Medium dog</span><strong>$85</strong></div>
              <div class="pricing-row"><span>Large dog</span><strong>$110</strong></div>
            </div>
          </article>

          <article class="pricing-card">
            <h3>Boarding</h3>
            <div class="pricing-list">
              <div class="pricing-row"><span>Small dog</span><strong>$90 / night</strong></div>
              <div class="pricing-row"><span>Medium dog</span><strong>$110 / night</strong></div>
              <div class="pricing-row"><span>Large dog</span><strong>$120 / night</strong></div>
            </div>
          </article>
        </div>
      </div>
    </section>

    <section id="booking-form">
      <div class="container">
        <div class="section-head">
          <h2>Submit a booking request</h2>
          <p>
            Complete the form below and we’ll review your request, confirm availability, and follow up with next steps. The form adjusts based on the service you select.
          </p>
        </div>

        <div class="booking-wrap">
          <aside class="info-panel">
            <h3>What happens next</h3>
            <p>
              Once your request is submitted, we review the details, check availability, and reach out to confirm the booking. Your request is not considered final until confirmed.
            </p>

            <div class="info-list">
              <div class="info-item">
                <strong>Easy to request</strong>
                <span>No login is required for non-members to request service.</span>
              </div>

              <div class="info-item">
                <strong>Tailored by service type</strong>
                <span>Walks, daycare, and boarding each collect the details most relevant to that service.</span>
              </div>

              <div class="info-item">
                <strong>Premium care standards</strong>
                <span>We prioritize safety, communication, reliability, and personalized attention.</span>
              </div>

              <div class="info-item">
                <strong>Membership path available</strong>
                <span>Recurring clients can move into membership for preferred pricing and added value.</span>
              </div>
            </div>
          </aside>

          <div class="form-panel">
            <h3>Booking request form</h3>
            <p>Please complete the required details and submit your request.</p>

            <?php if ($successMessage !== ''): ?>
              <div class="status-message success"><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if ($errorMessage !== ''): ?>
              <div class="status-message error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <div class="estimate-box" id="estimate-box">
              <strong>Estimated rate</strong>
              <span class="estimate-price" id="estimate-price">Select a service</span>
              <span id="estimate-detail">Choose your service details to see the estimated rate.</span>
            </div>

            <form method="post" action="book-walk.php">
              <div class="form-grid">
                <div class="field">
                  <label for="full_name">Full Name *</label>
                  <input type="text" id="full_name" name="full_name" value="<?php echo old_value('full_name'); ?>" required>
                </div>

                <div class="field">
                  <label for="email">Email *</label>
                  <input type="email" id="email" name="email" value="<?php echo old_value('email'); ?>" required>
                </div>

                <div class="field">
                  <label for="phone">Phone *</label>
                  <input type="text" id="phone" name="phone" value="<?php echo old_value('phone'); ?>" required>
                </div>

                <div class="field">
                  <label for="pet_name">Dog's Name *</label>
                  <input type="text" id="pet_name" name="pet_name" value="<?php echo old_value('pet_name'); ?>" required>
                </div>

                <div class="field">
                  <label for="service_type">Service Type *</label>
                  <select id="service_type" name="service_type" required>
                    <option value="">Select a service</option>
                    <option value="walk" <?php echo (old_value('service_type') === 'walk') ? 'selected' : ''; ?>>Walk</option>
                    <option value="daycare" <?php echo (old_value('service_type') === 'daycare') ? 'selected' : ''; ?>>Daycare</option>
                    <option value="boarding" <?php echo (old_value('service_type') === 'boarding') ? 'selected' : ''; ?>>Boarding</option>
                  </select>
                </div>

                <div class="field">
                  <label for="pet_size">Dog Size *</label>
                  <select id="pet_size" name="pet_size" required>
                    <option value="">Select a size</option>
                    <option value="small" <?php echo (old_value('pet_size') === 'small') ? 'selected' : ''; ?>>Small</option>
                    <option value="medium" <?php echo (old_value('pet_size') === 'medium') ? 'selected' : ''; ?>>Medium</option>
                    <option value="large" <?php echo (old_value('pet_size') === 'large') ? 'selected' : ''; ?>>Large</option>
                  </select>
                </div>

                <div class="field service-walk">
                  <label for="walk_duration">Walk Duration *</label>
                  <select id="walk_duration" name="walk_duration">
                    <option value="">Select walk duration</option>
                    <option value="15" <?php echo (old_value('walk_duration') === '15') ? 'selected' : ''; ?>>15 minutes</option>
                    <option value="20" <?php echo (old_value('walk_duration') === '20') ? 'selected' : ''; ?>>20 minutes</option>
                    <option value="30" <?php echo (old_value('walk_duration') === '30') ? 'selected' : ''; ?>>30 minutes</option>
                    <option value="45" <?php echo (old_value('walk_duration') === '45') ? 'selected' : ''; ?>>45 minutes</option>
                    <option value="60" <?php echo (old_value('walk_duration') === '60') ? 'selected' : ''; ?>>60 minutes</option>
                  </select>
                  <div class="helper">Required for walk requests.</div>
                </div>

                <div class="field service-walk service-daycare">
                  <label for="preferred_date">Date *</label>
                  <input type="date" id="preferred_date" name="preferred_date" value="<?php echo old_value('preferred_date'); ?>">
                </div>

                <div class="field service-walk">
                  <label for="preferred_time">Preferred Walk Time</label>
                  <input type="time" id="preferred_time" name="preferred_time" value="<?php echo old_value('preferred_time'); ?>">
                </div>

                <div class="field service-daycare">
                  <label for="dropoff_time">Daycare Drop-Off Time</label>
                  <input type="time" id="dropoff_time" name="dropoff_time" value="<?php echo old_value('dropoff_time'); ?>">
                </div>

                <div class="field service-daycare">
                  <label for="pickup_time">Daycare Pick-Up Time</label>
                  <input type="time" id="pickup_time" name="pickup_time" value="<?php echo old_value('pickup_time'); ?>">
                </div>

                <div class="field service-boarding">
                  <label for="checkin_date">Boarding Check-In Date *</label>
                  <input type="date" id="checkin_date" name="checkin_date" value="<?php echo old_value('checkin_date'); ?>">
                </div>

                <div class="field service-boarding">
                  <label for="checkout_date">Boarding Check-Out Date *</label>
                  <input type="date" id="checkout_date" name="checkout_date" value="<?php echo old_value('checkout_date'); ?>">
                </div>

                <div class="field service-boarding">
                  <label for="checkin_time">Boarding Check-In Time</label>
                  <input type="time" id="checkin_time" name="checkin_time" value="<?php echo old_value('checkin_time'); ?>">
                </div>

                <div class="field service-boarding">
                  <label for="checkout_time">Boarding Check-Out Time</label>
                  <input type="time" id="checkout_time" name="checkout_time" value="<?php echo old_value('checkout_time'); ?>">
                </div>

                <div class="field full service-daycare service-boarding">
                  <label for="feeding_schedule">Feeding Schedule</label>
                  <input type="text" id="feeding_schedule" name="feeding_schedule" value="<?php echo old_value('feeding_schedule'); ?>" placeholder="Example: Breakfast 8am, Dinner 6pm">
                </div>

                <div class="field full">
                  <label for="notes">Additional Notes</label>
                  <textarea id="notes" name="notes" placeholder="Share anything helpful about routines, behavior, medications, pickup details, access notes, or care preferences."><?php echo old_value('notes'); ?></textarea>
                </div>

                <div class="field full">
                  <button type="submit" class="btn btn-gold">Submit Booking Request</button>
                </div>
              </div>
            </form>
          </div>
        </div>
      </div>
    </section>

    <section style="padding-top: 10px; padding-bottom: 80px;">
      <div class="container">
        <div class="member-banner">
          <div>
            <h2>Want better recurring value?</h2>
            <p>
              Non-members can book anytime here. Clients who need ongoing care can usually get stronger value, preferred walk pricing, and premium perks through a membership.
            </p>
          </div>

          <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <a href="memberships.php" class="btn btn-gold">Explore Memberships</a>
            <a href="services.php" class="btn btn-soft">View Services</a>
          </div>
        </div>
      </div>
    </section>
  </main>

  <footer>
    <div class="container">
      &copy; <?php echo date('Y'); ?> Doggie Dorian's. Luxury dog care with public booking and premium membership options.
    </div>
  </footer>

  <script>
    const serviceType = document.getElementById('service_type');
    const petSize = document.getElementById('pet_size');
    const walkDuration = document.getElementById('walk_duration');

    const preferredDate = document.getElementById('preferred_date');
    const checkinDate = document.getElementById('checkin_date');
    const checkoutDate = document.getElementById('checkout_date');

    const estimatePrice = document.getElementById('estimate-price');
    const estimateDetail = document.getElementById('estimate-detail');

    const walkPrices = {
      '15': 23,
      '20': 25,
      '30': 30,
      '45': 38,
      '60': 42
    };

    const daycarePrices = {
      'small': 65,
      'medium': 85,
      'large': 110
    };

    const boardingPrices = {
      'small': 90,
      'medium': 110,
      'large': 120
    };

    function toggleServiceFields() {
      const selectedService = serviceType.value;

      document.querySelectorAll('.service-walk, .service-daycare, .service-boarding').forEach(field => {
        field.classList.add('hidden-service-field');
      });

      if (selectedService === 'walk') {
        document.querySelectorAll('.service-walk').forEach(field => field.classList.remove('hidden-service-field'));
      }

      if (selectedService === 'daycare') {
        document.querySelectorAll('.service-daycare').forEach(field => field.classList.remove('hidden-service-field'));
      }

      if (selectedService === 'boarding') {
        document.querySelectorAll('.service-boarding').forEach(field => field.classList.remove('hidden-service-field'));
      }

      updateEstimate();
    }

    function updateEstimate() {
      const selectedService = serviceType.value;
      const selectedSize = petSize.value;
      const selectedDuration = walkDuration.value;

      if (selectedService === 'walk') {
        if (selectedDuration && walkPrices[selectedDuration]) {
          estimatePrice.textContent = '$' + walkPrices[selectedDuration];
          estimateDetail.textContent = selectedDuration + '-minute walk estimate for public booking.';
        } else {
          estimatePrice.textContent = 'Select walk duration';
          estimateDetail.textContent = 'Choose a walk duration to see the estimated walk rate.';
        }
        return;
      }

      if (selectedService === 'daycare') {
        if (selectedSize && daycarePrices[selectedSize]) {
          estimatePrice.textContent = '$' + daycarePrices[selectedSize];
          estimateDetail.textContent = 'Estimated daycare rate based on dog size.';
        } else {
          estimatePrice.textContent = 'Select dog size';
          estimateDetail.textContent = 'Choose your dog size to see the estimated daycare rate.';
        }
        return;
      }

      if (selectedService === 'boarding') {
        if (selectedSize && boardingPrices[selectedSize]) {
          estimatePrice.textContent = '$' + boardingPrices[selectedSize] + ' / night';
          estimateDetail.textContent = 'Estimated boarding rate per night based on dog size.';
        } else {
          estimatePrice.textContent = 'Select dog size';
          estimateDetail.textContent = 'Choose your dog size to see the estimated boarding rate.';
        }
        return;
      }

      estimatePrice.textContent = 'Select a service';
      estimateDetail.textContent = 'Choose your service details to see the estimated rate.';
    }

    function setMinDates() {
      const today = new Date().toISOString().split('T')[0];

      if (preferredDate) {
        preferredDate.min = today;
      }

      if (checkinDate) {
        checkinDate.min = today;
      }

      if (checkoutDate) {
        checkoutDate.min = today;
      }

      if (checkinDate && checkoutDate) {
        checkinDate.addEventListener('change', function () {
          checkoutDate.min = checkinDate.value || today;
          if (checkoutDate.value && checkinDate.value && checkoutDate.value < checkinDate.value) {
            checkoutDate.value = checkinDate.value;
          }
        });
      }
    }

    serviceType.addEventListener('change', toggleServiceFields);
    petSize.addEventListener('change', updateEstimate);
    walkDuration.addEventListener('change', updateEstimate);

    setMinDates();
    toggleServiceFields();
    updateEstimate();
  </script>

</body>
</html>