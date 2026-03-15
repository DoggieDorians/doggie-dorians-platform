<?php include 'includes/header.php'; ?>
<?php include 'includes/nav.php'; ?>

<style>
.membership-page {
  background: #f4f1ea;
  color: #111111;
}

.membership-shell {
  max-width: 1320px;
  margin: 0 auto;
  padding: 34px 20px 70px;
  display: grid;
  gap: 24px;
}

.membership-hero {
  background: linear-gradient(135deg, #111111 0%, #2b2414 100%);
  color: #ffffff;
  border-radius: 30px;
  padding: 42px 36px;
  box-shadow: 0 14px 40px rgba(0,0,0,0.12);
}

.membership-hero h1 {
  margin: 0 0 14px;
  font-size: 46px;
  line-height: 1.08;
}

.membership-hero p {
  margin: 0;
  max-width: 780px;
  color: rgba(255,255,255,0.82);
  font-size: 17px;
  line-height: 1.6;
}

.hero-actions {
  display: flex;
  gap: 14px;
  flex-wrap: wrap;
  margin-top: 24px;
}

.hero-button {
  display: inline-block;
  padding: 14px 18px;
  border-radius: 999px;
  font-weight: 700;
  text-decoration: none;
}

.hero-button.gold {
  background: #d4af37;
  color: #111111;
}

.hero-button.light {
  background: rgba(255,255,255,0.08);
  color: #ffffff;
  border: 1px solid rgba(255,255,255,0.1);
}

.section-card {
  background: #ffffff;
  border-radius: 26px;
  padding: 28px;
  box-shadow: 0 12px 30px rgba(0,0,0,0.07);
}

.section-card h2 {
  margin: 0 0 12px;
  font-size: 30px;
}

.section-card p.section-intro {
  margin: 0 0 22px;
  color: #666666;
  line-height: 1.6;
}

.value-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 16px;
}

.value-box {
  background: #f7f4ee;
  border-radius: 20px;
  padding: 20px;
}

.value-box h3 {
  margin: 0 0 8px;
  font-size: 20px;
}

.value-box p {
  margin: 0;
  color: #666666;
  line-height: 1.55;
}

.plan-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 18px;
}

.plan-box {
  background: #f7f4ee;
  border-radius: 22px;
  padding: 24px;
  position: relative;
}

.plan-box.featured {
  background: linear-gradient(180deg, #111111 0%, #231d11 100%);
  color: #ffffff;
}

.plan-badge {
  display: inline-block;
  margin-bottom: 14px;
  padding: 8px 12px;
  border-radius: 999px;
  background: rgba(212,175,55,0.18);
  color: #d4af37;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.7px;
  text-transform: uppercase;
}

.plan-box h3 {
  margin: 0 0 10px;
  font-size: 26px;
}

.plan-price {
  font-size: 34px;
  font-weight: 800;
  margin: 10px 0 14px;
}

.plan-box p {
  margin: 0 0 18px;
  line-height: 1.6;
  color: inherit;
}

.plan-list {
  display: grid;
  gap: 10px;
}

.plan-list div {
  padding: 12px 14px;
  border-radius: 14px;
  background: rgba(255,255,255,0.65);
  color: #111111;
}

.plan-box.featured .plan-list div {
  background: rgba(255,255,255,0.08);
  color: #ffffff;
}

.compare-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 16px;
}

.compare-box {
  background: #f7f4ee;
  border-radius: 20px;
  padding: 20px;
}

.compare-box h3 {
  margin: 0 0 8px;
  font-size: 20px;
}

.compare-box p {
  margin: 0;
  color: #666666;
  line-height: 1.55;
}

