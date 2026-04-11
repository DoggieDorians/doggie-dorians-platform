<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
/**
 * OPTIONAL:
 * If you already have a stronger admin session check in your project,
 * replace this block with your existing admin access logic.
 */
$isAdmin =
    isset($_SESSION['admin_id']) ||
    (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) ||
    (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

if (!$isAdmin) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * CHANGE THESE:
 */
$fromEmail = 'no-reply@dorianspetcare.com';
$siteName = "Doggie Dorian's";
$siteBaseUrl = 'https://dorianspetcare.com';

$storageFile = __DIR__ . '/data/founder-applications.json';

function dd_load_founder_applications_admin(string $file): array
{
    if (!is_file($file)) {
        return [];
    }

    $raw = file_get_contents($file);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function dd_save_founder_applications_admin(string $file, array $applications): bool
{
    return file_put_contents(
        $file,
        json_encode($applications, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    ) !== false;
}

function dd_send_email_admin(string $to, string $subject, string $message, string $fromEmail): bool
{
    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-type: text/plain; charset=UTF-8';
    $headers[] = 'From: Doggie Dorian\'s <' . $fromEmail . '>';
    $headers[] = 'Reply-To: ' . $fromEmail;
    $headers[] = 'X-Mailer: PHP/' . phpversion();

    return @mail($to, $subject, $message, implode("\r\n", $headers));
}

function dd_status_class(string $status): string
{
    switch ($status) {
        case 'approved':
            return 'approved';
        case 'declined':
            return 'declined';
        default:
            return 'pending';
    }
}

$applications = dd_load_founder_applications_admin($storageFile);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $applicationId = trim((string)($_POST['application_id'] ?? ''));
    $newStatus = trim((string)($_POST['status'] ?? ''));

    $allowedStatuses = ['pending', 'approved', 'declined'];

    if ($applicationId === '' || !in_array($newStatus, $allowedStatuses, true)) {
        $error = 'Invalid request.';
    } else {
        $updated = false;

        foreach ($applications as &$application) {
            if (($application['id'] ?? '') === $applicationId) {
                $oldStatus = (string)($application['status'] ?? 'pending');
                $application['status'] = $newStatus;
                $application['reviewed_at'] = date('Y-m-d H:i:s');

                if ($newStatus === 'approved') {
                    if (empty($application['approval_token'])) {
                        $application['approval_token'] = bin2hex(random_bytes(24));
                    }

                    $paymentUrl = $siteBaseUrl . '/founder-payment.php?token=' . urlencode($application['approval_token']);

                    $clientSubject = 'Founder Membership Approved - ' . $siteName;
                    $clientMessage =
                        "Hi " . ($application['full_name'] ?? 'there') . ",\n\n" .
                        "Your founder membership request for " . ($application['plan_name'] ?? 'your selected plan') . " has been approved.\n\n" .
                        "Use your private payment link below to continue:\n" .
                        $paymentUrl . "\n\n" .
                        "Application ID: " . ($application['id'] ?? '') . "\n\n" .
                        "This link is private and tied to your approval.\n\n" .
                        "Thank you,\n" . $siteName;

                    dd_send_email_admin((string)($application['email'] ?? ''), $clientSubject, $clientMessage, $fromEmail);

                    $application['approval_sent_at'] = date('Y-m-d H:i:s');
                }

                if ($newStatus === 'declined' && $oldStatus !== 'declined') {
                    $clientSubject = 'Founder Membership Update - ' . $siteName;
                    $clientMessage =
                        "Hi " . ($application['full_name'] ?? 'there') . ",\n\n" .
                        "Thank you for your founder membership request for " . ($application['plan_name'] ?? 'your selected plan') . ".\n\n" .
                        "At this time, we’re unable to move forward with the founder application.\n\n" .
                        "You can still use our regular booking options, and you’re welcome to reach out if you have any questions.\n\n" .
                        "Thank you,\n" . $siteName;

                    dd_send_email_admin((string)($application['email'] ?? ''), $clientSubject, $clientMessage, $fromEmail);
                }

                $updated = true;
                break;
            }
        }
        unset($application);

        if (!$updated) {
            $error = 'Application not found.';
        } else {
            if (dd_save_founder_applications_admin($storageFile, $applications)) {
                $message = 'Application updated successfully.';
            } else {
                $error = 'Could not save status update.';
            }
        }
    }
}

$applications = dd_load_founder_applications_admin($storageFile);

usort($applications, function ($a, $b) {
    return strcmp((string)($b['submitted_at'] ?? ''), (string)($a['submitted_at'] ?? ''));
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Founder Applications Admin | Doggie Dorian's</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg: #0a0c10;
      --panel: rgba(255,255,255,0.05);
      --line: rgba(255,255,255,0.09);
      --text: #f6f1e8;
      --muted: #c9c0af;
      --gold: #d7b26a;
      --gold-light: #f0d59f;
      --shadow: 0 18px 50px rgba(0,0,0,0.35);
      --success-bg: rgba(104, 201, 128, 0.14);
      --success-line: rgba(104, 201, 128, 0.22);
      --error-bg: rgba(255, 91, 60, 0.14);
      --error-line: rgba(255, 91, 60, 0.22);
    }
    body {
      font-family: Arial, sans-serif;
      background: linear-gradient(180deg, #06070a 0%, #0b0d12 100%);
      color: var(--text);
      padding: 28px;
    }
    .wrap {
      max-width: 1300px;
      margin: 0 auto;
    }
    h1 {
      font-size: 2rem;
      margin-bottom: 10px;
    }
    .sub {
      color: var(--muted);
      margin-bottom: 24px;
    }
    .notice, .error {
      margin-bottom: 20px;
      padding: 14px 16px;
      border-radius: 12px;
      color: var(--text);
    }
    .notice {
      background: var(--success-bg);
      border: 1px solid var(--success-line);
    }
    .error {
      background: var(--error-bg);
      border: 1px solid var(--error-line);
    }
    .empty {
      padding: 24px;
      border-radius: 18px;
      border: 1px solid var(--line);
      background: var(--panel);
      color: var(--muted);
    }
    .grid {
      display: grid;
      gap: 18px;
    }
    .card {
      border-radius: 18px;
      border: 1px solid var(--line);
      background: var(--panel);
      box-shadow: var(--shadow);
      padding: 22px;
    }
    .top {
      display: flex;
      justify-content: space-between;
      gap: 18px;
      flex-wrap: wrap;
      margin-bottom: 16px;
    }
    .title {
      font-size: 1.25rem;
      color: white;
      margin-bottom: 4px;
    }
    .meta {
      color: var(--muted);
      font-size: 0.95rem;
    }
    .status {
      display: inline-block;
      padding: 8px 12px;
      border-radius: 999px;
      font-size: 0.82rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.06em;
    }
    .status.pending {
      background: rgba(255,255,255,0.08);
      border: 1px solid rgba(255,255,255,0.10);
    }
    .status.approved {
      background: rgba(104, 201, 128, 0.14);
      border: 1px solid rgba(104, 201, 128, 0.22);
    }
    .status.declined {
      background: rgba(255, 91, 60, 0.14);
      border: 1px solid rgba(255, 91, 60, 0.22);
    }
    .detail-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 14px;
      margin-bottom: 16px;
    }
    .detail {
      padding: 14px;
      border-radius: 14px;
      background: rgba(255,255,255,0.03);
      border: 1px solid rgba(255,255,255,0.06);
    }
    .detail strong {
      display: block;
      margin-bottom: 4px;
      color: white;
      font-size: 0.92rem;
    }
    .detail span {
      color: var(--muted);
      font-size: 0.95rem;
      word-break: break-word;
    }
    .block {
      margin-bottom: 14px;
      padding: 14px;
      border-radius: 14px;
      background: rgba(255,255,255,0.03);
      border: 1px solid rgba(255,255,255,0.06);
    }
    .block strong {
      display: block;
      margin-bottom: 6px;
      color: white;
    }
    .block p {
      color: var(--muted);
      white-space: pre-wrap;
    }
    .link-box {
      margin-bottom: 14px;
      padding: 14px;
      border-radius: 14px;
      background: rgba(215,178,106,0.08);
      border: 1px solid rgba(215,178,106,0.16);
    }
    .link-box strong {
      display: block;
      margin-bottom: 6px;
      color: white;
    }
    .link-box a {
      color: var(--gold-light);
      word-break: break-all;
    }
    form {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      align-items: center;
      margin-top: 12px;
    }
    select, button {
      border-radius: 999px;
      padding: 10px 14px;
      border: 1px solid rgba(255,255,255,0.10);
      background: rgba(255,255,255,0.04);
      color: white;
    }
    button {
      background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
      color: #15120d;
      font-weight: 700;
      border: none;
      cursor: pointer;
    }
    @media (max-width: 840px) {
      .detail-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <h1>Founder Applications</h1>
    <p class="sub">Review incoming founder requests, then approve or decline them.</p>

    <?php if ($message !== ''): ?>
      <div class="notice"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if (empty($applications)): ?>
      <div class="empty">No founder applications have been submitted yet.</div>
    <?php else: ?>
      <div class="grid">
        <?php foreach ($applications as $application): ?>
          <div class="card">
            <div class="top">
              <div>
                <div class="title"><?php echo htmlspecialchars($application['full_name'] ?? 'Unknown Applicant'); ?></div>
                <div class="meta">
                  <?php echo htmlspecialchars($application['plan_name'] ?? 'Unknown Plan'); ?> •
                  Submitted <?php echo htmlspecialchars($application['submitted_at'] ?? ''); ?>
                </div>
              </div>
              <div class="status <?php echo dd_status_class((string)($application['status'] ?? 'pending')); ?>">
                <?php echo htmlspecialchars($application['status'] ?? 'pending'); ?>
              </div>
            </div>

            <div class="detail-grid">
              <div class="detail">
                <strong>Email</strong>
                <span><?php echo htmlspecialchars($application['email'] ?? ''); ?></span>
              </div>
              <div class="detail">
                <strong>Phone</strong>
                <span><?php echo htmlspecialchars($application['phone'] ?? ''); ?></span>
              </div>
              <div class="detail">
                <strong>Dog Name</strong>
                <span><?php echo htmlspecialchars($application['pet_name'] ?? ''); ?></span>
              </div>
              <div class="detail">
                <strong>Breed / Age</strong>
                <span>
                  <?php echo htmlspecialchars($application['pet_breed'] ?? ''); ?>
                  <?php if (!empty($application['pet_age'])): ?>
                    • <?php echo htmlspecialchars($application['pet_age']); ?>
                  <?php endif; ?>
                </span>
              </div>
            </div>

            <div class="block">
              <strong>Service Needs</strong>
              <p><?php echo htmlspecialchars($application['service_needs'] ?? ''); ?></p>
            </div>

            <div class="block">
              <strong>Notes</strong>
              <p><?php echo htmlspecialchars($application['notes'] ?? ''); ?></p>
            </div>

            <?php if (!empty($application['approval_token'])): ?>
              <div class="link-box">
                <strong>Private Payment Link</strong>
                <a href="<?php echo htmlspecialchars($siteBaseUrl . '/founder-payment.php?token=' . urlencode($application['approval_token'])); ?>" target="_blank">
                  <?php echo htmlspecialchars($siteBaseUrl . '/founder-payment.php?token=' . urlencode($application['approval_token'])); ?>
                </a>
              </div>
            <?php endif; ?>

            <form method="post">
              <input type="hidden" name="application_id" value="<?php echo htmlspecialchars($application['id'] ?? ''); ?>">
              <select name="status">
                <option value="pending" <?php echo (($application['status'] ?? '') === 'pending') ? 'selected' : ''; ?>>Pending</option>
                <option value="approved" <?php echo (($application['status'] ?? '') === 'approved') ? 'selected' : ''; ?>>Approved</option>
                <option value="declined" <?php echo (($application['status'] ?? '') === 'declined') ? 'selected' : ''; ?>>Declined</option>
              </select>
              <button type="submit">Update Status</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>