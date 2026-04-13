<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/database/setup.php';
require_once __DIR__ . '/admin-auth.php';

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money(float $amount): string
{
    return '$' . number_format($amount, 2);
}

function tableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
        $stmt->execute([':table' => $table]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function getColumns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!tableExists($pdo, $table)) {
        $cache[$table] = [];
        return [];
    }

    try {
        $safeTable = str_replace('"', '""', $table);
        $stmt = $pdo->query('PRAGMA table_info("' . $safeTable . '")');
        $columns = [];

        if ($stmt) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
                if (isset($column['name'])) {
                    $columns[] = (string) $column['name'];
                }
            }
        }

        $cache[$table] = $columns;
        return $columns;
    } catch (Throwable $e) {
        $cache[$table] = [];
        return [];
    }
}

function firstExistingColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function quotedIdentifier(string $value): string
{
    return '"' . str_replace('"', '""', $value) . '"';
}

function fetchAllRows(PDO $pdo, string $table, array $columns): array
{
    if (empty($columns)) {
        return [];
    }

    $select = implode(', ', array_map(static fn ($column) => quotedIdentifier((string) $column), $columns));
    $safeTable = quotedIdentifier($table);

    try {
        $stmt = $pdo->query("SELECT {$select} FROM {$safeTable}");
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        return [];
    }
}

function rowValue(array $row, array $candidates, $default = null)
{
    foreach ($candidates as $candidate) {
        if ($candidate === null || $candidate === '') {
            continue;
        }

        if (array_key_exists($candidate, $row) && $row[$candidate] !== null && $row[$candidate] !== '') {
            return $row[$candidate];
        }
    }

    return $default;
}

function normalizeSlug(string $value): string
{
    $value = trim(strtolower($value));
    $value = str_replace(['_', '-'], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim($value);
}

function titleize(string $value, string $fallback = 'Unknown'): string
{
    $value = normalizeSlug($value);

    if ($value === '') {
        return $fallback;
    }

    return ucwords($value);
}

function serviceLabel(string $value, string $fallback = 'Service'): string
{
    $normalized = normalizeSlug($value);

    return match ($normalized) {
        'walk', 'walks' => 'Walk',
        'drop in', 'dropin' => 'Drop-In',
        'daycare', 'day care' => 'Daycare',
        'boarding', 'board' => 'Boarding',
        'sitting', 'pet sitting', 'in home sitting' => 'Pet Sitting',
        default => $normalized !== '' ? ucwords($normalized) : $fallback,
    };
}

function normalizePaymentStatus(?string $paymentStatus, ?string $legacyStatus = null): string
{
    $paymentStatus = normalizeSlug((string) $paymentStatus);
    $legacyStatus = normalizeSlug((string) $legacyStatus);

    if ($paymentStatus !== '') {
        return match ($paymentStatus) {
            'pending payment', 'awaiting payment' => 'pending',
            default => $paymentStatus,
        };
    }

    return match ($legacyStatus) {
        'paid' => 'paid',
        'pending payment', 'awaiting payment' => 'pending',
        default => 'unpaid',
    };
}

function paymentStatusLabel(string $value): string
{
    $value = normalizePaymentStatus($value);

    return match ($value) {
        'paid' => 'Paid',
        'pending' => 'Pending',
        'unpaid' => 'Unpaid',
        'failed' => 'Failed',
        'refunded' => 'Refunded',
        'partial' => 'Partial',
        default => titleize($value, 'Unpaid'),
    };
}

function paymentStatusClass(string $value): string
{
    $normalized = normalizePaymentStatus($value);

    return match ($normalized) {
        'paid' => 'paid',
        'pending' => 'pending',
        'unpaid' => 'unpaid',
        'failed' => 'failed',
        'refunded' => 'refunded',
        'partial' => 'partial',
        default => 'default',
    };
}

function isPaidStatus(string $value): bool
{
    return normalizePaymentStatus($value) === 'paid';
}

function isCancelledStatus(?string $status): bool
{
    $status = normalizeSlug((string) $status);
    return in_array($status, ['cancelled', 'canceled'], true);
}

function amountFromRow(array $row): float
{
    $value = rowValue($row, [
        'price',
        'total_price',
        'amount_due',
        'amount',
        'monthly_total',
        'total',
        'quoted_total',
        'grand_total',
        'estimated_price',
    ], 0);

    return is_numeric($value) ? round((float) $value, 2) : 0.0;
}

function normalizeDateBucket(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $matches)) {
        return $matches[0];
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d', $timestamp) : '';
}

