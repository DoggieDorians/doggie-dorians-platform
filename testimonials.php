<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = "Testimonials | Doggie Dorian's";
$metaDescription = "Read trusted client testimonials and pet parent reference letters for Doggie Dorian's premium dog walking and care services in Manhattan.";

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$testimonials = [
    [
        'id' => 'lucy',
        'name' => 'Jennifer Shatzky',
        'pet' => 'Lucy',
        'title' => "Lucy's Mom",
        'format' => 'image',
        'media' => 'assets/images/lucy.jpg',
        'alt' => 'Lucy enjoying a walk with Doggie Dorian’s',
        'highlight' => '“Dorian is the only dog walker we trust completely.”',
        'summary' => 'Reliability, emergency availability, intuitive canine understanding, and warm, professional communication.',
        'paragraphs' => [
            'I am writing to provide my highest recommendation for Dorian Xerri as a dog walker. After experiencing his services firsthand with our beloved Lucy, I can confidently say that Dorian is the only dog walker we trust completely.',
            'Dorian’s reliability is unmatched. He consistently shows up on time and follows through on every commitment he makes. Beyond his dependability for scheduled walks, Dorian has proven himself invaluable during emergencies, always making himself available when we need him most. This level of dedication and flexibility provides tremendous peace of mind.',
            'What truly sets Dorian apart is his natural ability with dogs. He doesn’t just walk them — he connects with them. Lucy took to him immediately, and their bond has only strengthened over time. Dorian has an intuitive understanding of canine behavior and needs that can only be described as that of a “dog whisperer.” He reads their signals, responds to their moods, and genuinely becomes their friend.',
            'His friendly demeanor extends to both pets and their owners. Communication is always clear, professional, and warm. We feel completely confident leaving Lucy in his care, which is not something we say lightly.',
            'Without reservation, I give Dorian Xerri my strongest endorsement. Any dog would be fortunate to have him as their walker, and any owner would be lucky to have such a trustworthy professional caring for their pet.',
        ],
    ],
    [
        'id' => 'apple',
        'name' => 'Linda',
        'pet' => 'Apple',
        'title' => "Apple's Mom",
        'format' => 'image',
        'media' => 'assets/images/apple.jpg',
        'alt' => 'Apple photographed for Doggie Dorian’s testimonial page',
        'highlight' => '“I can not recommend him highly enough. I totally trust him in every way.”',
        'summary' => 'Patience, last-minute reliability, confidence with more difficult dogs, and extra care with medications.',
        'paragraphs' => [
            'I have lived in this building for over 30 years and I can honestly say Dorian is one of the best doormen we have ever had. He has the patience of a saint, listens to people, is always helpful, and goes the extra mile — especially with dogs and children.',
            'I second everything Emily said. He is wonderful with pets.',
            'What Emily didn’t say is her dog is very sweet and docile. I, on the other hand, have one that is not. While she is cute, she has the devil in her, but with Dorian she literally turns into an angel.',
            'She had an ear infection a few months ago and would not let me put drops in her ears. I asked Dorian to do it and no problem. Also, with her monthly pills, same thing.',
            'Once in a while, I will call him at the last minute to walk her. Even if he has finished his walks for the day, he makes every effort to come back.',
            'I can not recommend him highly enough. I totally trust him in every way.',
        ],
    ],
    [
        'id' => 'lola',
        'name' => 'Eileen Goldenberg',
        'pet' => 'Lola',
        'title' => "Lola's Mom",
        'format' => 'video',
        'media' => 'assets/videos/lola1.mp4',
        'alt' => 'Lola video testimonial media',
        'highlight' => '“Any pet in his care is truly in good hands.”',
        'summary' => 'Attentive winter care, Manhattan walk safety, calm judgment, and true sensitivity to a small dog’s comfort.',
        'paragraphs' => [
            'I am very happy to write this letter of reference for Dorian, my dog walker, who has taken exceptional care of my King Charles Cavalier while walking her throughout Manhattan, including Central Park and busy city sidewalks.',
            'My dog Lola mostly lives in Florida and is only a part-time New Yorker. She is also very small for her breed and especially vulnerable to cold weather, and during the recent freezing temperatures — often as low as 5°F — Dorian has been consistently attentive, thoughtful, and cautious.',
            'He is extremely mindful of her comfort and safety, adjusting walks as needed, watching her closely for signs of discomfort, and always prioritizing her well-being over distance or speed.',
            'Walking a small dog in New York City during winter requires patience, awareness, and genuine care, and Dorian brings all of that and more. He is reliable, calm, and clearly loves animals. I trust him completely with my dog, even in challenging weather and high-traffic environments.',
            'I would not hesitate to recommend Dorian to anyone looking for a responsible, compassionate, and dependable dog walker. Any pet in his care is truly in good hands.',
        ],
    ],
];
?>

