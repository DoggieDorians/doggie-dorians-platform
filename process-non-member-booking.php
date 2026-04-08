<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/database/setup.php';
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
        'sitting', 'in-home sitting', 'in_home_sitting' => 'sitting', // ✅ ADDED
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

/*
|--------------------------------------------------------------------------
| Collect form data
|--------------------------------------------------------------------------
*/
$formData = $_POST;

$errors = [];

$serviceType = normalize_service_type($formData['service_type'] ?? '');
$dogSize = normalize_dog_size($formData['dog_size'] ?? '');

$dateStart = trim((string) ($formData['date_start'] ?? ''));
$dateEnd = trim((string) ($formData['date_end'] ?? ''));

$walkDuration = (int) ($formData['walk_duration'] ?? 0);
$dropInHours = (int) ($formData['drop_in_hours'] ?? 1);
$dropInAddWalk = isset($formData['drop_in_add_walk']);
$daycareProvideFood = isset($formData['daycare_provide_food']);
$daycareExtraWalks = (int) ($formData['daycare_extra_walks'] ?? 0);

// ✅ NEW
$sittingExtraWalks = (int) ($formData['sitting_extra_walks'] ?? 0);

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/
if (empty($formData['full_name'])) $errors[] = 'Full name required.';
if (empty($formData['email'])) $errors[] = 'Email required.';
if (empty($formData['phone'])) $errors[] = 'Phone required.';
if (empty($formData['dog_name'])) $errors[] = 'Dog name required.';
if ($serviceType === '') $errors[] = 'Invalid service type.';

if ($dateStart === '') {
    $errors[] = 'Start date required.';
}

if ($serviceType === 'boarding' && $dateEnd === '') {
    $errors[] = 'Check-out date required for boarding.';
}

if ($serviceType === 'boarding' && $dogSize === '') {
    $errors[] = 'Dog size required for boarding.';
}

if ($serviceType === 'walk' && !in_array($walkDuration, [15,20,30,45,60])) {
    $errors[] = 'Invalid walk duration.';
}

if ($errors) {
    redirect_with_flash('error', implode(' ', $errors), $formData);
}

/*
|--------------------------------------------------------------------------
| Pricing logic (UPDATED)
|--------------------------------------------------------------------------
*/
try {
    if ($serviceType === 'walk') {
        $pricing = dd_get_service_pricing('walk', false, [
            'duration_minutes' => $walkDuration
        ]);
    }

    elseif ($serviceType === 'drop_in') {
        $pricing = dd_get_service_pricing('drop_in', false, [
            'quantity' => $dropInHours,
            'add_walk' => $dropInAddWalk
        ]);
    }

    elseif ($serviceType === 'daycare') {
        $pricing = dd_get_service_pricing('daycare', false, [
            'provide_food' => $daycareProvideFood,
            'extra_walks' => $daycareExtraWalks
        ]);
    }

    // ✅ NEW BLOCK (CRITICAL)
    elseif ($serviceType === 'sitting') {
        $pricing = dd_get_service_pricing('sitting', false, [
            'extra_walks' => $sittingExtraWalks
        ]);
    }

    elseif ($serviceType === 'boarding') {
        $nights = dd_calculate_boarding_nights($dateStart, $dateEnd);

        $pricing = dd_get_service_pricing('boarding', false, [
            'dog_size' => $dogSize,
            'quantity' => $nights
        ]);
    }

    else {
        throw new Exception('Invalid service type.');
    }

} catch (Throwable $e) {
    redirect_with_flash('error', $e->getMessage(), $formData);
}

/*
|--------------------------------------------------------------------------
| Save booking
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    INSERT INTO public_booking_requests (
        full_name, email, phone,
        dog_name, dog_size,
        service_type,
        date_start, date_end,
        walk_duration,
        estimated_price,
        unit_price,
        quantity,
        pricing_type,
        discount_label,
        status
    ) VALUES (
        :full_name, :email, :phone,
        :dog_name, :dog_size,
        :service_type,
        :date_start, :date_end,
        :walk_duration,
        :estimated_price,
        :unit_price,
        :quantity,
        :pricing_type,
        :discount_label,
        'pending'
    )
");

$stmt->execute([
    ':full_name' => $formData['full_name'],
    ':email' => $formData['email'],
    ':phone' => $formData['phone'],
    ':dog_name' => $formData['dog_name'],
    ':dog_size' => $dogSize ?: null,
    ':service_type' => $serviceType,
    ':date_start' => $dateStart,
    ':date_end' => $dateEnd ?: null,
    ':walk_duration' => $walkDuration ?: null,
    ':estimated_price' => $pricing['total_price'],
    ':unit_price' => $pricing['unit_price'],
    ':quantity' => $pricing['quantity'],
    ':pricing_type' => $pricing['pricing_type'],
    ':discount_label' => $pricing['discount_label'],
]);

$_SESSION['nonmember_flash_type'] = 'success';
$_SESSION['nonmember_flash_message'] = 'Booking submitted successfully.';

header('Location: non-member-booking.php');
exit;