function displayDate(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '—';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('M j, Y', $timestamp);
}

function displayDateTime(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '—';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('M j, Y g:i A', $timestamp);
}

function addBucket(array &$buckets, string $key, string $label, float $amount, int $count = 1): void
{
    if (!isset($buckets[$key])) {
        $buckets[$key] = [
            'label' => $label,
            'amount' => 0.0,
            'count' => 0,
        ];
    }

    $buckets[$key]['amount'] += $amount;
    $buckets[$key]['count'] += $count;
}

function sortedBuckets(array $buckets): array
{
    uasort($buckets, static function (array $a, array $b): int {
        if ((float) $a['amount'] === (float) $b['amount']) {
            return (int) $b['count'] <=> (int) $a['count'];
        }

        return ((float) $b['amount'] <=> (float) $a['amount']);
    });

    return array_values($buckets);
}

$summary = [
    'tracked_value' => 0.0,
    'collected_value' => 0.0,
    'outstanding_value' => 0.0,
    'cancelled_value' => 0.0,
    'member_booking_value' => 0.0,
    'non_member_value' => 0.0,
    'custom_plan_value' => 0.0,
    'record_count' => 0,
    'paid_count' => 0,
];

$sourceBreakdown = [];
$serviceBreakdown = [];
$paymentBreakdown = [];
$dailyCollections = [];
$recentRevenueRows = [];

/*
|--------------------------------------------------------------------------
| Member Bookings / Walks
|--------------------------------------------------------------------------
*/
$memberBookingTable = null;
foreach (['bookings', 'walks'] as $candidateTable) {
    if (tableExists($pdo, $candidateTable)) {
        $memberBookingTable = $candidateTable;
        break;
    }
}

