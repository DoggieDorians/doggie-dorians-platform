<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/member_config.php';
require_once __DIR__ . '/includes/stripe-config.php';
require_once __DIR__ . '/vendor/autoload.php';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money_fmt(float $amount): string
{
    return '$' . number_format($amount, 2);
}

function redirect_to(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function current_member_id_safe(?PDO $pdo): int
{
    if (!$pdo instanceof PDO || !function_exists('currentMember')) {
        return 0;
    }

    try {
        $member = currentMember($pdo);
        return (int) ($member['id'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    } catch (Exception $e) {
        return 0;
    }
}

function normalize_mode(string $value): string
{
    $value = strtolower(trim($value));

    return match ($value) {
        'custom_plan' => 'custom_plan',
        'service_overage' => 'service_overage',
        'non_member' => 'non_member',
        'membership' => 'membership',
        default => '',
    };
}

function service_label_from_type(string $serviceType): string
{
    $serviceType = strtolower(trim($serviceType));

    return match ($serviceType) {
        'walk', 'walks' => 'Walk',
        'drop_in', 'drop-in', 'dropin', 'drop in' => 'Drop-In',
        'daycare', 'day care' => 'Daycare',
        'boarding', 'board' => 'Boarding',
        'sitting', 'pet sitting', 'in-home sitting', 'in_home_sitting' => 'Pet Sitting',
        default => 'Service',
    };
}

function payment_status_is_paid(string $value): bool
{
    return strtolower(trim($value)) === 'paid';
}

function getTableColumns(PDO $pdo, string $table): array
{
    static $cache = array();

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
        $columns = array();

        if ($stmt) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($row['name'])) {
                    $columns[] = (string) $row['name'];
                }
            }
        }

        $cache[$table] = $columns;
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = array();
        return $cache[$table];
    } catch (Exception $e) {
        $cache[$table] = array();
        return $cache[$table];
    }
}

function first_existing_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function safeFetchOne(PDO $pdo, string $sql, array $params = array()): ?array
{
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt->execute($params)) {
            return null;
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    } catch (Exception $e) {
        return null;
    }
}

function find_non_member_payment_record(PDO $pdo, int $requestId): ?array
{
    if ($requestId <= 0) {
        return null;
    }

    $tables = array(
        array(
            'table' => 'non_member_bookings',
            'id_candidates' => array('id'),
            'status_candidates' => array('payment_status', 'status'),
        ),
        array(
            'table' => 'public_booking_requests',
            'id_candidates' => array('id', 'request_id'),
            'status_candidates' => array('payment_status', 'status'),
        ),
    );

    foreach ($tables as $config) {
        $table = (string) $config['table'];
        $columns = getTableColumns($pdo, $table);

        if (empty($columns)) {
            continue;
        }

        $idColumn = first_existing_column($columns, $config['id_candidates']);
        $statusColumn = first_existing_column($columns, $config['status_candidates']);

        if ($idColumn === null || $statusColumn === null) {
            continue;
        }

        $row = safeFetchOne(
            $pdo,
            "SELECT * FROM {$table} WHERE {$idColumn} = :id LIMIT 1",
            array(':id' => $requestId)
        );

        if ($row !== null) {
            return array(
                'table' => $table,
                'status_column' => $statusColumn,
                'row' => $row,
            );
        }
    }

    return null;
}

function current_request_uri(): string
{
    $uri = trim((string) ($_SERVER['REQUEST_URI'] ?? 'payment-success.php'));
    return $uri !== '' ? $uri : 'payment-success.php';
}

$pdoInstance = (isset($pdo) && $pdo instanceof PDO) ? $pdo : null;
$sessionId = trim((string) ($_GET['session_id'] ?? ''));
$stripeKey = trim((string) dd_stripe_secret_key());

$viewState = 'error';
$statusBadgeClass = 'error';
$statusBadgeLabel = 'Verification Issue';

$errorMessage = 'We could not verify this payment yet. Please contact support if this persists.';
$mode = normalize_mode((string) ($_GET['mode'] ?? ''));

$pageTitle = 'Payment Verification';
$headline = 'We couldn’t confirm this payment yet';
$bodyText = 'There was a problem verifying the Stripe session for this payment.';

$amountPaid = 0.00;
$itemLabel = 'Purchase';
$itemName = 'Payment';

