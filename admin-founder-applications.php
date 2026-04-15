<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-auth.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection not available.');
}

$storageFile = __DIR__ . '/data/founder-applications.json';

function ddAdminFounderH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ddAdminFounderQuoteIdentifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function ddAdminFounderTableExists(PDO $pdo, string $table): bool
{
    static $cache = array();

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
        $stmt->execute(array(':table' => $table));
        $cache[$table] = (bool) $stmt->fetchColumn();
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = false;
        return false;
    }
}

function ddAdminFounderGetColumns(PDO $pdo, string $table): array
{
    static $cache = array();

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    if (!ddAdminFounderTableExists($pdo, $table)) {
        $cache[$table] = array();
        return $cache[$table];
    }

    try {
        $stmt = $pdo->query('PRAGMA table_info(' . ddAdminFounderQuoteIdentifier($table) . ')');
        if (!($stmt instanceof PDOStatement)) {
            $cache[$table] = array();
            return $cache[$table];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $columns = array();

        foreach ($rows as $row) {
            if (!empty($row['name'])) {
                $columns[] = (string) $row['name'];
            }
        }

        $cache[$table] = $columns;
        return $cache[$table];
    } catch (Throwable $e) {
        $cache[$table] = array();
        return $cache[$table];
    }
}

function ddAdminFounderFlash(string $type, string $message): void
{
    $_SESSION['admin_founder_flash'] = array(
        'type' => $type,
        'message' => $message,
    );
}

function ddAdminFounderPullFlash(): ?array
{
    if (
        !isset($_SESSION['admin_founder_flash']) ||
        !is_array($_SESSION['admin_founder_flash'])
    ) {
        return null;
    }

    $flash = $_SESSION['admin_founder_flash'];
    unset($_SESSION['admin_founder_flash']);

    return $flash;
}

function ddAdminFounderCsrfToken(): string
{
    if (empty($_SESSION['admin_founder_csrf']) || !is_string($_SESSION['admin_founder_csrf'])) {
        $_SESSION['admin_founder_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['admin_founder_csrf'];
}

function ddAdminFounderValidateCsrf(?string $submittedToken): bool
{
    $sessionToken = $_SESSION['admin_founder_csrf'] ?? '';

    if (!is_string($sessionToken) || $sessionToken === '' || $submittedToken === null || $submittedToken === '') {
        return false;
    }

    return hash_equals($sessionToken, $submittedToken);
}

function ddAdminFounderGetBaseUrl(): string
{
    $https = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    );

    $scheme = $https ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));

    if ($host === '') {
        return 'https://dorianspetcare.com';
    }

    return $scheme . '://' . $host;
}

function ddAdminFounderLoadApplications(string $file): array
{
    if (!is_file($file)) {
        return array();
    }

    $raw = @file_get_contents($file);
    if (!is_string($raw) || trim($raw) === '') {
        return array();
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? array_values($decoded) : array();
}

function ddAdminFounderSaveApplications(string $file, array $applications): bool
{
    $directory = dirname($file);

    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    $encoded = json_encode($applications, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        return false;
    }

    $tempFile = $file . '.tmp';
    if (@file_put_contents($tempFile, $encoded, LOCK_EX) === false) {
        return false;
    }

    return @rename($tempFile, $file);
}

function ddAdminFounderStatusClass(string $status): string
{
    $status = strtolower(trim($status));

    if ($status === 'approved') {
        return 'approved';
    }

    if ($status === 'declined') {
        return 'declined';
    }

    return 'pending';
}

function ddAdminFounderValue(array $row, array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null) {
            $value = trim((string) $row[$key]);
            if ($value !== '') {
                return $value;
            }
        }
    }

    return $default;
}

function ddAdminFounderBoolishValue(array $row, array $keys): bool
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }

        $value = $row[$key];

        if (is_bool($value)) {
            return $value;
        }

        $string = strtolower(trim((string) $value));
        if (in_array($string, array('1', 'true', 'yes', 'y', 'on'), true)) {
            return true;
        }
    }

    return false;
}

