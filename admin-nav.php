<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/admin-auth.php';

function dd_admin_nav_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$currentFile = basename((string) ($_SERVER['PHP_SELF'] ?? 'admin-nav.php'));

$sections = [
    [
        'title' => 'Core Admin',
        'description' => 'Main control pages for the admin side.',
        'items' => [
            ['file' => 'admin-dashboard.php', 'label' => 'Admin Dashboard', 'note' => 'Main admin home', 'href' => 'admin-dashboard.php', 'type' => 'page'],
            ['file' => 'admin-analytics.php', 'label' => 'Admin Analytics', 'note' => 'Traffic, funnel, geo, badge, and business analytics dashboard', 'href' => 'admin-analytics.php', 'type' => 'page'],
            ['file' => 'admin-analytics-tracking.php', 'label' => 'Admin Analytics Tracking', 'note' => 'Raw session and event explorer for visitor tracking', 'href' => 'admin-analytics-tracking.php', 'type' => 'page'],
            ['file' => 'admin-nav.php', 'label' => 'Admin Navigation', 'note' => 'Central admin link hub', 'href' => 'admin-nav.php', 'type' => 'page'],
            ['file' => 'admin.php', 'label' => 'Admin', 'note' => 'Legacy or alternate admin entry', 'href' => 'admin-dashboard.php', 'type' => 'redirected'],
            ['file' => 'admin-revenue.php', 'label' => 'Admin Revenue', 'note' => 'Revenue dashboard', 'href' => 'admin-revenue.php', 'type' => 'page'],
            ['file' => 'admin-notifications.php', 'label' => 'Admin Notifications', 'note' => 'Admin alert center', 'href' => 'admin-notifications.php', 'type' => 'page'],
            ['file' => 'admin-login.php', 'label' => 'Admin Login', 'note' => 'Admin login page', 'href' => 'admin-login.php', 'type' => 'page'],
            ['file' => 'admin-logout.php', 'label' => 'Admin Logout', 'note' => 'Logout endpoint', 'href' => 'logout.php', 'type' => 'redirected'],
        ],
    ],
    [
        'title' => 'Bookings & Clients',
        'description' => 'Member bookings, non-member bookings, member pages, pets, and booking actions.',
        'items' => [
            ['file' => 'admin-bookings.php', 'label' => 'Admin Bookings', 'note' => 'Main booking management', 'href' => 'admin-bookings.php', 'type' => 'page'],
            ['file' => 'admin-booking-requests.php', 'label' => 'Admin Booking Requests', 'note' => 'Incoming request review', 'href' => 'admin-booking-requests.php', 'type' => 'page'],
            ['file' => 'admin-booking-update.php', 'label' => 'Admin Booking Update', 'note' => 'Requires a specific public booking. Opens the public bookings list first.', 'href' => 'admin-bookings.php?view=public', 'type' => 'context'],
            ['file' => 'admin-create-booking.php', 'label' => 'Admin Create Booking', 'note' => 'Create a booking manually', 'href' => 'admin-create-booking.php', 'type' => 'page'],
            ['file' => 'admin-edit-booking.php', 'label' => 'Admin Edit Booking', 'note' => 'Requires a specific member booking. Opens the member bookings list first.', 'href' => 'admin-bookings.php?view=member', 'type' => 'context'],
            ['file' => 'admin-update-booking-status.php', 'label' => 'Admin Update Booking Status', 'note' => 'Status action for a specific member booking. Opens the member bookings list first.', 'href' => 'admin-bookings.php?view=member', 'type' => 'context'],
            ['file' => 'admin-members.php', 'label' => 'Admin Members', 'note' => 'Member directory', 'href' => 'admin-members.php', 'type' => 'page'],
            ['file' => 'admin-member-view.php', 'label' => 'Admin Member View', 'note' => 'Requires a specific member. Opens the members list first.', 'href' => 'admin-members.php', 'type' => 'context'],
            ['file' => 'admin-non-member-bookings.php', 'label' => 'Admin Non-Member Bookings', 'note' => 'Public booking queue', 'href' => 'admin-non-member-bookings.php', 'type' => 'page'],
            ['file' => 'admin-non-member-booking-view.php', 'label' => 'Admin Non-Member Booking View', 'note' => 'Requires a specific public booking. Opens the public bookings list first.', 'href' => 'admin-non-member-bookings.php', 'type' => 'context'],
            ['file' => 'admin-dogs.php', 'label' => 'Admin Dogs', 'note' => 'Dog directory or dog management', 'href' => 'admin-dogs.php', 'type' => 'page'],
            ['file' => 'admin-add-dog.php', 'label' => 'Admin Add Dog', 'note' => 'Add dog from admin side', 'href' => 'admin-add-dog.php', 'type' => 'page'],
            ['file' => 'admin-edit-dog.php', 'label' => 'Admin Edit Dog', 'note' => 'Requires a specific dog. Opens the dogs list first.', 'href' => 'admin-dogs.php', 'type' => 'context'],
            ['file' => 'add-pet.php', 'label' => 'Add Pet', 'note' => 'Pet creation page visible in your list', 'href' => 'add-pet.php', 'type' => 'page'],
        ],
    ],
    [
        'title' => 'Walks, Tracking & Worker Ops',
        'description' => 'Walk workflow, worker assignment, live tracking, and worker management.',
        'items' => [
            ['file' => 'admin-walks.php', 'label' => 'Admin Walks', 'note' => 'Walk operations board', 'href' => 'admin-walks.php', 'type' => 'page'],
            ['file' => 'admin-live-tracking.php', 'label' => 'Admin Live Tracking', 'note' => 'Admin live tracking screen', 'href' => 'admin-live-tracking.php', 'type' => 'page'],
            ['file' => 'admin-tracking.php', 'label' => 'Admin Tracking', 'note' => 'Member-facing dog tracking page', 'href' => 'admin-tracking.php', 'type' => 'page'],
            ['file' => 'admin-walker-management.php', 'label' => 'Admin Walker Management', 'note' => 'Worker directory and controls', 'href' => 'admin-walker-management.php', 'type' => 'page'],
            ['file' => 'admin-worker-view.php', 'label' => 'Admin Worker View', 'note' => 'Requires a specific worker. Opens worker management first.', 'href' => 'admin-walker-management.php', 'type' => 'context'],
            ['file' => 'admin-worker-jobs.php', 'label' => 'Admin Worker Jobs', 'note' => 'Requires a specific worker context. Opens worker management first.', 'href' => 'admin-walker-management.php', 'type' => 'context'],
            ['file' => 'admin-create-worker.php', 'label' => 'Admin Create Worker', 'note' => 'Create worker account', 'href' => 'admin-create-worker.php', 'type' => 'page'],
            ['file' => 'admin-edit-worker.php', 'label' => 'Admin Edit Worker', 'note' => 'Requires a specific worker. Opens worker management first.', 'href' => 'admin-walker-management.php', 'type' => 'context'],
            ['file' => 'admin-enable-worker.php', 'label' => 'Admin Enable Worker', 'note' => 'Requires a specific worker. Opens worker management first.', 'href' => 'admin-walker-management.php', 'type' => 'context'],
            ['file' => 'admin-disable-worker.php', 'label' => 'Admin Disable Worker', 'note' => 'Requires a specific worker. Opens worker management first.', 'href' => 'admin-walker-management.php', 'type' => 'context'],
            ['file' => 'admin-assign-walker.php', 'label' => 'Admin Assign Walker', 'note' => 'Assign worker to booking', 'href' => 'admin-assign-walker.php', 'type' => 'page'],
            ['file' => 'admin-unassign-walker.php', 'label' => 'Admin Unassign Walker', 'note' => 'Requires a specific booking assignment context. Opens bookings first.', 'href' => 'admin-bookings.php', 'type' => 'context'],
        ],
    ],
    [
        'title' => 'Applications & Programs',
        'description' => 'Founder applications, group walks, and related program pages.',
        'items' => [
            ['file' => 'admin-founder-applications.php', 'label' => 'Admin Founder Applications', 'note' => 'Founder application review', 'href' => 'admin-founder-applications.php', 'type' => 'page'],
            ['file' => 'admin-group-walk-applications.php', 'label' => 'Admin Group Walk Applications', 'note' => 'Group walk application queue', 'href' => 'admin-group-walk-applications.php', 'type' => 'page'],
            ['file' => 'ambassadors.php', 'label' => 'Ambassadors', 'note' => 'Ambassador-related page visible in your list', 'href' => 'ambassadors.php', 'type' => 'page'],
        ],
    ],
    [
        'title' => 'Internal / Utility Files',
        'description' => 'These are helper or action files, not normal landing pages.',
        'items' => [
            ['file' => 'admin-auth.php', 'label' => 'Admin Auth', 'note' => 'Admin auth helper / guard', 'href' => '', 'type' => 'internal'],
            ['file' => 'admin-config.php', 'label' => 'Admin Config', 'note' => 'Admin config / settings helper', 'href' => '', 'type' => 'internal'],
            ['file' => 'api-create-booking.php', 'label' => 'API Create Booking', 'note' => 'API / action endpoint', 'href' => '', 'type' => 'internal'],
        ],
    ],
];

