<?php
session_start();

require_once __DIR__ . '/data/config/db.php';
require_once __DIR__ . '/includes/pricing.php';

$isLoggedIn = isset($_SESSION['member_id']);
$userId = $isLoggedIn ? (int) $_SESSION['member_id'] : 0;

$success = '';
$error = '';
$pets = [];
$pricingPreview = null;

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function posted(string $key, string $default = ''): string
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : $default;
}

$serviceType      = posted('service_type', 'walk');
$petId            = (int) ($_POST['pet_id'] ?? 0);
$guestName        = posted('guest_name');
$guestEmail       = posted('guest_email');
$guestPhone       = posted('guest_phone');
$dogName          = posted('dog_name');
$dogSize          = posted('dog_size');
$walkDate         = posted('walk_date');
$walkTime         = posted('walk_time');
$durationMinutes  = (int) ($_POST['duration_minutes'] ?? 30);
$daycareStart     = posted('daycare_start');
$daycareEnd       = posted('daycare_end');
$daycareTime      = posted('daycare_time');
$boardingStart    = posted('boarding_start');
$boardingEnd      = posted('boarding_end');
$boardingTime     = posted('boarding_time');
$feedingSchedule  = posted('feeding_schedule');
$preferredContact = posted('preferred_contact');
$clientNotes      = posted('client_notes');
$accessNotes      = posted('access_notes');

