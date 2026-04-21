<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/includes/analytics.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection is not available.';
    exit;
}

dd_analytics_ensure_schema($pdo);

function analytics_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function analytics_money(float|int|string|null $value): string
{
    if ($value === null || $value === '') {
        return '$0.00';
    }

    return '$' . number_format((float) $value, 2);
}

function analytics_number(int|float|string|null $value): string
{
    return number_format((float) ($value ?? 0));
}

function analytics_pct(float|int|string|null $value): string
{
    return number_format((float) ($value ?? 0), 1) . '%';
}

function analytics_duration_label(int|float|string|null $value): string
{
    return dd_analytics_format_duration((int) round((float) ($value ?? 0)));
}

$allowedDays = [7, 30, 90, 365];
$days = (int) ($_GET['days'] ?? 30);
if (!in_array($days, $allowedDays, true)) {
    $days = 30;
}

$pageTitle = 'Admin Analytics | Doggie Dorian’s';

$kpis = dd_analytics_fetch_kpis($pdo, $days);
$dailySeries = dd_analytics_fetch_daily_series($pdo, $days);
$topPages = dd_analytics_fetch_top_pages($pdo, $days, 12);
$landingPages = dd_analytics_fetch_landing_pages($pdo, $days, 8);
$exitPages = dd_analytics_fetch_exit_pages($pdo, $days, 8);
$topSources = dd_analytics_fetch_top_sources($pdo, $days, 10);
$topCountries = dd_analytics_fetch_geo_breakdown($pdo, $days, 'country_name', 10);
$topRegions = dd_analytics_fetch_geo_breakdown($pdo, $days, 'region_name', 10);
$topCities = dd_analytics_fetch_geo_breakdown($pdo, $days, 'city_name', 10);
$deviceBreakdown = dd_analytics_fetch_device_breakdown($pdo, $days, 'device_type');
$browserBreakdown = dd_analytics_fetch_device_breakdown($pdo, $days, 'browser');
$osBreakdown = dd_analytics_fetch_device_breakdown($pdo, $days, 'os');
$recentEvents = dd_analytics_fetch_recent_events($pdo, 24, $days);
$businessSnapshot = dd_analytics_fetch_business_snapshot($pdo);
$serviceMix = dd_analytics_fetch_service_mix($pdo);
$rewardTiers = dd_analytics_fetch_reward_tiers($pdo);
$funnelSnapshot = dd_analytics_guess_funnel_snapshot($pdo, $days);

$maxPageViews = 0;
foreach ($topPages as $row) {
    $maxPageViews = max($maxPageViews, (int) ($row['views'] ?? 0));
}
$maxDailySessions = 0;
foreach ($dailySeries as $row) {
    $maxDailySessions = max($maxDailySessions, (int) ($row['session_count'] ?? 0));
}
$maxSourceSessions = 0;
foreach ($topSources as $row) {
    $maxSourceSessions = max($maxSourceSessions, (int) ($row['sessions'] ?? 0));
}
$maxGeoSessions = 0;
foreach ([$topCountries, $topRegions, $topCities, $deviceBreakdown, $browserBreakdown, $osBreakdown, $serviceMix, $rewardTiers, $landingPages, $exitPages] as $group) {
    foreach ($group as $row) {
        $maxGeoSessions = max(
            $maxGeoSessions,
            (int) ($row['sessions'] ?? 0),
            (int) ($row['count'] ?? 0),
            (int) ($row['member_count'] ?? 0),
            (int) ($row['exits'] ?? 0)
        );
    }
}