<style>
  .testimonials-shell,
  .testimonials-shell * {
    box-sizing: border-box;
  }

  .testimonials-shell {
    width: min(1320px, calc(100% - 32px));
    margin: 0 auto;
    padding: 34px 0 84px;
  }

  .testimonials-hero {
    display: grid;
    grid-template-columns: 1.08fr 0.92fr;
    gap: 22px;
    margin-bottom: 24px;
  }

  .testimonials-card {
    background: linear-gradient(180deg, rgba(255,255,255,0.055), rgba(255,255,255,0.03));
    border: 1px solid rgba(255,255,255,0.10);
    border-radius: 28px;
    box-shadow: 0 24px 70px rgba(0,0,0,0.34);
  }

  .testimonials-card.hero-primary {
    padding: 34px 30px;
    background:
      radial-gradient(circle at top right, rgba(215,178,106,0.12), transparent 28%),
      linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.03));
  }

  .testimonials-card.hero-secondary {
    padding: 28px;
    display: flex;
    flex-direction: column;
    gap: 16px;
  }

  .testimonials-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 14px;
    color: #d7b26a;
    text-transform: uppercase;
    letter-spacing: 0.16em;
    font-size: 0.78rem;
    font-weight: 800;
  }

  .testimonials-hero h1 {
    margin: 0 0 14px;
    color: #fff;
    font-size: clamp(2.2rem, 4vw, 3.35rem);
    line-height: 1.02;
  }

  .testimonials-hero p {
    margin: 0;
    color: #d6cebf;
    line-height: 1.75;
    font-size: 1rem;
  }

  .hero-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 24px;
  }

  .hero-actions a {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 48px;
    padding: 0 20px;
    border-radius: 999px;
    font-weight: 700;
    text-decoration: none;
    transition: transform 0.18s ease, box-shadow 0.18s ease, opacity 0.18s ease;
  }

  .hero-actions a:hover {
    transform: translateY(-1px);
  }

  .hero-btn-gold {
    background: linear-gradient(135deg, #d7b26a, #f0d59f);
    color: #171105;
    box-shadow: 0 16px 38px rgba(215,181,109,0.20);
  }

  .hero-btn-soft {
    background: rgba(255,255,255,0.05);
    color: #f6f1e8;
    border: 1px solid rgba(255,255,255,0.12);
  }

  .trust-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
    margin-top: 24px;
  }

  .trust-pill {
    padding: 15px 16px;
    border-radius: 18px;
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08);
  }

  .trust-label {
    color: rgba(255,255,255,0.50);
    text-transform: uppercase;
    letter-spacing: 0.12em;
    font-size: 0.72rem;
    font-weight: 800;
    margin-bottom: 8px;
  }

  .trust-value {
    color: #fff;
    font-size: 1.05rem;
    font-weight: 800;
    line-height: 1.3;
  }

  .featured-quote {
    padding: 20px 22px;
    border-radius: 22px;
    background: linear-gradient(135deg, rgba(215,178,106,0.12), rgba(255,255,255,0.03));
    border: 1px solid rgba(215,178,106,0.20);
  }

  .featured-quote blockquote {
    margin: 0 0 10px;
    color: #fff4dc;
    font-size: 1.1rem;
    line-height: 1.6;
    font-weight: 700;
  }

  .featured-quote cite {
    color: #d1c5b1;
    font-style: normal;
    font-size: 0.95rem;
  }

  .testimonials-intro-note {
    padding: 18px 20px;
    border-radius: 20px;
    background: rgba(255,255,255,0.035);
    border: 1px solid rgba(255,255,255,0.08);
    color: #d6cebf;
    line-height: 1.75;
  }

  .section-heading {
    margin: 0 0 18px;
    color: #fff;
    font-size: 1.55rem;
    line-height: 1.1;
  }

  .testimonial-stories {
    display: grid;
    gap: 24px;
    margin-top: 8px;
  }

  .story-card {
    display: grid;
    grid-template-columns: 0.96fr 1.04fr;
    gap: 0;
    overflow: hidden;
  }

  .story-card.reverse {
    grid-template-columns: 1.04fr 0.96fr;
  }

  .story-media,
  .story-content {
    min-width: 0;
  }

  .story-media {
    position: relative;
    background: #050608;
    min-height: 100%;
  }

  .story-media img,
  .story-media video {
    width: 100%;
    height: 100%;
    min-height: 100%;
    display: block;
    object-fit: cover;
  }

  .story-content {
    padding: 28px 28px 30px;
  }

  .story-tag {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 14px;
    padding: 8px 12px;
    border-radius: 999px;
    background: rgba(215,178,106,0.12);
    border: 1px solid rgba(215,178,106,0.18);
    color: #ecd6a8;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    font-size: 0.72rem;
    font-weight: 800;
  }

  .story-content h2 {
    margin: 0 0 10px;
    color: #fff;
    font-size: 1.6rem;
    line-height: 1.08;
  }

  .story-subline {
    margin: 0 0 16px;
    color: #e7dcc8;
    font-size: 0.98rem;
    font-weight: 700;
    line-height: 1.6;
  }

  .story-highlight {
    margin: 0 0 16px;
    padding: 16px 18px;
    border-radius: 18px;
    background: rgba(255,255,255,0.035);
    border: 1px solid rgba(255,255,255,0.08);
    color: #fff4dc;
    font-size: 1rem;
    line-height: 1.7;
    font-weight: 700;
  }

  .story-letter {
    display: grid;
    gap: 14px;
  }

  .story-letter p {
    margin: 0;
    color: #d6cebf;
    line-height: 1.8;
    font-size: 0.98rem;
  }

  .story-signoff {
    margin-top: 4px;
    padding-top: 14px;
    border-top: 1px solid rgba(255,255,255,0.08);
  }

  .story-signoff strong {
    color: #fff;
    display: block;
    font-size: 1rem;
    margin-bottom: 4px;
  }

  .story-signoff span {
    color: #c7baa3;
    font-size: 0.94rem;
  }

  .testimonial-bottom {
    margin-top: 28px;
    padding: 28px 26px;
    text-align: center;
  }

  .testimonial-bottom h2 {
    margin: 0 0 10px;
    color: #fff;
    font-size: 1.8rem;
  }

  .testimonial-bottom p {
    max-width: 760px;
    margin: 0 auto;
    color: #d6cebf;
    line-height: 1.75;
  }

  .testimonial-bottom .hero-actions {
    justify-content: center;
    margin-top: 20px;
  }

  @media (max-width: 1080px) {
    .testimonials-hero,
    .story-card,
    .story-card.reverse {
      grid-template-columns: 1fr;
    }

    .story-media {
      min-height: 360px;
    }
  }

  @media (max-width: 760px) {
    .testimonials-shell {
      width: min(100%, calc(100% - 24px));
      padding: 22px 0 64px;
    }

    .testimonials-card.hero-primary,
    .testimonials-card.hero-secondary,
    .story-content,
    .testimonial-bottom {
      padding: 22px 20px;
    }

    .trust-grid {
      grid-template-columns: 1fr;
    }

    .hero-actions {
      flex-direction: column;
    }

    .hero-actions a {
      width: 100%;
    }

    .story-media {
      min-height: 260px;
    }
  }
