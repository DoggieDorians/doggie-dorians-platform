<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';

function ddCareArticleH(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ddCareArticleRedirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function ddCareArticleCurrentUserId(): int
{
    foreach (['user_id', 'member_id', 'client_id', 'id'] as $key) {
        if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) {
            return (int) $_SESSION[$key];
        }
    }

    return 0;
}

function ddCareArticleCurrentUserRole(): string
{
    $role = strtolower(trim((string) ($_SESSION['role'] ?? '')));

    if ($role !== '') {
        return $role;
    }

    if (!empty($_SESSION['is_admin'])) {
        return 'admin';
    }

    if (!empty($_SESSION['walker_id']) || !empty($_SESSION['staff_id']) || !empty($_SESSION['employee_id'])) {
        return 'walker';
    }

    return 'member';
}

function ddCareArticleRequireMemberAccess(): void
{
    $userId = ddCareArticleCurrentUserId();
    $role = ddCareArticleCurrentUserRole();

    if ($userId <= 0 || $role === 'walker') {
        ddCareArticleRedirect('login.php');
    }
}

function ddCareArticleEnsureTables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS care_articles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            category TEXT NOT NULL DEFAULT '',
            excerpt TEXT NOT NULL DEFAULT '',
            content TEXT NOT NULL DEFAULT '',
            featured_image TEXT NOT NULL DEFAULT '',
            is_featured INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'draft',
            is_member_only INTEGER NOT NULL DEFAULT 1,
            publish_at TEXT DEFAULT NULL,
            expires_at TEXT DEFAULT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            author_name TEXT NOT NULL DEFAULT 'Doggie Dorian''s Team',
            meta_title TEXT NOT NULL DEFAULT '',
            meta_description TEXT NOT NULL DEFAULT '',
            read_time INTEGER NOT NULL DEFAULT 5,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
}

ddCareArticleRequireMemberAccess();
ddCareArticleEnsureTables($pdo);

$slug = trim((string) ($_GET['slug'] ?? ''));
$now = date('Y-m-d H:i:s');

$sql = "
    SELECT *
    FROM care_articles
    WHERE status = 'published'
      AND COALESCE(is_member_only, 1) = 1
      AND (publish_at IS NULL OR datetime(publish_at) <= datetime(:now))
      AND (expires_at IS NULL OR expires_at = '' OR datetime(expires_at) > datetime(:now))
";
$params = [':now' => $now];

if ($slug !== '') {
    $sql .= " AND slug = :slug";
    $params[':slug'] = $slug;
    $sql .= " LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $article = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} else {
    $stmt = $pdo->prepare($sql . " ORDER BY is_featured DESC, sort_order ASC, datetime(publish_at) DESC, id DESC LIMIT 1");
    $stmt->execute($params);
    $article = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$article) {
    ddCareArticleRedirect('member-care-library.php');
}