$summaryCards = [
    ['label' => 'Unique Visitors', 'value' => analytics_number($kpis['visitors']), 'note' => 'Distinct visitors over the selected window'],
    ['label' => 'Sessions', 'value' => analytics_number($kpis['sessions']), 'note' => 'Tracked visits started within the selected window'],
    ['label' => 'Pageviews', 'value' => analytics_number($kpis['pageviews']), 'note' => 'Server-side page view records'],
    ['label' => 'Tracked Events', 'value' => analytics_number($kpis['events']), 'note' => 'Clicks, scrolls, form submits, exits, and page meta'],
    ['label' => 'Avg Pages / Session', 'value' => number_format((float) $kpis['avg_pages_per_session'], 2), 'note' => 'Deeper browsing means stronger intent'],
    ['label' => 'Avg Session Length', 'value' => analytics_duration_label($kpis['avg_session_seconds']), 'note' => 'Approximate based on page exit beacons'],
    ['label' => 'Quick Exit Rate', 'value' => analytics_pct($kpis['bounce_like_rate']), 'note' => analytics_number($kpis['bounce_like_sessions']) . ' quick-exit sessions'],
    ['label' => 'Badges in Vault', 'value' => analytics_number($businessSnapshot['badges']), 'note' => analytics_number($businessSnapshot['badged_members']) . ' members currently badged'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= analytics_h($pageTitle) ?></title>
    <meta name="description" content="Advanced visitor, funnel, badge, and business analytics for Doggie Dorian’s.">
    <style>
        * { box-sizing: border-box; }

        :root {
            --bg: #05080e;
            --bg-deep: #09111c;
            --panel: rgba(11, 18, 30, 0.92);
            --panel-strong: rgba(255,255,255,0.06);
            --line: rgba(255,255,255,0.10);
            --line-soft: rgba(255,255,255,0.06);
            --text: #eef4fb;
            --muted: #9db1c7;
            --gold: #d4af37;
            --gold-soft: #f5df9f;
            --blue: #8bc3ff;
            --green: #7fe0aa;
            --red: #f09191;
            --shadow: 0 28px 80px rgba(0,0,0,0.42);
            --radius: 26px;
            --radius-sm: 18px;
            --max: 1580px;
        }

        html, body {
            margin: 0;
            padding: 0;
            min-height: 100%;
            color: var(--text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top left, rgba(212,175,55,0.14), transparent 25%),
                radial-gradient(circle at top right, rgba(139,195,255,0.10), transparent 22%),
                linear-gradient(180deg, #06101b 0%, #0a1320 48%, #05080e 100%);
        }

        a { color: inherit; text-decoration: none; }

        .page {
            max-width: var(--max);
            margin: 0 auto;
            padding: 28px 18px 80px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 18px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }

        .eyebrow {
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--gold-soft);
        }

        h1, h2, h3 {
            margin: 0;
            letter-spacing: -0.04em;
        }

        h1 {
            font-size: clamp(34px, 4vw, 56px);
            line-height: 0.96;
        }

        .subtext {
            color: var(--muted);
            line-height: 1.7;
            max-width: 900px;
            font-size: 15px;
            margin-top: 12px;
        }

        .top-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .top-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 0 18px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: rgba(255,255,255,0.04);
            color: var(--text);
            font-weight: 700;
        }

        .top-btn.primary {
            background: linear-gradient(135deg, #f1d883 0%, #d4af37 55%, #b88f1b 100%);
            color: #0a0f17;
            border-color: rgba(212, 175, 55, 0.48);
        }

        .hero {
            border-radius: 30px;
            border: 1px solid rgba(212,175,55,0.16);
            background: linear-gradient(180deg, rgba(11,18,30,0.98), rgba(11,18,30,0.86));
            padding: 24px;
            box-shadow: var(--shadow);
            margin-bottom: 24px;
        }

        .range-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
        }

        .range-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 16px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: rgba(255,255,255,0.04);
            font-weight: 700;
            color: var(--muted);
        }

        .range-pill.active {
            background: rgba(212,175,55,0.18);
            border-color: rgba(212,175,55,0.34);
            color: #fff6d8;
        }

        .grid {
            display: grid;
            gap: 18px;
        }

        .summary-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr));
            margin-bottom: 24px;
        }

        .two-col {
            grid-template-columns: minmax(0, 1.35fr) minmax(360px, 0.9fr);
            margin-bottom: 24px;
        }

        .three-col {
            grid-template-columns: repeat(3, minmax(0, 1fr));
            margin-bottom: 24px;
        }

        .card {
            background: linear-gradient(180deg, rgba(11,18,30,0.96), rgba(11,18,30,0.86));
            border: 1px solid var(--line-soft);
            border-radius: var(--radius);
            padding: 22px;
            box-shadow: var(--shadow);
        }

        .metric-card {
            display: grid;
            gap: 10px;
        }

        .metric-label {
            color: var(--muted);
            font-size: 12px;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            font-weight: 800;
        }

        .metric-value {
            font-size: clamp(28px, 3vw, 44px);
            line-height: 1;
            font-weight: 900;
            letter-spacing: -0.05em;
        }

        .metric-note {
            color: var(--muted);
            line-height: 1.6;
            font-size: 14px;
        }

        .section-head {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-end;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .section-copy {
            color: var(--muted);
            line-height: 1.7;
            max-width: 880px;
        }

        .stat-strip {
            display: grid;
            grid-template-columns: repeat(4, minmax(0,1fr));
            gap: 12px;
        }

        .mini-stat {
            border-radius: 18px;
            border: 1px solid var(--line);
            background: rgba(255,255,255,0.03);
            padding: 16px;
            display: grid;
            gap: 6px;
        }

        .mini-stat .label {
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            font-weight: 800;
        }

        .mini-stat .value {
            font-size: 24px;
            font-weight: 900;
            letter-spacing: -0.04em;
        }

        .bars {
            display: grid;
            gap: 12px;
        }

        .bar-row {
            display: grid;
            gap: 8px;
        }

        .bar-top {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: baseline;
        }

        .bar-label {
            font-weight: 700;
            color: var(--text);
        }

        .bar-value {
            color: var(--gold-soft);
            font-weight: 800;
        }

        .bar-track {
            width: 100%;
            height: 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            overflow: hidden;
        }

        .bar-fill {
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, #9acaff 0%, #d4af37 100%);
        }

        .line-chart {
            display: grid;
            gap: 12px;
        }

        .line-points {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(34px, 1fr));
            align-items: end;
            gap: 8px;
            min-height: 240px;
            padding-top: 24px;
        }

        .line-point {
            display: grid;
            justify-items: center;
            gap: 8px;
            align-items: end;
        }

        .line-bar {
            width: 100%;
            max-width: 24px;
            border-radius: 999px 999px 8px 8px;
            background: linear-gradient(180deg, rgba(212,175,55,0.95), rgba(139,195,255,0.82));
            min-height: 8px;
            box-shadow: 0 12px 30px rgba(212,175,55,0.16);
        }

        .line-count {
            font-size: 12px;
            color: #fff;
            font-weight: 700;
        }

        .line-label {
            font-size: 11px;
            color: var(--muted);
            writing-mode: vertical-rl;
            transform: rotate(180deg);
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
        }

        th, td {
            padding: 14px 12px;
            border-bottom: 1px solid var(--line-soft);
            text-align: left;
            vertical-align: top;
        }

        th {
            color: var(--gold-soft);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.16em;
        }

        td {
            color: var(--text);
            font-size: 14px;
            line-height: 1.55;
        }

        .muted { color: var(--muted); }
        .pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 28px;
            padding: 0 10px;
            border-radius: 999px;
            font-size: 11px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            font-weight: 800;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.05);
            color: #fff;
            white-space: nowrap;
        }

        .pill.good { background: rgba(127,224,170,0.14); color: #ccffe4; border-color: rgba(127,224,170,0.24); }
        .pill.gold { background: rgba(212,175,55,0.16); color: #fff1c8; border-color: rgba(212,175,55,0.28); }
        .pill.blue { background: rgba(139,195,255,0.16); color: #e0f1ff; border-color: rgba(139,195,255,0.28); }
        .pill.red { background: rgba(240,145,145,0.16); color: #ffd9d9; border-color: rgba(240,145,145,0.28); }

        .event-list {
            display: grid;
            gap: 12px;
        }

        .event-item {
            padding: 16px;
            border-radius: 18px;
            border: 1px solid var(--line);
            background: rgba(255,255,255,0.03);
            display: grid;
            gap: 8px;
        }

        .event-top {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        .event-title {
            font-weight: 800;
            color: #fff;
        }

        .event-meta {
            font-size: 13px;
            color: var(--muted);
            line-height: 1.6;
        }

        .split {
            display: grid;
            gap: 16px;
            grid-template-columns: repeat(2, minmax(0,1fr));
        }

        .footer-note {
            color: var(--muted);
            font-size: 13px;
            margin-top: 12px;
            line-height: 1.7;
        }

        @media (max-width: 1280px) {
            .summary-grid, .three-col, .stat-strip { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .two-col { grid-template-columns: 1fr; }
        }

        @media (max-width: 760px) {
            .page { padding: 22px 12px 64px; }
            .summary-grid, .three-col, .split, .stat-strip { grid-template-columns: 1fr; }
            .line-points { grid-template-columns: repeat(auto-fit, minmax(28px, 1fr)); }
            .line-label { writing-mode: initial; transform: none; }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="topbar">
            <div>
                <div class="eyebrow">Doggie Dorian’s Admin</div>
                <h1>Admin Analytics</h1>
                <div class="subtext">
                    First-party visitor analytics, page flow intelligence, approximate geo by IP, badge and reward insight, plus business snapshot cards — all without a browser permission prompt.
                </div>
            </div>
            <div class="top-actions">
                <a class="top-btn" href="admin-dashboard.php">Dashboard</a>
                <a class="top-btn" href="admin-nav.php">Admin Nav</a>
                <a class="top-btn" href="admin-analytics-tracking.php">Analytics Tracking</a>
                <a class="top-btn primary" href="admin-analytics.php">Analytics</a>
            </div>
        </div>

        <section class="hero">
            <div class="eyebrow">Window</div>
            <h2 style="font-size: 30px;">Traffic, conversion, and member intelligence in one place</h2>
            <div class="subtext">
                This dashboard blends real visitor behavior, session quality, geo/source data, and your internal Doggie Dorian’s business records. It is designed to show you where traffic comes from, how people move through the site, and how member progress connects to badges and rewards.
            </div>

            <div class="range-pills">
                <?php foreach ($allowedDays as $option): ?>
                    <a class="range-pill <?= $days === $option ? 'active' : '' ?>" href="admin-analytics.php?days=<?= (int) $option ?>">
                        Last <?= (int) $option ?> days
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="grid summary-grid">
            <?php foreach ($summaryCards as $card): ?>
                <div class="card metric-card">
                    <div class="metric-label"><?= analytics_h($card['label']) ?></div>
                    <div class="metric-value"><?= analytics_h($card['value']) ?></div>
                    <div class="metric-note"><?= analytics_h($card['note']) ?></div>
                </div>
            <?php endforeach; ?>
        </section>

        <section class="card" style="margin-bottom:24px;">
            <div class="section-head">
                <div>
                    <div class="eyebrow">Business Snapshot</div>
                    <h2 style="font-size:30px;">Live business totals from your database</h2>
                </div>
                <div class="section-copy">
                    These cards read from your current business tables and badge vault so you can compare site traffic against operational growth.
                </div>
            </div>

            <div class="stat-strip">
                <div class="mini-stat">
                    <div class="label">Members</div>
                    <div class="value"><?= analytics_number($businessSnapshot['members']) ?></div>
                    <div class="muted">Core member count from your live records</div>
                </div>
                <div class="mini-stat">
                    <div class="label">Pets</div>
                    <div class="value"><?= analytics_number($businessSnapshot['pets']) ?></div>
                    <div class="muted">Pets currently stored in the platform</div>
                </div>
                <div class="mini-stat">
                    <div class="label">Member Bookings</div>
                    <div class="value"><?= analytics_number($businessSnapshot['member_bookings']) ?></div>
                    <div class="muted">Internal member booking volume</div>
                </div>
                <div class="mini-stat">
                    <div class="label">Public Bookings</div>
                    <div class="value"><?= analytics_number($businessSnapshot['public_bookings']) ?></div>
                    <div class="muted">Non-member booking volume</div>
                </div>
                <div class="mini-stat">
                    <div class="label">Member Revenue</div>
                    <div class="value"><?= analytics_money($businessSnapshot['member_revenue']) ?></div>
                    <div class="muted">Booking-value sum from member bookings</div>
                </div>
                <div class="mini-stat">
                    <div class="label">Public Revenue</div>
                    <div class="value"><?= analytics_money($businessSnapshot['public_revenue']) ?></div>
                    <div class="muted">Booking-value sum from non-member bookings</div>
                </div>
                <div class="mini-stat">
                    <div class="label">Founder Walk</div>
                    <div class="value"><?= analytics_number($businessSnapshot['founder_walk']) ?></div>
                    <div class="muted">Members with the Founding Walker distinction</div>
                </div>
                <div class="mini-stat">
                    <div class="label">Founder Care / Elite</div>
                    <div class="value"><?= analytics_number($businessSnapshot['founder_care']) ?> / <?= analytics_number($businessSnapshot['founder_elite']) ?></div>
                    <div class="muted">Founder Care and Elite badge holders</div>
                </div>
            </div>
        </section>

        <section class="grid two-col">
            <div class="card">
                <div class="section-head">
                    <div>
                        <div class="eyebrow">Session Trend</div>
                        <h2 style="font-size:30px;">Daily traffic pattern</h2>
                    </div>
                    <div class="section-copy">
                        Sessions, unique visitors, pageviews, and tracked actions grouped by day.
                    </div>
                </div>

                <?php if ($dailySeries !== []): ?>
                    <div class="line-chart">
                        <div class="line-points">
                            <?php foreach ($dailySeries as $point): ?>
                                <?php
                                $height = $maxDailySessions > 0 ? max(10, (int) round(((int) ($point['session_count'] ?? 0) / $maxDailySessions) * 180)) : 10;
                                ?>
                                <div class="line-point">
                                    <div class="line-count"><?= analytics_number($point['session_count'] ?? 0) ?></div>
                                    <div class="line-bar" style="height: <?= $height ?>px;"></div>
                                    <div class="line-label"><?= analytics_h((string) ($point['day_label'] ?? '')) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Day</th>
                                        <th>Sessions</th>
                                        <th>Visitors</th>
                                        <th>Pageviews</th>
                                        <th>Events</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dailySeries as $point): ?>
                                        <tr>
                                            <td><?= analytics_h((string) ($point['day_label'] ?? '')) ?></td>
                                            <td><?= analytics_number($point['session_count'] ?? 0) ?></td>
                                            <td><?= analytics_number($point['visitor_count'] ?? 0) ?></td>
                                            <td><?= analytics_number($point['pageviews'] ?? 0) ?></td>
                                            <td><?= analytics_number($point['events'] ?? 0) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="footer-note">No tracked session history has been recorded yet for this window.</div>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="section-head">
                    <div>
                        <div class="eyebrow">Funnel Guess</div>
                        <h2 style="font-size:30px;">Membership and booking path</h2>
                    </div>
                    <div class="section-copy">
                        These counts are inferred from tracked page views and form submits already happening on the site.
                    </div>
                </div>

                <div class="bars">
                    <?php
                    $funnelRows = [
                        ['label' => 'Membership Page Views', 'count' => (int) $funnelSnapshot['membership_views']],
                        ['label' => 'Signup Page Views', 'count' => (int) $funnelSnapshot['signup_views']],
                        ['label' => 'Booking Page Views', 'count' => (int) $funnelSnapshot['booking_views']],
                        ['label' => 'Form Submits', 'count' => (int) $funnelSnapshot['form_submits']],
                        ['label' => 'Payment Success Views', 'count' => (int) $funnelSnapshot['payment_success_views']],
                    ];
                    $funnelMax = 0;
                    foreach ($funnelRows as $funnelRow) {
                        $funnelMax = max($funnelMax, $funnelRow['count']);
                    }
                    ?>
                    <?php foreach ($funnelRows as $funnelRow): ?>
                        <?php $width = $funnelMax > 0 ? max(3, (int) round(($funnelRow['count'] / $funnelMax) * 100)) : 0; ?>
                        <div class="bar-row">
                            <div class="bar-top">
                                <div class="bar-label"><?= analytics_h($funnelRow['label']) ?></div>
                                <div class="bar-value"><?= analytics_number($funnelRow['count']) ?></div>
                            </div>
                            <div class="bar-track"><div class="bar-fill" style="width: <?= $width ?>%;"></div></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="footer-note">
                    This view gets stronger as more real traffic passes through the site and more tracked form submits accumulate.
                </div>
            </div>
        </section>

        <section class="grid three-col">
            <div class="card">
                <div class="section-head">
                    <div>
                        <div class="eyebrow">Top Sources</div>
                        <h2 style="font-size:26px;">Where traffic is coming from</h2>
                    </div>
                </div>
                <div class="bars">
                    <?php foreach ($topSources as $row): ?>
                        <?php $width = $maxSourceSessions > 0 ? max(3, (int) round(((int) ($row['sessions'] ?? 0) / $maxSourceSessions) * 100)) : 0; ?>
                        <div class="bar-row">
                            <div class="bar-top">
                                <div class="bar-label"><?= analytics_h((string) ($row['source_label'] ?? 'direct')) ?></div>
                                <div class="bar-value"><?= analytics_number($row['sessions'] ?? 0) ?></div>
                            </div>
                            <div class="bar-track"><div class="bar-fill" style="width: <?= $width ?>%;"></div></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($topSources === []): ?>
                        <div class="footer-note">No source data has been logged yet.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="section-head">
                    <div>
                        <div class="eyebrow">Top Pages</div>
                        <h2 style="font-size:26px;">Most viewed URLs</h2>
                    </div>
                </div>
                <div class="bars">
                    <?php foreach (array_slice($topPages, 0, 8) as $row): ?>
                        <?php $width = $maxPageViews > 0 ? max(3, (int) round(((int) ($row['views'] ?? 0) / $maxPageViews) * 100)) : 0; ?>
                        <div class="bar-row">
                            <div class="bar-top">
                                <div class="bar-label"><?= analytics_h((string) ($row['page_path'] ?? '/')) ?></div>
                                <div class="bar-value"><?= analytics_number($row['views'] ?? 0) ?></div>
                            </div>
                            <div class="bar-track"><div class="bar-fill" style="width: <?= $width ?>%;"></div></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($topPages === []): ?>
                        <div class="footer-note">No pageview data has been logged yet.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="section-head">
                    <div>
                        <div class="eyebrow">Service Mix</div>
                        <h2 style="font-size:26px;">Operational demand by service</h2>
                    </div>
                </div>
                <div class="bars">
                    <?php foreach (array_slice($serviceMix, 0, 8) as $row): ?>
                        <?php $width = $maxGeoSessions > 0 ? max(3, (int) round(((int) ($row['count'] ?? 0) / $maxGeoSessions) * 100)) : 0; ?>
                        <div class="bar-row">
                            <div class="bar-top">
                                <div class="bar-label"><?= analytics_h((string) ($row['label'] ?? 'Service')) ?></div>
                                <div class="bar-value"><?= analytics_number($row['count'] ?? 0) ?></div>
                            </div>
                            <div class="bar-track"><div class="bar-fill" style="width: <?= $width ?>%;"></div></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($serviceMix === []): ?>
                        <div class="footer-note">Service mix becomes available when booking tables contain service labels.</div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="grid three-col">
            <div class="card">
                <div class="section-head">
                    <div>
                        <div class="eyebrow">Geo</div>
                        <h2 style="font-size:26px;">Top countries</h2>
                    </div>
                </div>
                <div class="bars">
                    <?php foreach ($topCountries as $row): ?>
                        <?php $width = $maxGeoSessions > 0 ? max(3, (int) round(((int) ($row['sessions'] ?? 0) / $maxGeoSessions) * 100)) : 0; ?>
                        <div class="bar-row">
                            <div class="bar-top">
                                <div class="bar-label"><?= analytics_h((string) ($row['label'] ?? 'Unknown')) ?></div>
                                <div class="bar-value"><?= analytics_number($row['sessions'] ?? 0) ?></div>
                            </div>
                            <div class="bar-track"><div class="bar-fill" style="width: <?= $width ?>%;"></div></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($topCountries === []): ?><div class="footer-note">Geo sessions will appear once public IP sessions are enriched.</div><?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="section-head">
                    <div>
                        <div class="eyebrow">Geo</div>
                        <h2 style="font-size:26px;">Top regions / states</h2>
                    </div>
                </div>
                <div class="bars">
                    <?php foreach ($topRegions as $row): ?>
                        <?php $width = $maxGeoSessions > 0 ? max(3, (int) round(((int) ($row['sessions'] ?? 0) / $maxGeoSessions) * 100)) : 0; ?>
                        <div class="bar-row">
                            <div class="bar-top">
                                <div class="bar-label"><?= analytics_h((string) ($row['label'] ?? 'Unknown')) ?></div>
                                <div class="bar-value"><?= analytics_number($row['sessions'] ?? 0) ?></div>
                            </div>
                            <div class="bar-track"><div class="bar-fill" style="width: <?= $width ?>%;"></div></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($topRegions === []): ?><div class="footer-note">Regional data is approximate and depends on IP enrichment.</div><?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="section-head">
                    <div>
                        <div class="eyebrow">Geo</div>
                        <h2 style="font-size:26px;">Top cities</h2>
                    </div>
                </div>
                <div class="bars">
                    <?php foreach ($topCities as $row): ?>
                        <?php $width = $maxGeoSessions > 0 ? max(3, (int) round(((int) ($row['sessions'] ?? 0) / $maxGeoSessions) * 100)) : 0; ?>
                        <div class="bar-row">
                            <div class="bar-top">
                                <div class="bar-label"><?= analytics_h((string) ($row['label'] ?? 'Unknown')) ?></div>
                                <div class="bar-value"><?= analytics_number($row['sessions'] ?? 0) ?></div>
                            </div>
                            <div class="bar-track"><div class="bar-fill" style="width: <?= $width ?>%;"></div></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($topCities === []): ?><div class="footer-note">City-level location remains approximate from IP geolocation.</div><?php endif; ?>
                </div>
            </div>
        </section>

        <section class="grid three-col">
            <div class="card">
                <div class="section-head">
                    <div>
                        <div class="eyebrow">Devices</div>
                        <h2 style="font-size:26px;">Device type split</h2>
                    </div>
                </div>
                <div class="bars">
                    <?php foreach ($deviceBreakdown as $row): ?>
                        <?php $width = $maxGeoSessions > 0 ? max(3, (int) round(((int) ($row['sessions'] ?? 0) / $maxGeoSessions) * 100)) : 0; ?>
                        <div class="bar-row">
                            <div class="bar-top">
                                <div class="bar-label"><?= analytics_h((string) ($row['label'] ?? 'Unknown')) ?></div>
                                <div class="bar-value"><?= analytics_number($row['sessions'] ?? 0) ?></div>
                            </div>
                            <div class="bar-track"><div class="bar-fill" style="width: <?= $width ?>%;"></div></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <div class="section-head">
                    <div>
                        <div class="eyebrow">Browsers</div>
                        <h2 style="font-size:26px;">Browser mix</h2>
                    </div>
                </div>
                <div class="bars">
                    <?php foreach ($browserBreakdown as $row): ?>
                        <?php $width = $maxGeoSessions > 0 ? max(3, (int) round(((int) ($row['sessions'] ?? 0) / $maxGeoSessions) * 100)) : 0; ?>
                        <div class="bar-row">
                            <div class="bar-top">
                                <div class="bar-label"><?= analytics_h((string) ($row['label'] ?? 'Unknown')) ?></div>
                                <div class="bar-value"><?= analytics_number($row['sessions'] ?? 0) ?></div>
                            </div>
                            <div class="bar-track"><div class="bar-fill" style="width: <?= $width ?>%;"></div></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <div class="section-head">
                    <div>
                        <div class="eyebrow">Operating Systems</div>
                        <h2 style="font-size:26px;">OS mix</h2>
                    </div>
                </div>
                <div class="bars">
                    <?php foreach ($osBreakdown as $row): ?>
                        <?php $width = $maxGeoSessions > 0 ? max(3, (int) round(((int) ($row['sessions'] ?? 0) / $maxGeoSessions) * 100)) : 0; ?>
                        <div class="bar-row">
                            <div class="bar-top">
                                <div class="bar-label"><?= analytics_h((string) ($row['label'] ?? 'Unknown')) ?></div>
                                <div class="bar-value"><?= analytics_number($row['sessions'] ?? 0) ?></div>
                            </div>
                            <div class="bar-track"><div class="bar-fill" style="width: <?= $width ?>%;"></div></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="grid two-col">
            <div class="card">
                <div class="section-head">
                    <div>
                        <div class="eyebrow">Page Performance</div>
                        <h2 style="font-size:30px;">Top page detail</h2>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Page</th>
                                <th>Views</th>
                                <th>Sessions</th>
                                <th>Avg Time</th>
                                <th>Avg Scroll</th>
                                <th>Clicks</th>
                                <th>Form Submits</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topPages as $row): ?>
                                <tr>
                                    <td>
                                        <strong><?= analytics_h((string) ($row['page_path'] ?? '/')) ?></strong>
                                    </td>
                                    <td><?= analytics_number($row['views'] ?? 0) ?></td>
                                    <td><?= analytics_number($row['sessions'] ?? 0) ?></td>
                                    <td><?= analytics_duration_label((int) round((float) ($row['avg_seconds'] ?? 0))) ?></td>
                                    <td><?= analytics_pct($row['avg_scroll'] ?? 0) ?></td>
                                    <td><?= analytics_number($row['total_clicks'] ?? 0) ?></td>
                                    <td><?= analytics_number($row['total_form_submits'] ?? 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($topPages === []): ?>
                                <tr><td colspan="7" class="muted">No page detail recorded yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="section-head">
                    <div>
                        <div class="eyebrow">Badge & Reward View</div>
                        <h2 style="font-size:30px;">Visible tier distribution</h2>
                    </div>
                    <div class="section-copy">
                        Badge counts are grouped into the visible reward tiers you set up for members.
                    </div>
                </div>

                <div class="bars">
                    <?php foreach ($rewardTiers as $tier): ?>
                        <?php $width = $maxGeoSessions > 0 ? max(3, (int) round(((int) ($tier['member_count'] ?? 0) / $maxGeoSessions) * 100)) : 0; ?>
                        <div class="bar-row">
                            <div class="bar-top">
                                <div class="bar-label"><?= analytics_h((string) ($tier['name'] ?? 'Tier')) ?></div>
                                <div class="bar-value"><?= analytics_number($tier['member_count'] ?? 0) ?></div>
                            </div>
                            <div class="bar-track"><div class="bar-fill" style="width: <?= $width ?>%;"></div></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="split" style="margin-top:20px;">
                    <div class="mini-stat">
                        <div class="label">Landing Leaders</div>
                        <div class="value"><?= analytics_number(array_sum(array_map(static fn($row) => (int) ($row['sessions'] ?? 0), $landingPages))) ?></div>
                        <div class="muted">Sessions summarized from top entry pages</div>
                    </div>
                    <div class="mini-stat">
                        <div class="label">Top Exits</div>
                        <div class="value"><?= analytics_number(array_sum(array_map(static fn($row) => (int) ($row['exits'] ?? 0), $exitPages))) ?></div>
                        <div class="muted">Sessions with a tracked exit page</div>
                    </div>
                </div>

                <div class="footer-note">
                    Tier counts become stronger as your badge roadmap expands and more members unlock milestone badges.
                </div>
            </div>
        </section>

        <section class="grid two-col">
            <div class="card">
                <div class="section-head">
                    <div>
                        <div class="eyebrow">Landings & Exits</div>
                        <h2 style="font-size:30px;">Entry and departure pages</h2>
                    </div>
                </div>
                <div class="split">
                    <div>
                        <div class="metric-label" style="margin-bottom:10px;">Top Landing Pages</div>
                        <div class="bars">
                            <?php foreach ($landingPages as $row): ?>
                                <?php $width = $maxGeoSessions > 0 ? max(3, (int) round(((int) ($row['sessions'] ?? 0) / $maxGeoSessions) * 100)) : 0; ?>
                                <div class="bar-row">
                                    <div class="bar-top">
                                        <div class="bar-label"><?= analytics_h((string) ($row['landing_page'] ?? '/')) ?></div>
                                        <div class="bar-value"><?= analytics_number($row['sessions'] ?? 0) ?></div>
                                    </div>
                                    <div class="bar-track"><div class="bar-fill" style="width: <?= $width ?>%;"></div></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div>
                        <div class="metric-label" style="margin-bottom:10px;">Top Exit Pages</div>
                        <div class="bars">
                            <?php foreach ($exitPages as $row): ?>
                                <?php $width = $maxGeoSessions > 0 ? max(3, (int) round(((int) ($row['exits'] ?? 0) / $maxGeoSessions) * 100)) : 0; ?>
                                <div class="bar-row">
                                    <div class="bar-top">
                                        <div class="bar-label"><?= analytics_h((string) ($row['exit_page'] ?? '/')) ?></div>
                                        <div class="bar-value"><?= analytics_number($row['exits'] ?? 0) ?></div>
                                    </div>
                                    <div class="bar-track"><div class="bar-fill" style="width: <?= $width ?>%;"></div></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="section-head">
                    <div>
                        <div class="eyebrow">Recent Tracked Activity</div>
                        <h2 style="font-size:30px;">Latest signals</h2>
                    </div>
                </div>

                <div class="event-list">
                    <?php foreach ($recentEvents as $event): ?>
                        <?php
                        $eventType = (string) ($event['event_type'] ?? 'event');
                        $pillClass = 'blue';
                        if ($eventType === 'form_submit') {
                            $pillClass = 'good';
                        } elseif ($eventType === 'page_exit') {
                            $pillClass = 'gold';
                        } elseif ($eventType === 'click') {
                            $pillClass = 'blue';
                        } elseif ($eventType === 'scroll') {
                            $pillClass = 'red';
                        }
                        ?>
                        <div class="event-item">
                            <div class="event-top">
                                <div class="event-title"><?= analytics_h((string) ($event['label'] ?? $event['event_name'] ?? 'Activity')) ?></div>
                                <span class="pill <?= analytics_h($pillClass) ?>"><?= analytics_h($eventType) ?></span>
                            </div>
                            <div class="event-meta">
                                <strong>Page:</strong> <?= analytics_h((string) ($event['page_path'] ?? '')) ?><br>
                                <strong>When:</strong> <?= analytics_h((string) ($event['created_at'] ?? '')) ?><br>
                                <strong>Session:</strong> <?= analytics_h((string) ($event['session_uuid'] ?? '')) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($recentEvents === []): ?>
                        <div class="footer-note">No recent event detail has been captured yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>
</body>
</html>