if ($memberBookingTable !== null) {
    $columns = getColumns($pdo, $memberBookingTable);

    $idColumn = firstExistingColumn($columns, ['id', 'booking_id', 'walk_id']);
    $amountColumn = firstExistingColumn($columns, ['price', 'total_price', 'amount_due', 'amount']);
    $serviceColumn = firstExistingColumn($columns, ['service_type', 'type', 'booking_type', 'category', 'service']);
    $dateColumn = firstExistingColumn($columns, ['service_date', 'booking_date', 'walk_date', 'date', 'scheduled_date', 'start_date']);
    $statusColumn = firstExistingColumn($columns, ['status']);
    $paymentStatusColumn = firstExistingColumn($columns, ['payment_status', 'payment_state']);
    $paymentMethodColumn = firstExistingColumn($columns, ['payment_method']);
    $paymentPaidAtColumn = firstExistingColumn($columns, ['payment_paid_at']);
    $walkerColumn = firstExistingColumn($columns, ['walker_name']);

    if ($amountColumn !== null) {
        $selectColumns = array_values(array_filter([
            $idColumn,
            $amountColumn,
            $serviceColumn,
            $dateColumn,
            $statusColumn,
            $paymentStatusColumn,
            $paymentMethodColumn,
            $paymentPaidAtColumn,
            $walkerColumn,
        ]));

        $rows = fetchAllRows($pdo, $memberBookingTable, $selectColumns);

        foreach ($rows as $row) {
            $amount = amountFromRow($row);
            if ($amount <= 0) {
                continue;
            }

            $summary['tracked_value'] += $amount;
            $summary['member_booking_value'] += $amount;
            $summary['record_count']++;

            $workflowStatus = (string) rowValue($row, [$statusColumn], '');
            $paymentStatus = normalizePaymentStatus(
                (string) rowValue($row, [$paymentStatusColumn], ''),
                $workflowStatus
            );

            if (isPaidStatus($paymentStatus)) {
                $summary['collected_value'] += $amount;
                $summary['paid_count']++;
            } elseif (isCancelledStatus($workflowStatus)) {
                $summary['cancelled_value'] += $amount;
            } else {
                $summary['outstanding_value'] += $amount;
            }

            addBucket($sourceBreakdown, 'member_bookings', 'Member Bookings', $amount);
            addBucket(
                $serviceBreakdown,
                'member:' . strtolower((string) rowValue($row, [$serviceColumn], 'service')),
                serviceLabel((string) rowValue($row, [$serviceColumn], 'Service')),
                $amount
            );
            addBucket($paymentBreakdown, $paymentStatus, paymentStatusLabel($paymentStatus), $amount);

            $collectionDate = normalizeDateBucket((string) rowValue($row, [$paymentPaidAtColumn], ''));
            if ($collectionDate !== '' && isPaidStatus($paymentStatus)) {
                addBucket($dailyCollections, $collectionDate, $collectionDate, $amount);
            }

            $recentRevenueRows[] = [
                'source' => 'Member Booking',
                'id' => (string) rowValue($row, [$idColumn], ''),
                'label' => serviceLabel((string) rowValue($row, [$serviceColumn], 'Service')),
                'date' => (string) rowValue($row, [$dateColumn], ''),
                'workflow_status' => $workflowStatus,
                'payment_status' => paymentStatusLabel($paymentStatus),
                'payment_status_key' => $paymentStatus,
                'payment_method' => titleize((string) rowValue($row, [$paymentMethodColumn], ''), '—'),
                'extra' => (string) rowValue($row, [$walkerColumn], ''),
                'amount' => $amount,
                'sort_date' => (string) rowValue($row, [$paymentPaidAtColumn], (string) rowValue($row, [$dateColumn], '')),
            ];
        }
    }
}

/*
|--------------------------------------------------------------------------
| Non-Member Bookings
|--------------------------------------------------------------------------
*/
$nonMemberTable = null;
foreach (['non_member_bookings', 'public_booking_requests'] as $candidateTable) {
    if (tableExists($pdo, $candidateTable)) {
        $nonMemberTable = $candidateTable;
        break;
    }
}