</style>

<div class="testimonials-shell">
  <section class="testimonials-hero">
    <div class="testimonials-card hero-primary">
      <div class="testimonials-eyebrow">Client Testimonials</div>
      <h1>Trusted by pet parents who expect exceptional care.</h1>
      <p>
        Doggie Dorian’s was built on trust, consistency, and a deeply personal standard of care.
        These reference letters reflect what matters most to us: reliability, calm handling, thoughtful communication,
        and dogs who feel genuinely safe and cared for.
      </p>

      <div class="hero-actions">
        <a href="book-service.php" class="hero-btn-gold">Book Service</a>
        <a href="memberships.php" class="hero-btn-soft">Explore Memberships</a>
      </div>

      <div class="trust-grid">
        <div class="trust-pill">
          <div class="trust-label">Pet Parent References</div>
          <div class="trust-value">Three detailed client letters</div>
        </div>
        <div class="trust-pill">
          <div class="trust-label">Care Standards</div>
          <div class="trust-value">Reliable, responsive, and highly personalized</div>
        </div>
        <div class="trust-pill">
          <div class="trust-label">Service Style</div>
          <div class="trust-value">Luxury dog care with real trust behind it</div>
        </div>
      </div>
    </div>

    <div class="testimonials-card hero-secondary">
      <div class="featured-quote">
        <blockquote>
          “Without reservation, I give Dorian Xerri my strongest endorsement. Any dog would be fortunate to have him as their walker.”
        </blockquote>
        <cite>— Jennifer Shatzky, Lucy’s Mom</cite>
      </div>

      <div class="featured-quote">
        <blockquote>
          “I can not recommend him highly enough. I totally trust him in every way.”
        </blockquote>
        <cite>— Linda, Apple’s Mom</cite>
      </div>

      <div class="featured-quote">
        <blockquote>
          “Walking a small dog in New York City during winter requires patience, awareness, and genuine care, and Dorian brings all of that and more.”
        </blockquote>
        <cite>— Eileen Goldenberg, Lola’s Mom</cite>
      </div>

      <div class="testimonials-intro-note">
        These testimonials are presented as client reference highlights for Doggie Dorian’s and reflect firsthand experiences from pet parents whose dogs have been personally cared for by Dorian.
      </div>
    </div>
  </section>

  <h2 class="section-heading">Reference letters from pet parents</h2>

  <section class="testimonial-stories">
    <?php foreach ($testimonials as $index => $testimonial): ?>
      <article class="testimonials-card story-card<?php echo ($index % 2 === 1) ? ' reverse' : ''; ?>" id="<?php echo h($testimonial['id']); ?>">
        <?php if ($index % 2 === 1): ?>
          <div class="story-content">
            <div class="story-tag"><?php echo h($testimonial['title']); ?></div>
            <h2><?php echo h($testimonial['pet']); ?></h2>
            <p class="story-subline"><?php echo h($testimonial['summary']); ?></p>
            <div class="story-highlight"><?php echo h($testimonial['highlight']); ?></div>

            <div class="story-letter">
              <?php foreach ($testimonial['paragraphs'] as $paragraph): ?>
                <p><?php echo h($paragraph); ?></p>
              <?php endforeach; ?>
            </div>

            <div class="story-signoff">
              <strong><?php echo h($testimonial['name']); ?></strong>
              <span><?php echo h($testimonial['title']); ?></span>
            </div>
          </div>

          <div class="story-media">
            <?php if ($testimonial['format'] === 'video'): ?>
              <video controls playsinline preload="metadata" aria-label="<?php echo h($testimonial['alt']); ?>">
                <source src="<?php echo h($testimonial['media']); ?>" type="video/mp4">
                Your browser does not support the video tag.
              </video>
            <?php else: ?>
              <img src="<?php echo h($testimonial['media']); ?>" alt="<?php echo h($testimonial['alt']); ?>" loading="lazy">
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="story-media">
            <?php if ($testimonial['format'] === 'video'): ?>
              <video controls playsinline preload="metadata" aria-label="<?php echo h($testimonial['alt']); ?>">
                <source src="<?php echo h($testimonial['media']); ?>" type="video/mp4">
                Your browser does not support the video tag.
              </video>
            <?php else: ?>
              <img src="<?php echo h($testimonial['media']); ?>" alt="<?php echo h($testimonial['alt']); ?>" loading="lazy">
            <?php endif; ?>
          </div>

          <div class="story-content">
            <div class="story-tag"><?php echo h($testimonial['title']); ?></div>
            <h2><?php echo h($testimonial['pet']); ?></h2>
            <p class="story-subline"><?php echo h($testimonial['summary']); ?></p>
            <div class="story-highlight"><?php echo h($testimonial['highlight']); ?></div>

            <div class="story-letter">
              <?php foreach ($testimonial['paragraphs'] as $paragraph): ?>
                <p><?php echo h($paragraph); ?></p>
              <?php endforeach; ?>
            </div>

            <div class="story-signoff">
              <strong><?php echo h($testimonial['name']); ?></strong>
              <span><?php echo h($testimonial['title']); ?></span>
            </div>
          </div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="testimonials-card testimonial-bottom">
    <div class="testimonials-eyebrow">Work With Doggie Dorian’s</div>
    <h2>Premium care backed by real trust.</h2>
    <p>
      From dependable daily walks to thoughtful handling, comfort-first routines, and personalized attention,
      Doggie Dorian’s is designed for pet parents who want more than a basic service.
    </p>

    <div class="hero-actions">
      <a href="book-service.php" class="hero-btn-gold">Book Service</a>
      <a href="contact.php" class="hero-btn-soft">Contact</a>
    </div>
  </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>