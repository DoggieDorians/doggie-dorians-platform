<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/pricing.php';

function redirect_with_flash(string $type, string $message, array $formData = []): void
{
    $_SESSION['nonmember_flash_type'] = $type;
    $_SESSION['nonmember_flash_message'] = $message;
    $_SESSION['nonmember_form_data'] = $formData;

    header('Location: non-member-booking.php');
    exit;
}

function normalize_service_type(string $value): string
{
    $value = strtolower(trim($value));

    return match ($value) {
        'walk', 'walks' => 'walk',
        'daycare', 'day care' => 'daycare',
        'boarding', 'board' => 'boarding',
        'drop-in', 'drop in', 'drop_in' => 'drop_in',
        'sitting', 'in-home sitting', 'in_home_sitting' => 'sitting',
        default => '',
    };
}

function normalize_dog_size(string $value): string
{
    $value = strtolower(trim($value));

    return match ($value) {
        'small', 'small dog' => 'small',
        'medium', 'medium dog' => 'medium',
        'large', 'large dog' => 'large',
        default => '',
    };
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: non-member-booking.php');
    exit;
}

$formData = $_POST;
$errors = [];

$serviceType = normalize_service_type((string) ($formData['service_type'] ?? ''));
$dogSize = normalize_dog_size((string) ($formData['dog_size'] ?? ''));

$dateStart = trim((string) ($formData['date_start'] ?? ''));
$dateEnd = trim((string) ($formData['date_end'] ?? ''));

$walkDuration = (int) ($formData['walk_duration'] ?? 0);
$dropInHours = (int) ($formData['drop_in_hours'] ?? 1);
$dropInAddWalk = isset($formData['drop_in_add_walk']);
$daycareProvideFood = isset($formData['daycare_provide_food']);
$daycareExtraWalks = (int) ($formData['daycare_extra_walks'] ?? 0);
$sittingExtraWalks = (int) ($formData['sitting_extra_walks'] ?? 0);

$fullName = trim((string) ($formData['full_name'] ?? ''));
$email = trim((string) ($formData['email'] ?? ''));
$phone = trim((string) ($formData['phone'] ?? ''));
$dogName = trim((string) ($formData['dog_name'] ?? ''));
$preferredWalkTime = trim((string) ($formData['preferred_walk_time'] ?? ''));
$feedingSchedule = trim((string) ($formData['feeding_schedule'] ?? ''));
$preferredContact = trim((string) ($formData['preferred_contact'] ?? ''));
$notes = trim((string) ($formData['notes'] ?? ''));

if ($fullName === '') {
    $errors[] = 'Full name required.';
}
if ($email === '') {
    $errors[] = 'Email required.';
}
if ($phone === '') {
    $errors[] = 'Phone required.';
}
if ($dogName === '') {
    $errors[] = 'Dog name required.';
}
if ($serviceType === '') {
    $errors[] = 'Invalid service type.';
}
if ($dateStart === '') {
    $errors[] = 'Start date required.';
}

if ($serviceType === 'boarding' && $dateEnd === '') {
    $errors[] = 'Check-out date required for boarding.';
}

if ($serviceType === 'boarding' && $dogSize === '') {
    $errors[] = 'Dog size required for boarding.';
}

if ($serviceType === 'walk' && !in_array($walkDuration, [15, 20, 30, 45, 60], true)) {
    $errors[] = 'Invalid walk duration.';
}

if ($errors) {
    redirect_with_flash('error', implode(' ', $errors), $formData);
}

try {
    if ($serviceType === 'walk') {
        $pricing = dd_get_service_pricing('walk', false, [
            'duration_minutes' => $walkDuration,
        ]);
    } elseif ($serviceType === 'drop_in') {
        $pricing = dd_get_service_pricing('drop_in', false, [
            'quantity' => $dropInHours,
            'add_walk' => $dropInAddWalk,
        ]);
    } elseif ($serviceType === 'daycare') {
        $pricing = dd_get_service_pricing('daycare', false, [
            'provide_food' => $daycareProvideFood,
            'extra_walks' => $daycareExtraWalks,
        ]);
    } elseif ($serviceType === 'sitting') {
        $pricing = dd_get_service_pricing('sitting', false, [
            'extra_walks' => $sittingExtraWalks,
        ]);
    } elseif ($serviceType === 'boarding') {
        $nights = dd_calculate_boarding_nights($dateStart, $dateEnd);

        $pricing = dd_get_service_pricing('boarding', false, [
            'dog_size' => $dogSize,
            'quantity' => $nights,
        ]);
    } else {
        throw new Exception('Invalid service type.');
    }
} catch (Throwable $e) {
    redirect_with_flash('error', $e->getMessage(), $formData);
}

try {
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
            status,
            pricing_type,
            unit_price,
            discount_label,
            quantity
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
            :status,
            :pricing_type,
            :unit_price,
            :discount_label,
            :quantity
        )
    ");

    $stmt->execute([
        ':full_name' => $fullName,
        ':phone' => $phone,
        ':email' => $email,
        ':service_type' => $serviceType,
        ':dog_name' => $dogName,
        ':dog_size' => $dogSize !== '' ? $dogSize : null,
        ':walk_duration' => $walkDuration > 0 ? $walkDuration : null,
        ':preferred_walk_time' => $preferredWalkTime !== '' ? $preferredWalkTime : null,
        ':date_start' => $dateStart,
        ':date_end' => $dateEnd !== '' ? $dateEnd : null,
        ':feeding_schedule' => $feedingSchedule !== '' ? $feedingSchedule : null,
        ':preferred_contact' => $preferredContact !== '' ? $preferredContact : null,
        ':notes' => $notes !== '' ? $notes : null,
        ':estimated_price' => (float) $pricing['total_price'],
        ':status' => 'Requested',
        ':pricing_type' => (string) $pricing['pricing_type'],
        ':unit_price' => (float) $pricing['unit_price'],
        ':discount_label' => (string) $pricing['discount_label'],
        ':quantity' => (int) $pricing['quantity'],
    ]);
} catch (Throwable $e) {
    redirect_with_flash('error', 'Unable to save booking right now. Please try again.', $formData);
}

$_SESSION['nonmember_flash_type'] = 'success';
$_SESSION['nonmember_flash_message'] = 'Booking submitted successfully.';

header('Location: non-member-booking.php');
exit;