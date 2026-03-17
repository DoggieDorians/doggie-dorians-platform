<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: non-member-booking.php');
    exit;
}

$dbFile = __DIR__ . '/data/members.sqlite';
$dbDir = dirname($dbFile);

if (!is_dir($dbDir)) {
    mkdir($dbDir, 0777, true);
}

$allowedServiceTypes = ['Walk', 'Daycare', 'Boarding'];
$allowedDogSizes = ['', 'Small', 'Medium', 'Large'];
$allowedWalkDurations = ['', '15', '20', '30', '45', '60'];
$allowedPreferredWalkTimes = ['', 'Early Morning', 'Morning', 'Midday', 'Afternoon', 'Evening'];
$allowedFeedingSchedules = ['', 'Once Daily', 'Twice Daily', 'Three Times Daily', 'Custom Schedule'];
$allowedPreferredContact = ['', 'Phone', 'Text', 'Email'];

$fullName = trim($_POST['full_name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$serviceType = trim($_POST['service_type'] ?? '');
$dogName = trim($_POST['dog_name'] ?? '');
$dogSize = trim($_POST['dog_size'] ?? '');
$walkDuration = trim($_POST['walk_duration'] ?? '');
$preferredWalkTime = trim($_POST['preferred_walk_time'] ?? '');
$dateStart = trim($_POST['date_start'] ?? '');
$dateEnd = trim($_POST['date_end'] ?? '');
$feedingSchedule = trim($_POST['feeding_schedule'] ?? '');
$preferredContact = trim($_POST['preferred_contact'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$estimatedPrice = trim($_POST['estimated_price'] ?? '');

$_SESSION['nonmember_form_data'] = [
    'full_name' => $fullName,
    'phone' => $phone,
    'email' => $email,
    'service_type' => $serviceType,
    'dog_name' => $dogName,
    'dog_size' => $dogSize,
    'walk_duration' => $walkDuration,
    'preferred_walk_time' => $preferredWalkTime,
    'date_start' => $dateStart,
    'date_end' => $dateEnd,
    'feeding_schedule' => $feedingSchedule,
    'preferred_contact' => $preferredContact,
    'notes' => $notes,
    'estimated_price' => $estimatedPrice,
];

if ($fullName === '' || $email === '' || $serviceType === '' || $dogName === '' || $dateStart === '') {
    $_SESSION['nonmember_flash_type'] = 'error';
    $_SESSION['nonmember_flash_message'] = 'Please complete all required booking fields.';
    header('Location: non-member-booking.php#non-member-booking-form');
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['nonmember_flash_type'] = 'error';
    $_SESSION['nonmember_flash_message'] = 'Please enter a valid email address.';
    header('Location: non-member-booking.php#non-member-booking-form');
    exit;
}

if (!in_array($serviceType, $allowedServiceTypes, true)) {
    $_SESSION['nonmember_flash_type'] = 'error';
    $_SESSION['nonmember_flash_message'] = 'Please choose a valid service type.';
    header('Location: non-member-booking.php#non-member-booking-form');
    exit;
}

if (!in_array($dogSize, $allowedDogSizes, true)) {
    $_SESSION['nonmember_flash_type'] = 'error';
    $_SESSION['nonmember_flash_message'] = 'Please choose a valid dog size.';
    header('Location: non-member-booking.php#non-member-booking-form');
    exit;
}

if (!in_array($walkDuration, $allowedWalkDurations, true)) {
    $_SESSION['nonmember_flash_type'] = 'error';
    $_SESSION['nonmember_flash_message'] = 'Please choose a valid walk duration.';
    header('Location: non-member-booking.php#non-member-booking-form');
    exit;
}

if (!in_array($preferredWalkTime, $allowedPreferredWalkTimes, true)) {
    $_SESSION['nonmember_flash_type'] = 'error';
    $_SESSION['nonmember_flash_message'] = 'Please choose a valid preferred walk time.';
    header('Location: non-member-booking.php#non-member-booking-form');
    exit;
}

if (!in_array($feedingSchedule, $allowedFeedingSchedules, true)) {
    $_SESSION['nonmember_flash_type'] = 'error';
    $_SESSION['nonmember_flash_message'] = 'Please choose a valid feeding schedule.';
    header('Location: non-member-booking.php#non-member-booking-form');
    exit;
}

if (!in_array($preferredContact, $allowedPreferredContact, true)) {
    $_SESSION['nonmember_flash_type'] = 'error';
    $_SESSION['nonmember_flash_message'] = 'Please choose a valid contact method.';
    header('Location: non-member-booking.php#non-member-booking-form');
    exit;
}

if ($serviceType === 'Walk' && $walkDuration === '') {
    $_SESSION['nonmember_flash_type'] = 'error';
    $_SESSION['nonmember_flash_message'] = 'Please choose a walk duration for walk bookings.';
    header('Location: non-member-booking.php#non-member-booking-form');
    exit;
}

if (($serviceType === 'Daycare' || $serviceType === 'Boarding') && $dogSize === '') {
    $_SESSION['nonmember_flash_type'] = 'error';
    $_SESSION['nonmember_flash_message'] = 'Please choose a dog size for daycare or boarding.';
    header('Location: non-member-booking.php#non-member-booking-form');
    exit;
}

if ($serviceType === 'Boarding' && $dateEnd === '') {
    $_SESSION['nonmember_flash_type'] = 'error';
    $_SESSION['nonmember_flash_message'] = 'Please provide an end date for boarding.';
    header('Location: non-member-booking.php#non-member-booking-form');
    exit;
}

$calculatedPrice = 0.00;

if ($serviceType === 'Walk') {
    $walkPrices = [
        '15' => 23,
        '20' => 25,
        '30' => 30,
        '45' => 38,
        '60' => 42
    ];
    $calculatedPrice = $walkPrices[$walkDuration] ?? 0;
}

if ($serviceType === 'Daycare') {
    $daycarePrices = [
        'Small' => 65,
        'Medium' => 85,
        'Large' => 110
    ];
    $calculatedPrice = $daycarePrices[$dogSize] ?? 0;
}

if ($serviceType === 'Boarding') {
    $boardingPrices = [
        'Small' => 90,
        'Medium' => 110,
        'Large' => 120
    ];
    $calculatedPrice = $boardingPrices[$dogSize] ?? 0;
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS non_member_bookings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            phone TEXT,
            email TEXT NOT NULL,
            service_type TEXT NOT NULL,
            dog_name TEXT NOT NULL,
            dog_size TEXT,
            walk_duration INTEGER,
            preferred_walk_time TEXT,
            date_start TEXT NOT NULL,
            date_end TEXT,
            feeding_schedule TEXT,
            preferred_contact TEXT,
            notes TEXT,
            estimated_price REAL,
            status TEXT NOT NULL DEFAULT 'New',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $stmt = $pdo->prepare("
        INSERT INTO non_member_bookings (
            full_name,
            phone,
            email,
            service_type,
            dog_name,
            dog_size,
            walk_duration,
            preferred_walk_time,
            date_start,
            date_end,
            feeding_schedule,
            preferred_contact,
            notes,
            estimated_price,
            status
        ) VALUES (
            :full_name,
            :phone,
            :email,
            :service_type,
            :dog_name,
            :dog_size,
            :walk_duration,
            :preferred_walk_time,
            :date_start,
            :date_end,
            :feeding_schedule,
            :preferred_contact,
            :notes,
            :estimated_price,
            'New'
        )
    ");

    $stmt->execute([
        ':full_name' => $fullName,
        ':phone' => $phone,
        ':email' => $email,
        ':service_type' => $serviceType,
        ':dog_name' => $dogName,
        ':dog_size' => $dogSize !== '' ? $dogSize : null,
        ':walk_duration' => $walkDuration !== '' ? (int)$walkDuration : null,
        ':preferred_walk_time' => $preferredWalkTime !== '' ? $preferredWalkTime : null,
        ':date_start' => $dateStart,
        ':date_end' => $dateEnd !== '' ? $dateEnd : null,
        ':feeding_schedule' => $feedingSchedule !== '' ? $feedingSchedule : null,
        ':preferred_contact' => $preferredContact !== '' ? $preferredContact : null,
        ':notes' => $notes !== '' ? $notes : null,
        ':estimated_price' => $calculatedPrice > 0 ? $calculatedPrice : null,
    ]);

    unset($_SESSION['nonmember_form_data']);
    $_SESSION['nonmember_flash_type'] = 'success';
    $_SESSION['nonmember_flash_message'] = 'Your non-member booking request has been submitted successfully.';
} catch (Throwable $e) {
    $_SESSION['nonmember_flash_type'] = 'error';
    $_SESSION['nonmember_flash_message'] = 'There was a problem saving your booking request. Please try again.';
}

header('Location: non-member-booking.php#non-member-booking-form');
exit;