if ($nonMemberTable !== null) {
    $columns = getColumns($pdo, $nonMemberTable);

    $idColumn = firstExistingColumn($columns, ['id', 'request_id']);
    $amountColumn = firstExistingColumn($columns, ['price', 'total_price', 'amount_due', 'amount', 'quoted_total', 'grand_total', 'estimated_price']);
    $serviceColumn = firstExistingColumn($columns, ['service_type', 'type', 'booking_type', 'category', 'service']);
    $dateColumn = firstExistingColumn($columns, ['service_date', 'booking_date', 'walk_date', 'date', 'scheduled_date', 'start_date', 'date_start']);
    $statusColumn = firstExistingColumn($columns, ['status']);
    $paymentStatusColumn = firstExistingColumn($columns, ['payment_status']);
    $paymentMethodColumn = firstExistingColumn($columns, ['payment_method']);
    $paymentPaidAtColumn = firstExistingColumn($columns, ['payment_paid_at']);
    $nameColumn = firstExistingColumn($columns, ['full_name', 'client_name', 'owner_name']);

    if ($amountColumn !== null) {
        $selectColumns = array_values(array_filter([
            $idColumn,
            $amountColumn,
            $serviceColumn,
            $dateColumn,
            $statusColumn,
            $paymentStatusColumn,
            $paymentMethodColumn,
            $paymentPaidAtColumn,
            $nameColumn,
        ]));

        $rows = fetchAllRows($pdo, $nonMemberTable, $selectColumns);

        foreach ($rows as $row) {
            $amount = amountFromRow($row);
            if ($amount <= 0) {
                continue;
            }

            $summary['tracked_value'] += $amount;
            $summary['non_member_value'] += $amount;
            $summary['record_count']++;

            $workflowStatus = (string) rowValue($row, [$statusColumn], '');
            $paymentStatus = normalizePaymentStatus(
                (string) rowValue($row, [$paymentStatusColumn], ''),
                $workflowStatus
            );

            if (isPaidStatus($paymentStatus)) {
                $summary['collected_value'] += $amount;
                $summary['paid_count']++;
            } elseif (isCancelledStatus($workflowStatus)) {
                $summary['cancelled_value'] += $amount;
            } else {
                $summary['outstanding_value'] += $amount;
            }

            addBucket($sourceBreakdown, 'non_member', 'Non-Member Bookings', $amount);
            addBucket(
                $serviceBreakdown,
                'nonmember:' . strtolower((string) rowValue($row, [$serviceColumn], 'service')),
                serviceLabel((string) rowValue($row, [$serviceColumn], 'Service')),
                $amount
            );
            addBucket($paymentBreakdown, $paymentStatus, paymentStatusLabel($paymentStatus), $amount);

            $collectionDate = normalizeDateBucket((string) rowValue($row, [$paymentPaidAtColumn], ''));
            if ($collectionDate !== '' && isPaidStatus($paymentStatus)) {
                addBucket($dailyCollections, $collectionDate, $collectionDate, $amount);
            }

            $recentRevenueRows[] = [
                'source' => 'Non-Member Booking',
                'id' => (string) rowValue($row, [$idColumn], ''),
                'label' => serviceLabel((string) rowValue($row, [$serviceColumn], 'Service')),
                'date' => (string) rowValue($row, [$dateColumn], ''),
                'workflow_status' => $workflowStatus,
                'payment_status' => paymentStatusLabel($paymentStatus),
                'payment_status_key' => $paymentStatus,
                'payment_method' => titleize((string) rowValue($row, [$paymentMethodColumn], ''), '—'),
                'extra' => (string) rowValue($row, [$nameColumn], ''),
                'amount' => $amount,
                'sort_date' => (string) rowValue($row, [$paymentPaidAtColumn], (string) rowValue($row, [$dateColumn], '')),
            ];
        }
    }
}

/*
|--------------------------------------------------------------------------
| Custom Plans
|--------------------------------------------------------------------------
*/
if (tableExists($pdo, 'custom_plans')) {
    $columns = getColumns($pdo, 'custom_plans');

    $idColumn = firstExistingColumn($columns, ['id']);
    $amountColumn = firstExistingColumn($columns, ['monthly_total', 'price', 'amount', 'total']);
    $nameColumn = firstExistingColumn($columns, ['plan_name', 'name']);
    $statusColumn = firstExistingColumn($columns, ['status']);
    $paymentStatusColumn = firstExistingColumn($columns, ['payment_status']);
    $paymentMethodColumn = firstExistingColumn($columns, ['payment_method']);
    $paymentPaidAtColumn = firstExistingColumn($columns, ['payment_paid_at']);
    $updatedAtColumn = firstExistingColumn($columns, ['updated_at', 'created_at']);

    if ($amountColumn !== null) {
        $selectColumns = array_values(array_filter([
            $idColumn,
            $amountColumn,
            $nameColumn,
            $statusColumn,
            $paymentStatusColumn,
            $paymentMethodColumn,
            $paymentPaidAtColumn,
            $updatedAtColumn,
        ]));

        $rows = fetchAllRows($pdo, 'custom_plans', $selectColumns);

        foreach ($rows as $row) {
            $amount = amountFromRow($row);
            if ($amount <= 0) {
                continue;
            }

            $summary['tracked_value'] += $amount;
            $summary['custom_plan_value'] += $amount;
            $summary['record_count']++;

            $workflowStatus = (string) rowValue($row, [$statusColumn], '');
            $paymentStatus = normalizePaymentStatus(
                (string) rowValue($row, [$paymentStatusColumn], ''),
                $workflowStatus
            );

            if (isPaidStatus($paymentStatus)) {
                $summary['collected_value'] += $amount;
                $summary['paid_count']++;
            } else {
                $summary['outstanding_value'] += $amount;
            }

            $planLabel = trim((string) rowValue($row, [$nameColumn], 'Custom Plan'));
            if ($planLabel === '') {
                $planLabel = 'Custom Plan';
            }

            addBucket($sourceBreakdown, 'custom_plans', 'Custom Plans', $amount);
            addBucket($serviceBreakdown, 'plan:' . strtolower($planLabel), $planLabel, $amount);
            addBucket($paymentBreakdown, $paymentStatus, paymentStatusLabel($paymentStatus), $amount);

            $collectionDate = normalizeDateBucket((string) rowValue($row, [$paymentPaidAtColumn], ''));
            if ($collectionDate !== '' && isPaidStatus($paymentStatus)) {
                addBucket($dailyCollections, $collectionDate, $collectionDate, $amount);
            }

            $recentRevenueRows[] = [
                'source' => 'Custom Plan',
                'id' => (string) rowValue($row, [$idColumn], ''),
                'label' => $planLabel,
                'date' => (string) rowValue($row, [$updatedAtColumn], ''),
                'workflow_status' => $workflowStatus,
                'payment_status' => paymentStatusLabel($paymentStatus),
                'payment_status_key' => $paymentStatus,
                'payment_method' => titleize((string) rowValue($row, [$paymentMethodColumn], ''), '—'),
                'extra' => '',
                'amount' => $amount,
                'sort_date' => (string) rowValue($row, [$paymentPaidAtColumn], (string) rowValue($row, [$updatedAtColumn], '')),
            ];
        }
    }
}