.cta-card {
  background: linear-gradient(135deg, #111111 0%, #2b2414 100%);
  color: #ffffff;
  border-radius: 30px;
  padding: 34px;
  box-shadow: 0 14px 40px rgba(0,0,0,0.12);
}

.cta-card h2 {
  margin: 0 0 10px;
  font-size: 34px;
}

.cta-card p {
  margin: 0;
  max-width: 760px;
  color: rgba(255,255,255,0.82);
  line-height: 1.6;
}

.cta-actions {
  display: flex;
  gap: 14px;
  flex-wrap: wrap;
  margin-top: 22px;
}

.cta-button {
  display: inline-block;
  padding: 14px 18px;
  border-radius: 999px;
  font-weight: 700;
  text-decoration: none;
}

.cta-button.gold {
  background: #d4af37;
  color: #111111;
}

.cta-button.light {
  background: rgba(255,255,255,0.08);
  color: #ffffff;
  border: 1px solid rgba(255,255,255,0.1);
}

@media (max-width: 980px) {
  .value-grid,
  .compare-grid,
  .plan-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 700px) {
  .membership-hero h1 {
    font-size: 34px;
  }

  .section-card h2,
  .cta-card h2 {
    font-size: 28px;
  }

  .membership-hero,
  .section-card,
  .cta-card {
    padding: 24px;
  }
}
</style>

<main class="membership-page">
  <div class="membership-shell">

    <section class="membership-hero">
      <h1>Membership for dog owners who want more than basic care</h1>
      <p>
        Doggie Dorian’s Membership is designed for clients who want reliable,
        elevated dog care with better value, smoother scheduling, premium communication,
        and a more personalized experience.
      </p>

      <div class="hero-actions">
        <a href="signup.php" class="hero-button gold">Become a Member</a>
        <a href="customize-plan.php" class="hero-button light">Build Your Plan</a>
      </div>
    </section>

    <section class="section-card">
      <h2>Why members choose us</h2>
      <p class="section-intro">
        Membership is built to make recurring dog care easier, more flexible, and more luxurious.
      </p>

      <div class="value-grid">
        <div class="value-box">
          <h3>Better pricing</h3>
          <p>
            Members receive better rates than non-members, with upfront options creating even stronger value.
          </p>
        </div>

        <div class="value-box">
          <h3>Priority access</h3>
          <p>
            Membership clients are positioned for easier repeat scheduling and a more concierge-style experience.
          </p>
        </div>

        <div class="value-box">
          <h3>More personalized care</h3>
          <p>
            Dog profiles, walk notes, live updates, and recurring preferences help every service feel tailored.
          </p>
        </div>
      </div>
    </section>

    <section class="section-card">
      <h2>Two ways to join</h2>
      <p class="section-intro">
        Choose the structure that fits your routine, flexibility, and budget best.
      </p>

      <div class="plan-grid">
        <div class="plan-box">
          <span class="plan-badge">Flexible Option</span>
          <h3>Pay As You Go</h3>
          <div class="plan-price">Member pricing</div>
          <p>
            Great for clients who want membership benefits with more flexibility month to month.
          </p>

          <div class="plan-list">
            <div>Member rates on eligible services</div>
            <div>Customize your monthly mix of walks and care</div>
            <div>Option to pay now or pay later</div>
            <div>Ideal for variable schedules</div>
          </div>
        </div>

        <div class="plan-box featured">
          <span class="plan-badge">Best Value</span>
          <h3>Upfront Membership</h3>
          <div class="plan-price">Lowest pricing</div>
          <p>
            Best for clients who already know they want recurring care and want the strongest savings.
          </p>

          <div class="plan-list">
            <div>Cheapest pricing tier available</div>
            <div>Best for recurring weekly care</div>
            <div>Built for consistent luxury service</div>
            <div>Ideal for serious long-term members</div>
          </div>
        </div>
      </div>
    </section>

    <section class="section-card">
      <h2>What you can include in your plan</h2>
      <p class="section-intro">
        Build a membership around your real routine instead of forcing your dog into a one-size-fits-all plan.
      </p>

      <div class="compare-grid">
        <div class="compare-box">
          <h3>Walks</h3>
          <p>
            Include any mix of 15, 20, 30, 45, or 60 minute walks depending on your dog’s needs.
          </p>
        </div>

        <div class="compare-box">
          <h3>Daycare</h3>
          <p>
            Add daycare days for social time, structure, and dependable daytime support.
          </p>
        </div>

        <div class="compare-box">
          <h3>Boarding & Drop-Ins</h3>
          <p>
            Add boarding nights by dog size and 30-minute drop-in visits for extra flexibility.
          </p>
        </div>
      </div>
    </section>

    <section class="section-card">
      <h2>Who membership is best for</h2>
      <p class="section-intro">
        Membership works especially well for clients who want dependable premium care, not just occasional help.
      </p>

      <div class="compare-grid">
        <div class="compare-box">
          <h3>Busy professionals</h3>
          <p>
            Perfect for clients who need reliable recurring support during the week.
          </p>
        </div>

        <div class="compare-box">
          <h3>Luxury-focused clients</h3>
          <p>
            Best for those who value communication, consistency, and a higher-end care experience.
          </p>
        </div>

        <div class="compare-box">
          <h3>Recurring care households</h3>
          <p>
            Ideal for dogs who benefit from structure, regular walks, repeat routines, and familiar handlers.
          </p>
        </div>
      </div>
    </section>

    <section class="cta-card">
      <h2>Ready to build your membership?</h2>
      <p>
        Start with a custom plan, choose upfront or pay-as-you-go, and create a dog care routine that feels premium from day one.
      </p>

      <div class="cta-actions">
        <a href="customize-plan.php" class="cta-button gold">Build Your Membership</a>
        <a href="services.php" class="cta-button light">View Services</a>
      </div>
    </section>

  </div>
</main>

<?php include 'includes/footer.php'; ?>