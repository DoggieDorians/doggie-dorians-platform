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

function tracking_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function tracking_number(int|float|string|null $value): string
{
    return number_format((float) ($value ?? 0));
}

function tracking_duration(int|float|string|null $value): string
{
    return dd_analytics_format_duration((int) round((float) ($value ?? 0)));
}

$allowedDays = [1, 7, 30, 90, 365];
$days = (int) ($_GET['days'] ?? 30);
if (!in_array($days, $allowedDays, true)) {
    $days = 30;
}

$filters = [
    'days' => $days,
    'event_type' => trim((string) ($_GET['event_type'] ?? '')),
    'page_path' => trim((string) ($_GET['page_path'] ?? '')),
    'source' => trim((string) ($_GET['source'] ?? '')),
    'country' => trim((string) ($_GET['country'] ?? '')),
    'device_type' => trim((string) ($_GET['device_type'] ?? '')),
    'search' => trim((string) ($_GET['search'] ?? '')),
    'session_uuid' => trim((string) ($_GET['session_uuid'] ?? '')),
];

$filteredSessions = dd_analytics_fetch_filtered_sessions($pdo, $filters, 80);
$filteredEvents = dd_analytics_fetch_filtered_events($pdo, $filters, 120);
$selectedSessionUuid = $filters['session_uuid'];
if ($selectedSessionUuid === '' && $filteredSessions !== []) {
    $selectedSessionUuid = trim((string) ($filteredSessions[0]['session_uuid'] ?? ''));
}
$selectedSessionPageviews = $selectedSessionUuid !== '' ? dd_analytics_fetch_session_pageviews($pdo, $selectedSessionUuid) : [];

$eventTypeOptions = dd_analytics_safe_fetch_all(
    $pdo,
    'SELECT event_type, COUNT(*) AS item_count FROM analytics_events GROUP BY event_type ORDER BY item_count DESC, event_type ASC'
);
$deviceOptions = dd_analytics_safe_fetch_all(
    $pdo,
    'SELECT device_type, COUNT(*) AS item_count FROM analytics_sessions WHERE device_type <> "" GROUP BY device_type ORDER BY item_count DESC'
);

$totals = [
    'session_count' => count($filteredSessions),
    'event_count' => count($filteredEvents),
    'selected_pageviews' => count($selectedSessionPageviews),
];

