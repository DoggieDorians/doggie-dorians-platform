<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db.php';

function ddCareAdminH(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ddCareAdminRedirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function ddCareAdminIsLoggedIn(): bool
{
    if (!empty($_SESSION['is_admin'])) {
        return true;
    }

    if (!empty($_SESSION['admin_logged_in'])) {
        return true;
    }

    if (isset($_SESSION['role']) && strtolower((string) $_SESSION['role']) === 'admin') {
        return true;
    }

    return !empty($_SESSION['admin_id']);
}

function ddCareAdminRequire(): void
{
    if (!ddCareAdminIsLoggedIn()) {
        ddCareAdminRedirect('admin-login.php');
    }
}

function ddCareAdminCategoryList(): array
{
    return [
        'Nutrition & Feeding',
        'Training & Behavior',
        'Exercise & Walk Routines',
        'Enrichment & Mental Stimulation',
        'Daycare & Boarding Preparation',
        'Seasonal Care & Safety',
    ];
}

function ddCareAdminSlugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '';
    $value = trim($value, '-');

    return $value !== '' ? $value : 'article';
}

function ddCareAdminNormalizeDateTime(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function ddCareAdminReadTime(string $html): int
{
    $words = str_word_count(trim(strip_tags($html)));
    return max(3, (int) ceil($words / 220));
}

function ddCareAdminSanitizeContent(string $content): string
{
    $content = trim($content);
    $content = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $content) ?? $content;

    return strip_tags($content, '<p><br><strong><em><ul><ol><li><h2><h3><blockquote><a>');
}