$primaryHref = 'index.php';
$primaryLabel = 'Return Home';
$secondaryHref = 'index.php';
$secondaryLabel = 'Go Back';

$topLinks = array(
    array('href' => 'index.php', 'label' => 'Home'),
    array('href' => 'contact.php', 'label' => 'Contact'),
);

if ($sessionId === '') {
    $errorMessage = 'Invalid payment session.';
} elseif ($stripeKey === '') {
    error_log('Stripe key missing in payment-success.php');
    $errorMessage = 'Payment system configuration error.';
} else {
    try {
        \Stripe\Stripe::setApiKey($stripeKey);

        $session = \Stripe\Checkout\Session::retrieve($sessionId);

        if (!$session) {
            throw new RuntimeException('Stripe session not found.');
        }

        $paymentStatus = (string) ($session->payment_status ?? '');
        $amountPaidCents = (int) ($session->amount_total ?? 0);
        $metadata = $session->metadata ?? new stdClass();

        if ($mode === '') {
            $mode = normalize_mode((string) ($metadata->mode ?? ''));
        }

        if ($mode === '' && (($metadata->ledger_action ?? '') === 'membership_signup')) {
            $mode = 'membership';
        }

        if ($mode === '') {
            throw new RuntimeException('Invalid Stripe session mode.');
        }

        if ($paymentStatus !== 'paid') {
            throw new RuntimeException('Stripe has not marked this payment as paid.');
        }

        $amountPaid = $amountPaidCents > 0 ? ($amountPaidCents / 100) : 0.00;

        /*
        |--------------------------------------------------------------------------
        | Membership Success Fallback
        |--------------------------------------------------------------------------
        */
        if ($mode === 'membership') {
            $memberId = current_member_id_safe($pdoInstance);
            $planName = trim((string) ($metadata->plan_name ?? 'Founder Membership'));

            $viewState = 'pending';
            $statusBadgeClass = 'pending';
            $statusBadgeLabel = 'Payment Received';
            $pageTitle = 'Membership Payment Received';
            $headline = 'Your membership payment was received';
            $bodyText = 'Stripe received your membership payment. Your account is being finalized and your membership should appear shortly.';
            $itemLabel = 'Membership';
            $itemName = $planName !== '' ? $planName : 'Founder Membership';
            $primaryHref = current_request_uri();
            $primaryLabel = 'Refresh Page';
            $secondaryHref = $memberId > 0 ? 'dashboard.php' : 'memberships.php';
            $secondaryLabel = $memberId > 0 ? 'Go to Dashboard' : 'Return to Memberships';
            $topLinks = $memberId > 0
                ? array(
                    array('href' => 'dashboard.php', 'label' => 'Dashboard'),
                    array('href' => 'memberships.php', 'label' => 'Memberships'),
                )
                : array(
                    array('href' => 'memberships.php', 'label' => 'Memberships'),
                    array('href' => 'contact.php', 'label' => 'Contact'),
                );

            $errorMessage = 'Membership payment was received, but final confirmation is still in progress.';
        }

        /*
        |--------------------------------------------------------------------------
        | Custom Plan Success
        |--------------------------------------------------------------------------
        */
        if ($mode === 'custom_plan') {
            if (!$pdoInstance instanceof PDO) {
                throw new RuntimeException('Database connection is not available for payment verification.');
            }

            $memberId = current_member_id_safe($pdoInstance);

            if ($memberId <= 0) {
                redirect_to('login.php');
            }

            $planId = (int) ($metadata->custom_plan_id ?? 0);
            $stripeMemberId = (int) ($metadata->member_id ?? 0);

            if ($planId <= 0 || $stripeMemberId <= 0) {
                throw new RuntimeException('Invalid Stripe session metadata.');
            }

            if ($stripeMemberId !== $memberId) {
                throw new RuntimeException('This payment does not belong to the signed-in member.');
            }

            $plan = safeFetchOne(
                $pdoInstance,
                "
                SELECT *
                FROM custom_plans
                WHERE id = :id
                  AND member_id = :member_id
                LIMIT 1
                ",
                array(
                    ':id' => $planId,
                    ':member_id' => $memberId,
                )
            );

            if (!$plan) {
                throw new RuntimeException('Matching custom plan was not found.');
            }

            $expectedAmountCents = (int) round((float) ($plan['monthly_total'] ?? 0) * 100);

            if ($expectedAmountCents > 0 && $amountPaidCents > 0 && $amountPaidCents !== $expectedAmountCents) {
                throw new RuntimeException('Paid amount does not match expected plan amount.');
            }

            $itemLabel = 'Plan';
            $itemName = (string) ($plan['plan_name'] ?? 'Custom Plan');
            $amountPaid = (float) ($plan['monthly_total'] ?? $amountPaid);

            if (!payment_status_is_paid((string) ($plan['payment_status'] ?? ''))) {
                $viewState = 'pending';
                $statusBadgeClass = 'pending';
                $statusBadgeLabel = 'Payment Received';
                $pageTitle = 'Payment Received';
                $headline = 'We’re finalizing your custom plan';
                $bodyText = 'Stripe received your payment, but your plan is still being activated in your account.';
                $primaryHref = current_request_uri();
                $primaryLabel = 'Refresh Page';
                $secondaryHref = 'dashboard.php';
                $secondaryLabel = 'Go to Dashboard';
                $topLinks = array(
                    array('href' => 'dashboard.php', 'label' => 'Dashboard'),
                    array('href' => 'my-bookings.php', 'label' => 'My Bookings'),
                );
                $errorMessage = 'Payment was received, but your custom plan is still being finalized.';
            } else {
                $viewState = 'success';
                $statusBadgeClass = 'success';
                $statusBadgeLabel = 'Payment Confirmed';
                $pageTitle = 'Payment Successful';
                $headline = 'Payment Successful';
                $bodyText = 'Your custom plan has been verified with Stripe and activated successfully.';
                $primaryHref = 'dashboard.php';
                $primaryLabel = 'Go to Dashboard';
                $secondaryHref = 'my-bookings.php';
                $secondaryLabel = 'View Bookings';
                $topLinks = array(
                    array('href' => 'dashboard.php', 'label' => 'Dashboard'),
                    array('href' => 'my-bookings.php', 'label' => 'My Bookings'),
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Member Service Overage Success
        |--------------------------------------------------------------------------
        */
        if ($mode === 'service_overage') {
            if (!$pdoInstance instanceof PDO) {
                throw new RuntimeException('Database connection is not available for payment verification.');
            }

            $memberId = current_member_id_safe($pdoInstance);

            if ($memberId <= 0) {
                redirect_to('login.php');
            }

            $stripeMemberId = (int) ($metadata->member_id ?? 0);
            $bookingId = (int) ($metadata->booking_id ?? 0);
            $serviceType = (string) ($metadata->service_type ?? '');
            $overageUnits = (int) ($metadata->overage_units ?? 0);
            $memberPlanSlug = trim((string) ($metadata->member_plan_slug ?? ''));
            $expectedAmount = (float) ($metadata->total_amount ?? 0);

            if ($stripeMemberId <= 0 || $stripeMemberId !== $memberId) {
                throw new RuntimeException('This overage payment does not belong to the signed-in member.');
            }

            if ($bookingId <= 0) {
                throw new RuntimeException('Invalid overage booking reference.');
            }

            if ($expectedAmount > 0) {
                $expectedAmountCents = (int) round($expectedAmount * 100);

                if ($amountPaidCents > 0 && $expectedAmountCents !== $amountPaidCents) {
                    throw new RuntimeException('Paid amount does not match expected overage total.');
                }
            }

            $booking = safeFetchOne(
                $pdoInstance,
                "
                SELECT *
                FROM bookings
                WHERE id = :id
                  AND member_id = :member_id
                LIMIT 1
                ",
                array(
                    ':id' => $bookingId,
                    ':member_id' => $memberId,
                )
            );

            if (!$booking) {
                throw new RuntimeException('Matching booking was not found.');
            }

            unset($_SESSION['service_payment_portal']);

            $itemLabel = 'Service';
            $itemName = service_label_from_type($serviceType) . ($overageUnits > 0 ? ' × ' . $overageUnits : '');
            if ($memberPlanSlug !== '') {
                $itemName .= ' · ' . ucwords(str_replace('_', ' ', $memberPlanSlug));
            }

            if (!payment_status_is_paid((string) ($booking['payment_status'] ?? ''))) {
                $viewState = 'pending';
                $statusBadgeClass = 'pending';
                $statusBadgeLabel = 'Payment Received';
                $pageTitle = 'Payment Received';
                $headline = 'We’re finalizing your booking payment';
                $bodyText = 'Stripe received your payment, but your booking is still being updated in your account.';
                $primaryHref = current_request_uri();
                $primaryLabel = 'Refresh Page';
                $secondaryHref = 'my-bookings.php';
                $secondaryLabel = 'View Bookings';
                $topLinks = array(
                    array('href' => 'dashboard.php', 'label' => 'Dashboard'),
                    array('href' => 'my-bookings.php', 'label' => 'My Bookings'),
                );
                $errorMessage = 'Payment was received, but your booking status is still being finalized.';
            } else {
                $viewState = 'success';
                $statusBadgeClass = 'success';
                $statusBadgeLabel = 'Payment Confirmed';
                $pageTitle = 'Payment Successful';
                $headline = 'Member Overage Paid';
                $bodyText = 'Your member overage payment has been verified successfully and the uncovered portion of your booking has been paid.';
                $primaryHref = 'my-bookings.php';
                $primaryLabel = 'View Bookings';
                $secondaryHref = 'dashboard.php';
                $secondaryLabel = 'Go to Dashboard';
                $topLinks = array(
                    array('href' => 'dashboard.php', 'label' => 'Dashboard'),
                    array('href' => 'my-bookings.php', 'label' => 'My Bookings'),
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Non-Member Success
        |--------------------------------------------------------------------------
        */
        if ($mode === 'non_member') {
            if (!$pdoInstance instanceof PDO) {
                throw new RuntimeException('Database connection is not available for payment verification.');
            }

            $requestId = (int) ($metadata->request_id ?? 0);
            $serviceType = (string) ($metadata->service_type ?? '');
            $fullName = trim((string) ($metadata->full_name ?? ''));
            $dogName = trim((string) ($metadata->dog_name ?? ''));
            $expectedAmount = (float) ($metadata->total_amount ?? 0);

            if ($requestId <= 0) {
                throw new RuntimeException('Invalid non-member request reference.');
            }

            if ($expectedAmount > 0) {
                $expectedAmountCents = (int) round($expectedAmount * 100);

                if ($amountPaidCents > 0 && $expectedAmountCents !== $amountPaidCents) {
                    throw new RuntimeException('Paid amount does not match expected non-member total.');
                }
            }

            $record = find_non_member_payment_record($pdoInstance, $requestId);

            if ($record === null) {
                throw new RuntimeException('Matching non-member booking record was not found.');
            }

            unset($_SESSION['non_member_payment_portal']);

            $statusColumn = (string) ($record['status_column'] ?? '');
            $row = is_array($record['row'] ?? null) ? $record['row'] : array();
            $statusValue = $statusColumn !== '' ? (string) ($row[$statusColumn] ?? '') : '';

            $itemLabel = 'Booking';
            $itemName = service_label_from_type($serviceType);

            if ($dogName !== '') {
                $itemName .= ' · ' . $dogName;
            }

            if ($fullName !== '') {
                $itemName .= ' · ' . $fullName;
            }

            if (!payment_status_is_paid($statusValue)) {
                $viewState = 'pending';
                $statusBadgeClass = 'pending';
                $statusBadgeLabel = 'Payment Received';
                $pageTitle = 'Payment Received';
                $headline = 'We’re finalizing your booking request';
                $bodyText = 'Stripe received your payment, but your booking request is still being updated.';
                $primaryHref = current_request_uri();
                $primaryLabel = 'Refresh Page';
                $secondaryHref = 'contact.php';
                $secondaryLabel = 'Contact Us';
                $topLinks = array(
                    array('href' => 'non-member-booking.php', 'label' => 'Booking'),
                    array('href' => 'contact.php', 'label' => 'Contact'),
                );
                $errorMessage = 'Payment was received, but your booking request is still being finalized.';
            } else {
                $viewState = 'success';
                $statusBadgeClass = 'success';
                $statusBadgeLabel = 'Payment Confirmed';
                $pageTitle = 'Payment Successful';
                $headline = 'Non-Member Booking Paid';
                $bodyText = 'Your non-member booking payment has been verified successfully. Your request is now marked as paid and ready for follow-up.';
                $primaryHref = 'non-member-booking.php';
                $primaryLabel = 'Book Another Service';
                $secondaryHref = 'contact.php';
                $secondaryLabel = 'Contact Us';
                $topLinks = array(
                    array('href' => 'non-member-booking.php', 'label' => 'Booking'),
                    array('href' => 'contact.php', 'label' => 'Contact'),
                );
            }
        }
    } catch (Throwable $e) {
        error_log('payment-success.php error: ' . $e->getMessage());

        if ($mode === 'custom_plan') {
            $primaryHref = 'login.php';
            $primaryLabel = 'Member Login';
            $secondaryHref = 'customize-plan.php';
            $secondaryLabel = 'Back to Plans';
            $topLinks = array(
                array('href' => 'login.php', 'label' => 'Login'),
                array('href' => 'customize-plan.php', 'label' => 'Plans'),
            );
        } elseif ($mode === 'service_overage') {
            $primaryHref = 'login.php';
            $primaryLabel = 'Member Login';
            $secondaryHref = 'my-bookings.php';
            $secondaryLabel = 'View Bookings';
            $topLinks = array(
                array('href' => 'login.php', 'label' => 'Login'),
                array('href' => 'my-bookings.php', 'label' => 'My Bookings'),
            );
        } elseif ($mode === 'non_member') {
            $primaryHref = 'non-member-booking.php';
            $primaryLabel = 'Back to Booking';
            $secondaryHref = 'contact.php';
            $secondaryLabel = 'Contact Us';
            $topLinks = array(
                array('href' => 'non-member-booking.php', 'label' => 'Booking'),
                array('href' => 'contact.php', 'label' => 'Contact'),
            );
        } elseif ($mode === 'membership') {
            $primaryHref = 'memberships.php';
            $primaryLabel = 'Return to Memberships';
            $secondaryHref = 'contact.php';
            $secondaryLabel = 'Contact Us';
            $topLinks = array(
                array('href' => 'memberships.php', 'label' => 'Memberships'),
                array('href' => 'contact.php', 'label' => 'Contact'),
            );
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $viewState === 'success' ? h($pageTitle) : 'Payment Verification' ?> | Doggie Dorian’s</title>
    <style>
        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            font-family: Georgia, "Times New Roman", serif;
            background:
                radial-gradient(circle at top, rgba(212, 175, 55, 0.14), transparent 35%),
                linear-gradient(180deg, #05060a 0%, #090b12 45%, #04050a 100%);
            color: #f4f1ea;
        }

        .success-page {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .topbar {
            width: 100%;
            padding: 20px 22px 0;
        }

        .topbar-inner {
            max-width: 1120px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .brand {
            color: #f4f1ea;
            text-decoration: none;
            font-size: 1.1rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .brand span {
            color: #d4af37;
        }

        .top-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .top-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: #f4f1ea;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.04);
            padding: 10px 14px;
            border-radius: 999px;
            font-size: 0.95rem;
            transition: 0.2s ease;
        }

        .top-link:hover {
            background: rgba(255,255,255,0.08);
        }

        .success-main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 34px 18px 72px;
        }

        .success-shell {
            width: 100%;
            max-width: 760px;
        }

        .success-card {
            position: relative;
            overflow: hidden;
            background: linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.03));
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 28px;
            padding: 34px 28px 30px;
            box-shadow: 0 24px 70px rgba(0,0,0,0.40);
            backdrop-filter: blur(8px);
        }

        .success-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(212,175,55,0.10), transparent 35%);
            pointer-events: none;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 14px;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 18px;
            position: relative;
            z-index: 1;
        }

        .status-badge.success {
            background: rgba(212,175,55,0.14);
            color: #f2d471;
            border: 1px solid rgba(212,175,55,0.25);
        }

        .status-badge.pending {
            background: rgba(212,175,55,0.10);
            color: #f2d471;
            border: 1px solid rgba(212,175,55,0.18);
        }

        .status-badge.error {
            background: rgba(255,92,92,0.12);
            color: #ffb3b3;
            border: 1px solid rgba(255,92,92,0.22);
        }

        .success-title {
            position: relative;
            z-index: 1;
            margin: 0 0 10px;
            font-size: 2.35rem;
            line-height: 1.08;
            color: #fff;
        }

        .success-text {
            position: relative;
            z-index: 1;
            margin: 0;
            color: rgba(244,241,234,0.78);
            line-height: 1.7;
            font-size: 1.02rem;
        }

        .amount-panel {
            position: relative;
            z-index: 1;
            margin-top: 24px;
            padding: 22px;
            border-radius: 22px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            text-align: center;
        }

        .amount-label {
            font-size: 0.9rem;
            color: rgba(244,241,234,0.62);
            text-transform: uppercase;
            letter-spacing: 0.10em;
        }

        .amount-value {
            margin-top: 10px;
            font-size: 3rem;
            font-weight: 800;
            color: #f2d471;
            line-height: 1;
        }

        .plan-box {
            position: relative;
            z-index: 1;
            margin-top: 18px;
            padding: 18px 20px;
            border-radius: 18px;
            background: rgba(255,255,255,0.035);
            border: 1px solid rgba(255,255,255,0.07);
            text-align: center;
        }

        .plan-label {
            display: block;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.10em;
            color: rgba(244,241,234,0.58);
            margin-bottom: 8px;
        }

        .plan-name {
            font-size: 1.12rem;
            font-weight: 700;
            color: #ffffff;
        }

        .debug-box {
            position: relative;
            z-index: 1;
            margin-top: 20px;
            padding: 16px 18px;
            border-radius: 16px;
            background: rgba(255,92,92,0.08);
            border: 1px solid rgba(255,92,92,0.18);
            color: #ffd2d2;
            line-height: 1.6;
            word-break: break-word;
        }

        .success-actions {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 28px;
        }

        .btn-primary,
        .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 180px;
            padding: 14px 20px;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 700;
            transition: 0.2s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, #e2c48d, #b9975b);
            color: #0b0b10;
            box-shadow: 0 10px 24px rgba(185,151,91,0.22);
        }

        .btn-primary:hover {
            filter: brightness(1.04);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: rgba(255,255,255,0.06);
            color: #ffffff;
            border: 1px solid rgba(255,255,255,0.08);
        }

        .btn-secondary:hover {
            background: rgba(255,255,255,0.10);
        }

        @media (max-width: 680px) {
            .topbar {
                padding: 16px 14px 0;
            }

            .topbar-inner {
                flex-direction: column;
                align-items: stretch;
            }

            .brand {
                text-align: center;
            }

            .top-actions {
                justify-content: center;
            }

            .success-main {
                align-items: flex-start;
                padding: 24px 14px 56px;
            }

            .success-card {
                padding: 26px 18px 24px;
                border-radius: 22px;
            }

            .success-title {
                font-size: 1.95rem;
                text-align: center;
            }

            .success-text {
                text-align: center;
            }

            .amount-value {
                font-size: 2.45rem;
            }

            .btn-primary,
            .btn-secondary {
                width: 100%;
                min-width: 0;
            }
        }
    </style>
</head>
<body>
    <div class="success-page">
        <div class="topbar">
            <div class="topbar-inner">
                <a href="index.php" class="brand">Doggie <span>Dorian’s</span></a>

                <div class="top-actions">
                    <?php foreach ($topLinks as $link): ?>
                        <a href="<?= h((string) $link['href']) ?>" class="top-link"><?= h((string) $link['label']) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <main class="success-main">
            <div class="success-shell">
                <section class="success-card">
                    <div class="status-badge <?= h($statusBadgeClass) ?>"><?= h($statusBadgeLabel) ?></div>
                    <h1 class="success-title"><?= h($headline) ?></h1>
                    <p class="success-text"><?= h($bodyText) ?></p>

                    <?php if ($amountPaid > 0): ?>
                        <div class="amount-panel">
                            <div class="amount-label">Amount Paid</div>
                            <div class="amount-value"><?= h(money_fmt($amountPaid)) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($itemName !== ''): ?>
                        <div class="plan-box">
                            <span class="plan-label"><?= h($itemLabel) ?></span>
                            <div class="plan-name"><?= h($itemName) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($viewState === 'error'): ?>
                        <div class="debug-box"><?= h($errorMessage !== '' ? $errorMessage : 'Unknown error.') ?></div>
                    <?php elseif ($viewState === 'pending'): ?>
                        <div class="debug-box"><?= h($errorMessage !== '' ? $errorMessage : 'Your payment is still being finalized.') ?></div>
                    <?php endif; ?>

                    <div class="success-actions">
                        <a href="<?= h($primaryHref) ?>" class="btn-primary"><?= h($primaryLabel) ?></a>
                        <a href="<?= h($secondaryHref) ?>" class="btn-secondary"><?= h($secondaryLabel) ?></a>
                    </div>
                </section>
            </div>
        </main>
    </div>
</body>
</html>