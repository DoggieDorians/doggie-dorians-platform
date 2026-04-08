<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection is not available.';
    exit;
}

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function safe_execute(PDO $pdo, $sql, array $params = array())
{
    try {
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    } catch (Throwable $e) {
        return false;
    } catch (Exception $e) {
        return false;
    }
}

function table_exists(PDO $pdo, $table)
{
    try {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1");
        $stmt->execute(array(':table' => $table));
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    } catch (Exception $e) {
        return false;
    }
}

function create_group_walks_table_if_needed(PDO $pdo)
{
    if (table_exists($pdo, 'group_walk_applications')) {
        return true;
    }

    $sql = "
        CREATE TABLE IF NOT EXISTS group_walk_applications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_name TEXT NOT NULL,
            email TEXT NOT NULL,
            phone TEXT NOT NULL,
            neighborhood TEXT NOT NULL,
            dog_name TEXT NOT NULL,
            breed TEXT NOT NULL,
            size TEXT NOT NULL,
            age TEXT NOT NULL,
            temperament TEXT NOT NULL,
            leash_behavior TEXT NOT NULL,
            preferred_days TEXT NOT NULL,
            preferred_time TEXT NOT NULL,
            prior_group_experience TEXT NOT NULL,
            notes TEXT DEFAULT '',
            status TEXT NOT NULL DEFAULT 'new',
            created_at TEXT NOT NULL
        )
    ";

    return safe_execute($pdo, $sql);
}

function create_notification_if_possible(PDO $pdo, $title, $message)
{
    if (!table_exists($pdo, 'notifications')) {
        return;
    }

    $createdAt = date('Y-m-d H:i:s');

    $possibleColumns = array(
        'title' => $title,
        'message' => $message,
        'type' => 'group_walk_application',
        'is_read' => 0,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    );

    try {
        $stmt = $pdo->query('PRAGMA table_info("notifications")');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
    } catch (Throwable $e) {
        return;
    } catch (Exception $e) {
        return;
    }

    $columns = array();
    foreach ($rows as $row) {
        if (isset($row['name'])) {
            $columns[] = (string) $row['name'];
        }
    }

    if (empty($columns)) {
        return;
    }

    $insertCols = array();
    $placeholders = array();
    $params = array();

    foreach ($possibleColumns as $col => $value) {
        if (in_array($col, $columns, true)) {
            $insertCols[] = $col;
            $placeholders[] = ':' . $col;
            $params[':' . $col] = $value;
        }
    }

    if (empty($insertCols)) {
        return;
    }

    $sql = 'INSERT INTO notifications (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $placeholders) . ')';
    safe_execute($pdo, $sql, $params);
}

function old_value(array $data, $key)
{
    return h(isset($data[$key]) ? $data[$key] : '');
}

function normalize_multiline_text($value)
{
    $value = str_replace("\r\n", "\n", (string) $value);
    $value = str_replace("\r", "\n", $value);
    return trim($value);
}

if (!create_group_walks_table_if_needed($pdo)) {
    http_response_code(500);
    echo 'Could not prepare the application table.';
    exit;
}