function ddAdminFounderBuildApplicantName(array $application): string
{
    $direct = ddAdminFounderValue($application, array(
        'full_name',
        'name',
        'applicant_name',
        'customer_name',
    ));

    if ($direct !== '') {
        return $direct;
    }

    $first = ddAdminFounderValue($application, array('first_name', 'firstname', 'first'));
    $last = ddAdminFounderValue($application, array('last_name', 'lastname', 'last'));
    $combined = trim($first . ' ' . $last);

    return $combined !== '' ? $combined : 'Unknown Applicant';
}

function ddAdminFounderApplicationId(array $application, int $index): string
{
    $id = ddAdminFounderValue($application, array(
        'id',
        'application_id',
        'uuid',
        'token_id',
    ));

    if ($id !== '') {
        return $id;
    }

    return 'founder-app-' . ($index + 1);
}

function ddAdminFounderPlanName(array $application): string
{
    return ddAdminFounderValue($application, array(
        'plan_name',
        'plan',
        'selected_plan',
        'membership_plan',
        'package_name',
    ), 'Unknown Plan');
}

function ddAdminFounderSubmittedAt(array $application): string
{
    return ddAdminFounderValue($application, array(
        'submitted_at',
        'created_at',
        'applied_at',
        'timestamp',
        'date_submitted',
    ));
}

function ddAdminFounderReviewedAt(array $application): string
{
    return ddAdminFounderValue($application, array(
        'reviewed_at',
        'updated_at',
        'status_updated_at',
    ));
}

function ddAdminFounderApplicantEmail(array $application): string
{
    return ddAdminFounderValue($application, array(
        'email',
        'email_address',
        'applicant_email',
    ), '—');
}

function ddAdminFounderApplicantPhone(array $application): string
{
    return ddAdminFounderValue($application, array(
        'phone',
        'phone_number',
        'mobile',
        'contact_number',
    ), '—');
}

function ddAdminFounderDogName(array $application): string
{
    return ddAdminFounderValue($application, array(
        'pet_name',
        'dog_name',
        'pet',
        'dog',
    ), '—');
}

function ddAdminFounderBreedAge(array $application): string
{
    $breed = ddAdminFounderValue($application, array(
        'pet_breed',
        'dog_breed',
        'breed',
    ));

    $age = ddAdminFounderValue($application, array(
        'pet_age',
        'dog_age',
        'age',
    ));

    $combined = trim($breed . ($age !== '' ? ' • ' . $age : ''));

    return $combined !== '' ? $combined : '—';
}

function ddAdminFounderServiceNeeds(array $application): string
{
    return ddAdminFounderValue($application, array(
        'service_needs',
        'services_needed',
        'service_interest',
        'services',
        'care_needs',
    ), '—');
}

function ddAdminFounderNotes(array $application): string
{
    return ddAdminFounderValue($application, array(
        'notes',
        'note',
        'additional_notes',
        'message',
        'comments',
        'why_join',
    ), '—');
}

function ddAdminFounderStatus(array $application): string
{
    $status = strtolower(ddAdminFounderValue($application, array(
        'status',
        'application_status',
        'review_status',
    ), 'pending'));

    return in_array($status, array('pending', 'approved', 'declined'), true)
        ? $status
        : 'pending';
}

function ddAdminFounderApprovalToken(array $application): string
{
    return ddAdminFounderValue($application, array(
        'approval_token',
        'token',
    ));
}

function ddAdminFounderPaymentUrl(string $baseUrl, string $token): string
{
    return rtrim($baseUrl, '/') . '/founder-payment.php?token=' . urlencode($token);
}

function ddAdminFounderSendEmail(string $to, string $subject, string $message, string $fromEmail): bool
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $headers = array();
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-type: text/plain; charset=UTF-8';
    $headers[] = 'From: Doggie Dorian\'s <' . $fromEmail . '>';
    $headers[] = 'Reply-To: ' . $fromEmail;
    $headers[] = 'X-Mailer: PHP/' . phpversion();

    return @mail($to, $subject, $message, implode("\r\n", $headers));
}