$relatedStmt = $pdo->prepare("
    SELECT *
    FROM care_articles
    WHERE status = 'published'
      AND COALESCE(is_member_only, 1) = 1
      AND category = :category
      AND id != :id
      AND (publish_at IS NULL OR datetime(publish_at) <= datetime(:now))
      AND (expires_at IS NULL OR expires_at = '' OR datetime(expires_at) > datetime(:now))
    ORDER BY datetime(publish_at) DESC, sort_order ASC, id DESC
    LIMIT 3
");
$relatedStmt->execute([
    ':category' => (string) $article['category'],
    ':id' => (int) $article['id'],
    ':now' => $now,
]);
$relatedArticles = $relatedStmt->fetchAll(PDO::FETCH_ASSOC);

$tipsStmt = $pdo->prepare("
    SELECT *
    FROM care_quick_tips
    WHERE is_active = 1
      AND (publish_at IS NULL OR datetime(publish_at) <= datetime(:now))
      AND (expires_at IS NULL OR expires_at = '' OR datetime(expires_at) > datetime(:now))
    ORDER BY sort_order ASC, datetime(publish_at) DESC, id DESC
    LIMIT 3
");
$tips = [];
try {
    $tipsStmt->execute([':now' => $now]);
    $tips = $tipsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $tips = [];
}

$title = trim((string) ($article['meta_title'] ?? ''));
if ($title === '') {
    $title = (string) $article['title'] . ' | Member Care Library';
}
$description = trim((string) ($article['meta_description'] ?? ''));
if ($description === '') {
    $description = (string) $article['excerpt'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo ddCareArticleH($title); ?></title>
    <meta name="description" content="<?php echo ddCareArticleH($description); ?>">
    <style>
        * { box-sizing: border-box; }

        :root {
            --bg: #090c11;
            --panel: rgba(19,25,35,0.96);
            --border: rgba(255,255,255,0.08);
            --text: #f7f4ee;
            --muted: rgba(247,244,238,0.72);
            --gold: #d6b36a;
            --gold-soft: rgba(214,179,106,0.14);
            --shadow: 0 24px 72px rgba(0,0,0,0.34);
        }

        body {
            margin: 0;
            color: var(--text);
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top, rgba(214,179,106,0.10), transparent 28%),
                linear-gradient(180deg, #07090d 0%, #0d1218 100%);
        }

        a { color: inherit; text-decoration: none; }

        .page {
            max-width: 1160px;
            margin: 0 auto;
            padding: 28px 18px 80px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .brand {
            font-size: 1.42rem;
            font-weight: 900;
            letter-spacing: .04em;
        }

        .nav {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .nav a {
            padding: 10px 14px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,0.04);
            font-weight: 800;
            font-size: .92rem;
        }

        .layout {
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 22px;
        }

        .article-shell,
        .side-card {
            background: linear-gradient(180deg, rgba(19,25,35,0.96), rgba(13,18,26,0.96));
            border: 1px solid var(--border);
            border-radius: 28px;
            box-shadow: var(--shadow);
        }

        .article-shell {
            overflow: hidden;
        }

        .article-top {
            padding: 32px 32px 22px;
            border-bottom: 1px solid var(--border);
            background:
                linear-gradient(135deg, rgba(214,179,106,0.12), rgba(255,255,255,0.03)),
                linear-gradient(180deg, rgba(19,25,35,0.98), rgba(13,18,26,0.98));
        }

        .badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 7px 11px;
            background: var(--gold-soft);
            border: 1px solid rgba(214,179,106,0.22);
            color: #f5deb0;
            font-size: .75rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .11em;
            margin-bottom: 14px;
        }

        h1 {
            margin: 0 0 14px;
            font-size: clamp(2rem, 4vw, 3rem);
            line-height: 1.08;
        }

        .excerpt {
            color: var(--muted);
            line-height: 1.8;
            font-size: 1.03rem;
            max-width: 820px;
        }

        .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin-top: 16px;
            color: rgba(247,244,238,0.66);
            font-size: .9rem;
            font-weight: 800;
        }

        .content {
            padding: 32px;
            color: #f5f2eb;
            line-height: 1.82;
            font-size: 1rem;
        }

        .content h2 {
            margin: 30px 0 12px;
            font-size: 1.45rem;
            line-height: 1.15;
        }

        .content h3 {
            margin: 22px 0 10px;
            font-size: 1.12rem;
        }

        .content p {
            margin: 0 0 16px;
            color: rgba(247,244,238,0.9);
        }

        .content ul,
        .content ol {
            margin: 0 0 18px 20px;
            padding: 0;
        }

        .content li {
            margin: 0 0 10px;
            color: rgba(247,244,238,0.9);
        }

        .content blockquote {
            margin: 22px 0;
            padding: 18px 20px;
            border-left: 3px solid rgba(214,179,106,0.55);
            background: rgba(255,255,255,0.04);
            border-radius: 0 16px 16px 0;
            color: #f3e2bd;
        }

        .side {
            display: grid;
            gap: 18px;
            align-content: start;
        }

        .side-card {
            padding: 22px;
        }

        .eyebrow {
            display: inline-block;
            margin-bottom: 10px;
            color: var(--gold);
            text-transform: uppercase;
            letter-spacing: .16em;
            font-size: .76rem;
            font-weight: 900;
        }

        .side-card h2,
        .side-card h3 {
            margin: 0 0 10px;
        }

        .side-card p {
            margin: 0;
            color: var(--muted);
            line-height: 1.7;
        }

        .side-list {
            display: grid;
            gap: 14px;
            margin-top: 14px;
        }

        .side-item {
            padding-top: 14px;
            border-top: 1px solid rgba(255,255,255,0.07);
        }

        .side-item:first-child {
            border-top: none;
            padding-top: 0;
        }

        .side-item a {
            color: #f1d18d;
            font-weight: 800;
        }

        .back-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 18px;
        }

        .back-link {
            padding: 10px 14px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,0.04);
            font-weight: 800;
            font-size: .92rem;
        }

        .footer-note {
            margin-top: 26px;
            padding: 18px 20px;
            border-radius: 18px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,0.035);
            color: var(--muted);
            line-height: 1.72;
        }

        @media (max-width: 980px) {
            .layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .page { padding: 20px 12px 64px; }
            .article-top,
            .content,
            .side-card { padding: 20px; }
            .article-shell,
            .side-card { border-radius: 22px; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="topbar">
        <div class="brand">Doggie Dorian’s</div>
        <div class="nav">
            <a href="dashboard.php">Dashboard</a>
            <a href="book-service.php">Book Service</a>
            <a href="manage-pets.php">Manage Pets</a>
            <a href="member-care-library.php">Care Library</a>
            <a href="logout.php">Logout</a>
        </div>
    </div>

    <div class="back-row">
        <a class="back-link" href="member-care-library.php">Back to Care Library</a>
        <a class="back-link" href="member-care-library.php?category=<?php echo rawurlencode((string) $article['category']); ?>">More in <?php echo ddCareArticleH((string) $article['category']); ?></a>
    </div>

    <div class="layout">
        <article class="article-shell">
            <div class="article-top">
                <span class="badge"><?php echo ddCareArticleH((string) $article['category']); ?></span>
                <h1><?php echo ddCareArticleH((string) $article['title']); ?></h1>
                <div class="excerpt"><?php echo ddCareArticleH((string) $article['excerpt']); ?></div>
                <div class="meta">
                    <span><?php echo date('F j, Y', strtotime((string) $article['publish_at'])); ?></span>
                    <span><?php echo (int) $article['read_time']; ?> min read</span>
                    <span><?php echo ddCareArticleH((string) ($article['author_name'] !== '' ? $article['author_name'] : 'Doggie Dorian’s Team')); ?></span>
                </div>
            </div>

            <div class="content">
                <?php echo (string) $article['content']; ?>

                <div class="footer-note">
                    <strong style="display:block; color:#f1d9a1; margin-bottom:8px;">Educational guidance only.</strong>
                    For medical, allergy-related, or condition-specific care decisions, please consult your veterinarian.
                </div>
            </div>
        </article>

        <aside class="side">
            <div class="side-card">
                <div class="eyebrow">Member Note</div>
                <h3>Practical, premium guidance</h3>
                <p>This library is designed to support better routines, calmer transitions, and more thoughtful everyday care — without overwhelming the member experience.</p>
            </div>

            <?php if (!empty($relatedArticles)): ?>
                <div class="side-card">
                    <div class="eyebrow">Related Guides</div>
                    <h3>Keep reading</h3>
                    <div class="side-list">
                        <?php foreach ($relatedArticles as $related): ?>
                            <div class="side-item">
                                <a href="member-care-article.php?slug=<?php echo rawurlencode((string) $related['slug']); ?>">
                                    <?php echo ddCareArticleH((string) $related['title']); ?>
                                </a>
                                <p style="margin-top:8px;"><?php echo ddCareArticleH((string) $related['excerpt']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($tips)): ?>
                <div class="side-card">
                    <div class="eyebrow">Quick Tips</div>
                    <h3>Short reminders</h3>
                    <div class="side-list">
                        <?php foreach ($tips as $tip): ?>
                            <div class="side-item">
                                <strong style="display:block; margin-bottom:8px; color:#f2d08a;"><?php echo ddCareArticleH((string) $tip['title']); ?></strong>
                                <p><?php echo ddCareArticleH((string) $tip['tip_text']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </aside>
    </div>
</div>
</body>
</html>