<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
session_start();

$isLoggedIn = isset($_SESSION['user_id']) || isset($_SESSION['member_id']) || isset($_SESSION['id']);

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doggie Dorian’s | Terms of Service</title>
    <meta name="description" content="Doggie Dorian’s membership terms of service, including founder membership terms, credit usage, rollover rules, and booking policies.">
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
            transition: transform .15s ease, background .15s ease, border-color .15s ease;
        }

        .top-link:hover {
            transform: translateY(-1px);
            background: rgba(255,255,255,0.10);
        }

        .top-link-signup {
            background: linear-gradient(135deg, #e2c48d, #b9975b);
            color: #0b0b10;
            border: 1px solid rgba(255,255,255,0.14);
        }

        .top-link-signup:hover {
            background: linear-gradient(135deg, #ead1a0, #c6a468);
        }

        .tos-hero {
            background: linear-gradient(135deg, rgba(198,178,139,0.18), rgba(255,255,255,0.04));
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 26px;
            padding: 28px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.28);
            margin-bottom: 22px;
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
            margin: 0 0 14px;
            font-size: clamp(2.3rem, 5vw, 4.1rem);
            line-height: 1.02;
            color: #f4f1ea;
        }

        .hero-sub {
            color: rgba(244,241,234,0.76);
            line-height: 1.75;
            font-size: 1rem;
            max-width: 900px;
        }

        .effective-date {
            margin-top: 16px;
            color: #f3e5c7;
            font-size: .95rem;
            font-weight: 800;
        }

        .tos-card {
            background: linear-gradient(180deg, rgba(255,255,255,0.065), rgba(255,255,255,0.03));
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 26px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.28);
        }

        .tos-card h2 {
            margin: 30px 0 14px;
            font-size: 1.9rem;
            line-height: 1.15;
            color: #f4f1ea;
        }

        .tos-card h3 {
            margin: 24px 0 10px;
            font-size: 1.15rem;
            line-height: 1.35;
            color: #f3e5c7;
        }

        .tos-card p {
            margin: 0 0 14px;
            color: rgba(244,241,234,0.82);
            line-height: 1.82;
            font-size: 1rem;
        }

        .tos-card ul {
            margin: 0 0 18px;
            padding-left: 22px;
        }

        .tos-card li {
            margin-bottom: 10px;
            color: rgba(244,241,234,0.82);
            line-height: 1.78;
        }

        .tos-card hr {
            border: 0;
            border-top: 1px solid rgba(255,255,255,0.08);
            margin: 32px 0;
        }

        .tos-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 24px;
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

        @media (max-width: 900px) {
            .page {
                padding: 22px 14px 60px;
            }

            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .top-links {
                width: 100%;
            }

            .tos-hero,
            .tos-card {
                padding: 22px;
                border-radius: 22px;
            }

            .tos-card h2 {
                font-size: 1.6rem;
            }
        }

        @media (max-width: 640px) {
            .brand {
                font-size: 1.3rem;
            }

            .top-links {
                gap: 10px;
            }

            .top-link {
                padding: 9px 12px;
                font-size: .92rem;
            }

            .tos-card,
            .tos-hero {
                padding: 18px;
            }

            .tos-card h2 {
                font-size: 1.4rem;
            }

            .tos-card h3 {
                font-size: 1.05rem;
            }

            .tos-card p,
            .tos-card li,
            .hero-sub {
                font-size: .96rem;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="topbar">
            <a href="index.php" class="brand">Doggie Dorian’s</a>

            <div class="top-links">
                <a href="index.php" class="top-link">Home</a>
                <a href="pricing.php" class="top-link">Pricing</a>
                <a href="founders-memberships.php" class="top-link">Founder Memberships</a>
                <a href="<?php echo $isLoggedIn ? 'book-service.php' : 'non-member-booking.php'; ?>" class="top-link">Book Now</a>
                <a href="group-walks.php" class="top-link">Group Walks</a>
                <a href="contact.php" class="top-link">Contact</a>
                <a href="login.php" class="top-link">Login</a>
                <a href="signup.php" class="top-link top-link-signup">Sign Up</a>
            </div>
        </div>

        <section class="tos-hero">
            <div class="eyebrow">Membership Policies</div>
            <h1>Membership Terms of Service</h1>
            <div class="hero-sub">
                These Terms of Service govern the use of Doggie Dorian’s memberships, including founder memberships,
                recurring membership benefits, service credits, booking privileges, and related membership perks.
            </div>
            <div class="effective-date">Effective Date: April 7, 2026</div>
        </section>

        <section class="tos-card">
            <p>
                By purchasing or using a Doggie Dorian’s membership, including any Founder Membership Package,
                you agree to the following Terms of Service.
            </p>

            <h2>1. Membership Overview</h2>
            <p>
                Doggie Dorian’s memberships provide access to bundled services, discounted pricing, priority booking,
                and exclusive benefits.
            </p>
            <p>
                Memberships are recurring and billed on a monthly basis unless otherwise specified.
            </p>

            <h2>2. Payments &amp; Billing</h2>
            <ul>
                <li>Memberships are billed automatically on a recurring basis.</li>
                <li>Payments are processed securely through Stripe or another approved payment provider.</li>
                <li>By subscribing, you authorize automatic recurring charges.</li>
                <li>All payments are non-refundable and non-transferable.</li>
            </ul>
            <p>
                Failure to complete payment may result in suspension or termination of membership benefits.
            </p>

            <h2>3. Credits &amp; Usage (General)</h2>
            <ul>
                <li>Service credits, including walks, daycare, drop-ins, boarding, and related services, have no cash value.</li>
                <li>All credits are non-transferable.</li>
                <li>Credits must be used within their designated validity period.</li>
            </ul>
            <p>
                Doggie Dorian’s reserves the right to define credit value, apply internal pricing and time-value calculations,
                and adjust credit structures with notice.
            </p>

            <h2>4. Booking &amp; Scheduling</h2>
            <ul>
                <li>All services must be scheduled in advance.</li>
                <li>Bookings are subject to availability and operational capacity.</li>
                <li>Members may receive priority booking over non-members.</li>
            </ul>
            <p>
                Priority access does not guarantee availability.
            </p>

            <h2>5. Cancellations &amp; No-Shows</h2>
            <ul>
                <li>Cancellations must be made at least <strong>24 hours</strong> in advance.</li>
                <li>Late cancellations or no-shows may result in lost credits and/or additional fees.</li>
            </ul>

            <h2>6. Membership Changes &amp; Cancellation</h2>
            <ul>
                <li>Memberships may be canceled at any time prior to the next billing cycle.</li>
                <li>No partial refunds will be issued.</li>
                <li>If a membership is canceled, benefits may immediately terminate.</li>
                <li>Promotional pricing, including founder pricing, may be forfeited upon cancellation or lapse.</li>
            </ul>

            <h2>7. Client Responsibilities</h2>
            <p>You agree that:</p>
            <ul>
                <li>Your dog is healthy, vaccinated, and safe for handling and interaction.</li>
                <li>You will disclose all relevant behavioral issues, medical conditions, and risks.</li>
                <li>You accept responsibility for your dog’s behavior.</li>
            </ul>
            <p>
                Doggie Dorian’s may refuse service at any time for safety or operational reasons.
            </p>

            <h2>8. Liability Waiver</h2>
            <p>
                Dog-related services involve inherent risks. Doggie Dorian’s, its owners, employees, and contractors
                are not liable for injury, illness, or death of any pet, or for damage caused by your dog.
            </p>
            <p>
                You agree to indemnify and hold Doggie Dorian’s harmless from any claims arising from your dog,
                your use of services, or your breach of these terms.
            </p>

            <h2>9. Service Interruptions</h2>
            <p>
                Doggie Dorian’s is not liable for delays or interruptions caused by weather, emergencies,
                staffing issues, transportation issues, or events beyond its control.
            </p>

            <h2>10. Modifications to Terms</h2>
            <p>
                Doggie Dorian’s may update these Terms of Service at any time. Continued use of services
                constitutes acceptance of any updated terms.
            </p>

            <h2>11. Contact Information</h2>
            <p>
                <strong>Doggie Dorian’s</strong><br>
                Email: [Insert Email]<br>
                Phone: [Insert Phone]<br>
                Website: [Insert URL]
            </p>

            <hr>

            <h2>12. Founder Membership Terms</h2>
            <p>
                Founder memberships are limited, premium offerings and include additional terms beyond the general
                membership terms above.
            </p>

            <h3>A. Credit Conversion (Service Duration Adjustments)</h3>
            <p>
                Founder credits are based on standard service durations, including 30-minute walks.
                Clients may request conversion into longer durations.
            </p>
            <ul>
                <li>Credits are adjusted proportionally based on time.</li>
                <li>Example: 16 thirty-minute walks may be converted into 8 one-hour walks.</li>
                <li>All conversions are calculated based on total time value, not session count.</li>
            </ul>
            <p>
                Doggie Dorian’s reserves full discretion over all conversions, adjustments, approvals, and implementation.
            </p>

            <h3>B. Founder Credit Expiration (14-Month Rule)</h3>
            <p>
                All credits issued under founder memberships must be used within <strong>14 months</strong> of issuance.
            </p>
            <ul>
                <li>Credits automatically expire after 14 months.</li>
                <li>Credits are non-refundable and non-transferable.</li>
                <li>Unused credits are forfeited after expiration.</li>
            </ul>

            <h3>C. Service Credit (Quarterly Issuance)</h3>
            <p>
                Founder memberships may include annual service credits issued quarterly.
            </p>
            <ul>
                <li>Credits are issued in equal quarterly installments depending on membership tier.</li>
                <li>Credits may be used toward eligible services, boarding, or membership renewal.</li>
                <li>Credits have no cash value and are non-transferable.</li>
                <li>All quarterly-issued credits may accumulate and must be used by the end of the final quarter of the membership year.</li>
                <li>Any unused quarterly credits remaining after the final quarter will expire and will not roll over into a new membership year.</li>
                <li>Credits are valid only while the membership remains active and in good standing.</li>
            </ul>
            <p>
                Doggie Dorian’s reserves the right to track, apply, validate, and reject credit usage where necessary.
            </p>

            <h3>D. Included Services &amp; Monthly Allocations</h3>
            <p>
                Each founder tier includes fixed monthly services, which may include walks, daycare days,
                drop-in visits, boarding nights, service credits, and founder-only benefits.
            </p>
            <ul>
                <li>All included services are non-transferable.</li>
                <li>All included services are non-refundable.</li>
                <li>Standard service durations apply unless converted under Section 12A.</li>
            </ul>

            <h3>E. Walk Rollover Policy</h3>
            <ul>
                <li>Unused walks roll over for <strong>one (1) billing cycle only</strong>.</li>
                <li>Rolled-over walks must be used in the immediately following month.</li>
                <li>Any rolled-over walks not used within that following month expire.</li>
                <li>No additional or extended rollover is permitted beyond one cycle.</li>
            </ul>

            <h3>F. Boarding Benefits</h3>
            <ul>
                <li>Complimentary boarding nights must be used within the active cycle unless otherwise approved in writing.</li>
                <li>Boarding discounts apply only to active members at the time of booking and service.</li>
                <li>All boarding benefits remain subject to availability and scheduling capacity.</li>
            </ul>

            <h3>G. Booking Priority &amp; Reserved Availability</h3>
            <p>
                Founder members may receive priority scheduling and reserved availability during peak demand periods.
                These benefits remain subject to operational capacity and do not guarantee availability in all circumstances.
            </p>

            <h3>H. Founder Pricing Protection</h3>
            <ul>
                <li>Founder pricing is locked in only while the membership remains active and in good standing.</li>
                <li>If a founder membership is canceled, paused beyond permitted limits, terminated, or allowed to lapse, founder pricing may be permanently forfeited.</li>
                <li>Any future re-enrollment may be subject to current pricing and availability.</li>
            </ul>

            <h3>I. Founder Access &amp; Communication</h3>
            <p>
                Founder-only communication access, including private contact access, is a privilege and may be modified,
                limited, or revoked at any time in Doggie Dorian’s sole discretion.
            </p>

            <h3>J. Limited Availability</h3>
            <p>
                Founder memberships are limited in quantity. Once founder spots are filled, no additional founder memberships
                are required to be offered, and new clients may be directed to standard service offerings instead.
            </p>

            <h3>K. Membership Value Representation</h3>
            <p>
                Any displayed membership value, savings amount, or comparative pricing is based on standard pricing estimates
                and is provided for illustrative purposes only. Actual realized value may vary based on client usage and booking patterns.
            </p>

            <h3>L. Add-On Services &amp; Discounts</h3>
            <ul>
                <li>Discounts apply only to eligible services.</li>
                <li>Discounts cannot be combined with other offers unless explicitly stated.</li>
                <li>All add-on services remain subject to standard pricing rules, scheduling limits, and availability.</li>
            </ul>

            <h3>M. Fair Use &amp; Misuse</h3>
            <p>
                Founder memberships are intended for reasonable personal use.
                Doggie Dorian’s may suspend, restrict, or terminate memberships for abuse of services,
                excessive or unreasonable usage, fraud, misconduct, resale, scheduling abuse,
                or circumvention of pricing or membership structures.
            </p>

            <h3>N. Service Substitution</h3>
            <p>
                Doggie Dorian’s may substitute services of equal time or value when necessary due to availability,
                staffing, safety, logistics, or operational constraints.
            </p>

            <h3>O. Modifications to Founder Packages</h3>
            <p>
                Doggie Dorian’s may modify founder package structure, included benefits, perks, availability, and policies.
                However, already-issued valid credits will be honored within their applicable validity period unless otherwise required by law.
            </p>

            <h3>P. Founder Tier Descriptions</h3>
            <p>
                Founder package descriptions, including Walk Club, Care Club, and Elite Club, describe current intended membership structures
                and perks. These descriptions do not override these Terms of Service, and Doggie Dorian’s reserves the right
                to interpret, apply, and administer all founder benefits in accordance with these Terms.
            </p>

            <h3>Q. Acceptance</h3>
            <p>
                By enrolling in any founder membership, including Founder Walk Club, Founder Care Club, or Founder Elite Club,
                you acknowledge that you have read, understood, and agreed to all applicable terms in this Terms of Service.
            </p>

            <div class="tos-actions">
                <a href="founders-memberships.php" class="btn btn-gold">View Founder Memberships</a>
                <a href="pricing.php" class="btn btn-light">View Pricing</a>
                <a href="contact.php" class="btn btn-light">Contact Us</a>
            </div>
        </section>
    </div>
</body>
</html>