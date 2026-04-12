<?php
$currentPage = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');

$session = (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION) && is_array($_SESSION))
    ? $_SESSION
    : [];

$isLoggedIn = !empty($session['member_id']) || !empty($session['user_id']) || !empty($session['user']);

function dd_nav_active(array $pages, string $currentPage): string
{
    return in_array($currentPage, $pages, true) ? ' active' : '';
}
?>
<style>
  .site-nav,
  .site-nav * {
    box-sizing: border-box;
  }

  .site-nav {
    position: sticky;
    top: 0;
    z-index: 1000;
    width: 100%;
    background: rgba(7, 8, 11, 0.86);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
  }

  .nav-container {
    max-width: 1320px;
    margin: 0 auto;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
  }

  .logo {
    min-width: 0;
  }

  .logo a {
    color: #f6f1e8;
    text-decoration: none;
    font-family: Georgia, "Times New Roman", serif;
    font-size: 1.35rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    white-space: nowrap;
  }

  .nav-links {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    align-items: center;
    gap: 22px;
  }

  .nav-links li {
    list-style: none;
    margin: 0;
    padding: 0;
  }

  .nav-links a {
    color: rgba(246, 241, 232, 0.82);
    text-decoration: none;
    font-family: Georgia, "Times New Roman", serif;
    font-size: 0.98rem;
    font-weight: 600;
    transition: color 0.18s ease, opacity 0.18s ease;
  }

  .nav-links a:hover,
  .nav-links a.active {
    color: #d7b26a;
  }

  .nav-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
  }

  .join-btn,
  .login-btn,
  .account-btn,
  .nav-toggle {
    appearance: none;
    border: 0;
    text-decoration: none;
    font-family: Georgia, "Times New Roman", serif;
    font-weight: 700;
    border-radius: 999px;
    min-height: 46px;
    padding: 0 18px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.18s ease, opacity 0.18s ease, background 0.18s ease, border-color 0.18s ease;
  }

  .join-btn:hover,
  .login-btn:hover,
  .account-btn:hover,
  .nav-toggle:hover {
    transform: translateY(-1px);
  }

  .join-btn {
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.10);
    color: #f6f1e8;
  }

  .login-btn,
  .account-btn {
    background: linear-gradient(135deg, #d7b26a, #f0d59f);
    color: #171105;
    box-shadow: 0 14px 34px rgba(215, 178, 106, 0.22);
  }

  .nav-toggle {
    display: none;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.10);
    color: #f6f1e8;
    width: 46px;
    min-width: 46px;
    padding: 0;
    cursor: pointer;
    font-size: 1.2rem;
    line-height: 1;
  }

  .nav-toggle-icon {
    display: inline-block;
    transform: translateY(-1px);
  }

  @media (max-width: 900px) {
    .nav-container {
      flex-wrap: wrap;
      gap: 14px;
    }

    .nav-toggle {
      display: inline-flex;
      margin-left: auto;
    }

    .nav-links,
    .nav-actions {
      width: 100%;
      display: none;
    }

    .site-nav.nav-open .nav-links,
    .site-nav.nav-open .nav-actions {
      display: flex;
    }

    .nav-links {
      flex-direction: column;
      align-items: flex-start;
      gap: 14px;
      padding: 8px 2px 0;
    }

    .nav-actions {
      flex-direction: column;
      align-items: stretch;
      padding-top: 4px;
    }

    .join-btn,
    .login-btn,
    .account-btn {
      width: 100%;
    }
  }
</style>

<nav class="site-nav" id="siteNav">
  <div class="nav-container">

    <div class="logo">
      <a href="index.php">Doggie Dorian's</a>
    </div>

    <button class="nav-toggle" type="button" id="navToggle" aria-label="Toggle navigation" aria-expanded="false" aria-controls="siteNavLinks">
      <span class="nav-toggle-icon">☰</span>
    </button>

    <ul class="nav-links" id="siteNavLinks">
      <li><a href="index.php" class="<?= dd_nav_active(['index.php'], $currentPage) ?>">Home</a></li>
      <li><a href="services.php" class="<?= dd_nav_active(['services.php', 'pricing.php'], $currentPage) ?>">Services</a></li>
      <li><a href="memberships.php" class="<?= dd_nav_active(['memberships.php', 'signup.php', 'login.php', 'tos.php'], $currentPage) ?>">Membership</a></li>
      <li><a href="contact.php" class="<?= dd_nav_active(['contact.php'], $currentPage) ?>">Contact</a></li>
    </ul>

    <div class="nav-actions">
      <?php if ($isLoggedIn): ?>
        <a href="dashboard.php" class="join-btn">Dashboard</a>
        <a href="book-service.php" class="account-btn">Book Services</a>
      <?php else: ?>
        <a href="signup.php" class="join-btn">Join</a>
        <a href="login.php" class="login-btn">Member Login</a>
      <?php endif; ?>
    </div>

  </div>
</nav>

<script>
  (function () {
    var nav = document.getElementById('siteNav');
    var toggle = document.getElementById('navToggle');

    if (!nav || !toggle) {
      return;
    }

    toggle.addEventListener('click', function () {
      var isOpen = nav.classList.toggle('nav-open');
      toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
  })();
</script>