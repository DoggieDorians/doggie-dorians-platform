<?php
declare(strict_types=1);

// Security headers
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    if (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    ) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    header(
        "Content-Security-Policy: " .
        "default-src 'self'; " .
        "img-src 'self' data: https:; " .
        "style-src 'self' 'unsafe-inline' https:; " .
        "script-src 'self'; " .
        "font-src 'self' data: https:; " .
        "connect-src 'self' https:; " .
        "frame-ancestors 'self'; " .
        "base-uri 'self'; " .
        "form-action 'self'; " .
        "object-src 'none';"
    );
}

// Detect HTTPS correctly behind proxy/load balancer
$isHttps =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

// Force HTTPS
if (!$isHttps && ($_SERVER['HTTP_HOST'] ?? '') !== 'localhost') {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
    exit;
}

// Secure session settings must be applied before session_start()
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

$isLoggedIn = isset($_SESSION['user_id']) || isset($_SESSION['member_id']) || isset($_SESSION['id']);

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$primaryLink = $isLoggedIn ? 'dashboard.php' : 'non-member-booking.php';
$primaryText = $isLoggedIn ? 'Open Member Dashboard' : 'Book Without an Account';
$secondaryLink = $isLoggedIn ? 'book-service.php' : 'login.php';
$secondaryText = $isLoggedIn ? 'Book Service' : 'Member Login';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doggie Dorian’s | Premium Dog Care</title>
    <meta name="description" content="Premium dog walks, daycare, boarding, sitting, founder memberships, and group walk applications with Doggie Dorian’s.">
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #09090d;
            color: #f4f1ea;
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .page {
            max-width: 1320px;
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
            font-size: 1.5rem;
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

        .top-link-signup {
            background: linear-gradient(135deg, #e2c48d, #b9975b);
            color: #0b0b10;
            border: 1px solid rgba(255,255,255,0.14);
        }

        .hero {
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            gap: 20px;
            margin-bottom: 22px;
        }

        .card {
            background: linear-gradient(180deg, rgba(255,255,255,0.065), rgba(255,255,255,0.03));
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 26px;
            padding: 24px;
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
            margin: 0 0 12px;
            font-size: 2.35rem;
            line-height: 1.04;
        }

        h2 {
            margin: 0 0 10px;
            font-size: 1.35rem;
        }

        .sub {
            color: rgba(244,241,234,0.74);
            line-height: 1.7;
            font-size: 1rem;
        }

        .notice-banner {
            margin-top: 18px;
            padding: 14px 16px;
            border-radius: 18px;
            background: linear-gradient(135deg, rgba(226,196,141,0.16), rgba(185,151,91,0.08));
            border: 1px solid rgba(226,196,141,0.28);
            color: #f3e5c7;
            font-size: .95rem;
            line-height: 1.65;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.04);
        }

        .notice-banner strong {
            color: #fff4dc;
        }

        .cta-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 22px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 48px;
            padding: 13px 18px;
            border-radius: 14px;
            font-size: .95rem;
            font-weight: 800;
            border: none;
            cursor: pointer;
            transition: transform .15s ease;
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

        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
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
            letter-spacing: .12em;
            font-size: .73rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 1.35rem;
            font-weight: 900;
        }

        .section {
            margin-top: 22px;
        }

        .grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
        }

        .feature-card {
            height: 100%;
        }

        .feature-copy {
            color: rgba(244,241,234,0.72);
            line-height: 1.65;
        }

        .pill-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            padding: 9px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            color: rgba(244,241,234,0.88);
            font-size: .85rem;
            font-weight: 700;
        }

        .group-callout {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 20px;
            align-items: center;
        }

        .list {
            display: grid;
            gap: 12px;
            margin-top: 14px;
        }

        .list-item {
            padding: 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .list-item strong {
            display: block;
            margin-bottom: 6px;
            color: #f3e5c7;
        }

        .founder-highlight-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-top: 18px;
        }

        .founder-highlight {
            padding: 14px;
            border-radius: 18px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .founder-highlight strong {
            display: block;
            color: #f3e5c7;
            margin-bottom: 6px;
        }

        .founder-highlight span {
            color: rgba(244,241,234,0.70);
            font-size: .92rem;
            line-height: 1.55;
        }

        .footer {
            margin-top: 44px;
            padding-top: 24px;
            border-top: 1px solid rgba(255,255,255,0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
        }

        .footer-left {
            color: rgba(244,241,234,0.56);
            font-size: .92rem;
        }

        .footer-links {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .footer-links a {
            color: rgba(244,241,234,0.72);
            font-size: .92rem;
            font-weight: 700;
        }

        .footer-links a:hover {
            color: #e2c48d;
        }

        @media (max-width: 1020px) {
            .hero,
            .group-callout,
            .grid-3,
            .founder-highlight-grid {
                grid-template-columns: 1fr;
            }

            .stats {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .page {
                padding: 20px 12px 60px;
            }

            h1 {
                font-size: 1.8rem;
            }

            .card {
                padding: 18px;
                border-radius: 22px;
            }

            .cta-row {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }

            .footer {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }

            .footer-links {
                justify-content: center;
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
                <a class="top-link" href="pricing.php">Pricing</a>
                <a class="top-link" href="memberships.php#founders">Founder Memberships</a>
                <a class="top-link" href="non-member-booking.php">Book Now</a>
                <a class="top-link" href="group-walks.php">Group Walks</a>
                <a class="top-link" href="contact.php">Contact</a>
                <?php if ($isLoggedIn): ?>
                    <a class="top-link" href="dashboard.php">Dashboard</a>
                    <a class="top-link" href="logout.php">Logout</a>
                <?php else: ?>
                    <a class="top-link" href="login.php">Login</a>
                    <a class="top-link top-link-signup" href="signup.php">Sign Up</a>
                <?php endif; ?>
            </div>
        </div>

        <section class="hero">
            <div class="card hero-primary">
                <div class="eyebrow">Premium Dog Care</div>
                <h1>Luxury dog care built around trust, structure, and real-time service visibility.</h1>
                <div class="sub">
                    Doggie Dorian’s offers premium walks, daycare, boarding, sitting, founder memberships, and specialty care with a modern booking experience for both members and non-members.
                </div>

                <div class="notice-banner">
                    <strong>Membership release:</strong> We are accepting only <strong>50 regular memberships</strong>. Once all membership spots are filled, new clients will be placed on a waitlist.
                </div>

                <div class="cta-row">
                    <a class="btn btn-gold" href="<?php echo h($primaryLink); ?>"><?php echo h($primaryText); ?></a>
                    <a class="btn btn-light" href="<?php echo h($secondaryLink); ?>"><?php echo h($secondaryText); ?></a>
                    <a class="btn btn-light" href="memberships.php#founders">View Founder Memberships</a>
                    <?php if (!$isLoggedIn): ?>
                        <a class="btn btn-light" href="signup.php">Create Account</a>
                    <?php endif; ?>
                </div>

                <div class="stats">
                    <div class="stat">
                        <div class="stat-label">Services</div>
                        <div class="stat-value">Walks + Care</div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Membership Access</div>
                        <div class="stat-value">50 Members Only</div>
                    </div>
                    <div class="stat">
                        <div class="stat-label">Experience</div>
                        <div class="stat-value">Premium</div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="eyebrow">Booking Options</div>
                <h2>Choose the right entry point</h2>
                <div class="sub">
                    Whether you are booking your first service, managing care as a returning member, or exploring founder access, we’ve made each path simple and easy to follow.
                </div>

                <div class="list">
                    <div class="list-item">
                        <strong>Book without an account</strong>
                        New clients can schedule services directly without creating a membership first.
                    </div>

                    <div class="list-item">
                        <strong>Member booking</strong>
                        Returning members can manage walks, daycare, boarding, and other services from one coordinated booking hub.
                    </div>

                    <div class="list-item">
                        <strong>Founder memberships</strong>
                        Clients interested in limited founder access can explore the collection and submit a founder request.
                    </div>

                    <div class="list-item">
                        <strong>Group walk applications</strong>
                        Dogs interested in group walks can apply for evaluation and placement before joining the program.
                    </div>
                </div>

                <div class="cta-row">
                    <a class="btn btn-light" href="non-member-booking.php">Book Without an Account</a>
                    <a class="btn btn-light" href="memberships.php#founders">Founder Memberships</a>
                    <a class="btn btn-light" href="group-walks.php">Apply for Group Walks</a>
                    <?php if (!$isLoggedIn): ?>
                        <a class="btn btn-light" href="signup.php">Sign Up</a>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="section">
            <div class="grid-3">
                <div class="card feature-card">
                    <div class="eyebrow">Walks</div>
                    <h2>Structured daily walks</h2>
                    <div class="feature-copy">
                        Solo walks and premium service scheduling with a cleaner booking flow for clients who want reliable, polished care.
                    </div>
                    <div class="pill-row">
                        <span class="pill">15–60 min options</span>
                        <span class="pill">Premium handling</span>
                    </div>
                </div>

                <div class="card feature-card">
                    <div class="eyebrow">Care Services</div>
                    <h2>Boarding, daycare, sitting</h2>
                    <div class="feature-copy">
                        Members can book all core care services in one place through the unified member booking page.
                    </div>
                    <div class="pill-row">
                        <span class="pill">Boarding</span>
                        <span class="pill">Daycare</span>
                        <span class="pill">Sitting</span>
                    </div>
                </div>

                <div class="card feature-card">
                    <div class="eyebrow">Founder Memberships</div>
                    <h2>Limited premium founder access</h2>
                    <div class="feature-copy">
                        Founder memberships are designed for clients who want premium recurring care, founder-only perks, quarterly credit, rollover benefits, and private access.
                    </div>
                    <div class="pill-row">
                        <span class="pill">Founder access</span>
                        <span class="pill">Quarterly credit</span>
                        <span class="pill">Private contact</span>
                    </div>
                </div>
            </div>
        </section>

        <section class="section">
            <div class="card">
                <div class="group-callout">
                    <div>
                        <div class="eyebrow">Founder Memberships</div>
                        <h2>Private founder access for clients who want more than standard booking.</h2>
                        <div class="sub">
                            Our founder memberships are limited by design. They are built for clients who want recurring care, stronger built-in value, premium scheduling advantages, and a more exclusive Doggie Dorian’s experience.
                        </div>

                        <div class="founder-highlight-grid">
                            <div class="founder-highlight">
                                <strong>Founder experience</strong>
                                <span>Founder memberships are reserved for clients who want a more private, premium, and high-touch care relationship.</span>
                            </div>
                            <div class="founder-highlight">
                                <strong>Premium value</strong>
                                <span>Each founder tier includes elevated care value, quarterly service credit, and more exclusive perks.</span>
                            </div>
                            <div class="founder-highlight">
                                <strong>Private access</strong>
                                <span>Founder members receive a private contact path and a higher-touch care experience.</span>
                            </div>
                        </div>

                        <div class="list">
                            <div class="list-item">
                                <strong>Founder Walk Club</strong>
                                Best for clients who want recurring walks, rollover flexibility, and premium founder access.
                            </div>
                            <div class="list-item">
                                <strong>Founder Care Club</strong>
                                Built for clients who want stronger recurring coverage across walks, daycare, drop-ins, and boarding value.
                            </div>
                            <div class="list-item">
                                <strong>Founder Elite Club</strong>
                                Designed for clients who want the highest level of recurring care, premium inclusions, and top-tier access.
                            </div>
                        </div>
                    </div>

                    <div>
                        <div class="card" style="margin:0;">
                            <div class="eyebrow">Founder Access</div>
                            <h2>Apply for premium founder access</h2>
                            <div class="sub">
                                Explore the founder collection, compare the value of each tier, and submit a request for the package that best matches your routine.
                            </div>

                            <div class="list">
                                <div class="list-item">
                                    <strong>Application-based entry</strong>
                                    Founder memberships are requested first, then reviewed before approval.
                                </div>
                                <div class="list-item">
                                    <strong>Quarterly credit included</strong>
                                    Founder tiers include recurring service credit that can also be used toward renewal.
                                </div>
                                <div class="list-item">
                                    <strong>Luxury positioning</strong>
                                    These are curated premium memberships designed for a more exclusive client experience.
                                </div>
                            </div>

                            <div class="cta-row">
                                <a class="btn btn-gold" href="memberships.php#founders">View Founder Memberships</a>
                                <a class="btn btn-light" href="founder-application.php">Apply for Founder Access</a>
                                <a class="btn btn-light" href="non-member-booking.php">Book Other Services</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="section">
            <div class="card">
                <div class="group-callout">
                    <div>
                        <div class="eyebrow">Group Walk Program</div>
                        <h2>Apply for group walks</h2>
                        <div class="sub">
                            Group walks are application-based so each dog can be evaluated for structure, temperament, and pack compatibility before placement.
                        </div>

                        <div class="list">
                            <div class="list-item">
                                <strong>Application first</strong>
                                We review fit before approval.
                            </div>
                            <div class="list-item">
                                <strong>Better placement</strong>
                                Neighborhood, behavior, leash habits, and social compatibility all matter.
                            </div>
                            <div class="list-item">
                                <strong>Premium standard</strong>
                                The goal is a controlled, high-quality group experience.
                            </div>
                        </div>
                    </div>

                    <div>
                        <div class="card" style="margin:0;">
                            <div class="eyebrow">Next Step</div>
                            <h2>Interested?</h2>
                            <div class="sub">
                                Tell us about your dog and we’ll review whether group walks are a strong fit.
                            </div>

                            <div class="cta-row">
                                <a class="btn btn-gold" href="group-walks.php">Apply for Group Walks</a>
                                <a class="btn btn-light" href="non-member-booking.php">Book Other Services</a>
                                <?php if (!$isLoggedIn): ?>
                                    <a class="btn btn-light" href="signup.php">Create Account</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <footer class="footer">
            <div class="footer-left">
                © <?php echo date('Y'); ?> Doggie Dorian’s — premium dog care, founder memberships, cleaner booking flow, and a stronger client experience.
            </div>

            <div class="footer-links">
                <a href="memberships.php#founders">Founder Memberships</a>
                <a href="privacy-policy.php">Privacy Policy</a>
                <a href="legal-notice.php">Legal Notice</a>
            </div>
        </footer>
    </div>
</body>
</html>