if ($isLoggedIn) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, pet_name, size
            FROM pets
            WHERE user_id = :user_id
            ORDER BY pet_name ASC
        ");
        $stmt->execute([':user_id' => $userId]);
        $pets = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $pets = [];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $serviceType = dd_normalize_service_type($serviceType);

        if ($serviceType === '') {
            throw new InvalidArgumentException('Please choose a valid service.');
        }

        if ($isLoggedIn) {
            if ($petId <= 0) {
                throw new InvalidArgumentException('Please select your dog.');
            }

            $stmt = $pdo->prepare("
                SELECT id, pet_name, size
                FROM pets
                WHERE id = :id AND user_id = :user_id
                LIMIT 1
            ");
            $stmt->execute([
                ':id' => $petId,
                ':user_id' => $userId
            ]);
            $selectedPet = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$selectedPet) {
                throw new InvalidArgumentException('Selected dog was not found.');
            }

            $dogName = trim((string) $selectedPet['pet_name']);
            $dogSize = dd_normalize_dog_size((string) ($selectedPet['size'] ?? ''));

            if ($dogSize === '') {
                throw new InvalidArgumentException('This pet does not have a size saved yet. Please update the pet profile first.');
            }
        } else {
            if ($guestName === '' || $guestEmail === '' || $guestPhone === '') {
                throw new InvalidArgumentException('Please complete your contact information.');
            }

            if ($dogName === '') {
                throw new InvalidArgumentException('Please enter your dog’s name.');
            }

            $dogSize = dd_normalize_dog_size($dogSize);
            if ($dogSize === '') {
                throw new InvalidArgumentException('Please select your dog’s size.');
            }
        }

        if ($serviceType === 'walk') {
            if ($walkDate === '' || $walkTime === '') {
                throw new InvalidArgumentException('Please choose a walk date and time.');
            }

            $pricingPreview = dd_get_service_pricing('walk', $isLoggedIn, [
                'duration_minutes' => $durationMinutes
            ]);

            if ($isLoggedIn) {
                $stmt = $pdo->prepare("
                    INSERT INTO bookings (
                        user_id,
                        pet_id,
                        service_type,
                        service_date,
                        service_time,
                        duration_minutes,
                        status,
                        access_notes,
                        client_notes,
                        price,
                        is_instant_booking,
                        pricing_type,
                        unit_price,
                        discount_label,
                        quantity,
                        end_date
                    ) VALUES (
                        :user_id,
                        :pet_id,
                        :service_type,
                        :service_date,
                        :service_time,
                        :duration_minutes,
                        'pending',
                        :access_notes,
                        :client_notes,
                        :price,
                        0,
                        :pricing_type,
                        :unit_price,
                        :discount_label,
                        :quantity,
                        :end_date
                    )
                ");

                $stmt->execute([
                    ':user_id' => $userId,
                    ':pet_id' => $petId,
                    ':service_type' => 'walk',
                    ':service_date' => $walkDate,
                    ':service_time' => $walkTime,
                    ':duration_minutes' => $durationMinutes,
                    ':access_notes' => $accessNotes,
                    ':client_notes' => $clientNotes,
                    ':price' => $pricingPreview['total_price'],
                    ':pricing_type' => $pricingPreview['pricing_type'],
                    ':unit_price' => $pricingPreview['unit_price'],
                    ':discount_label' => $pricingPreview['discount_label'],
                    ':quantity' => 1,
                    ':end_date' => null
                ]);
            } else {
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
                        'Requested',
                        :pricing_type,
                        :unit_price,
                        :discount_label,
                        :quantity
                    )
                ");

                $stmt->execute([
                    ':full_name' => $guestName,
                    ':phone' => $guestPhone,
                    ':email' => $guestEmail,
                    ':service_type' => 'walk',
                    ':dog_name' => $dogName,
                    ':dog_size' => $dogSize,
                    ':walk_duration' => $durationMinutes,
                    ':preferred_walk_time' => $walkTime,
                    ':date_start' => $walkDate,
                    ':date_end' => $walkDate,
                    ':feeding_schedule' => $feedingSchedule,
                    ':preferred_contact' => $preferredContact !== '' ? $preferredContact : 'phone',
                    ':notes' => $clientNotes,
                    ':estimated_price' => $pricingPreview['total_price'],
                    ':pricing_type' => $pricingPreview['pricing_type'],
                    ':unit_price' => $pricingPreview['unit_price'],
                    ':discount_label' => $pricingPreview['discount_label'],
                    ':quantity' => 1
                ]);
            }

            $success = 'Your walk request has been submitted successfully.';
        } elseif ($serviceType === 'daycare') {
            if ($daycareStart === '' || $daycareEnd === '') {
                throw new InvalidArgumentException('Please choose daycare start and end dates.');
            }

            if ($daycareTime === '') {
                throw new InvalidArgumentException('Please choose a preferred daycare drop-off time.');
            }

            $quantity = dd_calculate_daycare_days($daycareStart, $daycareEnd);

            $pricingPreview = dd_get_service_pricing('daycare', $isLoggedIn, [
                'dog_size' => $dogSize,
                'quantity' => $quantity
            ]);

            if ($isLoggedIn) {
                $stmt = $pdo->prepare("
                    INSERT INTO bookings (
                        user_id,
                        pet_id,
                        service_type,
                        service_date,
                        service_time,
                        duration_minutes,
                        status,
                        access_notes,
                        client_notes,
                        price,
                        is_instant_booking,
                        pricing_type,
                        unit_price,
                        discount_label,
                        quantity,
                        end_date
                    ) VALUES (
                        :user_id,
                        :pet_id,
                        :service_type,
                        :service_date,
                        :service_time,
                        NULL,
                        'pending',
                        :access_notes,
                        :client_notes,
                        :price,
                        0,
                        :pricing_type,
                        :unit_price,
                        :discount_label,
                        :quantity,
                        :end_date
                    )
                ");

                $stmt->execute([
                    ':user_id' => $userId,
                    ':pet_id' => $petId,
                    ':service_type' => 'daycare',
                    ':service_date' => $daycareStart,
                    ':service_time' => $daycareTime,
                    ':access_notes' => $accessNotes,
                    ':client_notes' => $clientNotes,
                    ':price' => $pricingPreview['total_price'],
                    ':pricing_type' => $pricingPreview['pricing_type'],
                    ':unit_price' => $pricingPreview['unit_price'],
                    ':discount_label' => $pricingPreview['discount_label'],
                    ':quantity' => $quantity,
                    ':end_date' => $daycareEnd
                ]);
            } else {
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
                        NULL,
                        :preferred_walk_time,
                        :date_start,
                        :date_end,
                        :feeding_schedule,
                        :preferred_contact,
                        :notes,
                        :estimated_price,
                        'Requested',
                        :pricing_type,
                        :unit_price,
                        :discount_label,
                        :quantity
                    )
                ");

                $stmt->execute([
                    ':full_name' => $guestName,
                    ':phone' => $guestPhone,
                    ':email' => $guestEmail,
                    ':service_type' => 'daycare',
                    ':dog_name' => $dogName,
                    ':dog_size' => $dogSize,
                    ':preferred_walk_time' => $daycareTime,
                    ':date_start' => $daycareStart,
                    ':date_end' => $daycareEnd,
                    ':feeding_schedule' => $feedingSchedule,
                    ':preferred_contact' => $preferredContact !== '' ? $preferredContact : 'phone',
                    ':notes' => $clientNotes,
                    ':estimated_price' => $pricingPreview['total_price'],
                    ':pricing_type' => $pricingPreview['pricing_type'],
                    ':unit_price' => $pricingPreview['unit_price'],
                    ':discount_label' => $pricingPreview['discount_label'],
                    ':quantity' => $quantity
                ]);
            }

            $success = 'Your daycare request has been submitted successfully.';
        } elseif ($serviceType === 'boarding') {
            if ($boardingStart === '' || $boardingEnd === '') {
                throw new InvalidArgumentException('Please choose boarding check-in and check-out dates.');
            }

            if ($boardingTime === '') {
                throw new InvalidArgumentException('Please choose a preferred check-in time.');
            }

            $quantity = dd_calculate_boarding_nights($boardingStart, $boardingEnd);

            $pricingPreview = dd_get_service_pricing('boarding', $isLoggedIn, [
                'dog_size' => $dogSize,
                'quantity' => $quantity
            ]);

            if ($isLoggedIn) {
                $stmt = $pdo->prepare("
                    INSERT INTO bookings (
                        user_id,
                        pet_id,
                        service_type,
                        service_date,
                        service_time,
                        duration_minutes,
                        status,
                        access_notes,
                        client_notes,
                        price,
                        is_instant_booking,
                        pricing_type,
                        unit_price,
                        discount_label,
                        quantity,
                        end_date
                    ) VALUES (
                        :user_id,
                        :pet_id,
                        :service_type,
                        :service_date,
                        :service_time,
                        NULL,
                        'pending',
                        :access_notes,
                        :client_notes,
                        :price,
                        0,
                        :pricing_type,
                        :unit_price,
                        :discount_label,
                        :quantity,
                        :end_date
                    )
                ");

                $stmt->execute([
                    ':user_id' => $userId,
                    ':pet_id' => $petId,
                    ':service_type' => 'boarding',
                    ':service_date' => $boardingStart,
                    ':service_time' => $boardingTime,
                    ':access_notes' => $accessNotes,
                    ':client_notes' => $clientNotes,
                    ':price' => $pricingPreview['total_price'],
                    ':pricing_type' => $pricingPreview['pricing_type'],
                    ':unit_price' => $pricingPreview['unit_price'],
                    ':discount_label' => $pricingPreview['discount_label'],
                    ':quantity' => $quantity,
                    ':end_date' => $boardingEnd
                ]);
            } else {
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
                        NULL,
                        :preferred_walk_time,
                        :date_start,
                        :date_end,
                        :feeding_schedule,
                        :preferred_contact,
                        :notes,
                        :estimated_price,
                        'Requested',
                        :pricing_type,
                        :unit_price,
                        :discount_label,
                        :quantity
                    )
                ");

                $stmt->execute([
                    ':full_name' => $guestName,
                    ':phone' => $guestPhone,
                    ':email' => $guestEmail,
                    ':service_type' => 'boarding',
                    ':dog_name' => $dogName,
                    ':dog_size' => $dogSize,
                    ':preferred_walk_time' => $boardingTime,
                    ':date_start' => $boardingStart,
                    ':date_end' => $boardingEnd,
                    ':feeding_schedule' => $feedingSchedule,
                    ':preferred_contact' => $preferredContact !== '' ? $preferredContact : 'phone',
                    ':notes' => $clientNotes,
                    ':estimated_price' => $pricingPreview['total_price'],
                    ':pricing_type' => $pricingPreview['pricing_type'],
                    ':unit_price' => $pricingPreview['unit_price'],
                    ':discount_label' => $pricingPreview['discount_label'],
                    ':quantity' => $quantity
                ]);
            }

            $success = 'Your boarding request has been submitted successfully.';
        }

        if ($success !== '') {
            $serviceType      = 'walk';
            $petId            = 0;
            $guestName        = '';
            $guestEmail       = '';
            $guestPhone       = '';
            $dogName          = '';
            $dogSize          = '';
            $walkDate         = '';
            $walkTime         = '';
            $durationMinutes  = 30;
            $daycareStart     = '';
            $daycareEnd       = '';
            $daycareTime      = '';
            $boardingStart    = '';
            $boardingEnd      = '';
            $boardingTime     = '';
            $feedingSchedule  = '';
            $preferredContact = '';
            $clientNotes      = '';
            $accessNotes      = '';
            $pricingPreview   = null;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($pricingPreview === null) {
    try {
        if ($serviceType === 'walk') {
            $pricingPreview = dd_get_service_pricing('walk', $isLoggedIn, [
                'duration_minutes' => $durationMinutes
            ]);
        } elseif ($serviceType === 'daycare' && $dogSize !== '' && $daycareStart !== '' && $daycareEnd !== '') {
            $quantity = dd_calculate_daycare_days($daycareStart, $daycareEnd);
            $pricingPreview = dd_get_service_pricing('daycare', $isLoggedIn, [
                'dog_size' => $dogSize,
                'quantity' => $quantity
            ]);
        } elseif ($serviceType === 'boarding' && $dogSize !== '' && $boardingStart !== '' && $boardingEnd !== '') {
            $quantity = dd_calculate_boarding_nights($boardingStart, $boardingEnd);
            $pricingPreview = dd_get_service_pricing('boarding', $isLoggedIn, [
                'dog_size' => $dogSize,
                'quantity' => $quantity
            ]);
        }
    } catch (Throwable $e) {
        $pricingPreview = null;
    }
}

$jsPets = [];
foreach ($pets as $pet) {
    $jsPets[] = [
        'id' => (int) $pet['id'],
        'pet_name' => (string) $pet['pet_name'],
        'size' => dd_normalize_dog_size((string) ($pet['size'] ?? ''))
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Book Premium Care | Doggie Dorian's</title>
  <meta name="description" content="Book luxury dog walks, premium daycare, and boutique boarding with Doggie Dorian’s." />
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg: #09090c;
      --bg-2: #101016;
      --panel: rgba(255,255,255,0.05);
      --border: rgba(255,255,255,0.1);
      --text: #f7f3ec;
      --muted: #cbc3b7;
      --soft: #9d9486;
      --gold: #d7b56d;
      --gold-2: #f2dba9;
      --danger: #ff9696;
      --success: #9de3b1;
      --shadow: 0 24px 70px rgba(0,0,0,0.45);
      --max: 1220px;
      --radius: 28px;
    }

    body {
      font-family: "Inter", "Helvetica Neue", Arial, sans-serif;
      background:
        radial-gradient(circle at top left, rgba(215,181,109,0.16), transparent 24%),
        radial-gradient(circle at top right, rgba(242,219,169,0.08), transparent 20%),
        linear-gradient(180deg, #09090c 0%, #101016 34%, #09090c 100%);
      color: var(--text);
      line-height: 1.6;
      overflow-x: hidden;
    }

    a { color: inherit; text-decoration: none; }

    .container {
      width: min(var(--max), calc(100% - 28px));
      margin: 0 auto;
    }

    .topbar {
      position: sticky;
      top: 0;
      z-index: 1000;
      backdrop-filter: blur(18px);
      background: rgba(8, 8, 11, 0.72);
      border-bottom: 1px solid rgba(255,255,255,.08);
    }

    .nav {
      min-height: 84px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 18px;
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .brand-mark {
      width: 48px;
      height: 48px;
      border-radius: 15px;
      display: grid;
      place-items: center;
      background: linear-gradient(135deg, rgba(242,219,169,.24), rgba(184,141,68,.72));
      border: 1px solid rgba(255,255,255,.12);
      color: #fff6e5;
      font-weight: 800;
      box-shadow: inset 0 1px 0 rgba(255,255,255,.16), 0 10px 30px rgba(0,0,0,.24);
    }

    .brand-title {
      font-size: 1.08rem;
      font-weight: 800;
      letter-spacing: -0.03em;
    }

    .brand-subtitle {
      font-size: 0.78rem;
      color: var(--soft);
      text-transform: uppercase;
      letter-spacing: 0.1em;
    }

    .nav-links {
      list-style: none;
      display: flex;
      align-items: center;
      gap: 26px;
      color: var(--muted);
      font-size: 0.98rem;
    }

    .nav-links a:hover { color: var(--text); }

    .nav-actions {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 50px;
      padding: 0 22px;
      border-radius: 999px;
      border: 1px solid transparent;
      font-size: 0.96rem;
      font-weight: 700;
      cursor: pointer;
      transition: .18s ease;
      white-space: nowrap;
    }

    .btn:hover { transform: translateY(-1px); }

    .btn-primary {
      background: linear-gradient(135deg, var(--gold-2), var(--gold));
      color: #171105;
      box-shadow: 0 16px 38px rgba(215,181,109,.3);
    }

    .btn-secondary {
      background: rgba(255,255,255,.05);
      border-color: rgba(255,255,255,.14);
      color: var(--text);
    }

    .btn-ghost {
      background: transparent;
      border-color: rgba(255,255,255,.1);
      color: var(--muted);
    }

    .hero {
      padding: 40px 0 28px;
    }

    .hero-grid {
      display: grid;
      grid-template-columns: 1.1fr .9fr;
      gap: 22px;
      align-items: stretch;
    }

    .panel {
      border-radius: var(--radius);
      padding: 28px;
      background:
        linear-gradient(180deg, rgba(255,255,255,.07), rgba(255,255,255,.03)),
        linear-gradient(160deg, #15151b, #101015);
      border: 1px solid rgba(255,255,255,.08);
      box-shadow: var(--shadow);
    }

    .eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 10px 16px;
      border-radius: 999px;
      border: 1px solid rgba(215,181,109,.24);
      background: rgba(215,181,109,.08);
      color: var(--gold-2);
      font-size: 0.82rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      margin-bottom: 16px;
    }

    .eyebrow::before {
      content: "";
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: var(--gold);
      box-shadow: 0 0 14px rgba(215,181,109,.95);
    }

    h1 {
      font-size: clamp(2.5rem, 5vw, 4.8rem);
      line-height: .95;
      letter-spacing: -.06em;
      margin-bottom: 16px;
    }

    h1 span,
    h2,
    h3,
    .price-highlight {
      color: var(--gold-2);
    }

    .lead {
      color: var(--muted);
      font-size: 1.05rem;
      max-width: 720px;
      margin-bottom: 24px;
    }

    .hero-badges {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
    }

    .badge {
      padding: 10px 14px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,.1);
      background: rgba(255,255,255,.04);
      color: var(--text);
      font-size: .9rem;
      font-weight: 600;
    }

    .page-grid {
      display: grid;
      grid-template-columns: 1fr 370px;
      gap: 22px;
      padding-bottom: 64px;
    }

    .section-title {
      font-size: 1.75rem;
      margin-bottom: 8px;
      letter-spacing: -.03em;
    }

    .section-copy {
      color: var(--muted);
      margin-bottom: 22px;
    }

    .alert {
      padding: 14px 16px;
      border-radius: 16px;
      margin-bottom: 18px;
      border: 1px solid rgba(255,255,255,.08);
    }

    .alert-success {
      background: rgba(157,227,177,.08);
      color: var(--success);
      border-color: rgba(157,227,177,.2);
    }

    .alert-error {
      background: rgba(255,150,150,.08);
      color: var(--danger);
      border-color: rgba(255,150,150,.2);
    }

    form {
      display: grid;
      gap: 18px;
    }

    .grid-2 {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 16px;
    }

    .grid-3 {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 16px;
    }

    label {
      display: block;
      font-size: 0.92rem;
      font-weight: 700;
      margin-bottom: 8px;
      color: var(--text);
    }

    input, select, textarea {
      width: 100%;
      padding: 14px 15px;
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,.1);
      background: rgba(255,255,255,.04);
      color: var(--text);
      font: inherit;
      outline: none;
    }

    input:focus, select:focus, textarea:focus {
      border-color: rgba(215,181,109,.4);
      box-shadow: 0 0 0 3px rgba(215,181,109,.08);
    }

    textarea {
      min-height: 120px;
      resize: vertical;
    }

    .service-block {
      display: none;
    }

    .service-block.active {
      display: block;
    }

    .sidebar-card h3 {
      font-size: 1.35rem;
      margin-bottom: 10px;
    }

    .sidebar-card p {
      color: var(--muted);
      margin-bottom: 14px;
    }

    .price-box {
      padding: 18px;
      border-radius: 18px;
      background: rgba(255,255,255,.04);
      border: 1px solid rgba(255,255,255,.08);
      margin-bottom: 14px;
    }

    .price-box strong {
      display: block;
      color: var(--gold-2);
      font-size: 1.05rem;
      margin-bottom: 6px;
    }

    .price-box span {
      color: var(--muted);
      display: block;
      font-size: .94rem;
    }

    .list {
      list-style: none;
      display: grid;
      gap: 10px;
      margin-top: 18px;
    }

    .list li {
      padding-left: 18px;
      position: relative;
      color: var(--text);
    }

    .list li::before {
      content: "";
      position: absolute;
      left: 0;
      top: 11px;
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: var(--gold);
    }

    .footer {
      padding: 42px 0 54px;
      color: var(--soft);
    }

    .footer-wrap {
      border-top: 1px solid rgba(255,255,255,.08);
      padding-top: 26px;
      display: flex;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 18px;
    }

    @media (max-width: 1100px) {
      .hero-grid,
      .page-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 920px) {
      .nav {
        flex-wrap: wrap;
        padding: 16px 0;
      }

      .nav-links {
        width: 100%;
        justify-content: center;
        flex-wrap: wrap;
        gap: 16px;
      }

      .grid-2,
      .grid-3 {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 640px) {
      .nav-actions {
        width: 100%;
        justify-content: space-between;
      }

      .hide-mobile {
        display: none;
      }

      .panel {
        border-radius: 20px;
      }
    }
  </style>
</head>
<body>

<header class="topbar">
  <div class="container nav">
    <a href="index.php" class="brand" aria-label="Doggie Dorian's home">
      <div class="brand-mark">DD</div>
      <div>
        <div class="brand-title">Doggie Dorian’s</div>
        <div class="brand-subtitle">Luxury Pet Care</div>
      </div>
    </a>

    <ul class="nav-links">
      <li><a href="index.php">Home</a></li>
      <li><a href="services.php">Services</a></li>
      <li><a href="pricing.php">Pricing</a></li>
      <li><a href="memberships.php">Memberships</a></li>
      <li><a href="book-walk.php">Book</a></li>
      <li><a href="contact.php">Contact</a></li>
    </ul>

    <div class="nav-actions">
      <?php if ($isLoggedIn): ?>
        <a href="dashboard.php" class="btn btn-secondary">Member Dashboard</a>
      <?php else: ?>
        <a href="login.php" class="btn btn-ghost hide-mobile">Member Login</a>
        <a href="memberships.php" class="btn btn-primary">Become a Member</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<main>
  <section class="hero">
    <div class="container hero-grid">
      <div class="panel">
        <span class="eyebrow">Book Premium Care</span>
        <h1>Walks, daycare, and boarding <span>in one place.</span></h1>
        <p class="lead">
          Submit a booking request for private walks, premium daycare, or boutique boarding. Member pricing and qualifying discounts are applied automatically.
        </p>

        <div class="hero-badges">
          <span class="badge">Member Savings</span>
          <span class="badge">Daycare 3+ Day Discount</span>
          <span class="badge">Boarding 5+ Night Discount</span>
          <span class="badge">Upper East Side Priority</span>
        </div>
      </div>

      <div class="panel sidebar-card">
        <h3>How pricing works</h3>
        <p>Non-members can book directly. Logged-in members automatically receive member pricing.</p>

        <div class="price-box">
          <strong>Walks</strong>
          <span>Member pricing applies to every duration.</span>
        </div>

        <div class="price-box">
          <strong>Daycare</strong>
          <span>Member discount applies at 3 or more booked days.</span>
        </div>

        <div class="price-box">
          <strong>Boarding</strong>
          <span>Member discount applies at 5 or more booked nights.</span>
        </div>
      </div>
    </div>
  </section>

  <section class="container page-grid">
    <div class="panel">
      <h2 class="section-title">Request a Booking</h2>
      <p class="section-copy">Choose a service, complete the details, and submit your request.</p>

      <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?php echo h($success); ?></div>
      <?php endif; ?>

      <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?php echo h($error); ?></div>
      <?php endif; ?>

      <form method="post" action="">
        <div>
          <label for="service_type">Service Type</label>
          <select name="service_type" id="service_type" required>
            <option value="walk" <?php echo $serviceType === 'walk' ? 'selected' : ''; ?>>Walk</option>
            <option value="daycare" <?php echo $serviceType === 'daycare' ? 'selected' : ''; ?>>Daycare</option>
            <option value="boarding" <?php echo $serviceType === 'boarding' ? 'selected' : ''; ?>>Boarding</option>
          </select>
        </div>

        <?php if ($isLoggedIn && !empty($pets)): ?>
          <div>
            <label for="pet_id">Select Your Dog</label>
            <select name="pet_id" id="pet_id" required>
              <option value="">Choose a dog</option>
              <?php foreach ($pets as $pet): ?>
                <option value="<?php echo (int) $pet['id']; ?>" <?php echo $petId === (int) $pet['id'] ? 'selected' : ''; ?>>
                  <?php echo h($pet['pet_name']); ?><?php echo !empty($pet['size']) ? ' (' . h($pet['size']) . ')' : ''; ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php else: ?>
          <div class="grid-3">
            <div>
              <label for="guest_name">Your Name</label>
              <input type="text" name="guest_name" id="guest_name" value="<?php echo h($guestName); ?>" <?php echo !$isLoggedIn ? 'required' : ''; ?>>
            </div>
            <div>
              <label for="guest_email">Email</label>
              <input type="email" name="guest_email" id="guest_email" value="<?php echo h($guestEmail); ?>" <?php echo !$isLoggedIn ? 'required' : ''; ?>>
            </div>
            <div>
              <label for="guest_phone">Phone</label>
              <input type="text" name="guest_phone" id="guest_phone" value="<?php echo h($guestPhone); ?>" <?php echo !$isLoggedIn ? 'required' : ''; ?>>
            </div>
          </div>

          <div class="grid-2">
            <div>
              <label for="dog_name">Dog Name</label>
              <input type="text" name="dog_name" id="dog_name" value="<?php echo h($dogName); ?>" <?php echo !$isLoggedIn ? 'required' : ''; ?>>
            </div>
            <div>
              <label for="dog_size">Dog Size</label>
              <select name="dog_size" id="dog_size" <?php echo !$isLoggedIn ? 'required' : ''; ?>>
                <option value="">Choose size</option>
                <option value="small" <?php echo $dogSize === 'small' ? 'selected' : ''; ?>>Small</option>
                <option value="medium" <?php echo $dogSize === 'medium' ? 'selected' : ''; ?>>Medium</option>
                <option value="large" <?php echo $dogSize === 'large' ? 'selected' : ''; ?>>Large</option>
              </select>
            </div>
          </div>
        <?php endif; ?>

        <div id="walk-block" class="service-block <?php echo $serviceType === 'walk' ? 'active' : ''; ?>">
          <div class="grid-3">
            <div>
              <label for="walk_date">Walk Date</label>
              <input type="date" name="walk_date" id="walk_date" value="<?php echo h($walkDate); ?>">
            </div>
            <div>
              <label for="walk_time">Walk Time</label>
              <input type="time" name="walk_time" id="walk_time" value="<?php echo h($walkTime); ?>">
            </div>
            <div>
              <label for="duration_minutes">Duration</label>
              <select name="duration_minutes" id="duration_minutes">
                <option value="15" <?php echo $durationMinutes === 15 ? 'selected' : ''; ?>>15 Minutes</option>
                <option value="20" <?php echo $durationMinutes === 20 ? 'selected' : ''; ?>>20 Minutes</option>
                <option value="30" <?php echo $durationMinutes === 30 ? 'selected' : ''; ?>>30 Minutes</option>
                <option value="45" <?php echo $durationMinutes === 45 ? 'selected' : ''; ?>>45 Minutes</option>
                <option value="60" <?php echo $durationMinutes === 60 ? 'selected' : ''; ?>>60 Minutes</option>
              </select>
            </div>
          </div>
        </div>

        <div id="daycare-block" class="service-block <?php echo $serviceType === 'daycare' ? 'active' : ''; ?>">
          <div class="grid-3">
            <div>
              <label for="daycare_start">Start Date</label>
              <input type="date" name="daycare_start" id="daycare_start" value="<?php echo h($daycareStart); ?>">
            </div>
            <div>
              <label for="daycare_end">End Date</label>
              <input type="date" name="daycare_end" id="daycare_end" value="<?php echo h($daycareEnd); ?>">
            </div>
            <div>
              <label for="daycare_time">Preferred Drop-Off Time</label>
              <input type="time" name="daycare_time" id="daycare_time" value="<?php echo h($daycareTime); ?>">
            </div>
          </div>
        </div>

        <div id="boarding-block" class="service-block <?php echo $serviceType === 'boarding' ? 'active' : ''; ?>">
          <div class="grid-3">
            <div>
              <label for="boarding_start">Check-In Date</label>
              <input type="date" name="boarding_start" id="boarding_start" value="<?php echo h($boardingStart); ?>">
            </div>
            <div>
              <label for="boarding_end">Check-Out Date</label>
              <input type="date" name="boarding_end" id="boarding_end" value="<?php echo h($boardingEnd); ?>">
            </div>
            <div>
              <label for="boarding_time">Preferred Check-In Time</label>
              <input type="time" name="boarding_time" id="boarding_time" value="<?php echo h($boardingTime); ?>">
            </div>
          </div>
        </div>

        <div class="grid-2">
          <div>
            <label for="feeding_schedule">Feeding Schedule</label>
            <input type="text" name="feeding_schedule" id="feeding_schedule" value="<?php echo h($feedingSchedule); ?>" placeholder="Example: Breakfast 8am, Dinner 6pm">
          </div>
          <div>
            <label for="preferred_contact">Preferred Contact Method</label>
            <select name="preferred_contact" id="preferred_contact">
              <option value="">Choose method</option>
              <option value="phone" <?php echo $preferredContact === 'phone' ? 'selected' : ''; ?>>Phone</option>
              <option value="email" <?php echo $preferredContact === 'email' ? 'selected' : ''; ?>>Email</option>
              <option value="text" <?php echo $preferredContact === 'text' ? 'selected' : ''; ?>>Text</option>
            </select>
          </div>
        </div>

        <?php if ($isLoggedIn): ?>
          <div>
            <label for="access_notes">Access Notes</label>
            <textarea name="access_notes" id="access_notes" placeholder="Building access, door instructions, concierge notes, or anything helpful..."><?php echo h($accessNotes); ?></textarea>
          </div>
        <?php endif; ?>

        <div>
          <label for="client_notes">Care Notes</label>
          <textarea name="client_notes" id="client_notes" placeholder="Anything important about your dog, routine, temperament, medications, or preferences..."><?php echo h($clientNotes); ?></textarea>
        </div>

        <div>
          <button type="submit" class="btn btn-primary">Submit Booking Request</button>
        </div>
      </form>
    </div>

    <aside class="panel sidebar-card">
      <h3>Booking Summary</h3>
      <p>Your expected pricing updates as you choose service details.</p>

      <div class="price-box">
        <strong>Pricing Type</strong>
        <span id="summary-pricing-type"><?php echo $pricingPreview ? h(ucwords(str_replace('_', ' ', $pricingPreview['pricing_type']))) : '—'; ?></span>
      </div>

      <div class="price-box">
        <strong>Unit Price</strong>
        <span id="summary-unit-price">
          <?php echo $pricingPreview ? h(dd_format_money((float) $pricingPreview['unit_price'])) . ' per ' . h($pricingPreview['unit_label']) : 'Choose enough details to calculate'; ?>
        </span>
      </div>

      <div class="price-box">
        <strong>Quantity</strong>
        <span id="summary-quantity">
          <?php
          if ($pricingPreview) {
              echo h((string) $pricingPreview['quantity']) . ' ' . h($pricingPreview['unit_label']) . ($pricingPreview['quantity'] !== 1 ? 's' : '');
          } else {
              echo '—';
          }
          ?>
        </span>
      </div>

      <div class="price-box">
        <strong>Total Estimate</strong>
        <span class="price-highlight" id="summary-total"><?php echo $pricingPreview ? h(dd_format_money((float) $pricingPreview['total_price'])) : '—'; ?></span>
      </div>

      <div class="price-box">
        <strong>Pricing Rule</strong>
        <span id="summary-rule"><?php echo $pricingPreview ? h(ucwords(str_replace('_', ' ', $pricingPreview['discount_label']))) : '—'; ?></span>
      </div>

      <ul class="list">
        <li>Members automatically receive member pricing when logged in</li>
        <li>Daycare member discount applies at 3 or more days</li>
        <li>Boarding member discount applies at 5 or more nights</li>
        <li>Final booking approval remains subject to availability</li>
      </ul>
    </aside>
  </section>
</main>

<footer class="footer">
  <div class="container footer-wrap">
    <div>
      <strong style="color: var(--text);">Doggie Dorian’s</strong><br />
      Luxury dog walking, premium daycare & boutique boarding in Manhattan.
    </div>
    <div>
      <a href="services.php">Services</a> &nbsp;•&nbsp;
      <a href="pricing.php">Pricing</a> &nbsp;•&nbsp;
      <a href="memberships.php">Memberships</a> &nbsp;•&nbsp;
      <a href="contact.php">Contact</a>
    </div>
  </div>
</footer>

<script>
  const IS_MEMBER = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
  const PETS = <?php echo json_encode($jsPets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

  const WALK_PRICES = {
    non_member: {15: 23, 20: 25, 30: 30, 45: 38, 60: 42},
    member: {15: 20, 20: 22, 30: 25, 45: 32, 60: 35}
  };

  const DAYCARE_PRICES = {
    non_member: {small: 65, medium: 85, large: 110},
    member: {small: 55, medium: 70, large: 90},
    member_3plus: {small: 50, medium: 65, large: 82}
  };

  const BOARDING_PRICES = {
    non_member: {small: 90, medium: 110, large: 120},
    member: {small: 75, medium: 90, large: 100},
    member_5plus: {small: 68, medium: 82, large: 92}
  };

  const serviceTypeSelect = document.getElementById('service_type');
  const walkBlock = document.getElementById('walk-block');
  const daycareBlock = document.getElementById('daycare-block');
  const boardingBlock = document.getElementById('boarding-block');

  const petIdField = document.getElementById('pet_id');
  const dogSizeField = document.getElementById('dog_size');
  const durationField = document.getElementById('duration_minutes');
  const daycareStartField = document.getElementById('daycare_start');
  const daycareEndField = document.getElementById('daycare_end');
  const boardingStartField = document.getElementById('boarding_start');
  const boardingEndField = document.getElementById('boarding_end');

  const summaryPricingType = document.getElementById('summary-pricing-type');
  const summaryUnitPrice = document.getElementById('summary-unit-price');
  const summaryQuantity = document.getElementById('summary-quantity');
  const summaryTotal = document.getElementById('summary-total');
  const summaryRule = document.getElementById('summary-rule');

  function formatMoney(amount) {
    return '$' + Number(amount).toFixed(2);
  }

  function titleize(value) {
    return String(value)
      .replace(/_/g, ' ')
      .replace(/\b\w/g, function(char) {
        return char.toUpperCase();
      });
  }

  function getSelectedDogSize() {
    if (IS_MEMBER) {
      if (!petIdField || !petIdField.value) return '';
      const petId = Number(petIdField.value);
      const pet = PETS.find(function(item) {
        return Number(item.id) === petId;
      });
      return pet && pet.size ? pet.size : '';
    }

    return dogSizeField ? dogSizeField.value : '';
  }

  function calculateInclusiveDays(startDate, endDate) {
    if (!startDate || !endDate) return 0;

    const start = new Date(startDate + 'T00:00:00');
    const end = new Date(endDate + 'T00:00:00');

    if (isNaN(start.getTime()) || isNaN(end.getTime()) || end < start) {
      return 0;
    }

    const diffMs = end.getTime() - start.getTime();
    const diffDays = Math.floor(diffMs / 86400000);

    return diffDays + 1;
  }

  function calculateNights(checkIn, checkOut) {
    if (!checkIn || !checkOut) return 0;

    const start = new Date(checkIn + 'T00:00:00');
    const end = new Date(checkOut + 'T00:00:00');

    if (isNaN(start.getTime()) || isNaN(end.getTime()) || end <= start) {
      return 0;
    }

    const diffMs = end.getTime() - start.getTime();
    return Math.floor(diffMs / 86400000);
  }

  function renderEmptySummary(message) {
    summaryPricingType.textContent = IS_MEMBER ? 'Member' : 'Non Member';
    summaryUnitPrice.textContent = message || 'Choose enough details to calculate';
    summaryQuantity.textContent = '—';
    summaryTotal.textContent = '—';
    summaryRule.textContent = '—';
  }

  function updateSummary() {
    const serviceType = serviceTypeSelect.value;
    const dogSize = getSelectedDogSize();
    const pricingType = IS_MEMBER ? 'member' : 'non_member';

    if (serviceType === 'walk') {
      const duration = Number(durationField ? durationField.value : 0);
      const unitPrice = WALK_PRICES[pricingType] && WALK_PRICES[pricingType][duration]
        ? WALK_PRICES[pricingType][duration]
        : 0;

      if (!unitPrice) {
        renderEmptySummary('Choose a valid walk duration');
        return;
      }

      summaryPricingType.textContent = titleize(pricingType);
      summaryUnitPrice.textContent = formatMoney(unitPrice) + ' per walk';
      summaryQuantity.textContent = '1 walk';
      summaryTotal.textContent = formatMoney(unitPrice);
      summaryRule.textContent = titleize(IS_MEMBER ? 'standard_member' : 'standard_non_member');
      return;
    }

    if (serviceType === 'daycare') {
      const days = calculateInclusiveDays(
        daycareStartField ? daycareStartField.value : '',
        daycareEndField ? daycareEndField.value : ''
      );

      if (!dogSize) {
        renderEmptySummary('Choose a dog size to calculate daycare pricing');
        return;
      }

      if (days < 1) {
        renderEmptySummary('Choose valid daycare dates');
        return;
      }

      let unitPrice = 0;
      let rule = IS_MEMBER ? 'standard_member' : 'standard_non_member';

      if (IS_MEMBER && days >= 3) {
        unitPrice = DAYCARE_PRICES.member_3plus[dogSize] || 0;
        rule = 'member_3plus_daycare';
      } else if (IS_MEMBER) {
        unitPrice = DAYCARE_PRICES.member[dogSize] || 0;
      } else {
        unitPrice = DAYCARE_PRICES.non_member[dogSize] || 0;
      }

      if (!unitPrice) {
        renderEmptySummary('Choose valid daycare details');
        return;
      }

      summaryPricingType.textContent = titleize(pricingType);
      summaryUnitPrice.textContent = formatMoney(unitPrice) + ' per day';
      summaryQuantity.textContent = days + ' day' + (days !== 1 ? 's' : '');
      summaryTotal.textContent = formatMoney(unitPrice * days);
      summaryRule.textContent = titleize(rule);
      return;
    }

    if (serviceType === 'boarding') {
      const nights = calculateNights(
        boardingStartField ? boardingStartField.value : '',
        boardingEndField ? boardingEndField.value : ''
      );

      if (!dogSize) {
        renderEmptySummary('Choose a dog size to calculate boarding pricing');
        return;
      }

      if (nights < 1) {
        renderEmptySummary('Choose valid boarding dates');
        return;
      }

      let unitPrice = 0;
      let rule = IS_MEMBER ? 'standard_member' : 'standard_non_member';

      if (IS_MEMBER && nights >= 5) {
        unitPrice = BOARDING_PRICES.member_5plus[dogSize] || 0;
        rule = 'member_5plus_boarding';
      } else if (IS_MEMBER) {
        unitPrice = BOARDING_PRICES.member[dogSize] || 0;
      } else {
        unitPrice = BOARDING_PRICES.non_member[dogSize] || 0;
      }

      if (!unitPrice) {
        renderEmptySummary('Choose valid boarding details');
        return;
      }

      summaryPricingType.textContent = titleize(pricingType);
      summaryUnitPrice.textContent = formatMoney(unitPrice) + ' per night';
      summaryQuantity.textContent = nights + ' night' + (nights !== 1 ? 's' : '');
      summaryTotal.textContent = formatMoney(unitPrice * nights);
      summaryRule.textContent = titleize(rule);
      return;
    }

    renderEmptySummary();
  }

  function toggleBlocks() {
    const value = serviceTypeSelect.value;

    walkBlock.classList.remove('active');
    daycareBlock.classList.remove('active');
    boardingBlock.classList.remove('active');

    if (value === 'walk') {
      walkBlock.classList.add('active');
    } else if (value === 'daycare') {
      daycareBlock.classList.add('active');
    } else if (value === 'boarding') {
      boardingBlock.classList.add('active');
    }

    updateSummary();
  }

  const watchedFields = [
    serviceTypeSelect,
    petIdField,
    dogSizeField,
    durationField,
    daycareStartField,
    daycareEndField,
    boardingStartField,
    boardingEndField,
    document.getElementById('walk_date'),
    document.getElementById('walk_time'),
    document.getElementById('daycare_time'),
    document.getElementById('boarding_time')
  ];

  watchedFields.forEach(function(field) {
    if (!field) return;
    field.addEventListener('change', updateSummary);
    field.addEventListener('input', updateSummary);
  });

  serviceTypeSelect.addEventListener('change', toggleBlocks);

  toggleBlocks();
  updateSummary();
</script>

</body>
</html>