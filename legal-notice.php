<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Legal Notice | Doggie Dorian’s</title>
    <meta name="description" content="Legal Notice for Doggie Dorian’s premium dog care services.">
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

    <h1>Legal Notice</h1>
    <p>Effective Date: <?php echo date('F j, Y'); ?></p>

    <h2>1. Company Information</h2>
    <p>
        Doggie Dorian’s provides premium dog care services including walking, daycare, boarding, and sitting.
    </p>

    <h2>2. Service Disclaimer</h2>
    <p>
        While we take all reasonable precautions to ensure the safety and well-being of pets, Doggie Dorian’s is not liable for unforeseen incidents, injuries, or health issues that may occur during services.
    </p>

    <h2>3. User Responsibilities</h2>
    <p>Clients agree to:</p>
    <ul>
        <li>Provide accurate pet and contact information</li>
        <li>Disclose behavioral or medical conditions</li>
        <li>Ensure pets are suitable for requested services</li>
    </ul>

    <h2>4. Booking & Cancellations</h2>
    <p>
        Booking policies, cancellations, and refunds are subject to our internal service guidelines and may be updated at any time.
    </p>

    <h2>5. Payments</h2>
    <p>
        Payments are processed through secure third-party providers. By booking services, you agree to applicable charges and fees.
    </p>

    <h2>6. Intellectual Property</h2>
    <p>
        All content on this website, including branding, text, and design, is the property of Doggie Dorian’s and may not be copied or used without permission.
    </p>

    <h2>7. Limitation of Liability</h2>
    <p>
        Doggie Dorian’s shall not be held liable for indirect, incidental, or consequential damages arising from use of the website or services.
    </p>

    <h2>8. Modifications</h2>
    <p>
        We reserve the right to modify services, pricing, and policies at any time without prior notice.
    </p>

    <h2>9. Governing Law</h2>
    <p>
        These terms shall be governed by the laws of the applicable jurisdiction.
    </p>

</div>

</body>
</html>