function ddAdminFounderSortNewestFirst(array $applications): array
{
    usort($applications, function (array $a, array $b): int {
        $left = ddAdminFounderSubmittedAt($a);
        $right = ddAdminFounderSubmittedAt($b);

        return strcmp($right, $left);
    });

    return $applications;
}

function ddAdminFounderExtraFields(array $application): array
{
    $hiddenKeys = array(
        'id',
        'application_id',
        'uuid',
        'token_id',
        'full_name',
        'name',
        'applicant_name',
        'customer_name',
        'first_name',
        'firstname',
        'first',
        'last_name',
        'lastname',
        'last',
        'plan_name',
        'plan',
        'selected_plan',
        'membership_plan',
        'package_name',
        'submitted_at',
        'created_at',
        'applied_at',
        'timestamp',
        'date_submitted',
        'reviewed_at',
        'updated_at',
        'status_updated_at',
        'email',
        'email_address',
        'applicant_email',
        'phone',
        'phone_number',
        'mobile',
        'contact_number',
        'pet_name',
        'dog_name',
        'pet',
        'dog',
        'pet_breed',
        'dog_breed',
        'breed',
        'pet_age',
        'dog_age',
        'age',
        'service_needs',
        'services_needed',
        'service_interest',
        'services',
        'care_needs',
        'notes',
        'note',
        'additional_notes',
        'message',
        'comments',
        'why_join',
        'status',
        'application_status',
        'review_status',
        'approval_token',
        'token',
        'approval_sent_at',
        'declined_sent_at',
        'email_last_attempt_at',
        'last_email_status',
    );

    $extras = array();

    foreach ($application as $key => $value) {
        if (in_array((string) $key, $hiddenKeys, true)) {
            continue;
        }

        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $text = trim((string) $value);
        if ($text === '') {
            continue;
        }

        $label = ucwords(str_replace(array('_', '-'), ' ', (string) $key));
        $extras[$label] = $text;
    }

    return $extras;
}

$dummyFounderTableCheck = ddAdminFounderTableExists($pdo, 'founder_applications');
$dummyFounderColumns = $dummyFounderTableCheck ? ddAdminFounderGetColumns($pdo, 'founder_applications') : array();
unset($dummyFounderColumns);