$sourceBreakdownRows = sortedBuckets($sourceBreakdown);
$serviceBreakdownRows = sortedBuckets($serviceBreakdown);
$paymentBreakdownRows = sortedBuckets($paymentBreakdown);

krsort($dailyCollections);
$dailyCollectionRows = array_slice(array_values($dailyCollections), 0, 20);

usort($recentRevenueRows, static function (array $a, array $b): int {
    $timeA = strtotime((string) ($a['sort_date'] ?? '')) ?: 0;
    $timeB = strtotime((string) ($b['sort_date'] ?? '')) ?: 0;

    if ($timeA === $timeB) {
        return strcmp((string) ($b['id'] ?? ''), (string) ($a['id'] ?? ''));
    }

    return $timeB <=> $timeA;
});

$recentRevenueRows = array_slice($recentRevenueRows, 0, 20);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Revenue Dashboard | Doggie Dorian’s Admin</title>
  <style>
    * { box-sizing: border-box; }

    :root {
      --bg: #070810;
      --panel: #11131b;
      --line: rgba(255,255,255,0.08);
      --text: #f7f4ee;
      --muted: rgba(247,244,238,0.68);
      --gold: #d4af37;
      --green: #5ed39a;
      --blue: #66b3ff;
      --red: #ff9898;
      --shadow: 0 20px 60px rgba(0,0,0,0.35);
    }

    body {
      margin: 0;
      background:
        radial-gradient(circle at top left, rgba(212,175,55,0.08), transparent 28%),
        linear-gradient(180deg, #090b13 0%, #05060b 100%);
      color: var(--text);
      font-family: Arial, Helvetica, sans-serif;
    }

    .wrap {
      max-width: 1460px;
      margin: 0 auto;
      padding: 34px 22px 60px;
    }

    .topbar {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 20px;
      flex-wrap: wrap;
      margin-bottom: 26px;
    }

    .eyebrow {
      color: var(--gold);
      letter-spacing: 0.14em;
      text-transform: uppercase;
      font-size: 12px;
      font-weight: 700;
      margin-bottom: 10px;
    }

    h1 {
      margin: 0;
      font-size: 42px;
      line-height: 1;
      letter-spacing: -0.03em;
    }

    .subtext {
      margin-top: 12px;
      color: var(--muted);
      font-size: 15px;
      max-width: 860px;
      line-height: 1.6;
    }

    .top-actions {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }

    .top-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 46px;
      padding: 0 18px;
      border-radius: 999px;
      text-decoration: none;
      font-weight: 700;
      border: 1px solid var(--line);
      background: rgba(255,255,255,0.02);
      color: var(--text);
    }

    .top-btn.primary {
      background: var(--gold);
      color: #0a0a0f;
      border-color: var(--gold);
    }

    .summary-grid {
      display: grid;
      grid-template-columns: repeat(8, minmax(0, 1fr));
      gap: 16px;
      margin-bottom: 24px;
    }

    .card {
      background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));
      border: 1px solid var(--line);
      border-radius: 20px;
      padding: 20px;
      box-shadow: var(--shadow);
    }

    .card-label {
      color: var(--muted);
      font-size: 12px;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      margin-bottom: 10px;
      font-weight: 700;
    }

    .card-value {
      font-size: 30px;
      font-weight: 800;
      letter-spacing: -0.03em;
      line-height: 1.05;
    }

    .card-note {
      margin-top: 8px;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.5;
    }

    .layout {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 22px;
      margin-bottom: 24px;
    }

    .panel {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 24px;
      box-shadow: var(--shadow);
      overflow: hidden;
    }

    .panel-inner {
      padding: 22px;
    }

    .panel-title {
      font-size: 24px;
      font-weight: 800;
      margin: 0 0 8px;
    }

    .panel-subtitle {
      color: var(--muted);
      font-size: 14px;
      margin-bottom: 20px;
      line-height: 1.6;
    }

    .list {
      display: grid;
      gap: 12px;
    }

    .list-row {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 16px;
      padding: 14px 16px;
      border-radius: 16px;
      background: rgba(255,255,255,0.03);
      border: 1px solid var(--line);
    }

    .list-left strong {
      display: block;
      font-size: 15px;
      color: var(--text);
      margin-bottom: 4px;
    }

    .list-left span {
      color: var(--muted);
      font-size: 13px;
    }

    .list-right {
      text-align: right;
      flex-shrink: 0;
    }

    .list-right strong {
      display: block;
      color: var(--gold);
      font-size: 16px;
    }

    .list-right span {
      color: var(--muted);
      font-size: 12px;
    }

    .table-wrap {
      width: 100%;
      overflow-x: auto;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 1150px;
    }

    thead th {
      color: var(--muted);
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.12em;
      font-weight: 700;
      text-align: left;
      padding: 16px 18px;
      border-bottom: 1px solid var(--line);
      background: rgba(255,255,255,0.01);
    }

    tbody td {
      padding: 18px;
      border-bottom: 1px solid var(--line);
      vertical-align: top;
    }

    tbody tr:hover {
      background: rgba(255,255,255,0.015);
    }

    .primary-text {
      font-weight: 700;
      font-size: 15px;
      color: var(--text);
    }

    .status-pill {
      display: inline-flex;
      align-items: center;
      padding: 7px 12px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 800;
      letter-spacing: 0.04em;
      text-transform: capitalize;
      background: rgba(255,255,255,0.06);
      color: var(--text);
    }

    .status-pill.paid {
      background: rgba(94,211,154,0.16);
      color: #9bf0c4;
    }

    .status-pill.pending {
      background: rgba(212,175,55,0.14);
      color: #f3d57a;
    }

    .status-pill.unpaid,
    .status-pill.default {
      background: rgba(255,255,255,0.08);
      color: #f7f4ee;
    }

    .status-pill.failed,
    .status-pill.refunded {
      background: rgba(255,152,152,0.14);
      color: #ffb2b2;
    }

    .status-pill.partial {
      background: rgba(102,179,255,0.14);
      color: #9acbff;
    }

    .empty-state {
      padding: 36px 20px;
      text-align: center;
      color: var(--muted);
    }

    .empty-state strong {
      display: block;
      color: var(--text);
      font-size: 18px;
      margin-bottom: 10px;
    }

    @media (max-width: 1400px) {
      .summary-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
      }
    }

    @media (max-width: 1200px) {
      .layout {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 700px) {
      .summary-grid {
        grid-template-columns: 1fr;
      }

      h1 {
        font-size: 32px;
      }

      .wrap {
        padding: 24px 14px 46px;
      }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="topbar">
      <div>
        <div class="eyebrow">Doggie Dorian’s Admin</div>
        <h1>Revenue Dashboard</h1>
        <div class="subtext">
          View tracked value, collected payments, unpaid balances, and revenue mix across member bookings, non-member bookings, and custom plans without mixing payment data into operational booking workflow.
        </div>
      </div>

      <div class="top-actions">
        <a href="admin-bookings.php" class="top-btn">Main Bookings</a>
        <a href="admin-dashboard.php" class="top-btn primary">Admin Home</a>
      </div>
    </div>

    <div class="summary-grid">
      <div class="card">
        <div class="card-label">Tracked Value</div>
        <div class="card-value"><?= money($summary['tracked_value']) ?></div>
        <div class="card-note">All tracked booking and plan amounts</div>
      </div>

      <div class="card">
        <div class="card-label">Collected Revenue</div>
        <div class="card-value"><?= money($summary['collected_value']) ?></div>
        <div class="card-note">Rows marked paid through payment status</div>
      </div>

      <div class="card">
        <div class="card-label">Outstanding Value</div>
        <div class="card-value"><?= money($summary['outstanding_value']) ?></div>
        <div class="card-note">Tracked value not yet marked paid</div>
      </div>

      <div class="card">
        <div class="card-label">Cancelled Value</div>
        <div class="card-value"><?= money($summary['cancelled_value']) ?></div>
        <div class="card-note">Operationally cancelled rows not counted as collected</div>
      </div>

      <div class="card">
        <div class="card-label">Member Bookings</div>
        <div class="card-value"><?= money($summary['member_booking_value']) ?></div>
        <div class="card-note">Tracked member booking value</div>
      </div>

      <div class="card">
        <div class="card-label">Non-Member</div>
        <div class="card-value"><?= money($summary['non_member_value']) ?></div>
        <div class="card-note">Tracked public booking value</div>
      </div>

      <div class="card">
        <div class="card-label">Custom Plans</div>
        <div class="card-value"><?= money($summary['custom_plan_value']) ?></div>
        <div class="card-note">Tracked custom plan value</div>
      </div>

      <div class="card">
        <div class="card-label">Paid Records</div>
        <div class="card-value"><?= (int) $summary['paid_count'] ?></div>
        <div class="card-note"><?= (int) $summary['record_count'] ?> tracked rows total</div>
      </div>
    </div>

    <div class="layout">
      <div class="panel">
        <div class="panel-inner">
          <h2 class="panel-title">Revenue by Source</h2>
          <div class="panel-subtitle">
            Compare how much tracked value is coming from member bookings, non-member bookings, and custom plans.
          </div>

          <?php if (!$sourceBreakdownRows): ?>
            <div class="empty-state">
              <strong>No revenue tracked yet</strong>
              No source totals are available right now.
            </div>
          <?php else: ?>
            <div class="list">
              <?php foreach ($sourceBreakdownRows as $row): ?>
                <div class="list-row">
                  <div class="list-left">
                    <strong><?= e((string) $row['label']) ?></strong>
                    <span><?= (int) $row['count'] ?> rows</span>
                  </div>
                  <div class="list-right">
                    <strong><?= money((float) $row['amount']) ?></strong>
                    <span>Tracked value</span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel">
        <div class="panel-inner">
          <h2 class="panel-title">Revenue by Service / Plan</h2>
          <div class="panel-subtitle">
            See which services and plan types are producing the most tracked value.
          </div>

          <?php if (!$serviceBreakdownRows): ?>
            <div class="empty-state">
              <strong>No service revenue yet</strong>
              No service or plan breakdown is available right now.
            </div>
          <?php else: ?>
            <div class="list">
              <?php foreach ($serviceBreakdownRows as $row): ?>
                <div class="list-row">
                  <div class="list-left">
                    <strong><?= e((string) $row['label']) ?></strong>
                    <span><?= (int) $row['count'] ?> rows</span>
                  </div>
                  <div class="list-right">
                    <strong><?= money((float) $row['amount']) ?></strong>
                    <span>Tracked value</span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="layout">
      <div class="panel">
        <div class="panel-inner">
          <h2 class="panel-title">Payment Breakdown</h2>
          <div class="panel-subtitle">
            Revenue grouped by payment status instead of booking workflow status.
          </div>

          <?php if (!$paymentBreakdownRows): ?>
            <div class="empty-state">
              <strong>No payment data yet</strong>
              No payment breakdown is available right now.
            </div>
          <?php else: ?>
            <div class="list">
              <?php foreach ($paymentBreakdownRows as $row): ?>
                <div class="list-row">
                  <div class="list-left">
                    <strong><?= e((string) $row['label']) ?></strong>
                    <span><?= (int) $row['count'] ?> rows</span>
                  </div>
                  <div class="list-right">
                    <strong><?= money((float) $row['amount']) ?></strong>
                    <span>Tracked value</span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel">
        <div class="panel-inner">
          <h2 class="panel-title">Daily Collections</h2>
          <div class="panel-subtitle">
            Paid revenue grouped by payment date where available.
          </div>

          <?php if (!$dailyCollectionRows): ?>
            <div class="empty-state">
              <strong>No collected revenue yet</strong>
              No paid rows with collection dates are available right now.
            </div>
          <?php else: ?>
            <div class="list">
              <?php foreach ($dailyCollectionRows as $row): ?>
                <div class="list-row">
                  <div class="list-left">
                    <strong><?= e(displayDate((string) $row['label'])) ?></strong>
                    <span><?= (int) $row['count'] ?> paid rows</span>
                  </div>
                  <div class="list-right">
                    <strong><?= money((float) $row['amount']) ?></strong>
                    <span>Collected</span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-inner">
        <h2 class="panel-title">Recent Revenue Rows</h2>
        <div class="panel-subtitle">
          Recent tracked rows across member bookings, non-member bookings, and custom plans for quick spot-checking.
        </div>

        <?php if (!$recentRevenueRows): ?>
          <div class="empty-state">
            <strong>No revenue rows yet</strong>
            No tracked rows are available right now.
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Source</th>
                  <th>ID</th>
                  <th>Item</th>
                  <th>Date</th>
                  <th>Workflow Status</th>
                  <th>Payment Status</th>
                  <th>Payment Method</th>
                  <th>Notes</th>
                  <th>Amount</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentRevenueRows as $row): ?>
                  <tr>
                    <td>
                      <div class="primary-text"><?= e((string) $row['source']) ?></div>
                    </td>
                    <td>
                      <div class="primary-text">#<?= e((string) $row['id']) ?></div>
                    </td>
                    <td>
                      <div class="primary-text"><?= e((string) $row['label']) ?></div>
                    </td>
                    <td>
                      <div class="primary-text"><?= e(displayDateTime((string) $row['date'])) ?></div>
                    </td>
                    <td>
                      <span class="status-pill"><?= e(titleize((string) $row['workflow_status'], '—')) ?></span>
                    </td>
                    <td>
                      <span class="status-pill <?= e(paymentStatusClass((string) $row['payment_status_key'])) ?>">
                        <?= e((string) $row['payment_status']) ?>
                      </span>
                    </td>
                    <td>
                      <div class="primary-text"><?= e((string) $row['payment_method']) ?></div>
                    </td>
                    <td>
                      <div class="primary-text"><?= e(trim((string) $row['extra']) !== '' ? (string) $row['extra'] : '—') ?></div>
                    </td>
                    <td>
                      <div class="primary-text"><?= money((float) $row['amount']) ?></div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>