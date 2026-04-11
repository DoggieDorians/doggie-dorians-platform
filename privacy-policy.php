<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy | Doggie Dorian’s</title>
    <meta name="description" content="Privacy Policy for Doggie Dorian’s premium dog care services.">
    <style>
        body {
            margin: 0;
            background: #09090d;
            color: #f4f1ea;
            font-family: Inter, sans-serif;
            line-height: 1.7;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        h1 {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        h2 {
            margin-top: 30px;
            color: #e2c48d;
        }

        p {
            color: rgba(244,241,234,0.85);
        }

        a {
            color: #e2c48d;
            text-decoration: none;
        }

        .back {
            margin-bottom: 20px;
            display: inline-block;
        }
    </style>
</head>
<body>

<div class="container">
    <a href="index.php" class="back">← Back to Home</a>

    <h1>Privacy Policy</h1>
    <p>Effective Date: <?php echo date('F j, Y'); ?></p>

    <p>
        Doggie Dorian’s ("we", "our", "us") respects your privacy and is committed to protecting your personal information.
        This Privacy Policy explains how we collect, use, and safeguard your information when you use our website and services.
    </p>

    <h2>1. Information We Collect</h2>
    <p>We may collect the following:</p>
    <ul>
        <li>Personal information (name, email, phone number)</li>
        <li>Pet information (name, breed, care notes)</li>
        <li>Booking details and service history</li>
        <li>Login and account data</li>
        <li>Payment-related data (processed securely via third-party providers such as Stripe)</li>
    </ul>

    <h2>2. How We Use Your Information</h2>
    <p>Your information is used to:</p>
    <ul>
        <li>Provide and manage bookings</li>
        <li>Communicate with you regarding services</li>
        <li>Improve our platform and services</li>
        <li>Ensure safety and service quality</li>
    </ul>

    <h2>3. Payment Processing</h2>
    <p>
        Payments are handled securely through third-party providers such as Stripe. We do not store full payment details on our servers.
    </p>

    <h2>4. Data Security</h2>
    <p>
        We implement appropriate security measures to protect your data. However, no system is completely secure.
    </p>

    <h2>5. Sharing of Information</h2>
    <p>
        We do not sell your personal data. Information may be shared with staff or service providers only as necessary to fulfill services.
    </p>

    <h2>6. Your Rights</h2>
    <p>
        You may request access, correction, or deletion of your personal data by contacting us.
    </p>

    <h2>7. Cookies</h2>
    <p>
        We may use cookies and session tracking to improve user experience and maintain login sessions.
    </p>

    <h2>8. Changes to This Policy</h2>
    <p>
        We may update this Privacy Policy at any time. Continued use of the site indicates acceptance.
    </p>

    <h2>9. Contact</h2>
    <p>
        For any privacy-related questions, please contact us through our website.
    </p>

</div>

</body>
</html>