$pageTitle = 'Admin Analytics Tracking | Doggie Dorian’s';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= tracking_h($pageTitle) ?></title>
    <meta name="description" content="Detailed raw analytics tracking explorer for Doggie Dorian’s admin.">
    <style>
        * { box-sizing: border-box; }

        :root {
            --bg: #06101a;
            --panel: rgba(10, 17, 29, 0.94);
            --line: rgba(255,255,255,0.10);
            --line-soft: rgba(255,255,255,0.06);
            --text: #eef4fb;
            --muted: #9db1c7;
            --gold: #d4af37;
            --gold-soft: #f5df9f;
            --blue: #91c6ff;
            --green: #7be1aa;
            --red: #f4a0a0;
            --shadow: 0 28px 80px rgba(0,0,0,0.42);
            --radius: 24px;
            --radius-sm: 16px;
            --max: 1680px;
        }

        html, body {
            margin: 0;
            padding: 0;
            min-height: 100%;
            background:
                radial-gradient(circle at top left, rgba(212,175,55,0.14), transparent 25%),
                radial-gradient(circle at top right, rgba(145,198,255,0.10), transparent 24%),
                linear-gradient(180deg, #06101a 0%, #0a1320 52%, #05080e 100%);
            color: var(--text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        a { color: inherit; text-decoration: none; }

        .page {
            max-width: var(--max);
            margin: 0 auto;
            padding: 28px 18px 82px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            flex-wrap: wrap;
            align-items: flex-start;
            margin-bottom: 24px;
        }

        .eyebrow {
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--gold-soft);
        }

        h1, h2, h3 { margin: 0; letter-spacing: -0.04em; }
        h1 { font-size: clamp(34px, 4vw, 56px); line-height: 0.96; }

        .subtext {
            color: var(--muted);
            line-height: 1.7;
            font-size: 15px;
            max-width: 920px;
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
            border-color: rgba(212,175,55,0.48);
        }

        .card {
            background: linear-gradient(180deg, rgba(10,17,29,0.98), rgba(10,17,29,0.88));
            border: 1px solid var(--line-soft);
            border-radius: var(--radius);
            padding: 22px;
            box-shadow: var(--shadow);
        }

        .hero {
            margin-bottom: 24px;
            border: 1px solid rgba(212,175,55,0.16);
        }

        .filters {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 12px;
            margin-top: 20px;
        }

        .field {
            display: grid;
            gap: 8px;
        }

        .field label {
            color: var(--gold-soft);
            font-size: 12px;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            font-weight: 800;
        }

        input, select {
            width: 100%;
            min-height: 48px;
            border-radius: 14px;
            border: 1px solid var(--line);
            background: rgba(255,255,255,0.04);
            color: #fff;
            padding: 0 14px;
            outline: none;
        }

        input:focus, select:focus {
            border-color: rgba(212,175,55,0.42);
            box-shadow: 0 0 0 3px rgba(212,175,55,0.12);
        }

        .filter-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 16px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 0 16px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: rgba(255,255,255,0.04);
            color: var(--text);
            font-weight: 700;
        }

        .btn.primary {
            background: linear-gradient(135deg, #f1d883 0%, #d4af37 55%, #b88f1b 100%);
            color: #0a0f17;
        }

        .quick-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 16px;
        }

        .quick-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 0 14px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: rgba(255,255,255,0.04);
            color: var(--muted);
            font-weight: 700;
        }

        .quick-pill.active {
            background: rgba(212,175,55,0.16);
            color: #fff3cd;
            border-color: rgba(212,175,55,0.28);
        }

        .summary-grid {
            display: grid;
            gap: 18px;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            margin-bottom: 24px;
        }

        .metric-card {
            display: grid;
            gap: 10px;
        }

        .metric-label {
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.16em;
            font-weight: 800;
        }

        .metric-value {
            font-size: clamp(28px, 3vw, 42px);
            line-height: 1;
            font-weight: 900;
            letter-spacing: -0.05em;
        }

        .metric-note {
            color: var(--muted);
            line-height: 1.6;
            font-size: 14px;
        }

        .layout {
            display: grid;
            grid-template-columns: 1.15fr 0.95fr;
            gap: 18px;
        }

        .panel-stack {
            display: grid;
            gap: 18px;
        }

        .section-head {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            align-items: flex-end;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .section-copy {
            color: var(--muted);
            line-height: 1.7;
            max-width: 840px;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 860px;
        }

        th, td {
            border-bottom: 1px solid var(--line-soft);
            padding: 13px 12px;
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

        .session-card, .flow-item {
            display: grid;
            gap: 8px;
            border-radius: 18px;
            border: 1px solid var(--line);
            background: rgba(255,255,255,0.03);
            padding: 16px;
        }

        .session-list, .flow-list {
            display: grid;
            gap: 12px;
        }

        .session-top {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        .session-title {
            font-weight: 800;
            color: #fff;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 28px;
            padding: 0 10px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.10);
            font-size: 11px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            font-weight: 800;
            background: rgba(255,255,255,0.05);
            color: #fff;
            white-space: nowrap;
        }

        .pill.gold { background: rgba(212,175,55,0.16); color: #fff1c8; border-color: rgba(212,175,55,0.28); }
        .pill.blue { background: rgba(145,198,255,0.16); color: #dff0ff; border-color: rgba(145,198,255,0.28); }
        .pill.good { background: rgba(123,225,170,0.16); color: #d7ffea; border-color: rgba(123,225,170,0.28); }
        .pill.red { background: rgba(244,160,160,0.16); color: #ffd7d7; border-color: rgba(244,160,160,0.28); }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0,1fr));
            gap: 10px 16px;
            font-size: 13px;
            color: var(--muted);
            line-height: 1.6;
        }

        .meta-grid strong { color: #fff; }

        .empty {
            color: var(--muted);
            padding: 18px;
            border-radius: 16px;
            background: rgba(255,255,255,0.03);
            border: 1px dashed rgba(255,255,255,0.10);
        }

        @media (max-width: 1320px) {
            .filters { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .layout { grid-template-columns: 1fr; }
        }

        @media (max-width: 860px) {
            .page { padding: 22px 12px 64px; }
            .filters, .summary-grid, .meta-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="topbar">
            <div>
                <div class="eyebrow">Doggie Dorian’s Admin</div>
                <h1>Analytics Tracking Explorer</h1>
                <div class="subtext">
                    Raw session and event intelligence. Filter by source, page, event type, device, country, or a specific session to inspect exactly how visitors moved through the site.
                </div>
            </div>
            <div class="top-actions">
                <a class="top-btn" href="admin-dashboard.php">Dashboard</a>
                <a class="top-btn" href="admin-nav.php">Admin Nav</a>
                <a class="top-btn" href="admin-analytics.php">Analytics</a>
                <a class="top-btn primary" href="admin-analytics-tracking.php">Tracking Explorer</a>
            </div>
        </div>

        <section class="card hero">
            <div class="eyebrow">Filters</div>
            <h2 style="font-size:30px;">Slice the traffic any way you want</h2>
            <div class="subtext">
                Use the filters below to narrow the explorer down to specific event types, pages, traffic sources, device categories, regions, or an exact session UUID.
            </div>

            <div class="quick-pills">
                <?php foreach ($allowedDays as $option): ?>
                    <?php
                    $query = $_GET;
                    $query['days'] = $option;
                    $href = 'admin-analytics-tracking.php?' . http_build_query($query);
                    ?>
                    <a class="quick-pill <?= $days === $option ? 'active' : '' ?>" href="<?= tracking_h($href) ?>">Last <?= (int) $option ?> days</a>
                <?php endforeach; ?>
            </div>

            <form method="get" action="admin-analytics-tracking.php">
                <div class="filters">
                    <div class="field">
                        <label for="days">Window</label>
                        <select id="days" name="days">
                            <?php foreach ($allowedDays as $option): ?>
                                <option value="<?= (int) $option ?>" <?= $days === $option ? 'selected' : '' ?>>Last <?= (int) $option ?> days</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="event_type">Event Type</label>
                        <select id="event_type" name="event_type">
                            <option value="">All event types</option>
                            <?php foreach ($eventTypeOptions as $option): ?>
                                <?php $label = (string) ($option['event_type'] ?? ''); ?>
                                <option value="<?= tracking_h($label) ?>" <?= $filters['event_type'] === $label ? 'selected' : '' ?>>
                                    <?= tracking_h($label) ?> (<?= tracking_number($option['item_count'] ?? 0) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="page_path">Page Path</label>
                        <input id="page_path" type="text" name="page_path" value="<?= tracking_h($filters['page_path']) ?>" placeholder="/memberships.php">
                    </div>

                    <div class="field">
                        <label for="source">Source / Referrer</label>
                        <input id="source" type="text" name="source" value="<?= tracking_h($filters['source']) ?>" placeholder="instagram, google, direct">
                    </div>

                    <div class="field">
                        <label for="country">Country / Region / City</label>
                        <input id="country" type="text" name="country" value="<?= tracking_h($filters['country']) ?>" placeholder="New York, Malta, Manhattan">
                    </div>

                    <div class="field">
                        <label for="device_type">Device</label>
                        <select id="device_type" name="device_type">
                            <option value="">All devices</option>
                            <?php foreach ($deviceOptions as $option): ?>
                                <?php $label = (string) ($option['device_type'] ?? ''); ?>
                                <option value="<?= tracking_h($label) ?>" <?= $filters['device_type'] === $label ? 'selected' : '' ?>>
                                    <?= tracking_h($label) ?> (<?= tracking_number($option['item_count'] ?? 0) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="search">Keyword Search</label>
                        <input id="search" type="text" name="search" value="<?= tracking_h($filters['search']) ?>" placeholder="button label, event text, metadata">
                    </div>

                    <div class="field">
                        <label for="session_uuid">Specific Session UUID</label>
                        <input id="session_uuid" type="text" name="session_uuid" value="<?= tracking_h($filters['session_uuid']) ?>" placeholder="Paste a full session UUID">
                    </div>
                </div>

                <div class="filter-actions">
                    <button class="btn primary" type="submit">Apply Filters</button>
                    <a class="btn" href="admin-analytics-tracking.php">Reset Filters</a>
                </div>
            </form>
        </section>

        <section class="summary-grid">
            <div class="card metric-card">
                <div class="metric-label">Filtered Sessions</div>
                <div class="metric-value"><?= tracking_number($totals['session_count']) ?></div>
                <div class="metric-note">Sessions that match the current filter state.</div>
            </div>
            <div class="card metric-card">
                <div class="metric-label">Filtered Events</div>
                <div class="metric-value"><?= tracking_number($totals['event_count']) ?></div>
                <div class="metric-note">Tracked interactions that match the current filter state.</div>
            </div>
            <div class="card metric-card">
                <div class="metric-label">Selected Session Pageviews</div>
                <div class="metric-value"><?= tracking_number($totals['selected_pageviews']) ?></div>
                <div class="metric-note">Page flow depth for the session selected in the filter or session list.</div>
            </div>
        </section>

        <section class="layout">
            <div class="panel-stack">
                <div class="card">
                    <div class="section-head">
                        <div>
                            <div class="eyebrow">Sessions</div>
                            <h2 style="font-size:30px;">Matching sessions</h2>
                        </div>
                        <div class="section-copy">
                            Use these to drill into a specific visit. Clicking a session pill below is the easiest way to isolate one visitor journey.
                        </div>
                    </div>

                    <?php if ($filteredSessions === []): ?>
                        <div class="empty">No sessions matched the current filter set.</div>
                    <?php else: ?>
                        <div class="session-list">
                            <?php foreach ($filteredSessions as $session): ?>
                                <?php
                                $pillClass = ((int) ($session['pageview_count'] ?? 0) > 2) ? 'good' : 'blue';
                                $sessionHrefQuery = $_GET;
                                $sessionHrefQuery['session_uuid'] = (string) ($session['session_uuid'] ?? '');
                                $sessionHref = 'admin-analytics-tracking.php?' . http_build_query($sessionHrefQuery);
                                ?>
                                <div class="session-card">
                                    <div class="session-top">
                                        <div class="session-title"><?= tracking_h((string) ($session['landing_page'] ?? '/')) ?></div>
                                        <a class="pill <?= tracking_h($pillClass) ?>" href="<?= tracking_h($sessionHref) ?>">
                                            <?= tracking_h((string) ($session['session_uuid'] ?? '')) ?>
                                        </a>
                                    </div>
                                    <div class="meta-grid">
                                        <div><strong>Started:</strong> <?= tracking_h((string) ($session['started_at'] ?? '')) ?></div>
                                        <div><strong>Source:</strong> <?= tracking_h((string) dd_analytics_normalize_source($session)) ?></div>
                                        <div><strong>Geo:</strong> <?= tracking_h(trim((string) ($session['city_name'] ?? '') . ', ' . (string) ($session['region_name'] ?? '') . ', ' . (string) ($session['country_name'] ?? ''))) ?></div>
                                        <div><strong>Device:</strong> <?= tracking_h((string) ($session['device_type'] ?? 'Unknown')) ?> / <?= tracking_h((string) ($session['browser'] ?? 'Unknown')) ?> / <?= tracking_h((string) ($session['os'] ?? 'Unknown')) ?></div>
                                        <div><strong>Pages:</strong> <?= tracking_number($session['pageview_count'] ?? 0) ?></div>
                                        <div><strong>Events:</strong> <?= tracking_number($session['event_count'] ?? 0) ?></div>
                                        <div><strong>Clicks:</strong> <?= tracking_number($session['click_count'] ?? 0) ?></div>
                                        <div><strong>Form Submits:</strong> <?= tracking_number($session['form_submit_count'] ?? 0) ?></div>
                                        <div><strong>Approx. Time:</strong> <?= tracking_duration($session['approx_time_seconds'] ?? 0) ?></div>
                                        <div><strong>Exit Page:</strong> <?= tracking_h((string) ($session['exit_page'] ?? '')) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="section-head">
                        <div>
                            <div class="eyebrow">Events</div>
                            <h2 style="font-size:30px;">Matching events</h2>
                        </div>
                        <div class="section-copy">
                            Raw events tell you what was actually clicked, submitted, scrolled, or exited.
                        </div>
                    </div>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>When</th>
                                    <th>Type</th>
                                    <th>Label</th>
                                    <th>Page</th>
                                    <th>Session</th>
                                    <th>Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($filteredEvents as $event): ?>
                                    <tr>
                                        <td><?= tracking_h((string) ($event['created_at'] ?? '')) ?></td>
                                        <td><span class="pill blue"><?= tracking_h((string) ($event['event_type'] ?? '')) ?></span></td>
                                        <td>
                                            <strong><?= tracking_h((string) ($event['label'] ?? $event['event_name'] ?? '')) ?></strong><br>
                                            <span class="muted"><?= tracking_h((string) ($event['element_text'] ?? '')) ?></span>
                                        </td>
                                        <td><?= tracking_h((string) ($event['page_path'] ?? '')) ?></td>
                                        <td><?= tracking_h((string) ($event['session_uuid'] ?? '')) ?></td>
                                        <td><?= tracking_h((string) ($event['value_numeric'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($filteredEvents === []): ?>
                                    <tr><td colspan="6" class="muted">No events matched the current filters.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="panel-stack">
                <div class="card">
                    <div class="section-head">
                        <div>
                            <div class="eyebrow">Selected Session</div>
                            <h2 style="font-size:30px;">Page flow explorer</h2>
                        </div>
                    </div>

                    <?php if ($selectedSessionUuid === ''): ?>
                        <div class="empty">Choose a session UUID from the session list or paste one into the filter form.</div>
                    <?php else: ?>
                        <div class="meta-grid" style="margin-bottom:16px;">
                            <div><strong>Session UUID:</strong> <?= tracking_h($selectedSessionUuid) ?></div>
                            <div><strong>Pageviews in flow:</strong> <?= tracking_number($totals['selected_pageviews']) ?></div>
                        </div>

                        <?php if ($selectedSessionPageviews === []): ?>
                            <div class="empty">No page flow records were found for that session yet.</div>
                        <?php else: ?>
                            <div class="flow-list">
                                <?php foreach ($selectedSessionPageviews as $view): ?>
                                    <div class="flow-item">
                                        <div class="session-top">
                                            <div class="session-title"><?= tracking_h((string) ($view['page_path'] ?? '/')) ?></div>
                                            <span class="pill <?= !empty($view['is_exit']) ? 'red' : (!empty($view['is_entry']) ? 'gold' : 'blue') ?>">
                                                <?php
                                                if (!empty($view['is_entry'])) {
                                                    echo 'Entry';
                                                } elseif (!empty($view['is_exit'])) {
                                                    echo 'Exit';
                                                } else {
                                                    echo 'In Flow';
                                                }
                                                ?>
                                            </span>
                                        </div>
                                        <div class="meta-grid">
                                            <div><strong>Viewed:</strong> <?= tracking_h((string) ($view['viewed_at'] ?? '')) ?></div>
                                            <div><strong>Title:</strong> <?= tracking_h((string) ($view['page_title'] ?? '')) ?></div>
                                            <div><strong>Duration:</strong> <?= tracking_duration($view['duration_seconds'] ?? 0) ?></div>
                                            <div><strong>Scroll Max:</strong> <?= tracking_h((string) ($view['scroll_max_percent'] ?? 0)) ?>%</div>
                                            <div><strong>Clicks:</strong> <?= tracking_number($view['clicks_on_page'] ?? 0) ?></div>
                                            <div><strong>Form Submits:</strong> <?= tracking_number($view['form_submits'] ?? 0) ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="section-head">
                        <div>
                            <div class="eyebrow">How to use this page</div>
                            <h2 style="font-size:28px;">Best workflows</h2>
                        </div>
                    </div>

                    <div class="flow-list">
                        <div class="flow-item">
                            <div class="session-title">Find the highest-intent traffic</div>
                            <div class="muted">Filter by source or page path, then look for sessions with more pageviews, longer time, and more form submits.</div>
                        </div>
                        <div class="flow-item">
                            <div class="session-title">Audit a specific booking or signup window</div>
                            <div class="muted">Set the timeframe, search the page path for booking or signup pages, and inspect the matching events list.</div>
                        </div>
                        <div class="flow-item">
                            <div class="session-title">Investigate drop-off</div>
                            <div class="muted">Open sessions with only one pageview or low time-on-page and compare their source, page, device, and geo fields.</div>
                        </div>
                    </div>

                    <div class="subtext" style="margin-top:16px;">
                        This explorer is most useful alongside the main Analytics dashboard, where the higher-level source, geo, funnel, and reward summaries are already grouped for you.
                    </div>
                </div>
            </div>
        </section>
    </div>
</body>
</html>