function ddCareAdminEnsureTables(PDO $pdo): void
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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS care_quick_tips (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            tip_text TEXT NOT NULL,
            category TEXT NOT NULL DEFAULT '',
            is_active INTEGER NOT NULL DEFAULT 1,
            publish_at TEXT DEFAULT NULL,
            expires_at TEXT DEFAULT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_care_articles_status_publish ON care_articles(status, publish_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_care_articles_category ON care_articles(category)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_care_articles_featured ON care_articles(is_featured)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_care_quick_tips_active_publish ON care_quick_tips(is_active, publish_at)");
}

function ddCareAdminSeedStarterContent(PDO $pdo): void
{
    $count = (int) $pdo->query("SELECT COUNT(*) FROM care_articles")->fetchColumn();
    if ($count > 0) {
        return;
    }

    $articles = [
        [
            'title' => 'Building a Better Daily Routine for Apartment Dogs',
            'slug' => 'building-a-better-daily-routine-for-apartment-dogs',
            'category' => 'Exercise & Walk Routines',
            'excerpt' => 'A thoughtful rhythm for meals, walks, rest, and enrichment can support calmer behavior and a more settled home life.',
            'content' => <<<HTML
<p>For many city dogs, daily life unfolds inside compact spaces, elevators, sidewalks, changing sounds, and shifting activity levels. A reliable routine can create calm where the day might otherwise feel overstimulating or inconsistent.</p>
<h2>Why routine matters</h2>
<p>Dogs often do best when the day has recognizable patterns. A predictable rhythm can support smoother transitions, better rest, and more confidence around meals, walks, and downtime.</p>
<ul>
    <li>More settled mornings and evenings</li>
    <li>Better transitions before and after walks</li>
    <li>More intentional feeding and enrichment habits</li>
    <li>Less restlessness around the home</li>
</ul>
<h2>A simple structure that works</h2>
<p>Start with a manageable rhythm rather than an overly ambitious one. A strong routine is not the busiest routine — it is the one you can actually maintain consistently.</p>
<p><strong>Morning:</strong> bathroom break, movement, water, and a clear start to the day.</p>
<p><strong>Midday:</strong> either a structured outing, enrichment session, or calm reset depending on energy needs.</p>
<p><strong>Evening:</strong> another reliable walk or movement block, dinner, and a slower wind-down period.</p>
<h2>Do not overlook recovery</h2>
<p>Some dogs need decompression as much as they need activity. A short quiet period after a stimulating walk or outing can help reduce post-walk overarousal.</p>
<h2>Dorian’s recommendation</h2>
<p>Choose a routine that feels calm, elegant, and realistic. When meals, exercise, rest, and enrichment each have a place in the day, many dogs become easier to read and easier to support.</p>
HTML,
            'is_featured' => 1,
            'sort_order' => 1,
            'days_ago' => 21,
        ],
        [
            'title' => 'How to Make Feeding Time More Consistent',
            'slug' => 'how-to-make-feeding-time-more-consistent',
            'category' => 'Nutrition & Feeding',
            'excerpt' => 'Simple structure around meals can support steadier habits, smoother digestion routines, and less food-related stress.',
            'content' => <<<HTML
<p>Feeding routines do not need to be rigid to be effective. What matters most is that they feel intentional. Dogs often do better when meals happen within a fairly recognizable rhythm.</p>
<h2>Keep meal timing steady</h2>
<p>A consistent feeding window can support calmer expectations and fewer daily disruptions. Even when your schedule changes slightly, aiming for general consistency helps.</p>
<h2>Measure with purpose</h2>
<p>It is easy for treats, table scraps, and uneven scoops to quietly change the balance of a feeding plan. Thoughtful portion awareness helps keep routines cleaner and more predictable.</p>
<h2>Treats should support the plan</h2>
<p>Treats are helpful, but they work best when they reinforce training or structure rather than gradually replacing meal balance.</p>
<h2>Questions worth asking</h2>
<ul>
    <li>Is my dog’s feeding schedule clear from day to day?</li>
    <li>Are treats being used intentionally?</li>
    <li>Would better meal structure improve the rest of the day?</li>
</ul>
<p><em>Educational guidance only. For medical, allergy-related, or condition-specific feeding decisions, consult your veterinarian.</em></p>
HTML,
            'is_featured' => 0,
            'sort_order' => 2,
            'days_ago' => 17,
        ],
        [
            'title' => 'Reducing Pulling and Overexcitement Before Walks',
            'slug' => 'reducing-pulling-and-overexcitement-before-walks',
            'category' => 'Training & Behavior',
            'excerpt' => 'A calmer exit routine often begins before the leash goes on. Small changes can make walks feel more focused and enjoyable.',
            'content' => <<<HTML
<p>Many difficult walks begin before the front door ever opens. If a dog is already overstimulated during the leash routine, the rest of the outing often feels more reactive and hurried.</p>
<h2>Slow down the exit ritual</h2>
<p>Take a moment before clipping the leash, opening the door, or stepping into the hallway. Calm departures often create calmer walks.</p>
<h2>Reward focus, not frenzy</h2>
<p>If your dog can pause, look to you, or wait briefly before moving forward, those moments are worth reinforcing. Small repetitions can add up quickly.</p>
<h2>Use transitions intentionally</h2>
<p>Thresholds, elevators, lobby doors, and busy sidewalks can all become useful moments for structure. Short pauses help a dog reset rather than rush from one stimulation point to the next.</p>
<h2>Dorian’s recommendation</h2>
<p>Instead of trying to “fix the whole walk” at once, improve the opening minute. Better beginnings often create better rhythm for the rest of the outing.</p>
HTML,
            'is_featured' => 0,
            'sort_order' => 3,
            'days_ago' => 14,
        ],
        [
            'title' => 'Indoor Enrichment Ideas for Rainy or Busy Days',
            'slug' => 'indoor-enrichment-ideas-for-rainy-or-busy-days',
            'category' => 'Enrichment & Mental Stimulation',
            'excerpt' => 'When longer outings are not possible, thoughtful enrichment can help meet mental needs indoors without making the day feel flat.',
            'content' => <<<HTML
<p>Not every day allows for long outdoor sessions. On wet days, rushed days, or recovery days, indoor enrichment can keep a dog engaged in a more focused and satisfying way.</p>
<h2>Simple enrichment options</h2>
<ul>
    <li>Food puzzles and frozen enrichment items</li>
    <li>Short scent games around the apartment</li>
    <li>Snuffle mats or search-based play</li>
    <li>Brief training refreshers with clear rewards</li>
</ul>
<h2>Keep it calm and deliberate</h2>
<p>Enrichment is most useful when it feels like a structured activity rather than a random burst of stimulation. Short, focused sessions often work better than long, chaotic ones.</p>
<h2>Rotate rather than overwhelm</h2>
<p>You do not need ten new activities. A small rotation of a few reliable enrichment tools is usually enough to keep things fresh and manageable.</p>
HTML,
            'is_featured' => 0,
            'sort_order' => 4,
            'days_ago' => 12,
        ],
        [
            'title' => 'Preparing Your Dog for Daycare or Boarding',
            'slug' => 'preparing-your-dog-for-daycare-or-boarding',
            'category' => 'Daycare & Boarding Preparation',
            'excerpt' => 'A little preparation can make transitions smoother, more reassuring, and easier for both dog and owner.',
            'content' => <<<HTML
<p>Whether your dog is heading into daycare or spending time away from home overnight, preparation matters. Familiar structure often helps dogs settle more smoothly into new routines.</p>
<h2>Helpful ways to prepare</h2>
<ul>
    <li>Keep meal timing as consistent as possible before the stay</li>
    <li>Make sure care notes are current and clear</li>
    <li>Share anything helpful about temperament, pacing, or comfort needs</li>
    <li>Practice calm departures rather than emotional goodbyes</li>
</ul>
<h2>Support confidence, not anxiety</h2>
<p>Dogs often read our energy closely. A steady, collected handoff can help create a more reassuring experience.</p>
<h2>Dorian’s recommendation</h2>
<p>Think of preparation as part of the service itself. Good transitions start before arrival and continue through routine, communication, and recovery afterward.</p>
HTML,
            'is_featured' => 0,
            'sort_order' => 5,
            'days_ago' => 9,
        ],
        [
            'title' => 'Warm-Weather Walking and Hydration Tips',
            'slug' => 'warm-weather-walking-and-hydration-tips',
            'category' => 'Seasonal Care & Safety',
            'excerpt' => 'Hot days require more attention to timing, pace, surfaces, and recovery so your dog stays more comfortable throughout the outing.',
            'content' => <<<HTML
<p>Warm-weather care is not only about shorter walks. It is also about timing, surface awareness, water access, pacing, and how the body recovers afterward.</p>
<h2>Choose smarter walk windows</h2>
<p>Whenever possible, shift more demanding outings toward cooler parts of the day. Midday heat can change how quickly a routine becomes tiring.</p>
<h2>Think beyond air temperature</h2>
<p>Sunny sidewalks, pavement heat, building lobbies, and low airflow can all change how a dog feels outdoors. Surface awareness matters.</p>
<h2>Recovery matters too</h2>
<p>Offer water, keep the transition home calm, and give your dog a chance to settle rather than rushing into more stimulation.</p>
<h2>Dorian’s recommendation</h2>
<p>Summer routines work best when they become more intentional rather than more intense. Shorter, smarter, better-timed sessions often create a more comfortable day overall.</p>
HTML,
            'is_featured' => 0,
            'sort_order' => 6,
            'days_ago' => 5,
        ],
        [
            'title' => 'Creating a Calm Evening Wind-Down Routine',
            'slug' => 'creating-a-calm-evening-wind-down-routine',
            'category' => 'Exercise & Walk Routines',
            'excerpt' => 'A clear transition into the evening can help dogs settle more comfortably after stimulation, outings, and the movement of the day.',
            'content' => <<<HTML
<p>Some dogs struggle in the final stretch of the day not because they need more excitement, but because they need a more reliable path into rest.</p>
<h2>Set a recognizable evening rhythm</h2>
<p>Try to make the last part of the day feel calmer than the middle. This might include a final outing, dinner, a brief enrichment moment, then quieter cues around the home.</p>
<h2>Lower the pace gradually</h2>
<p>Instead of ending the day abruptly after stimulation, create a gentle descent. Calm transitions often support better settling.</p>
<h2>Small consistency works</h2>
<p>Even a simple pattern repeated regularly can become reassuring. Dogs often respond well when the evening feels familiar and unhurried.</p>
HTML,
            'is_featured' => 0,
            'sort_order' => 7,
            'days_ago' => 3,
        ],
        [
            'title' => 'Signs Your Dog May Need More Mental Stimulation',
            'slug' => 'signs-your-dog-may-need-more-mental-stimulation',
            'category' => 'Enrichment & Mental Stimulation',
            'excerpt' => 'Not every dog needs more physical output. Sometimes a better answer is more thoughtful mental work.',
            'content' => <<<HTML
<p>When a dog seems restless, attention-seeking, or hard to settle, the answer is not always a longer walk. In some cases, the missing piece is focused mental engagement.</p>
<h2>Common signs to notice</h2>
<ul>
    <li>Restlessness after physical activity</li>
    <li>Difficulty settling indoors</li>
    <li>Constant searching for interaction or novelty</li>
    <li>Quick frustration with routine downtime</li>
</ul>
<h2>Helpful ways to add mental work</h2>
<p>Food puzzles, scent games, short training sessions, and thoughtful task-based routines can all help create a more balanced day.</p>
<h2>Dorian’s recommendation</h2>
<p>Look for small ways to make your dog think, solve, search, and focus. A little mental structure often changes the tone of the whole day.</p>
HTML,
            'is_featured' => 0,
            'sort_order' => 8,
            'days_ago' => 1,
        ],
    ];

    $articleStmt = $pdo->prepare("
        INSERT INTO care_articles (
            title, slug, category, excerpt, content, is_featured, status, is_member_only,
            publish_at, expires_at, sort_order, author_name, meta_title, meta_description,
            read_time, created_at, updated_at
        ) VALUES (
            :title, :slug, :category, :excerpt, :content, :is_featured, 'published', 1,
            :publish_at, :expires_at, :sort_order, 'Doggie Dorian''s Team', :meta_title, :meta_description,
            :read_time, :created_at, :updated_at
        )
    ");

    foreach ($articles as $article) {
        $publishAt = date('Y-m-d H:i:s', strtotime('-' . (int) $article['days_ago'] . ' days'));
        $articleStmt->execute([
            ':title' => $article['title'],
            ':slug' => $article['slug'],
            ':category' => $article['category'],
            ':excerpt' => $article['excerpt'],
            ':content' => $article['content'],
            ':is_featured' => (int) $article['is_featured'],
            ':publish_at' => $publishAt,
            ':expires_at' => null,
            ':sort_order' => (int) $article['sort_order'],
            ':meta_title' => $article['title'] . ' | Member Care Library',
            ':meta_description' => $article['excerpt'],
            ':read_time' => ddCareAdminReadTime($article['content']),
            ':created_at' => $publishAt,
            ':updated_at' => $publishAt,
        ]);
    }

    $tips = [
        ['title' => 'Treat Balance', 'tip_text' => 'Keep treat portions intentional so rewards support your routine instead of quietly replacing meal balance.', 'category' => 'Nutrition & Feeding', 'days_ago' => 24],
        ['title' => 'Calm Exits', 'tip_text' => 'Better walks often begin with calmer exits rather than faster exits.', 'category' => 'Training & Behavior', 'days_ago' => 22],
        ['title' => 'Food Puzzles', 'tip_text' => 'A simple food puzzle can turn an ordinary indoor period into meaningful enrichment.', 'category' => 'Enrichment & Mental Stimulation', 'days_ago' => 20],
        ['title' => 'Post-Walk Reset', 'tip_text' => 'A short decompression period after walks can help reduce post-walk overstimulation.', 'category' => 'Exercise & Walk Routines', 'days_ago' => 18],
        ['title' => 'Hydration Check', 'tip_text' => 'Hydration matters even more on warm, active days or after a more stimulating outing.', 'category' => 'Seasonal Care & Safety', 'days_ago' => 16],
        ['title' => 'Short Training Sessions', 'tip_text' => 'Short, focused training sessions are often more effective than long overwhelming ones.', 'category' => 'Training & Behavior', 'days_ago' => 14],
        ['title' => 'Gradual Food Changes', 'tip_text' => 'New foods are best introduced gradually rather than abruptly shifting a full routine.', 'category' => 'Nutrition & Feeding', 'days_ago' => 12],
        ['title' => 'Rest is Part of Care', 'tip_text' => 'Rest is part of a healthy routine — not a break from one.', 'category' => 'Exercise & Walk Routines', 'days_ago' => 10],
        ['title' => 'Mental Work Counts', 'tip_text' => 'Some dogs need more meaningful mental work, not just more physical output.', 'category' => 'Enrichment & Mental Stimulation', 'days_ago' => 8],
        ['title' => 'Smooth Handoffs', 'tip_text' => 'Preparation and calm handoffs can make daycare or boarding transitions easier on everyone.', 'category' => 'Daycare & Boarding Preparation', 'days_ago' => 6],
        ['title' => 'Structure Helps', 'tip_text' => 'Thoughtful structure often helps sensitive or nervous dogs feel safer and more settled.', 'category' => 'Training & Behavior', 'days_ago' => 4],
        ['title' => 'Small Improvements Matter', 'tip_text' => 'Small, repeatable routine improvements usually create the most lasting results.', 'category' => 'Exercise & Walk Routines', 'days_ago' => 2],
    ];

    $tipStmt = $pdo->prepare("
        INSERT INTO care_quick_tips (
            title, tip_text, category, is_active, publish_at, expires_at, sort_order, created_at, updated_at
        ) VALUES (
            :title, :tip_text, :category, 1, :publish_at, NULL, :sort_order, :created_at, :updated_at
        )
    ");

    $sort = 1;
    foreach ($tips as $tip) {
        $publishAt = date('Y-m-d H:i:s', strtotime('-' . (int) $tip['days_ago'] . ' days'));
        $tipStmt->execute([
            ':title' => $tip['title'],
            ':tip_text' => $tip['tip_text'],
            ':category' => $tip['category'],
            ':sort_order' => $sort++,
            ':created_at' => $publishAt,
            ':updated_at' => $publishAt,
        ]);
    }
}

function ddCareAdminUniqueSlug(PDO $pdo, string $slug, int $excludeId = 0): string
{
    $base = $slug !== '' ? $slug : 'article';
    $unique = $base;
    $suffix = 2;

    while (true) {
        $stmt = $pdo->prepare("SELECT id FROM care_articles WHERE slug = :slug LIMIT 1");
        $stmt->execute([':slug' => $unique]);
        $existingId = (int) $stmt->fetchColumn();

        if ($existingId === 0 || $existingId === $excludeId) {
            return $unique;
        }

        $unique = $base . '-' . $suffix++;
    }
}

ddCareAdminRequire();
ddCareAdminEnsureTables($pdo);
ddCareAdminSeedStarterContent($pdo);

if (empty($_SESSION['care_library_csrf'])) {
    $_SESSION['care_library_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['care_library_csrf'];

$flash = (string) ($_SESSION['care_library_flash'] ?? '');
$flashType = (string) ($_SESSION['care_library_flash_type'] ?? '');
unset($_SESSION['care_library_flash'], $_SESSION['care_library_flash_type']);

$categories = ddCareAdminCategoryList();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        $_SESSION['care_library_flash'] = 'Your session expired. Please try again.';
        $_SESSION['care_library_flash_type'] = 'error';
        ddCareAdminRedirect('admin-care-library.php');
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'save_article') {
            $id = (int) ($_POST['article_id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            $slug = ddCareAdminSlugify((string) ($_POST['slug'] ?? ''));
            $category = trim((string) ($_POST['category'] ?? ''));
            $excerpt = trim((string) ($_POST['excerpt'] ?? ''));
            $content = ddCareAdminSanitizeContent((string) ($_POST['content'] ?? ''));
            $status = trim((string) ($_POST['status'] ?? 'draft'));
            $publishAt = ddCareAdminNormalizeDateTime((string) ($_POST['publish_at'] ?? ''));
            $expiresAt = ddCareAdminNormalizeDateTime((string) ($_POST['expires_at'] ?? ''));
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            $authorName = trim((string) ($_POST['author_name'] ?? 'Doggie Dorian\'s Team'));
            $metaTitle = trim((string) ($_POST['meta_title'] ?? ''));
            $metaDescription = trim((string) ($_POST['meta_description'] ?? ''));
            $isFeatured = isset($_POST['is_featured']) ? 1 : 0;

            if ($title === '' || $category === '' || $content === '') {
                throw new RuntimeException('Title, category, and article content are required.');
            }

            if (!in_array($category, $categories, true)) {
                throw new RuntimeException('Please choose a valid category.');
            }

            if (!in_array($status, ['draft', 'published', 'archived'], true)) {
                $status = 'draft';
            }

            if ($publishAt === null && $status === 'published') {
                $publishAt = date('Y-m-d H:i:s');
            }

            $slug = ddCareAdminUniqueSlug($pdo, $slug !== '' ? $slug : ddCareAdminSlugify($title), $id);
            if ($metaTitle === '') {
                $metaTitle = $title . ' | Member Care Library';
            }
            if ($metaDescription === '') {
                $metaDescription = $excerpt;
            }
            if ($authorName === '') {
                $authorName = "Doggie Dorian's Team";
            }
            $readTime = ddCareAdminReadTime($content);
            $now = date('Y-m-d H:i:s');

            if ($isFeatured === 1) {
                $pdo->exec("UPDATE care_articles SET is_featured = 0");
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE care_articles
                    SET title = :title,
                        slug = :slug,
                        category = :category,
                        excerpt = :excerpt,
                        content = :content,
                        is_featured = :is_featured,
                        status = :status,
                        publish_at = :publish_at,
                        expires_at = :expires_at,
                        sort_order = :sort_order,
                        author_name = :author_name,
                        meta_title = :meta_title,
                        meta_description = :meta_description,
                        read_time = :read_time,
                        updated_at = :updated_at
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':title' => $title,
                    ':slug' => $slug,
                    ':category' => $category,
                    ':excerpt' => $excerpt,
                    ':content' => $content,
                    ':is_featured' => $isFeatured,
                    ':status' => $status,
                    ':publish_at' => $publishAt,
                    ':expires_at' => $expiresAt,
                    ':sort_order' => $sortOrder,
                    ':author_name' => $authorName,
                    ':meta_title' => $metaTitle,
                    ':meta_description' => $metaDescription,
                    ':read_time' => $readTime,
                    ':updated_at' => $now,
                    ':id' => $id,
                ]);
                $_SESSION['care_library_flash'] = 'Article updated successfully.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO care_articles (
                        title, slug, category, excerpt, content, is_featured, status, is_member_only,
                        publish_at, expires_at, sort_order, author_name, meta_title, meta_description,
                        read_time, created_at, updated_at
                    ) VALUES (
                        :title, :slug, :category, :excerpt, :content, :is_featured, :status, 1,
                        :publish_at, :expires_at, :sort_order, :author_name, :meta_title, :meta_description,
                        :read_time, :created_at, :updated_at
                    )
                ");
                $stmt->execute([
                    ':title' => $title,
                    ':slug' => $slug,
                    ':category' => $category,
                    ':excerpt' => $excerpt,
                    ':content' => $content,
                    ':is_featured' => $isFeatured,
                    ':status' => $status,
                    ':publish_at' => $publishAt,
                    ':expires_at' => $expiresAt,
                    ':sort_order' => $sortOrder,
                    ':author_name' => $authorName,
                    ':meta_title' => $metaTitle,
                    ':meta_description' => $metaDescription,
                    ':read_time' => $readTime,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
                $_SESSION['care_library_flash'] = 'Article created successfully.';
            }

            $_SESSION['care_library_flash_type'] = 'success';
            ddCareAdminRedirect('admin-care-library.php');
        }

        if ($action === 'feature_article') {
            $id = (int) ($_POST['article_id'] ?? 0);
            if ($id > 0) {
                $pdo->exec("UPDATE care_articles SET is_featured = 0");
                $stmt = $pdo->prepare("UPDATE care_articles SET is_featured = 1, updated_at = :updated_at WHERE id = :id");
                $stmt->execute([
                    ':updated_at' => date('Y-m-d H:i:s'),
                    ':id' => $id,
                ]);
                $_SESSION['care_library_flash'] = 'Featured article updated.';
                $_SESSION['care_library_flash_type'] = 'success';
            }
            ddCareAdminRedirect('admin-care-library.php');
        }

        if ($action === 'archive_article') {
            $id = (int) ($_POST['article_id'] ?? 0);
            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE care_articles
                    SET status = 'archived',
                        is_featured = 0,
                        updated_at = :updated_at
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':updated_at' => date('Y-m-d H:i:s'),
                    ':id' => $id,
                ]);
                $_SESSION['care_library_flash'] = 'Article archived.';
                $_SESSION['care_library_flash_type'] = 'success';
            }
            ddCareAdminRedirect('admin-care-library.php');
        }

        if ($action === 'save_tip') {
            $id = (int) ($_POST['tip_id'] ?? 0);
            $title = trim((string) ($_POST['tip_title'] ?? ''));
            $tipText = trim((string) ($_POST['tip_text'] ?? ''));
            $category = trim((string) ($_POST['tip_category'] ?? ''));
            $publishAt = ddCareAdminNormalizeDateTime((string) ($_POST['tip_publish_at'] ?? ''));
            $expiresAt = ddCareAdminNormalizeDateTime((string) ($_POST['tip_expires_at'] ?? ''));
            $sortOrder = (int) ($_POST['tip_sort_order'] ?? 0);
            $isActive = isset($_POST['tip_is_active']) ? 1 : 0;
            $now = date('Y-m-d H:i:s');

            if ($title === '' || $tipText === '' || $category === '') {
                throw new RuntimeException('Tip title, category, and text are required.');
            }

            if (!in_array($category, $categories, true)) {
                throw new RuntimeException('Please choose a valid tip category.');
            }

            if ($publishAt === null && $isActive === 1) {
                $publishAt = $now;
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE care_quick_tips
                    SET title = :title,
                        tip_text = :tip_text,
                        category = :category,
                        is_active = :is_active,
                        publish_at = :publish_at,
                        expires_at = :expires_at,
                        sort_order = :sort_order,
                        updated_at = :updated_at
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':title' => $title,
                    ':tip_text' => $tipText,
                    ':category' => $category,
                    ':is_active' => $isActive,
                    ':publish_at' => $publishAt,
                    ':expires_at' => $expiresAt,
                    ':sort_order' => $sortOrder,
                    ':updated_at' => $now,
                    ':id' => $id,
                ]);
                $_SESSION['care_library_flash'] = 'Quick tip updated successfully.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO care_quick_tips (
                        title, tip_text, category, is_active, publish_at, expires_at, sort_order, created_at, updated_at
                    ) VALUES (
                        :title, :tip_text, :category, :is_active, :publish_at, :expires_at, :sort_order, :created_at, :updated_at
                    )
                ");
                $stmt->execute([
                    ':title' => $title,
                    ':tip_text' => $tipText,
                    ':category' => $category,
                    ':is_active' => $isActive,
                    ':publish_at' => $publishAt,
                    ':expires_at' => $expiresAt,
                    ':sort_order' => $sortOrder,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
                $_SESSION['care_library_flash'] = 'Quick tip created successfully.';
            }

            $_SESSION['care_library_flash_type'] = 'success';
            ddCareAdminRedirect('admin-care-library.php');
        }

        if ($action === 'delete_tip') {
            $id = (int) ($_POST['tip_id'] ?? 0);
            if ($id > 0) {
                $stmt = $pdo->prepare("DELETE FROM care_quick_tips WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $_SESSION['care_library_flash'] = 'Quick tip deleted.';
                $_SESSION['care_library_flash_type'] = 'success';
            }
            ddCareAdminRedirect('admin-care-library.php');
        }
    } catch (Throwable $e) {
        $_SESSION['care_library_flash'] = $e->getMessage();
        $_SESSION['care_library_flash_type'] = 'error';
        ddCareAdminRedirect('admin-care-library.php');
    }
}

