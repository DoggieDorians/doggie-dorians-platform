<?php
session_start();
$isLoggedIn = isset($_SESSION['member_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Contact Doggie Dorian’s | Luxury Pet Care Inquiries</title>
  <meta name="description" content="Contact Doggie Dorian’s for luxury dog walking, daycare, boarding, memberships, and premium pet care inquiries.">

  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    :root {
      --bg: #0b0b0e;
      --bg-soft: #121217;
      --panel: rgba(255,255,255,0.05);
      --panel-strong: rgba(255,255,255,0.08);
      --border: rgba(255,255,255,0.10);
      --gold: #d4af37;
      --gold-soft: #f0d77a;
      --cream: #f8f4ea;
      --muted: #b8b2a7;
      --white: #ffffff;
      --shadow: 0 20px 60px rgba(0,0,0,0.45);
      --radius-xl: 28px;
      --radius-lg: 20px;
      --radius-md: 14px;
      --max: 1240px;
    }

    html {
      scroll-behavior: smooth;
    }

    body {
      font-family: "Georgia", "Times New Roman", serif;
      background:
        radial-gradient(circle at top left, rgba(212,175,55,0.12), transparent 30%),
        radial-gradient(circle at top right, rgba(212,175,55,0.07), transparent 28%),
        linear-gradient(180deg, #0a0a0d 0%, #111116 100%);
      color: var(--white);
      line-height: 1.6;
    }

    a {
      text-decoration: none;
      color: inherit;
    }

    .container {
      width: min(var(--max), calc(100% - 32px));
      margin: 0 auto;
    }

    .topbar {
      position: sticky;
      top: 0;
      z-index: 1000;
      backdrop-filter: blur(14px);
      background: rgba(10,10,13,0.72);
      border-bottom: 1px solid rgba(255,255,255,0.08);
    }

    .nav {
      min-height: 84px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 18px;
    }

    .brand {
      display: flex;
      flex-direction: column;
      line-height: 1.05;
    }

    .brand-name {
      font-size: 1.5rem;
      font-weight: 700;
      letter-spacing: 0.5px;
      color: var(--cream);
    }

    .brand-tag {
      font-family: Arial, sans-serif;
      font-size: 0.74rem;
      text-transform: uppercase;
      letter-spacing: 2.8px;
      color: rgba(240,215,122,0.88);
      margin-top: 6px;
    }

    .nav-links {
      display: flex;
      align-items: center;
      gap: 14px;
      flex-wrap: wrap;
    }

    .nav-links a {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.88);
      font-size: 0.95rem;
      padding: 10px 14px;
      border-radius: 999px;
      transition: 0.25s ease;
    }

    .nav-links a:hover {
      background: rgba(255,255,255,0.06);
      color: var(--gold-soft);
    }

    .nav-cta {
      border: 1px solid rgba(212,175,55,0.4);
      background: linear-gradient(135deg, rgba(212,175,55,0.18), rgba(255,255,255,0.04));
      color: var(--cream) !important;
    }

    .hero {
      padding: 72px 0 30px;
    }

    .hero-panel {
      border-radius: var(--radius-xl);
      border: 1px solid var(--border);
      background:
        linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.025)),
        radial-gradient(circle at top left, rgba(212,175,55,0.12), transparent 35%);
      box-shadow: var(--shadow);
      padding: 56px 44px;
      position: relative;
      overflow: hidden;
    }

    .eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      font-family: Arial, sans-serif;
      font-size: 0.8rem;
      letter-spacing: 2.5px;
      text-transform: uppercase;
      color: var(--gold-soft);
      margin-bottom: 18px;
    }

    .eyebrow::before {
      content: "";
      width: 38px;
      height: 1px;
      background: linear-gradient(90deg, var(--gold), transparent);
      display: inline-block;
    }

    .hero h1 {
      font-size: clamp(2.4rem, 5vw, 4.8rem);
      line-height: 0.98;
      color: var(--cream);
      margin-bottom: 18px;
      letter-spacing: -1.4px;
      max-width: 900px;
    }

    .hero h1 span {
      color: var(--gold-soft);
    }

    .hero p {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.78);
      font-size: 1.05rem;
      max-width: 760px;
    }

    section {
      padding: 42px 0;
    }

    .contact-grid {
      display: grid;
      grid-template-columns: 0.9fr 1.1fr;
      gap: 22px;
      align-items: stretch;
    }

    .info-panel,
    .form-panel {
      border-radius: var(--radius-xl);
      border: 1px solid rgba(255,255,255,0.08);
      box-shadow: var(--shadow);
    }

    .info-panel {
      background: linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.02));
      padding: 28px;
    }

    .panel-kicker {
      font-family: Arial, sans-serif;
      text-transform: uppercase;
      letter-spacing: 2.5px;
      color: var(--gold-soft);
      font-size: 0.76rem;
      margin-bottom: 12px;
    }

    .info-panel h2,
    .form-panel h2 {
      font-size: 2rem;
      line-height: 1.06;
      color: var(--cream);
      margin-bottom: 12px;
      letter-spacing: -0.8px;
    }

    .info-panel > p,
    .form-intro {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.74);
      font-size: 0.98rem;
      margin-bottom: 22px;
    }

    .contact-cards {
      display: grid;
      gap: 14px;
      margin-bottom: 20px;
    }

    .contact-card {
      border-radius: 18px;
      padding: 18px;
      background: rgba(255,255,255,0.04);
      border: 1px solid rgba(255,255,255,0.08);
    }

    .contact-card small {
      display: block;
      font-family: Arial, sans-serif;
      text-transform: uppercase;
      letter-spacing: 2px;
      color: var(--gold-soft);
      font-size: 0.72rem;
      margin-bottom: 7px;
    }

    .contact-card strong {
      display: block;
      color: var(--cream);
      font-size: 1.08rem;
      margin-bottom: 4px;
    }

    .contact-card span,
    .contact-card a {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.76);
      font-size: 0.95rem;
      word-break: break-word;
    }

    .contact-card a:hover {
      color: var(--gold-soft);
    }

    .luxury-note {
      border-radius: 20px;
      padding: 18px;
      background: linear-gradient(135deg, rgba(212,175,55,0.11), rgba(255,255,255,0.03));
      border: 1px solid rgba(212,175,55,0.16);
    }

    .luxury-note h3 {
      color: var(--cream);
      font-size: 1.18rem;
      margin-bottom: 8px;
    }

    .luxury-note p {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.76);
      font-size: 0.95rem;
    }

    .form-panel {
      background:
        linear-gradient(180deg, rgba(18,18,23,0.98), rgba(12,12,16,0.96)),
        radial-gradient(circle at top right, rgba(212,175,55,0.09), transparent 30%);
      padding: 30px;
    }

    .contact-form {
      display: grid;
      gap: 16px;
    }

    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }

    .field-group {
      display: grid;
      gap: 8px;
    }

    .field-group label {
      font-family: Arial, sans-serif;
      font-size: 0.9rem;
      color: rgba(255,255,255,0.86);
      font-weight: 600;
    }

    .field-group input,
    .field-group select,
    .field-group textarea {
      width: 100%;
      border: 1px solid rgba(255,255,255,0.10);
      border-radius: 16px;
      background: rgba(255,255,255,0.04);
      color: var(--white);
      padding: 15px 16px;
      font-family: Arial, sans-serif;
      font-size: 0.95rem;
      outline: none;
      transition: border-color 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
    }

    .field-group input::placeholder,
    .field-group textarea::placeholder {
      color: rgba(255,255,255,0.42);
    }

    .field-group input:focus,
    .field-group select:focus,
    .field-group textarea:focus {
      border-color: rgba(212,175,55,0.55);
      background: rgba(255,255,255,0.06);
      box-shadow: 0 0 0 4px rgba(212,175,55,0.08);
    }

    .field-group textarea {
      min-height: 150px;
      resize: vertical;
    }

    .form-note {
      font-family: Arial, sans-serif;
      font-size: 0.88rem;
      color: rgba(255,255,255,0.56);
      margin-top: -2px;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 54px;
      padding: 0 22px;
      border-radius: 999px;
      border: none;
      cursor: pointer;
      font-family: Arial, sans-serif;
      font-size: 0.96rem;
      font-weight: 700;
      letter-spacing: 0.3px;
      transition: transform 0.2s ease, opacity 0.2s ease;
    }

    .btn:hover {
      transform: translateY(-2px);
      opacity: 0.96;
    }

    .btn-gold {
      background: linear-gradient(135deg, #f0d77a 0%, #d4af37 45%, #b9921f 100%);
      color: #18140a;
      box-shadow: 0 14px 30px rgba(212,175,55,0.2);
    }

    .quick-links {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 18px;
    }

    .quick-card {
      border-radius: 22px;
      padding: 24px;
      background: linear-gradient(180deg, rgba(255,255,255,0.045), rgba(255,255,255,0.02));
      border: 1px solid rgba(255,255,255,0.08);
      box-shadow: var(--shadow);
    }

    .quick-card h3 {
      color: var(--cream);
      font-size: 1.2rem;
      margin-bottom: 10px;
    }

    .quick-card p {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.73);
      font-size: 0.95rem;
      margin-bottom: 16px;
    }

    .quick-card a {
      font-family: Arial, sans-serif;
      color: var(--gold-soft);
      font-weight: 700;
      font-size: 0.94rem;
    }

    .quick-card a:hover {
      opacity: 0.88;
    }

    footer {
      padding: 38px 0 50px;
    }

    .footer-wrap {
      border-top: 1px solid rgba(255,255,255,0.08);
      padding-top: 26px;
      display: flex;
      justify-content: space-between;
      gap: 18px;
      flex-wrap: wrap;
    }

    .footer-brand {
      color: var(--cream);
      font-size: 1.15rem;
    }

    .footer-text {
      font-family: Arial, sans-serif;
      color: rgba(255,255,255,0.58);
      font-size: 0.93rem;
    }

    @media (max-width: 1100px) {
      .contact-grid,
      .quick-links {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 860px) {
      .nav {
        flex-direction: column;
        align-items: flex-start;
        padding: 16px 0;
      }

      .nav-links {
        width: 100%;
      }

      .hero-panel {
        padding: 36px 24px;
      }

      .hero h1,
      .info-panel h2,
      .form-panel h2 {
        font-size: 2rem;
      }

      .form-row {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 560px) {
      .container {
        width: min(var(--max), calc(100% - 20px));
      }

      .hero {
        padding-top: 34px;
      }

      .brand-name {
        font-size: 1.28rem;
      }

      .brand-tag {
        letter-spacing: 2px;
      }

      .form-panel,
      .info-panel,
      .quick-card {
        padding: 22px;
      }

      .btn {
        width: 100%;
      }
    }
  </style>
</head>
<body>

  <header class="topbar">
    <div class="container nav">
      <div class="brand">
        <a href="index.php" class="brand-name">Doggie Dorian’s</a>
        <div class="brand-tag">Luxury Pet Care Experience</div>
      </div>

      <nav class="nav-links">
        <a href="index.php">Home</a>
        <a href="services.php">Services</a>
        <a href="memberships.php">Memberships</a>
        <a href="book-walk.php">Book</a>

        <?php if ($isLoggedIn): ?>
          <a href="dashboard.php">Dashboard</a>
        <?php else: ?>
          <a href="login.php">Login</a>
        <?php endif; ?>

        <a href="customize-plan.php" class="nav-cta">Build Your Plan</a>
      </nav>
    </div>
  </header>

  <main>
    <section class="hero">
      <div class="container">
        <div class="hero-panel">
          <div class="eyebrow">Contact Doggie Dorian’s</div>
          <h1>
            Let’s create a more <span>premium</span> care experience for your dog.
          </h1>
          <p>
            Reach out for bookings, memberships, daycare, boarding, custom plans, or general questions. We are building a more elevated pet care experience designed for clients who value trust, quality, and exceptional service.
          </p>
        </div>
      </div>
    </section>

    <section>
      <div class="container contact-grid">
        <div class="info-panel">
          <div class="panel-kicker">Get In Touch</div>
          <h2>We’d love to hear from you.</h2>
          <p>
            Whether you are looking to book your first service or want to ask about premium memberships, we are here to help you find the right fit for your dog.
          </p>

          <div class="contact-cards">
            <div class="contact-card">
              <small>Phone</small>
              <strong>Call or Text</strong>
              <a href="tel:+16316035644">+1 (631) 603-5644</a>
            </div>

            <div class="contact-card">
              <small>Email</small>
              <strong>General Inquiries</strong>
              <a href="mailto:doggie.dorians@gmail.com">doggie.dorians@gmail.com</a>
            </div>

            <div class="contact-card">
              <small>Instagram</small>
              <strong>Follow Our Brand</strong>
              <a href="https://instagram.com/doggie.dorians" target="_blank" rel="noopener noreferrer">@doggie.dorians</a>
            </div>
          </div>

          <div class="luxury-note">
            <h3>Luxury service begins with thoughtful communication.</h3>
            <p>
              We aim to make every interaction feel polished, responsive, and personal from the very first inquiry.
            </p>
          </div>
        </div>

        <div class="form-panel">
          <div class="panel-kicker">Premium Inquiry Form</div>
          <h2>Send us a message</h2>
          <p class="form-intro">
            Use the form below for service inquiries, membership questions, daycare, boarding, or custom requests.
          </p>

          <form class="contact-form" action="mailto:doggie.dorians@gmail.com" method="post" enctype="text/plain">
            <div class="form-row">
              <div class="field-group">
                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="Full Name" placeholder="Your full name" required>
              </div>

              <div class="field-group">
                <label for="phone">Phone Number</label>
                <input type="tel" id="phone" name="Phone Number" placeholder="Your phone number">
              </div>
            </div>

            <div class="form-row">
              <div class="field-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="Email Address" placeholder="Your email address" required>
              </div>

              <div class="field-group">
                <label for="service_type">Service Interest</label>
                <select id="service_type" name="Service Interest" required>
                  <option value="">Select a service</option>
                  <option value="Dog Walking">Dog Walking</option>
                  <option value="Daycare">Daycare</option>
                  <option value="Boarding">Boarding</option>
                  <option value="Memberships">Memberships</option>
                  <option value="Custom Plan">Custom Plan</option>
                  <option value="General Inquiry">General Inquiry</option>
                </select>
              </div>
            </div>

            <div class="form-row">
              <div class="field-group">
                <label for="dog_name">Dog Name</label>
                <input type="text" id="dog_name" name="Dog Name" placeholder="Your dog's name">
              </div>

              <div class="field-group">
                <label for="preferred_contact">Preferred Contact Method</label>
                <select id="preferred_contact" name="Preferred Contact Method">
                  <option value="">Select one</option>
                  <option value="Phone">Phone</option>
                  <option value="Text">Text</option>
                  <option value="Email">Email</option>
                </select>
              </div>
            </div>

            <div class="field-group">
              <label for="message">Tell Us More</label>
              <textarea id="message" name="Message" placeholder="Tell us about the services you’re interested in, your dog, your schedule, or any premium care needs you have." required></textarea>
            </div>

            <div class="form-note">
              This form opens through your device’s default email setup. Later, we can upgrade this to a true website contact form that sends messages directly and looks even more premium.
            </div>

            <button type="submit" class="btn btn-gold">Send Inquiry</button>
          </form>
        </div>
      </div>
    </section>

    <section>
      <div class="container quick-links">
        <div class="quick-card">
          <h3>Book a Service</h3>
          <p>
            Ready to move forward? Start your booking request for walks, daycare, or boarding.
          </p>
          <a href="book-walk.php">Go to Booking</a>
        </div>

        <div class="quick-card">
          <h3>Explore Memberships</h3>
          <p>
            Discover premium options designed for recurring care, better value, and priority access.
          </p>
          <a href="memberships.php">View Memberships</a>
        </div>

        <div class="quick-card">
          <h3>Build Your Plan</h3>
          <p>
            Want something more personalized? Create a custom plan that fits your dog and your schedule.
          </p>
          <a href="customize-plan.php">Customize Now</a>
        </div>
      </div>
    </section>
  </main>

  <footer>
    <div class="container footer-wrap">
      <div>
        <div class="footer-brand">Doggie Dorian’s</div>
        <div class="footer-text">Luxury dog walking, daycare, boarding, and premium membership care.</div>
      </div>

      <div class="footer-text">
        © <?php echo date('Y'); ?> Doggie Dorian’s. All rights reserved.
      </div>
    </div>
  </footer>

</body>
</html>