$siteBaseUrl = ddAdminFounderGetBaseUrl();
$fromEmail = 'no-reply@dorianspetcare.com';
$flash = ddAdminFounderPullFlash();
$applications = ddAdminFounderSortNewestFirst(ddAdminFounderLoadApplications($storageFile));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;

    if (!ddAdminFounderValidateCsrf($postedToken)) {
        ddAdminFounderFlash('error', 'Security check failed. Please refresh the page and try again.');
        header('Location: admin-founder-applications.php');
        exit;
    }

    $applicationId = trim((string) ($_POST['application_id'] ?? ''));
    $newStatus = strtolower(trim((string) ($_POST['status'] ?? '')));
    $allowedStatuses = array('pending', 'approved', 'declined');

    if ($applicationId === '' || !in_array($newStatus, $allowedStatuses, true)) {
        ddAdminFounderFlash('error', 'Invalid request.');
        header('Location: admin-founder-applications.php');
        exit;
    }

    $loadedApplications = ddAdminFounderLoadApplications($storageFile);
    $updated = false;
    $emailWarning = '';

    foreach ($loadedApplications as $index => $application) {
        $currentId = ddAdminFounderApplicationId((array) $application, $index);

        if ($currentId !== $applicationId) {
            continue;
        }

        $loadedApplications[$index]['id'] = $currentId;
        $oldStatus = ddAdminFounderStatus((array) $loadedApplications[$index]);
        $loadedApplications[$index]['status'] = $newStatus;
        $loadedApplications[$index]['reviewed_at'] = date('Y-m-d H:i:s');

        if ($newStatus === 'approved') {
            if (ddAdminFounderApprovalToken((array) $loadedApplications[$index]) === '') {
                $loadedApplications[$index]['approval_token'] = bin2hex(random_bytes(24));
            }

            $approvalToken = (string) $loadedApplications[$index]['approval_token'];
            $paymentUrl = ddAdminFounderPaymentUrl($siteBaseUrl, $approvalToken);
            $recipientName = ddAdminFounderBuildApplicantName((array) $loadedApplications[$index]);
            $planName = ddAdminFounderPlanName((array) $loadedApplications[$index]);
            $recipientEmail = ddAdminFounderApplicantEmail((array) $loadedApplications[$index]);

            $subject = 'Founder Membership Approved - Doggie Dorian\'s';
            $message =
                "Hi " . $recipientName . ",\n\n" .
                "Your founder membership request for " . $planName . " has been approved.\n\n" .
                "Use your private payment link below to continue:\n" .
                $paymentUrl . "\n\n" .
                "Application ID: " . $currentId . "\n\n" .
                "This link is private and tied to your approval.\n\n" .
                "Thank you,\nDoggie Dorian's";

            $loadedApplications[$index]['email_last_attempt_at'] = date('Y-m-d H:i:s');

            if (ddAdminFounderSendEmail($recipientEmail, $subject, $message, $fromEmail)) {
                $loadedApplications[$index]['approval_sent_at'] = date('Y-m-d H:i:s');
                $loadedApplications[$index]['last_email_status'] = 'approved_email_sent';
            } else {
                $loadedApplications[$index]['last_email_status'] = 'approved_email_failed';
                $emailWarning = ' Approval link was generated, but the approval email could not be sent automatically.';
            }
        } elseif ($newStatus === 'declined' && $oldStatus !== 'declined') {
            $recipientName = ddAdminFounderBuildApplicantName((array) $loadedApplications[$index]);
            $planName = ddAdminFounderPlanName((array) $loadedApplications[$index]);
            $recipientEmail = ddAdminFounderApplicantEmail((array) $loadedApplications[$index]);

            $subject = 'Founder Membership Update - Doggie Dorian\'s';
            $message =
                "Hi " . $recipientName . ",\n\n" .
                "Thank you for your founder membership request for " . $planName . ".\n\n" .
                "At this time, we’re unable to move forward with the founder application.\n\n" .
                "You can still use our regular booking options, and you’re welcome to reach out if you have any questions.\n\n" .
                "Thank you,\nDoggie Dorian's";

            $loadedApplications[$index]['email_last_attempt_at'] = date('Y-m-d H:i:s');

            if (ddAdminFounderSendEmail($recipientEmail, $subject, $message, $fromEmail)) {
                $loadedApplications[$index]['declined_sent_at'] = date('Y-m-d H:i:s');
                $loadedApplications[$index]['last_email_status'] = 'declined_email_sent';
            } else {
                $loadedApplications[$index]['last_email_status'] = 'declined_email_failed';
                $emailWarning = ' Decline email could not be sent automatically.';
            }
        }

        $updated = true;
        break;
    }

    if (!$updated) {
        ddAdminFounderFlash('error', 'Application not found.');
        header('Location: admin-founder-applications.php');
        exit;
    }

    if (!ddAdminFounderSaveApplications($storageFile, $loadedApplications)) {
        ddAdminFounderFlash('error', 'Could not save founder application changes.');
        header('Location: admin-founder-applications.php');
        exit;
    }

    ddAdminFounderFlash('success', 'Founder application updated successfully.' . $emailWarning);
    header('Location: admin-founder-applications.php');
    exit;
}

$applications = ddAdminFounderSortNewestFirst(ddAdminFounderLoadApplications($storageFile));
$csrfToken = ddAdminFounderCsrfToken();

$totalApplications = count($applications);
$pendingCount = 0;
$approvedCount = 0;
$declinedCount = 0;