$editArticle = null;
$editArticleId = (int) ($_GET['edit_article'] ?? 0);
if ($editArticleId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM care_articles WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $editArticleId]);
    $editArticle = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$editTip = null;
$editTipId = (int) ($_GET['edit_tip'] ?? 0);
if ($editTipId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM care_quick_tips WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $editTipId]);
    $editTip = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$articles = $pdo->query("
    SELECT *
    FROM care_articles
    ORDER BY
        CASE status
            WHEN 'published' THEN 0
            WHEN 'draft' THEN 1
            ELSE 2
        END,
        is_featured DESC,
        datetime(updated_at) DESC,
        id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$tips = $pdo->query("
    SELECT *
    FROM care_quick_tips
    ORDER BY is_active DESC, sort_order ASC, datetime(updated_at) DESC, id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$stats = [
    'published_articles' => 0,
    'draft_articles' => 0,
    'active_tips' => 0,
];

foreach ($articles as $article) {
    if (($article['status'] ?? '') === 'published') {
        $stats['published_articles']++;
    } elseif (($article['status'] ?? '') === 'draft') {
        $stats['draft_articles']++;
    }
}
foreach ($tips as $tip) {
    if ((int) ($tip['is_active'] ?? 0) === 1) {
        $stats['active_tips']++;
    }
}

function ddCareAdminDateLocal(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return '';
    }

    return date('Y-m-d\TH:i', $timestamp);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Care Library | Doggie Dorian’s</title>
    <meta name="description" content="Manage member care library articles, quick tips, featured guides, and publishing schedules.">
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
            --danger: #ef9d9d;
            --success: #bfe1bb;
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
            max-width: 1340px;
            margin: 0 auto;
            padding: 28px 18px 80px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }

        .brand {
            font-size: 1.46rem;
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

        .hero {
            display: grid;
            grid-template-columns: 1.1fr .9fr;
            gap: 20px;
            margin-bottom: 22px;
        }

        .card {
            background: linear-gradient(180deg, rgba(19,25,35,0.96), rgba(13,18,26,0.96));
            border: 1px solid var(--border);
            border-radius: 28px;
            padding: 24px;
            box-shadow: var(--shadow);
        }

        .hero-card {
            background:
                linear-gradient(135deg, rgba(214,179,106,0.14), rgba(255,255,255,0.04)),
                linear-gradient(180deg, rgba(19,25,35,0.98), rgba(13,18,26,0.98));
        }

        .eyebrow {
            display: inline-block;
            margin-bottom: 12px;
            color: var(--gold);
            text-transform: uppercase;
            letter-spacing: .16em;
            font-size: .76rem;
            font-weight: 900;
        }

        h1, h2, h3 {
            margin: 0;
            line-height: 1.08;
        }

        h1 {
            font-size: clamp(2rem, 4vw, 3rem);
            margin-bottom: 12px;
        }

        h2 {
            font-size: 1.5rem;
            margin-bottom: 10px;
        }

        .sub {
            color: var(--muted);
            line-height: 1.75;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-top: 18px;
        }

        .stat {
            padding: 16px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .stat-label {
            color: rgba(247,244,238,0.56);
            text-transform: uppercase;
            letter-spacing: .12em;
            font-size: .72rem;
            font-weight: 900;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 1.12rem;
            font-weight: 900;
        }

        .flash {
            margin-bottom: 18px;
            padding: 14px 16px;
            border-radius: 16px;
            font-weight: 800;
        }

        .flash.success {
            color: #dff5dd;
            background: rgba(125,206,141,0.14);
            border: 1px solid rgba(125,206,141,0.30);
        }

        .flash.error {
            color: #ffdada;
            background: rgba(224,111,111,0.14);
            border: 1px solid rgba(224,111,111,0.30);
        }

        .layout {
            display: grid;
            grid-template-columns: 1.1fr .9fr;
            gap: 22px;
            margin-bottom: 22px;
        }

        form {
            display: grid;
            gap: 16px;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: rgba(247,244,238,0.62);
            font-size: .76rem;
            text-transform: uppercase;
            letter-spacing: .12em;
            font-weight: 900;
        }

        input[type="text"],
        input[type="datetime-local"],
        select,
        textarea {
            width: 100%;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(0,0,0,0.24);
            color: #fff;
            padding: 13px 14px;
            font: inherit;
            outline: none;
        }

        textarea {
            min-height: 120px;
            resize: vertical;
        }

        .content-editor {
            min-height: 300px;
        }

        .checkbox-row {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 800;
            color: #f6efdf;
        }

        .checkbox-row input {
            transform: scale(1.1);
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 12px 18px;
            border-radius: 14px;
            font-weight: 800;
            border: none;
            cursor: pointer;
            transition: transform .15s ease;
        }

        .btn:hover { transform: translateY(-1px); }

        .btn-gold {
            background: linear-gradient(135deg, #e3c27d, #c59e58);
            color: #13100a;
        }

        .btn-soft {
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--border);
            color: #fff;
        }

        .btn-danger {
            background: rgba(224,111,111,0.16);
            border: 1px solid rgba(224,111,111,0.34);
            color: #ffd9d9;
        }

        .list-card {
            display: grid;
            gap: 14px;
        }

        .list-item {
            padding: 18px;
            border-radius: 20px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 6px 10px;
            background: rgba(255,255,255,0.06);
            font-size: .75rem;
            font-weight: 900;
            letter-spacing: .10em;
            text-transform: uppercase;
        }

        .pill.featured {
            background: var(--gold-soft);
            border: 1px solid rgba(214,179,106,0.20);
            color: #f5deb0;
        }

        .pill.published {
            color: #dbf5d6;
            background: rgba(125,206,141,0.14);
        }

        .pill.draft {
            color: #f1dfb1;
            background: rgba(214,179,106,0.12);
        }

        .pill.archived {
            color: #e3c0c0;
            background: rgba(224,111,111,0.12);
        }

        .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
            color: rgba(247,244,238,0.64);
            font-size: .88rem;
            font-weight: 700;
        }

        .list-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 14px;
        }

        .help-box {
            padding: 16px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
            color: var(--muted);
            line-height: 1.72;
        }

        .help-box strong {
            display: block;
            margin-bottom: 8px;
            color: #f3e1b9;
        }

        @media (max-width: 1080px) {
            .hero,
            .layout,
            .grid-2,
            .stats {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .page { padding: 20px 12px 64px; }
            .card, .list-item { padding: 18px; border-radius: 22px; }
            .actions { flex-direction: column; }
            .btn { width: 100%; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="topbar">
        <div class="brand">Doggie Dorian’s Admin</div>
        <div class="nav">
            <a href="admin-dashboard.php">Dashboard</a>
            <a href="admin-bookings.php">Bookings</a>
            <a href="admin-care-library.php">Care Library</a>
            <a href="logout.php">Logout</a>
        </div>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="flash <?php echo $flashType === 'success' ? 'success' : 'error'; ?>">
            <?php echo ddCareAdminH($flash); ?>
        </div>
    <?php endif; ?>

    <section class="hero">
        <div class="card hero-card">
            <div class="eyebrow">Content Control</div>
            <h1>Admin Care Library</h1>
            <div class="sub">
                Manage member-facing articles, featured guides, quick tips, and scheduled publishing from one place. The library is already seeded with launch-ready content and can now be expanded over time.
            </div>

            <div class="stats">
                <div class="stat">
                    <div class="stat-label">Published Articles</div>
                    <div class="stat-value"><?php echo $stats['published_articles']; ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">Draft Articles</div>
                    <div class="stat-value"><?php echo $stats['draft_articles']; ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">Active Tips</div>
                    <div class="stat-value"><?php echo $stats['active_tips']; ?></div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="eyebrow">Publishing Rhythm</div>
            <h2>Keep the library feeling alive</h2>
            <div class="help-box" style="margin-top:12px;">
                <strong>Recommended cadence</strong>
                Publish one quick tip weekly, one full guide every two weeks, and rotate one seasonal spotlight monthly. Use the publish and expiry fields below to let content appear over time without constant manual changes.
            </div>
        </div>
    </section>

    <section class="layout">
        <div class="card">
            <div class="eyebrow"><?php echo $editArticle ? 'Edit Article' : 'New Article'; ?></div>
            <h2><?php echo $editArticle ? 'Update article' : 'Create article'; ?></h2>

            <form method="post" action="admin-care-library.php">
                <input type="hidden" name="csrf_token" value="<?php echo ddCareAdminH($csrfToken); ?>">
                <input type="hidden" name="action" value="save_article">
                <input type="hidden" name="article_id" value="<?php echo (int) ($editArticle['id'] ?? 0); ?>">

                <div class="grid-2">
                    <div>
                        <label for="title">Title</label>
                        <input type="text" id="title" name="title" value="<?php echo ddCareAdminH((string) ($editArticle['title'] ?? '')); ?>" required>
                    </div>
                    <div>
                        <label for="slug">Slug</label>
                        <input type="text" id="slug" name="slug" value="<?php echo ddCareAdminH((string) ($editArticle['slug'] ?? '')); ?>" placeholder="leave blank to auto-generate">
                    </div>
                </div>

                <div class="grid-2">
                    <div>
                        <label for="category">Category</label>
                        <select id="category" name="category" required>
                            <option value="">Select category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo ddCareAdminH($category); ?>" <?php echo ((string) ($editArticle['category'] ?? '') === $category) ? 'selected' : ''; ?>>
                                    <?php echo ddCareAdminH($category); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="draft" <?php echo ((string) ($editArticle['status'] ?? 'draft') === 'draft') ? 'selected' : ''; ?>>Draft</option>
                            <option value="published" <?php echo ((string) ($editArticle['status'] ?? '') === 'published') ? 'selected' : ''; ?>>Published</option>
                            <option value="archived" <?php echo ((string) ($editArticle['status'] ?? '') === 'archived') ? 'selected' : ''; ?>>Archived</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label for="excerpt">Excerpt</label>
                    <textarea id="excerpt" name="excerpt"><?php echo ddCareAdminH((string) ($editArticle['excerpt'] ?? '')); ?></textarea>
                </div>

                <div>
                    <label for="content">Content (basic HTML allowed: p, h2, h3, ul, li, strong, em, blockquote, a)</label>
                    <textarea class="content-editor" id="content" name="content" required><?php echo ddCareAdminH((string) ($editArticle['content'] ?? '')); ?></textarea>
                </div>

                <div class="grid-2">
                    <div>
                        <label for="publish_at">Publish At</label>
                        <input type="datetime-local" id="publish_at" name="publish_at" value="<?php echo ddCareAdminH(ddCareAdminDateLocal((string) ($editArticle['publish_at'] ?? ''))); ?>">
                    </div>
                    <div>
                        <label for="expires_at">Expires At</label>
                        <input type="datetime-local" id="expires_at" name="expires_at" value="<?php echo ddCareAdminH(ddCareAdminDateLocal((string) ($editArticle['expires_at'] ?? ''))); ?>">
                    </div>
                </div>

                <div class="grid-2">
                    <div>
                        <label for="sort_order">Sort Order</label>
                        <input type="text" id="sort_order" name="sort_order" value="<?php echo ddCareAdminH((string) ($editArticle['sort_order'] ?? '0')); ?>">
                    </div>
                    <div>
                        <label for="author_name">Author Name</label>
                        <input type="text" id="author_name" name="author_name" value="<?php echo ddCareAdminH((string) ($editArticle['author_name'] ?? "Doggie Dorian's Team")); ?>">
                    </div>
                </div>

                <div class="grid-2">
                    <div>
                        <label for="meta_title">Meta Title</label>
                        <input type="text" id="meta_title" name="meta_title" value="<?php echo ddCareAdminH((string) ($editArticle['meta_title'] ?? '')); ?>">
                    </div>
                    <div>
                        <label for="meta_description">Meta Description</label>
                        <input type="text" id="meta_description" name="meta_description" value="<?php echo ddCareAdminH((string) ($editArticle['meta_description'] ?? '')); ?>">
                    </div>
                </div>

                <label class="checkbox-row">
                    <input type="checkbox" name="is_featured" value="1" <?php echo ((int) ($editArticle['is_featured'] ?? 0) === 1) ? 'checked' : ''; ?>>
                    Set as featured article
                </label>

                <div class="actions">
                    <button type="submit" class="btn btn-gold"><?php echo $editArticle ? 'Update Article' : 'Create Article'; ?></button>
                    <a href="admin-care-library.php" class="btn btn-soft">Clear Form</a>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="eyebrow"><?php echo $editTip ? 'Edit Quick Tip' : 'New Quick Tip'; ?></div>
            <h2><?php echo $editTip ? 'Update quick tip' : 'Create quick tip'; ?></h2>

            <form method="post" action="admin-care-library.php">
                <input type="hidden" name="csrf_token" value="<?php echo ddCareAdminH($csrfToken); ?>">
                <input type="hidden" name="action" value="save_tip">
                <input type="hidden" name="tip_id" value="<?php echo (int) ($editTip['id'] ?? 0); ?>">

                <div>
                    <label for="tip_title">Tip Title</label>
                    <input type="text" id="tip_title" name="tip_title" value="<?php echo ddCareAdminH((string) ($editTip['title'] ?? '')); ?>" required>
                </div>

                <div>
                    <label for="tip_category">Category</label>
                    <select id="tip_category" name="tip_category" required>
                        <option value="">Select category</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo ddCareAdminH($category); ?>" <?php echo ((string) ($editTip['category'] ?? '') === $category) ? 'selected' : ''; ?>>
                                <?php echo ddCareAdminH($category); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="tip_text">Tip Text</label>
                    <textarea id="tip_text" name="tip_text" required><?php echo ddCareAdminH((string) ($editTip['tip_text'] ?? '')); ?></textarea>
                </div>

                <div class="grid-2">
                    <div>
                        <label for="tip_publish_at">Publish At</label>
                        <input type="datetime-local" id="tip_publish_at" name="tip_publish_at" value="<?php echo ddCareAdminH(ddCareAdminDateLocal((string) ($editTip['publish_at'] ?? ''))); ?>">
                    </div>
                    <div>
                        <label for="tip_expires_at">Expires At</label>
                        <input type="datetime-local" id="tip_expires_at" name="tip_expires_at" value="<?php echo ddCareAdminH(ddCareAdminDateLocal((string) ($editTip['expires_at'] ?? ''))); ?>">
                    </div>
                </div>

                <div class="grid-2">
                    <div>
                        <label for="tip_sort_order">Sort Order</label>
                        <input type="text" id="tip_sort_order" name="tip_sort_order" value="<?php echo ddCareAdminH((string) ($editTip['sort_order'] ?? '0')); ?>">
                    </div>
                    <div style="display:flex; align-items:end;">
                        <label class="checkbox-row" style="margin:0 0 10px;">
                            <input type="checkbox" name="tip_is_active" value="1" <?php echo (!isset($editTip['is_active']) || (int) $editTip['is_active'] === 1) ? 'checked' : ''; ?>>
                            Tip is active
                        </label>
                    </div>
                </div>

                <div class="actions">
                    <button type="submit" class="btn btn-gold"><?php echo $editTip ? 'Update Quick Tip' : 'Create Quick Tip'; ?></button>
                    <a href="admin-care-library.php" class="btn btn-soft">Clear Form</a>
                </div>
            </form>
        </div>
    </section>

    <section class="card" style="margin-bottom:22px;">
        <div class="eyebrow">Article Inventory</div>
        <h2>Manage articles</h2>

        <div class="list-card" style="margin-top:18px;">
            <?php foreach ($articles as $article): ?>
                <div class="list-item">
                    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
                        <span class="pill <?php echo ddCareAdminH((string) $article['status']); ?>"><?php echo ddCareAdminH((string) $article['status']); ?></span>
                        <?php if ((int) $article['is_featured'] === 1): ?>
                            <span class="pill featured">Featured</span>
                        <?php endif; ?>
                    </div>

                    <h3><?php echo ddCareAdminH((string) $article['title']); ?></h3>

                    <div class="meta">
                        <span><?php echo ddCareAdminH((string) $article['category']); ?></span>
                        <span>Slug: <?php echo ddCareAdminH((string) $article['slug']); ?></span>
                        <span><?php echo (int) $article['read_time']; ?> min read</span>
                        <span>Publish: <?php echo ddCareAdminH((string) ($article['publish_at'] ?: 'Not scheduled')); ?></span>
                    </div>

                    <p style="margin:12px 0 0; color:var(--muted); line-height:1.7;"><?php echo ddCareAdminH((string) $article['excerpt']); ?></p>

                    <div class="list-actions">
                        <a class="btn btn-soft" href="admin-care-library.php?edit_article=<?php echo (int) $article['id']; ?>">Edit</a>

                        <form method="post" action="admin-care-library.php" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo ddCareAdminH($csrfToken); ?>">
                            <input type="hidden" name="action" value="feature_article">
                            <input type="hidden" name="article_id" value="<?php echo (int) $article['id']; ?>">
                            <button type="submit" class="btn btn-soft">Set Featured</button>
                        </form>

                        <a class="btn btn-soft" href="member-care-article.php?slug=<?php echo rawurlencode((string) $article['slug']); ?>">View</a>

                        <form method="post" action="admin-care-library.php" style="display:inline;" onsubmit="return confirm('Archive this article?');">
                            <input type="hidden" name="csrf_token" value="<?php echo ddCareAdminH($csrfToken); ?>">
                            <input type="hidden" name="action" value="archive_article">
                            <input type="hidden" name="article_id" value="<?php echo (int) $article['id']; ?>">
                            <button type="submit" class="btn btn-danger">Archive</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card">
        <div class="eyebrow">Quick Tip Inventory</div>
        <h2>Manage quick tips</h2>

        <div class="list-card" style="margin-top:18px;">
            <?php foreach ($tips as $tip): ?>
                <div class="list-item">
                    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
                        <span class="pill <?php echo (int) $tip['is_active'] === 1 ? 'published' : 'archived'; ?>">
                            <?php echo (int) $tip['is_active'] === 1 ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>

                    <h3><?php echo ddCareAdminH((string) $tip['title']); ?></h3>

                    <div class="meta">
                        <span><?php echo ddCareAdminH((string) $tip['category']); ?></span>
                        <span>Publish: <?php echo ddCareAdminH((string) ($tip['publish_at'] ?: 'Not scheduled')); ?></span>
                    </div>

                    <p style="margin:12px 0 0; color:var(--muted); line-height:1.7;"><?php echo ddCareAdminH((string) $tip['tip_text']); ?></p>

                    <div class="list-actions">
                        <a class="btn btn-soft" href="admin-care-library.php?edit_tip=<?php echo (int) $tip['id']; ?>">Edit</a>

                        <form method="post" action="admin-care-library.php" style="display:inline;" onsubmit="return confirm('Delete this quick tip?');">
                            <input type="hidden" name="csrf_token" value="<?php echo ddCareAdminH($csrfToken); ?>">
                            <input type="hidden" name="action" value="delete_tip">
                            <input type="hidden" name="tip_id" value="<?php echo (int) $tip['id']; ?>">
                            <button type="submit" class="btn btn-danger">Delete</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>
</body>
</html>