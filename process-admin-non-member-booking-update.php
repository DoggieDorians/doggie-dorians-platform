<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security-headers.php';

session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/mailer.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection is not available.';
    exit;
}

function redirectTo($url)
{
    header('Location: ' . $url);
    exit;
}

function isAdmin()
{
    if (!empty($_SESSION['is_admin'])) {
        return true;
    }

    return isset($_SESSION['role']) && strtolower((string) $_SESSION['role']) === 'admin';
}

if (!isAdmin()) {
    redirectTo('admin-login.php');
}

function safeExecute(PDOStatement $stmt, array $params = array())
{
    try {
        return $stmt->execute($params);
    } catch (Throwable $e) {
        return false;
    } catch (Exception $e) {
        return false;
    }
}

function safeFetchOne(PDO $pdo, $sql, array $params = array())
{
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt->execute($params)) {
            return null;
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    } catch (Throwable $e) {
        return null;
    } catch (Exception $e) {
        return null;
    }
}

function normalizeServiceType($type)
{
    $type = strtolower(trim((string) $type));

    if ($type === '') {
        return 'service';
    }
    if (strpos($type, 'walk') !== false) {
        return 'walk';
    }
    if (strpos($type, 'board') !== false) {
        return 'boarding';
    }
    if (strpos($type, 'daycare') !== false || strpos($type, 'day care') !== false) {
        return 'daycare';
    }
    if (strpos($type, 'sit') !== false) {
        return 'sitting';
    }
    if (strpos($type, 'drop') !== false) {
        return 'drop-in';
    }

    return $type;
}

function serviceDisplayName($type)
{
    $type = normalizeServiceType($type);

    if ($type === 'drop-in') {
        return 'Drop-In';
    }

    return ucfirst($type);
}

function formatDateDisplay($date)
{
    $date = trim((string) $date);
    if ($date === '') {
        return 'TBD';
    }

    $ts = strtotime($date);
    return $ts !== false ? date('F j, Y', $ts) : $date;
}

function formatTimeDisplay($time)
{
    $time = trim((string) $time);
    if ($time === '') {
        return 'TBD';
    }

    $ts = strtotime($time);
    return $ts !== false ? date('g:i A', $ts) : $time;
}

function allowedReturnUrl($url)
{
    $url = trim((string) $url);
    if ($url === '') {
        return 'admin-bookings.php';
    }

    if (strpos($url, "\n") !== false || strpos($url, "\r") !== false) {
        return 'admin-bookings.php';
    }

    if (preg_match('/^https?:\/\//i', $url)) {
        return 'admin-bookings.php';
    }

    if (strpos($url, '/') === 0) {
        return ltrim($url, '/');
    }

    return $url;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectTo('admin-bookings.php');
}

$action = isset($_POST['action']) ? trim((string) $_POST['action']) : '';
$returnUrl = allowedReturnUrl(isset($_POST['return_url']) ? (string) $_POST['return_url'] : 'admin-bookings.php');

if ($action !== 'send_email') {
    $_SESSION['admin_bookings_flash_type'] = 'error';
    $_SESSION['admin_bookings_flash'] = 'Invalid action.';
    redirectTo($returnUrl);
}

$bookingId = isset($_POST['id']) ? (int) $_POST['id'] : 0;

if ($bookingId <= 0) {
    $_SESSION['admin_bookings_flash_type'] = 'error';
    $_SESSION['admin_bookings_flash'] = 'Invalid booking selected.';
    redirectTo($returnUrl);
}

$booking = safeFetchOne(
    $pdo,
    'SELECT * FROM non_member_bookings WHERE id = :id LIMIT 1',
    array(':id' => $bookingId)
);

if (!$booking) {
    $_SESSION['admin_bookings_flash_type'] = 'error';
    $_SESSION['admin_bookings_flash'] = 'Booking not found.';
    redirectTo($returnUrl);
}

$clientName = trim((string) ($booking['full_name'] ?? $booking['name'] ?? 'Client'));
$clientEmail = trim((string) ($booking['email'] ?? ''));
$serviceType = serviceDisplayName((string) ($booking['service_type'] ?? $booking['service'] ?? 'service'));
$serviceDate = formatDateDisplay((string) ($booking['service_date'] ?? $booking['date'] ?? $booking['date_start'] ?? ''));
$serviceTime = formatTimeDisplay((string) ($booking['service_time'] ?? $booking['time'] ?? $booking['preferred_walk_time'] ?? ''));
$petName = trim((string) ($booking['pet_name'] ?? $booking['dog_name'] ?? ''));
$notes = trim((string) ($booking['notes'] ?? ''));
$phone = trim((string) ($booking['phone'] ?? ''));

if ($clientEmail === '') {
    $_SESSION['admin_bookings_flash_type'] = 'error';
    $_SESSION['admin_bookings_flash'] = 'This booking does not have an email address.';
    redirectTo($returnUrl);
}

$subject = 'Doggie Dorian’s Booking Request Received';