foreach ($applications as $application) {
    $status = ddAdminFounderStatus((array) $application);

    if ($status === 'approved') {
        $approvedCount++;
    } elseif ($status === 'declined') {
        $declinedCount++;
    } else {
        $pendingCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Founder Applications | Doggie Dorian’s</title>
    <meta name="description" content="Admin founder applications page for Doggie Dorian’s.">
    <style>
        :root {
            --bg: #07101d;
            --panel: rgba(15, 23, 42, 0.92);
            --panel-2: rgba(255,255,255,0.04);
            --line: rgba(148, 163, 184, 0.16);
            --line-2: rgba(255,255,255,0.08);
            --text: #e5edf7;
            --muted: #94a3b8;
            --gold: #d4af37;
            --gold-soft: #f5deb3;
            --green: #22c55e;
            --red: #ef4444;
            --amber: #f59e0b;
            --shadow: 0 24px 70px rgba(2, 8, 23, 0.42);
            --max: 1320px;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            min-height: 100%;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(212, 175, 55, 0.14), transparent 28%),
                radial-gradient(circle at top right, rgba(56, 189, 248, 0.08), transparent 22%),
                linear-gradient(180deg, #07101d 0%, #0b1220 50%, #0f172a 100%);
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .page {
            max-width: var(--max);
            margin: 0 auto;
            padding: 28px 18px 80px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .brand {
            font-size: 1.55rem;
            font-weight: 900;
            letter-spacing: 0.04em;
        }

        .top-links {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .top-link {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            font-weight: 700;
            font-size: 0.94rem;
        }

        .hero {
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(15, 23, 42, 0.82));
            border: 1px solid rgba(212, 175, 55, 0.14);
            border-radius: 30px;
            padding: 26px;
            box-shadow: var(--shadow);
            margin-bottom: 22px;
        }

        .eyebrow {
            color: var(--gold-soft);
            text-transform: uppercase;
            letter-spacing: 0.14em;
            font-size: 0.75rem;
            font-weight: 800;
            margin-bottom: 10px;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 2.15rem;
            line-height: 1.06;
        }

        .sub {
            color: rgba(244,241,234,0.72);
            line-height: 1.65;
            font-size: 0.98rem;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-top: 20px;
        }

        .stat {
            padding: 16px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .stat-label {
            color: rgba(244,241,234,0.58);
            text-transform: uppercase;
            letter-spacing: 0.1em;
            font-size: 0.72rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 1.55rem;
            font-weight: 900;
        }

        .flash {
            padding: 14px 16px;
            border-radius: 16px;
            margin-bottom: 18px;
            font-weight: 700;
            border: 1px solid transparent;
        }

        .flash.success {
            background: rgba(34, 197, 94, 0.16);
            color: #d7f1dd;
            border-color: rgba(34, 197, 94, 0.20);
        }

        .flash.error {
            background: rgba(239, 68, 68, 0.16);
            color: #ffd5d5;
            border-color: rgba(239, 68, 68, 0.20);
        }

        .empty {
            padding: 28px;
            border-radius: 24px;
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(15, 23, 42, 0.82));
            border: 1px solid rgba(212, 175, 55, 0.14);
            box-shadow: var(--shadow);
            color: var(--muted);
        }

        .grid {
            display: grid;
            gap: 18px;
        }

        .card {
            border-radius: 26px;
            padding: 22px;
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(15, 23, 42, 0.82));
            border: 1px solid rgba(212, 175, 55, 0.14);
            box-shadow: var(--shadow);
        }

        .card-top {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            align-items: flex-start;
            margin-bottom: 18px;
        }

        .card-title {
            font-size: 1.3rem;
            font-weight: 900;
            line-height: 1.2;
            margin-bottom: 6px;
        }

        .card-meta {
            color: var(--muted);
            line-height: 1.6;
            font-size: 0.95rem;
        }

        .status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 0 14px;
            border-radius: 999px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-size: 0.77rem;
            font-weight: 900;
            border: 1px solid transparent;
            white-space: nowrap;
        }

        .status.pending {
            background: rgba(245, 158, 11, 0.14);
            color: #fde68a;
            border-color: rgba(245, 158, 11, 0.18);
        }

        .status.approved {
            background: rgba(34, 197, 94, 0.14);
            color: #dcfce7;
            border-color: rgba(34, 197, 94, 0.18);
        }

        .status.declined {
            background: rgba(239, 68, 68, 0.14);
            color: #fecaca;
            border-color: rgba(239, 68, 68, 0.18);
        }

        .details {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .detail {
            padding: 14px;
            border-radius: 16px;
            background: var(--panel-2);
            border: 1px solid var(--line-2);
            min-height: 96px;
        }

        .detail-label {
            color: rgba(244,241,234,0.56);
            text-transform: uppercase;
            letter-spacing: 0.10em;
            font-size: 0.7rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .detail-value {
            font-weight: 800;
            line-height: 1.55;
            word-break: break-word;
        }

        .blocks {
            display: grid;
            gap: 12px;
            margin-bottom: 16px;
        }

        .block {
            padding: 16px;
            border-radius: 16px;
            background: var(--panel-2);
            border: 1px solid var(--line-2);
        }

        .block-title {
            font-size: 0.84rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--gold-soft);
            margin-bottom: 8px;
        }

        .block-text {
            white-space: pre-wrap;
            line-height: 1.7;
            color: rgba(244,241,234,0.88);
            word-break: break-word;
        }

        .link-box {
            padding: 16px;
            border-radius: 18px;
            margin-bottom: 16px;
            background: rgba(212, 175, 55, 0.10);
            border: 1px solid rgba(212, 175, 55, 0.18);
        }

        .link-box .block-title {
            margin-bottom: 8px;
        }

        .link-url {
            color: #fde7a8;
            word-break: break-all;
            line-height: 1.7;
            font-weight: 700;
        }

        .form-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        .field {
            appearance: none;
            min-height: 48px;
            padding: 0 16px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.04);
            color: var(--text);
            font: inherit;
            font-weight: 700;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 48px;
            padding: 0 18px;
            border-radius: 999px;
            font-weight: 800;
            border: 1px solid transparent;
            cursor: pointer;
            font: inherit;
        }

        .btn-primary {
            background: linear-gradient(135deg, #d4af37, #f5deb3);
            color: #15120d;
        }

        .extras {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .extra {
            padding: 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .extra-label {
            color: rgba(244,241,234,0.56);
            text-transform: uppercase;
            letter-spacing: 0.10em;
            font-size: 0.7rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .extra-value {
            font-weight: 700;
            line-height: 1.55;
            word-break: break-word;
        }

        @media (max-width: 1080px) {
            .details {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .extras {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 760px) {
            h1 {
                font-size: 1.7rem;
            }

            .page {
                padding: 20px 12px 60px;
            }

            .details,
            .stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="topbar">
            <div class="brand">Doggie Dorian’s</div>
            <div class="top-links">
                <a class="top-link" href="admin-dashboard.php">Dashboard</a>
                <a class="top-link" href="admin-nav.php">Admin Nav</a>
                <a class="top-link" href="founder-application.php">Founder Form</a>
                <a class="top-link" href="logout.php">Logout</a>
            </div>
        </div>

        <section class="hero">
            <div class="eyebrow">Admin Founder Intake</div>
            <h1>Founder Applications</h1>
            <div class="sub">
                Review incoming founder requests, approve or decline them, and generate private founder payment links when approved.
            </div>

            <div class="stats">
                <div class="stat">
                    <div class="stat-label">Total Applications</div>
                    <div class="stat-value"><?php echo (int) $totalApplications; ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">Pending</div>
                    <div class="stat-value"><?php echo (int) $pendingCount; ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">Approved</div>
                    <div class="stat-value"><?php echo (int) $approvedCount; ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">Declined</div>
                    <div class="stat-value"><?php echo (int) $declinedCount; ?></div>
                </div>
            </div>
        </section>

        <?php if ($flash !== null && !empty($flash['message'])): ?>
            <div class="flash <?php echo ($flash['type'] ?? '') === 'success' ? 'success' : 'error'; ?>">
                <?php echo ddAdminFounderH((string) $flash['message']); ?>
            </div>
        <?php endif; ?>

        <?php if (empty($applications)): ?>
            <div class="empty">No founder applications have been submitted yet.</div>
        <?php else: ?>
            <div class="grid">
                <?php foreach ($applications as $index => $application): ?>
                    <?php
                    $application = is_array($application) ? $application : array();
                    $applicationId = ddAdminFounderApplicationId($application, (int) $index);
                    $applicantName = ddAdminFounderBuildApplicantName($application);
                    $planName = ddAdminFounderPlanName($application);
                    $submittedAt = ddAdminFounderSubmittedAt($application);
                    $reviewedAt = ddAdminFounderReviewedAt($application);
                    $status = ddAdminFounderStatus($application);
                    $approvalToken = ddAdminFounderApprovalToken($application);
                    $paymentUrl = $approvalToken !== '' ? ddAdminFounderPaymentUrl($siteBaseUrl, $approvalToken) : '';
                    $extras = ddAdminFounderExtraFields($application);
                    ?>
                    <article class="card">
                        <div class="card-top">
                            <div>
                                <div class="card-title"><?php echo ddAdminFounderH($applicantName); ?></div>
                                <div class="card-meta">
                                    <?php echo ddAdminFounderH($planName); ?><br>
                                    Application ID: <?php echo ddAdminFounderH($applicationId); ?><br>
                                    Submitted: <?php echo ddAdminFounderH($submittedAt !== '' ? $submittedAt : '—'); ?>
                                    <?php if ($reviewedAt !== ''): ?>
                                        <br>Reviewed: <?php echo ddAdminFounderH($reviewedAt); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="status <?php echo ddAdminFounderH(ddAdminFounderStatusClass($status)); ?>">
                                <?php echo ddAdminFounderH(ucfirst($status)); ?>
                            </div>
                        </div>

                        <div class="details">
                            <div class="detail">
                                <div class="detail-label">Email</div>
                                <div class="detail-value"><?php echo ddAdminFounderH(ddAdminFounderApplicantEmail($application)); ?></div>
                            </div>
                            <div class="detail">
                                <div class="detail-label">Phone</div>
                                <div class="detail-value"><?php echo ddAdminFounderH(ddAdminFounderApplicantPhone($application)); ?></div>
                            </div>
                            <div class="detail">
                                <div class="detail-label">Dog Name</div>
                                <div class="detail-value"><?php echo ddAdminFounderH(ddAdminFounderDogName($application)); ?></div>
                            </div>
                            <div class="detail">
                                <div class="detail-label">Breed / Age</div>
                                <div class="detail-value"><?php echo ddAdminFounderH(ddAdminFounderBreedAge($application)); ?></div>
                            </div>
                        </div>

                        <div class="blocks">
                            <div class="block">
                                <div class="block-title">Service Needs</div>
                                <div class="block-text"><?php echo ddAdminFounderH(ddAdminFounderServiceNeeds($application)); ?></div>
                            </div>

                            <div class="block">
                                <div class="block-title">Notes</div>
                                <div class="block-text"><?php echo ddAdminFounderH(ddAdminFounderNotes($application)); ?></div>
                            </div>
                        </div>

                        <?php if (!empty($extras)): ?>
                            <div class="extras">
                                <?php foreach ($extras as $extraLabel => $extraValue): ?>
                                    <div class="extra">
                                        <div class="extra-label"><?php echo ddAdminFounderH($extraLabel); ?></div>
                                        <div class="extra-value"><?php echo ddAdminFounderH($extraValue); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($paymentUrl !== ''): ?>
                            <div class="link-box">
                                <div class="block-title">Private Payment Link</div>
                                <a class="link-url" href="<?php echo ddAdminFounderH($paymentUrl); ?>" target="_blank" rel="noopener noreferrer">
                                    <?php echo ddAdminFounderH($paymentUrl); ?>
                                </a>
                            </div>
                        <?php endif; ?>

                        <form method="post" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo ddAdminFounderH($csrfToken); ?>">
                            <input type="hidden" name="application_id" value="<?php echo ddAdminFounderH($applicationId); ?>">

                            <div class="form-row">
                                <select class="field" name="status">
                                    <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="declined" <?php echo $status === 'declined' ? 'selected' : ''; ?>>Declined</option>
                                </select>

                                <button class="btn btn-primary" type="submit">Update Status</button>
                            </div>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>