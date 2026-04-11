<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
$storageFile = __DIR__ . '/data/founder-applications.json';

function dd_load_founder_payment_applications(string $file): array
{
    if (!is_file($file)) return [];
    $raw = file_get_contents($file);
    if (!is_string($raw) || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

$token = trim((string)($_GET['token'] ?? ''));
$applications = dd_load_founder_payment_applications($storageFile);
$matchedApplication = null;

if ($token !== '') {
    foreach ($applications as $application) {
        if (($application['approval_token'] ?? '') === $token) {
            $matchedApplication = $application;
            break;
        }
    }
}

$isValid = $matchedApplication !== null && (($matchedApplication['status'] ?? '') === 'approved');

$planIdMap = [
    'Founder Walk Club' => 1,
    'Founder Care Club' => 2,
    'Founder Elite Club' => 3,
];

$planName = $matchedApplication['plan_name'] ?? '';
$planId = $planIdMap[$planName] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Founder Checkout</title>
<style>
body { background:#0b0b10; color:white; font-family:sans-serif; display:flex; justify-content:center; align-items:center; height:100vh; }
.card { padding:40px; border-radius:20px; background:#111; text-align:center; }
button { padding:15px 25px; border:none; border-radius:999px; background:gold; font-weight:bold; cursor:pointer; }
</style>
</head>
<body>

<div class="card">
<?php if ($isValid && $planId > 0): ?>

<h1>Founder Access Approved</h1>
<p><?php echo htmlspecialchars($planName); ?></p>

<form method="POST" action="signup.php">
    <input type="hidden" name="plan_id" value="<?php echo $planId; ?>">
    <input type="hidden" name="agree_tos" value="1">
    <button type="submit">Continue to Checkout</button>
</form>

<?php else: ?>

<h1>Invalid Access</h1>
<p>This link is not valid or not approved.</p>

<?php endif; ?>
</div>

</body>
</html>