$totalLinks = 0;
$internalLinks = 0;
$contextLinks = 0;

foreach ($sections as $section) {
    foreach ($section['items'] as $item) {
        $totalLinks++;
        if (($item['type'] ?? '') === 'internal') {
            $internalLinks++;
        }
        if (($item['type'] ?? '') === 'context') {
            $contextLinks++;
        }
    }
}

$primaryLinks = $totalLinks - $internalLinks - $contextLinks;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Navigation | Doggie Dorian’s</title>
    <meta name="description" content="Central admin navigation page for Doggie Dorian’s.">
    <style>
        * {
            box-sizing: border-box;
        }

        :root {
            --bg: #07101d;
            --panel: rgba(15, 23, 42, 0.92);
            --panel-soft: rgba(255, 255, 255, 0.04);
            --line: rgba(148, 163, 184, 0.14);
            --text: #eef4fb;
            --muted: #93a4bb;
            --gold: #d4af37;
            --gold-soft: #f4df9f;
            --green: #78dba8;
            --blue: #7ec0ff;
            --amber: #f5c26b;
            --shadow: 0 24px 70px rgba(2, 8, 23, 0.42);
            --max: 1440px;
        }

        html, body {
            margin: 0;
            padding: 0;
            min-height: 100%;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(212, 175, 55, 0.14), transparent 28%),
                radial-gradient(circle at top right, rgba(84, 160, 255, 0.08), transparent 20%),
                linear-gradient(180deg, #07101d 0%, #0b1322 50%, #111827 100%);
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .page {
            max-width: var(--max);
            margin: 0 auto;
            padding: 30px 18px 80px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 18px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }

        .brand-wrap {
            display: grid;
            gap: 10px;
        }

        .eyebrow {
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--gold-soft);
        }

        h1 {
            margin: 0;
            font-size: clamp(32px, 4vw, 52px);
            line-height: 0.98;
            letter-spacing: -0.04em;
        }

        .subtext {
            max-width: 860px;
            color: var(--muted);
            font-size: 15px;
            line-height: 1.7;
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
            background: rgba(255, 255, 255, 0.04);
            color: var(--text);
            font-weight: 700;
        }

        .top-btn.primary {
            background: linear-gradient(135deg, #f1d883 0%, #d4af37 55%, #b88f1b 100%);
            color: #0a0f17;
            border-color: rgba(212, 175, 55, 0.5);
        }

        .hero {
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(15, 23, 42, 0.84));
            border: 1px solid rgba(212, 175, 55, 0.14);
            border-radius: 30px;
            padding: 24px;
            box-shadow: var(--shadow);
            margin-bottom: 22px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-top: 18px;
        }

        .stat {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 20px;
            padding: 18px;
        }

        .stat-label {
            color: var(--muted);
            font-size: 12px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 30px;
            font-weight: 900;
            letter-spacing: -0.03em;
        }

        .stat-note {
            margin-top: 6px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.45;
        }

        .search-panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 24px;
            padding: 20px;
            box-shadow: var(--shadow);
            margin-bottom: 22px;
        }

        .search-label {
            font-size: 12px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            font-weight: 800;
            color: var(--gold-soft);
            margin-bottom: 10px;
        }

        .search-input {
            width: 100%;
            min-height: 54px;
            border-radius: 16px;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.04);
            color: var(--text);
            padding: 0 16px;
            font: inherit;
            outline: none;
        }

        .search-input:focus {
            border-color: rgba(212, 175, 55, 0.40);
            box-shadow: 0 0 0 4px rgba(212, 175, 55, 0.08);
        }

        .section {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 26px;
            padding: 22px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }

        .section-head {
            margin-bottom: 16px;
        }

        .section-title {
            margin: 0 0 8px;
            font-size: 24px;
            font-weight: 900;
            letter-spacing: -0.03em;
        }

        .section-copy {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }

        .link-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .link-card,
        .link-card-static {
            display: grid;
            gap: 12px;
            background: var(--panel-soft);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 20px;
            padding: 18px;
            transition: transform 0.18s ease, border-color 0.18s ease, background 0.18s ease;
        }

        .link-card:hover {
            transform: translateY(-2px);
            border-color: rgba(212, 175, 55, 0.24);
            background: rgba(255,255,255,0.055);
        }

        .link-card.is-current,
        .link-card-static.is-current {
            border-color: rgba(212, 175, 55, 0.42);
            background: rgba(212, 175, 55, 0.10);
        }

        .link-card-static.is-internal {
            border-style: dashed;
            opacity: 0.95;
        }

        .link-card.is-context {
            border-color: rgba(245, 194, 107, 0.24);
            background: rgba(245, 194, 107, 0.07);
        }

        .link-top {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
        }

        .link-title {
            font-size: 17px;
            font-weight: 900;
            line-height: 1.2;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 28px;
            padding: 0 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            white-space: nowrap;
            border: 1px solid rgba(255,255,255,0.10);
        }

        .pill.current {
            background: rgba(212, 175, 55, 0.16);
            color: var(--gold-soft);
            border-color: rgba(212, 175, 55, 0.28);
        }

        .pill.internal {
            background: rgba(126, 192, 255, 0.12);
            color: #d9edff;
            border-color: rgba(126, 192, 255, 0.22);
        }

        .pill.page {
            background: rgba(120, 219, 168, 0.12);
            color: #d8ffea;
            border-color: rgba(120, 219, 168, 0.22);
        }

        .pill.context {
            background: rgba(245, 194, 107, 0.14);
            color: #ffe8b7;
            border-color: rgba(245, 194, 107, 0.24);
        }

        .pill.redirected {
            background: rgba(255,255,255,0.10);
            color: #eef4fb;
            border-color: rgba(255,255,255,0.12);
        }

        .link-note {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.55;
            min-height: 40px;
        }

        .file-name {
            font-size: 12px;
            color: rgba(255,255,255,0.55);
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }

        .empty-search {
            display: none;
            margin-top: 12px;
            padding: 16px;
            border-radius: 16px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            color: var(--muted);
        }

        @media (max-width: 1180px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .link-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 760px) {
            .page {
                padding: 20px 12px 60px;
            }

            .stats,
            .link-grid {
                grid-template-columns: 1fr;
            }

            .topbar {
                align-items: stretch;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="topbar">
            <div class="brand-wrap">
                <div class="eyebrow">Doggie Dorian’s Admin</div>
                <h1>Admin Navigation</h1>
                <div class="subtext">
                    Central link hub for the admin area. ID-required booking, member, dog, and worker pages now route through their correct list pages first instead of opening directly without context.
                </div>
            </div>

            <div class="top-actions">
                <a class="top-btn" href="admin-dashboard.php">Dashboard</a>
                <a class="top-btn" href="admin-analytics.php">Analytics</a>
                <a class="top-btn" href="admin-analytics-tracking.php">Tracking</a>
                <a class="top-btn" href="admin-bookings.php">Bookings</a>
                <a class="top-btn primary" href="admin-nav.php">Admin Nav</a>
            </div>
        </div>

        <section class="hero">
            <div class="eyebrow">Overview</div>
            <h2 style="margin:0; font-size:28px; letter-spacing:-0.03em;">All admin links in one place</h2>
            <div class="subtext" style="margin-top:10px;">
                Use the search field below to instantly filter the page by file name, label, or note.
            </div>

            <div class="stats">
                <div class="stat">
                    <div class="stat-label">Total Links</div>
                    <div class="stat-value"><?php echo (int) $totalLinks; ?></div>
                    <div class="stat-note">All files visible in your screenshots that were added to this hub.</div>
                </div>
                <div class="stat">
                    <div class="stat-label">Primary Pages</div>
                    <div class="stat-value"><?php echo (int) $primaryLinks; ?></div>
                    <div class="stat-note">Regular admin destinations and working pages.</div>
                </div>
                <div class="stat">
                    <div class="stat-label">Context Required</div>
                    <div class="stat-value"><?php echo (int) $contextLinks; ?></div>
                    <div class="stat-note">Pages that need a specific record first, now routed safely.</div>
                </div>
                <div class="stat">
                    <div class="stat-label">Current File</div>
                    <div class="stat-value"><?php echo dd_admin_nav_h($currentFile); ?></div>
                    <div class="stat-note">This page highlights itself automatically.</div>
                </div>
            </div>
        </section>

        <section class="search-panel">
            <div class="search-label">Search Admin Links</div>
            <input
                id="adminNavSearch"
                class="search-input"
                type="text"
                placeholder="Search by file name, label, or note..."
                autocomplete="off"
            >
            <div id="emptySearchState" class="empty-search">
                No matching admin links were found for that search.
            </div>
        </section>

        <?php foreach ($sections as $section): ?>
            <section class="section admin-nav-section">
                <div class="section-head">
                    <h2 class="section-title"><?php echo dd_admin_nav_h($section['title']); ?></h2>
                    <div class="section-copy"><?php echo dd_admin_nav_h($section['description']); ?></div>
                </div>

                <div class="link-grid">
                    <?php foreach ($section['items'] as $item): ?>
                        <?php
                        $file = (string) $item['file'];
                        $label = (string) $item['label'];
                        $note = (string) $item['note'];
                        $href = (string) ($item['href'] ?? '');
                        $type = (string) ($item['type'] ?? 'page');
                        $isCurrent = $file === $currentFile;

                        $searchBlob = strtolower($file . ' ' . $label . ' ' . $note . ' ' . $href);

                        $cardClass = $href !== '' ? 'link-card' : 'link-card-static';
                        if ($isCurrent) {
                            $cardClass .= ' is-current';
                        }
                        if ($type === 'internal') {
                            $cardClass .= ' is-internal';
                        }
                        if ($type === 'context') {
                            $cardClass .= ' is-context';
                        }
                        ?>
                        <?php if ($href !== ''): ?>
                            <a
                                href="<?php echo dd_admin_nav_h($href); ?>"
                                class="<?php echo dd_admin_nav_h($cardClass); ?>"
                                data-search="<?php echo dd_admin_nav_h($searchBlob); ?>"
                            >
                                <div class="link-top">
                                    <div class="link-title"><?php echo dd_admin_nav_h($label); ?></div>

                                    <?php if ($isCurrent): ?>
                                        <span class="pill current">Current</span>
                                    <?php elseif ($type === 'context'): ?>
                                        <span class="pill context">Context</span>
                                    <?php elseif ($type === 'redirected'): ?>
                                        <span class="pill redirected">Safe Route</span>
                                    <?php else: ?>
                                        <span class="pill page">Page</span>
                                    <?php endif; ?>
                                </div>

                                <div class="link-note"><?php echo dd_admin_nav_h($note); ?></div>
                                <div class="file-name"><?php echo dd_admin_nav_h($file); ?></div>
                            </a>
                        <?php else: ?>
                            <div
                                class="<?php echo dd_admin_nav_h($cardClass); ?>"
                                data-search="<?php echo dd_admin_nav_h($searchBlob); ?>"
                            >
                                <div class="link-top">
                                    <div class="link-title"><?php echo dd_admin_nav_h($label); ?></div>

                                    <?php if ($isCurrent): ?>
                                        <span class="pill current">Current</span>
                                    <?php else: ?>
                                        <span class="pill internal">Internal</span>
                                    <?php endif; ?>
                                </div>

                                <div class="link-note"><?php echo dd_admin_nav_h($note); ?></div>
                                <div class="file-name"><?php echo dd_admin_nav_h($file); ?></div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>

    <script>
        (function () {
            const input = document.getElementById('adminNavSearch');
            const cards = Array.from(document.querySelectorAll('.link-card, .link-card-static'));
            const sections = Array.from(document.querySelectorAll('.admin-nav-section'));
            const emptyState = document.getElementById('emptySearchState');

            function applyFilter() {
                const query = (input.value || '').trim().toLowerCase();
                let visibleCards = 0;

                cards.forEach((card) => {
                    const haystack = (card.getAttribute('data-search') || '').toLowerCase();
                    const match = query === '' || haystack.includes(query);

                    card.style.display = match ? '' : 'none';

                    if (match) {
                        visibleCards += 1;
                    }
                });

                sections.forEach((section) => {
                    const sectionCards = Array.from(section.querySelectorAll('.link-card, .link-card-static'));
                    const anyVisible = sectionCards.some((card) => card.style.display !== 'none');
                    section.style.display = anyVisible ? '' : 'none';
                });

                emptyState.style.display = visibleCards === 0 ? 'block' : 'none';
            }

            input.addEventListener('input', applyFilter);
        })();
    </script>
</body>
</html>