$formData = array(
    'owner_name' => '',
    'email' => '',
    'phone' => '',
    'neighborhood' => '',
    'dog_name' => '',
    'breed' => '',
    'size' => '',
    'age' => '',
    'temperament' => '',
    'leash_behavior' => '',
    'preferred_days' => '',
    'preferred_time' => '',
    'prior_group_experience' => '',
    'notes' => '',
);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['owner_name'] = trim((string) (isset($_POST['owner_name']) ? $_POST['owner_name'] : ''));
    $formData['email'] = trim((string) (isset($_POST['email']) ? $_POST['email'] : ''));
    $formData['phone'] = trim((string) (isset($_POST['phone']) ? $_POST['phone'] : ''));
    $formData['neighborhood'] = trim((string) (isset($_POST['neighborhood']) ? $_POST['neighborhood'] : ''));
    $formData['dog_name'] = trim((string) (isset($_POST['dog_name']) ? $_POST['dog_name'] : ''));
    $formData['breed'] = trim((string) (isset($_POST['breed']) ? $_POST['breed'] : ''));
    $formData['size'] = trim((string) (isset($_POST['size']) ? $_POST['size'] : ''));
    $formData['age'] = trim((string) (isset($_POST['age']) ? $_POST['age'] : ''));
    $formData['temperament'] = normalize_multiline_text(isset($_POST['temperament']) ? $_POST['temperament'] : '');
    $formData['leash_behavior'] = normalize_multiline_text(isset($_POST['leash_behavior']) ? $_POST['leash_behavior'] : '');
    $formData['preferred_days'] = trim((string) (isset($_POST['preferred_days']) ? $_POST['preferred_days'] : ''));
    $formData['preferred_time'] = trim((string) (isset($_POST['preferred_time']) ? $_POST['preferred_time'] : ''));
    $formData['prior_group_experience'] = trim((string) (isset($_POST['prior_group_experience']) ? $_POST['prior_group_experience'] : ''));
    $formData['notes'] = normalize_multiline_text(isset($_POST['notes']) ? $_POST['notes'] : '');

    if ($formData['owner_name'] === '') {
        $error = 'Please enter your full name.';
    } elseif ($formData['email'] === '' || !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($formData['phone'] === '') {
        $error = 'Please enter your phone number.';
    } elseif ($formData['neighborhood'] === '') {
        $error = 'Please enter your neighborhood or area.';
    } elseif ($formData['dog_name'] === '') {
        $error = 'Please enter your dog’s name.';
    } elseif ($formData['breed'] === '') {
        $error = 'Please enter your dog’s breed.';
    } elseif ($formData['size'] === '') {
        $error = 'Please choose your dog’s size.';
    } elseif ($formData['age'] === '') {
        $error = 'Please enter your dog’s age.';
    } elseif ($formData['temperament'] === '') {
        $error = 'Please tell us about your dog’s temperament.';
    } elseif ($formData['leash_behavior'] === '') {
        $error = 'Please tell us about leash behavior.';
    } elseif ($formData['preferred_days'] === '') {
        $error = 'Please select your preferred days.';
    } elseif ($formData['preferred_time'] === '') {
        $error = 'Please select your preferred time window.';
    } elseif ($formData['prior_group_experience'] === '') {
        $error = 'Please tell us whether your dog has prior group walk experience.';
    } else {
        $insertOk = safe_execute(
            $pdo,
            "INSERT INTO group_walk_applications (
                owner_name,
                email,
                phone,
                neighborhood,
                dog_name,
                breed,
                size,
                age,
                temperament,
                leash_behavior,
                preferred_days,
                preferred_time,
                prior_group_experience,
                notes,
                status,
                created_at
            ) VALUES (
                :owner_name,
                :email,
                :phone,
                :neighborhood,
                :dog_name,
                :breed,
                :size,
                :age,
                :temperament,
                :leash_behavior,
                :preferred_days,
                :preferred_time,
                :prior_group_experience,
                :notes,
                'new',
                :created_at
            )",
            array(
                ':owner_name' => $formData['owner_name'],
                ':email' => $formData['email'],
                ':phone' => $formData['phone'],
                ':neighborhood' => $formData['neighborhood'],
                ':dog_name' => $formData['dog_name'],
                ':breed' => $formData['breed'],
                ':size' => $formData['size'],
                ':age' => $formData['age'],
                ':temperament' => $formData['temperament'],
                ':leash_behavior' => $formData['leash_behavior'],
                ':preferred_days' => $formData['preferred_days'],
                ':preferred_time' => $formData['preferred_time'],
                ':prior_group_experience' => $formData['prior_group_experience'],
                ':notes' => $formData['notes'],
                ':created_at' => date('Y-m-d H:i:s'),
            )
        );

        if ($insertOk) {
            create_notification_if_possible(
                $pdo,
                'New Group Walk Application',
                'A new group walk application was submitted by ' . $formData['owner_name'] . ' for dog ' . $formData['dog_name'] . '.'
            );

            $success = 'Your group walk application has been submitted successfully. We will review it and get back to you.';
            $formData = array(
                'owner_name' => '',
                'email' => '',
                'phone' => '',
                'neighborhood' => '',
                'dog_name' => '',
                'breed' => '',
                'size' => '',
                'age' => '',
                'temperament' => '',
                'leash_behavior' => '',
                'preferred_days' => '',
                'preferred_time' => '',
                'prior_group_experience' => '',
                'notes' => '',
            );
        } else {
            $error = 'We could not submit your application right now. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Group Walks | Doggie Dorian’s</title>
    <meta name="description" content="Apply for Doggie Dorian’s premium curated group walks.">
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #09090d;
            color: #f4f1ea;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .page {
            max-width: 1120px;
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
            font-size: 1.45rem;
            font-weight: 900;
            letter-spacing: .04em;
        }

        .top-links {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .top-link {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            font-weight: 700;
        }

        .hero {
            display: grid;
            grid-template-columns: 1.05fr 0.95fr;
            gap: 20px;
            margin-bottom: 22px;
        }

        .card {
            background: linear-gradient(180deg, rgba(255,255,255,0.065), rgba(255,255,255,0.03));
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 24px;
            padding: 22px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.28);
        }

        .hero-primary {
            background: linear-gradient(135deg, rgba(198,178,139,0.18), rgba(255,255,255,0.04));
        }

        .eyebrow {
            color: #c6b28b;
            text-transform: uppercase;
            letter-spacing: .14em;
            font-size: .75rem;
            font-weight: 800;
            margin-bottom: 10px;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 2rem;
            line-height: 1.08;
        }

        h2 {
            margin: 0 0 10px;
            font-size: 1.35rem;
        }

        .sub {
            color: rgba(244,241,234,0.72);
            line-height: 1.6;
        }

        .pricing-callout {
            margin-top: 18px;
            padding: 16px 18px;
            border-radius: 18px;
            background: linear-gradient(135deg, rgba(226,196,141,0.16), rgba(185,151,91,0.08));
            border: 1px solid rgba(226,196,141,0.28);
            color: #f3e5c7;
            line-height: 1.65;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.04);
        }

        .pricing-callout strong {
            color: #fff4dc;
        }

        .flash-error,
        .flash-success {
            margin-bottom: 18px;
            padding: 14px 18px;
            border-radius: 16px;
            font-weight: 700;
        }

        .flash-error {
            background: rgba(214,123,123,0.14);
            border: 1px solid rgba(214,123,123,0.3);
            color: #ffd5d5;
        }

        .flash-success {
            background: rgba(125,206,141,0.14);
            border: 1px solid rgba(125,206,141,0.3);
            color: #d7f1dd;
        }

        .feature-list {
            display: grid;
            gap: 12px;
            margin-top: 18px;
        }

        .feature {
            padding: 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .feature strong {
            display: block;
            margin-bottom: 6px;
        }

        .form-card {
            margin-top: 18px;
        }

        form {
            display: grid;
            gap: 16px;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: rgba(244,241,234,0.58);
            font-weight: 800;
        }

        select, input, textarea {
            width: 100%;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(0,0,0,0.26);
            color: #fff;
            padding: 13px 14px;
            font: inherit;
            outline: none;
        }

        textarea {
            min-height: 120px;
            resize: vertical;
        }

        .helper {
            margin-top: 7px;
            color: rgba(244,241,234,0.62);
            font-size: 13px;
            line-height: 1.6;
        }

        .submit-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 6px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 46px;
            padding: 12px 18px;
            border: none;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
            transition: transform 0.15s ease, opacity 0.15s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-gold {
            background: linear-gradient(135deg, #e2c48d, #b9975b);
            color: #0b0b10;
        }

        .btn-light {
            background: rgba(255,255,255,0.06);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.12);
        }

        @media (max-width: 980px) {
            .hero,
            .grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .page {
                padding: 20px 12px 60px;
            }

            h1 {
                font-size: 1.65rem;
            }

            .card {
                padding: 18px;
                border-radius: 22px;
            }

            .btn {
                width: 100%;
            }

            .submit-row {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="topbar">
            <div class="brand">Doggie Dorian’s</div>

            <div class="top-links">
                <a class="top-link" href="index.php">Home</a>
                <a class="top-link" href="book-service.php">Book Service</a>
                <a class="top-link" href="non-member-booking.php">Non-Member Booking</a>
                <a class="top-link" href="login.php">Member Login</a>
            </div>
        </div>

        <?php if ($error !== ''): ?>
            <div class="flash-error"><?php echo h($error); ?></div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="flash-success"><?php echo h($success); ?></div>
        <?php endif; ?>

        <section class="hero">
            <div class="card hero-primary">
                <div class="eyebrow">Group Walk Program</div>
                <h1>Apply for Curated Group Walks</h1>
                <div class="sub">
                    Our curated group walks are designed for social, well-matched dogs who are ready for structured small-group outings with premium handling, careful matching, and controlled pacing.
                </div>

                <div class="pricing-callout">
                    <strong>Curated Group Walk (60 Minutes):</strong><br>
                    Member: <strong>$30</strong> &nbsp;•&nbsp; Non-member: <strong>$40</strong><br>
                    Limited to <strong>5 dogs per group</strong>.<br>
                    <span style="opacity:.9;">Total service window may extend up to 75 minutes including pickup and drop-off coordination.</span>
                </div>

                <div class="feature-list">
                    <div class="feature">
                        <strong>Application based</strong>
                        Group walks are reviewed before acceptance so we can keep the pack safe, balanced, and enjoyable.
                    </div>

                    <div class="feature">
                        <strong>Fit matters</strong>
                        We look at temperament, leash manners, routine, energy level, and compatibility before placing a dog in group walks.
                    </div>

                    <div class="feature">
                        <strong>Premium experience</strong>
                        The goal is controlled, structured, high-quality group walking — not overcrowded pack chaos.
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">What We Review</div>
                <h2>Before approval</h2>
                <div class="sub">
                    We review each application to understand whether your dog is a strong fit for curated group walk structure, pace, and social compatibility.
                </div>

                <div class="feature-list">
                    <div class="feature">
                        <strong>Temperament</strong>
                        Comfort around other dogs, confidence level, and general behavior.
                    </div>

                    <div class="feature">
                        <strong>Handling</strong>
                        Leash manners, responsiveness, and ability to move safely in a group setting.
                    </div>

                    <div class="feature">
                        <strong>Scheduling fit</strong>
                        Neighborhood, preferred days, and time windows help us place dogs efficiently.
                    </div>
                </div>
            </div>
        </section>

        <div class="card form-card">
            <div class="eyebrow">Application Form</div>
            <h2>Tell us about you and your dog</h2>
            <div class="sub" style="margin-bottom:18px;">
                Complete the form below and we’ll review whether your dog is a fit for the curated group walk program.
            </div>

            <form method="post" action="group-walks.php" novalidate>
                <div class="grid">
                    <div>
                        <label for="owner_name">Owner Full Name</label>
                        <input type="text" id="owner_name" name="owner_name" value="<?php echo old_value($formData, 'owner_name'); ?>" required>
                    </div>

                    <div>
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" value="<?php echo old_value($formData, 'email'); ?>" required>
                    </div>
                </div>

                <div class="grid">
                    <div>
                        <label for="phone">Phone Number</label>
                        <input type="text" id="phone" name="phone" value="<?php echo old_value($formData, 'phone'); ?>" required>
                    </div>

                    <div>
                        <label for="neighborhood">Neighborhood / Area</label>
                        <input type="text" id="neighborhood" name="neighborhood" value="<?php echo old_value($formData, 'neighborhood'); ?>" required>
                    </div>
                </div>

                <div class="grid">
                    <div>
                        <label for="dog_name">Dog Name</label>
                        <input type="text" id="dog_name" name="dog_name" value="<?php echo old_value($formData, 'dog_name'); ?>" required>
                    </div>

                    <div>
                        <label for="breed">Breed</label>
                        <input type="text" id="breed" name="breed" value="<?php echo old_value($formData, 'breed'); ?>" required>
                    </div>
                </div>

                <div class="grid">
                    <div>
                        <label for="size">Size</label>
                        <select id="size" name="size" required>
                            <option value="">Select size</option>
                            <option value="small" <?php echo old_value($formData, 'size') === 'small' ? 'selected' : ''; ?>>Small</option>
                            <option value="medium" <?php echo old_value($formData, 'size') === 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="large" <?php echo old_value($formData, 'size') === 'large' ? 'selected' : ''; ?>>Large</option>
                        </select>
                    </div>

                    <div>
                        <label for="age">Age</label>
                        <input type="text" id="age" name="age" value="<?php echo old_value($formData, 'age'); ?>" placeholder="Example: 2 years" required>
                    </div>
                </div>

                <div>
                    <label for="temperament">Temperament / Social Behavior</label>
                    <textarea id="temperament" name="temperament" required><?php echo old_value($formData, 'temperament'); ?></textarea>
                    <div class="helper">Tell us how your dog behaves around other dogs, people, and new environments.</div>
                </div>

                <div>
                    <label for="leash_behavior">Leash Behavior</label>
                    <textarea id="leash_behavior" name="leash_behavior" required><?php echo old_value($formData, 'leash_behavior'); ?></textarea>
                    <div class="helper">Tell us whether your dog pulls, reacts, walks calmly, or needs special handling.</div>
                </div>

                <div class="grid">
                    <div>
                        <label for="preferred_days">Preferred Days</label>
                        <input type="text" id="preferred_days" name="preferred_days" value="<?php echo old_value($formData, 'preferred_days'); ?>" placeholder="Example: Mon, Wed, Fri" required>
                    </div>

                    <div>
                        <label for="preferred_time">Preferred Time Window</label>
                        <input type="text" id="preferred_time" name="preferred_time" value="<?php echo old_value($formData, 'preferred_time'); ?>" placeholder="Example: Mornings or 1pm–4pm" required>
                    </div>
                </div>

                <div>
                    <label for="prior_group_experience">Prior Group Walk Experience</label>
                    <select id="prior_group_experience" name="prior_group_experience" required>
                        <option value="">Select one</option>
                        <option value="yes" <?php echo old_value($formData, 'prior_group_experience') === 'yes' ? 'selected' : ''; ?>>Yes</option>
                        <option value="no" <?php echo old_value($formData, 'prior_group_experience') === 'no' ? 'selected' : ''; ?>>No</option>
                        <option value="some" <?php echo old_value($formData, 'prior_group_experience') === 'some' ? 'selected' : ''; ?>>Some</option>
                    </select>
                </div>

                <div>
                    <label for="notes">Additional Notes</label>
                    <textarea id="notes" name="notes"><?php echo old_value($formData, 'notes'); ?></textarea>
                    <div class="helper">Anything else that would help us review your dog for the group walk program.</div>
                </div>

                <div class="submit-row">
                    <button type="submit" class="btn btn-gold">Submit Application</button>
                    <a class="btn btn-light" href="book-service.php">Back to Booking</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>