$htmlBody = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Doggie Dorian’s Booking Request Received</title>
</head>
<body style="margin:0;padding:0;background:#09090d;color:#f4f1ea;font-family:Inter,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;">
    <div style="max-width:680px;margin:0 auto;padding:32px 20px;">
        <div style="background:linear-gradient(180deg,rgba(255,255,255,0.065),rgba(255,255,255,0.03));border:1px solid rgba(255,255,255,0.08);border-radius:24px;padding:28px;">
            <div style="color:#c6b28b;text-transform:uppercase;letter-spacing:.14em;font-size:12px;font-weight:800;margin-bottom:10px;">
                Doggie Dorian’s
            </div>

            <h1 style="margin:0 0 14px;font-size:28px;line-height:1.15;color:#f4f1ea;">
                We received your booking request
            </h1>

            <p style="margin:0 0 16px;line-height:1.7;color:rgba(244,241,234,0.82);">
                Hi ' . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . ',
            </p>

            <p style="margin:0 0 16px;line-height:1.7;color:rgba(244,241,234,0.82);">
                Thank you for reaching out to Doggie Dorian’s. This email confirms that we received your booking request and our team will review it shortly.
            </p>

            <div style="margin:22px 0;padding:18px;border-radius:18px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.06);">
                <div style="margin-bottom:8px;"><strong style="color:#f3e5c7;">Service:</strong> ' . htmlspecialchars($serviceType, ENT_QUOTES, 'UTF-8') . '</div>
                <div style="margin-bottom:8px;"><strong style="color:#f3e5c7;">Date:</strong> ' . htmlspecialchars($serviceDate, ENT_QUOTES, 'UTF-8') . '</div>
                <div style="margin-bottom:8px;"><strong style="color:#f3e5c7;">Time:</strong> ' . htmlspecialchars($serviceTime, ENT_QUOTES, 'UTF-8') . '</div>
                <div style="margin-bottom:8px;"><strong style="color:#f3e5c7;">Pet:</strong> ' . htmlspecialchars($petName !== '' ? $petName : '—', ENT_QUOTES, 'UTF-8') . '</div>
                <div style="margin-bottom:8px;"><strong style="color:#f3e5c7;">Phone:</strong> ' . htmlspecialchars($phone !== '' ? $phone : '—', ENT_QUOTES, 'UTF-8') . '</div>';

if ($notes !== '') {
    $htmlBody .= '
                <div style="margin-top:12px;"><strong style="color:#f3e5c7;">Notes:</strong><br>' . nl2br(htmlspecialchars($notes, ENT_QUOTES, 'UTF-8')) . '</div>';
}

$htmlBody .= '
            </div>

            <p style="margin:0 0 16px;line-height:1.7;color:rgba(244,241,234,0.82);">
                If we need any additional details, we will follow up with you directly.
            </p>

            <p style="margin:0;line-height:1.7;color:rgba(244,241,234,0.82);">
                — Doggie Dorian’s
            </p>
        </div>
    </div>
</body>
</html>';

$textBody = "Doggie Dorian's Booking Request Received\n\n"
    . "Hi " . $clientName . ",\n\n"
    . "Thank you for reaching out to Doggie Dorian's. This email confirms that we received your booking request and our team will review it shortly.\n\n"
    . "Service: " . $serviceType . "\n"
    . "Date: " . $serviceDate . "\n"
    . "Time: " . $serviceTime . "\n"
    . "Pet: " . ($petName !== '' ? $petName : '—') . "\n"
    . "Phone: " . ($phone !== '' ? $phone : '—') . "\n";

if ($notes !== '') {
    $textBody .= "Notes: " . $notes . "\n";
}

$textBody .= "\nIf we need any additional details, we will follow up with you directly.\n\n— Doggie Dorian's";

$emailResult = dd_send_email(
    $clientEmail,
    $clientName !== '' ? $clientName : 'Client',
    $subject,
    $htmlBody,
    $textBody
);

if (!$emailResult['success']) {
    $_SESSION['admin_bookings_flash_type'] = 'error';
    $_SESSION['admin_bookings_flash'] = 'Email could not be sent from the server. ' . ($emailResult['error'] ?? 'Unknown SMTP error.');
    redirectTo($returnUrl);
}

$statusUpdated = true;

try {
    $stmt = $pdo->prepare('UPDATE non_member_bookings SET status = CASE WHEN status = :current_status THEN :new_status ELSE status END WHERE id = :id');
    $statusUpdated = safeExecute(
        $stmt,
        array(
            ':current_status' => 'new',
            ':new_status' => 'reviewed',
            ':id' => $bookingId
        )
    );
} catch (Throwable $e) {
    $statusUpdated = false;
} catch (Exception $e) {
    $statusUpdated = false;
}

$_SESSION['admin_bookings_flash_type'] = 'success';

if ($statusUpdated) {
    $_SESSION['admin_bookings_flash'] = 'Email sent successfully to ' . $clientEmail . '.';
} else {
    $_SESSION['admin_bookings_flash'] = 'Email sent successfully to ' . $clientEmail . '. Status was not changed.';
}

redirectTo($returnUrl);