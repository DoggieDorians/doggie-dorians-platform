<?php
declare(strict_types=1);

if (!isset($pageTitle) || trim((string)$pageTitle) === '') {
    $pageTitle = "Doggie Dorian's";
}

if (!isset($metaDescription) || trim((string)$metaDescription) === '') {
    $metaDescription = "Luxury dog care, memberships, walks, drop-ins, pet sitting, founder packages, and concierge-level pet services from Doggie Dorian's.";
}

if (!isset($siteName) || trim((string)$siteName) === '') {
    $siteName = "Doggie Dorian's";
}

if (!function_exists('dd_h')) {
    function dd_h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('dd_detect_scheme')) {
    function dd_detect_scheme(): string
    {
        $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));

        if ($https !== '' && $https !== 'off') {
            return 'https';
        }

        if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return 'https';
        }

        $forwardedProto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($forwardedProto === 'https') {
            return 'https';
        }

        return 'http';
    }
}

if (!function_exists('dd_absolute_url')) {
    function dd_absolute_url(string $baseUrl, string $path): string
    {
        if (preg_match('~^https?://~i', $path) === 1) {
            return $path;
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }
}

$resolvedHost = trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'dorianspetcare.com'));
if ($resolvedHost === '') {
    $resolvedHost = 'dorianspetcare.com';
}

if (!isset($siteBaseUrl) || trim((string)$siteBaseUrl) === '') {
    $siteBaseUrl = dd_detect_scheme() . '://' . $resolvedHost;
}

$currentRequestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
if ($currentRequestUri === '') {
    $currentRequestUri = '/';
}

if (!isset($canonicalUrl) || trim((string)$canonicalUrl) === '') {
    $canonicalUrl = dd_absolute_url((string)$siteBaseUrl, $currentRequestUri);
}

if (!isset($ogTitle) || trim((string)$ogTitle) === '') {
    $ogTitle = $pageTitle;
}

if (!isset($ogDescription) || trim((string)$ogDescription) === '') {
    $ogDescription = $metaDescription;
}

if (!isset($ogType) || trim((string)$ogType) === '') {
    $ogType = 'website';
}

if (!isset($ogImagePath) || trim((string)$ogImagePath) === '') {
    $ogImagePath = '/assets/images/doggie-dorians-share.jpeg';
}

if (!isset($ogImageType) || trim((string)$ogImageType) === '') {
    $ogImageType = 'image/jpeg';
}

if (!isset($ogImageWidth) || trim((string)$ogImageWidth) === '') {
    $ogImageWidth = '1200';
}

if (!isset($ogImageHeight) || trim((string)$ogImageHeight) === '') {
    $ogImageHeight = '630';
}

$ogImageUrl = dd_absolute_url((string)$siteBaseUrl, (string)$ogImagePath);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= dd_h($pageTitle) ?></title>
  <meta name="description" content="<?= dd_h($metaDescription) ?>">
  <meta name="theme-color" content="#07080b">
  <link rel="canonical" href="<?= dd_h((string)$canonicalUrl) ?>">

  <meta property="og:site_name" content="<?= dd_h((string)$siteName) ?>">
  <meta property="og:type" content="<?= dd_h((string)$ogType) ?>">
  <meta property="og:title" content="<?= dd_h((string)$ogTitle) ?>">
  <meta property="og:description" content="<?= dd_h((string)$ogDescription) ?>">
  <meta property="og:url" content="<?= dd_h((string)$canonicalUrl) ?>">
  <meta property="og:image" content="<?= dd_h((string)$ogImageUrl) ?>">
  <meta property="og:image:secure_url" content="<?= dd_h((string)$ogImageUrl) ?>">
  <meta property="og:image:type" content="<?= dd_h((string)$ogImageType) ?>">
  <meta property="og:image:width" content="<?= dd_h((string)$ogImageWidth) ?>">
  <meta property="og:image:height" content="<?= dd_h((string)$ogImageHeight) ?>">
  <meta property="og:image:alt" content="Doggie Dorian's">

  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= dd_h((string)$ogTitle) ?>">
  <meta name="twitter:description" content="<?= dd_h((string)$ogDescription) ?>">
  <meta name="twitter:image" content="<?= dd_h((string)$ogImageUrl) ?>">

  <style>
    :root{
      --dd-bg:#07080b;
      --dd-bg-soft:#0d1016;
      --dd-bg-deep:#050609;
      --dd-panel:rgba(255,255,255,0.05);
      --dd-panel-strong:rgba(255,255,255,0.08);
      --dd-line:rgba(255,255,255,0.10);
      --dd-line-soft:rgba(255,255,255,0.06);
      --dd-text:#f6f1e8;
      --dd-text-soft:#ddd2bf;
      --dd-muted:#c9c0af;
      --dd-gold:#d7b26a;
      --dd-gold-light:#f0d59f;
      --dd-gold-deep:#9f7a35;
      --dd-danger:#d67b7b;
      --dd-success:#92c89b;
      --dd-shadow:0 22px 65px rgba(0,0,0,0.38);
      --dd-radius:28px;
      --dd-radius-sm:18px;
      --dd-max:1320px;
    }

    *{
      box-sizing:border-box;
    }

    html{
      margin:0;
      padding:0;
      min-height:100%;
      scroll-behavior:smooth;
      background:
        radial-gradient(circle at top, rgba(215,178,106,0.10), transparent 25%),
        linear-gradient(180deg, #06070a 0%, #0b0d12 45%, #06070a 100%);
    }

    body{
      margin:0;
      padding:0;
      min-height:100vh;
      background:
        radial-gradient(circle at top, rgba(215,178,106,0.10), transparent 25%),
        linear-gradient(180deg, #06070a 0%, #0b0d12 45%, #06070a 100%) !important;
      background-attachment:fixed;
      color:var(--dd-text);
      font-family:Georgia, "Times New Roman", serif;
      line-height:1.55;
      -webkit-font-smoothing:antialiased;
      -moz-osx-font-smoothing:grayscale;
    }

    html,
    body,
    #app,
    .site,
    .site-wrap,
    .site-shell,
    .page-wrap,
    .page-shell,
    .content-wrap,
    .content-shell,
    .main-wrap,
    .main-shell{
      background-color:transparent !important;
    }

    a{
      color:var(--dd-gold-light);
      text-decoration:none;
      transition:all .18s ease;
    }

    a:hover{
      color:#fff;
    }

    img{
      max-width:100%;
      height:auto;
      display:block;
    }

    button,
    input,
    select,
    textarea{
      font:inherit;
    }

    input,
    select,
    textarea{
      width:100%;
      background:rgba(0,0,0,0.26);
      color:#fff;
      border:1px solid rgba(255,255,255,0.10);
      border-radius:16px;
      padding:14px 16px;
      outline:none;
      transition:border-color .18s ease, box-shadow .18s ease, background .18s ease;
    }

    input::placeholder,
    textarea::placeholder{
      color:rgba(255,255,255,0.42);
    }

    input:focus,
    select:focus,
    textarea:focus{
      border-color:rgba(215,178,106,0.55);
      box-shadow:0 0 0 4px rgba(215,178,106,0.10);
      background:rgba(0,0,0,0.34);
    }

    button{
      cursor:pointer;
    }

    h1,h2,h3,h4,h5,h6{
      margin-top:0;
      color:#fff;
      line-height:1.15;
    }

    p{
      color:var(--dd-muted);
    }

    hr{
      border:none;
      border-top:1px solid rgba(255,255,255,0.08);
      margin:24px 0;
    }

    .container,
    .site-container,
    .page-container,
    .content-container{
      width:min(var(--dd-max), calc(100% - 32px));
      margin:0 auto;
    }

    .dd-card{
      background:linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.03));
      border:1px solid var(--dd-line);
      border-radius:var(--dd-radius);
      box-shadow:var(--dd-shadow);
    }

    .dd-card-sm{
      border-radius:var(--dd-radius-sm);
    }

    .dd-btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:46px;
      padding:0 18px;
      border-radius:999px;
      border:1px solid rgba(255,255,255,0.10);
      background:rgba(255,255,255,0.05);
      color:#fff;
      font-weight:700;
      text-decoration:none;
      transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease, background .18s ease;
    }

    .dd-btn:hover{
      transform:translateY(-1px);
      color:#fff;
      border-color:rgba(255,255,255,0.16);
      background:rgba(255,255,255,0.08);
    }

    .dd-btn-gold{
      background:linear-gradient(135deg, var(--dd-gold), var(--dd-gold-light));
      color:#171105;
      border:1px solid rgba(255,255,255,0.12);
      box-shadow:0 16px 38px rgba(215,181,109,.20);
    }

    .dd-btn-gold:hover{
      color:#171105;
      transform:translateY(-1px);
      box-shadow:0 20px 44px rgba(215,181,109,.28);
    }

    .dd-alert{
      border-radius:18px;
      padding:15px 18px;
      border:1px solid rgba(255,255,255,0.10);
      background:rgba(255,255,255,0.04);
      color:#fff;
    }

    .dd-alert-error{
      background:rgba(214,123,123,0.14);
      border-color:rgba(214,123,123,0.30);
      color:#ffd5d5;
    }

    .dd-alert-success{
      background:rgba(146,200,155,0.14);
      border-color:rgba(146,200,155,0.28);
      color:#dcf6e0;
    }

    .dd-alert-info{
      background:rgba(198,178,139,0.12);
      border-color:rgba(198,178,139,0.25);
      color:#f3e5c7;
    }

    /*
      Hard override against legacy white wrappers / headers / nav bars
    */
    header,
    .site-header,
    .main-header,
    .top-header,
    .top-bar,
    .navbar,
    .navbar-wrap,
    .header-wrap,
    .header-shell,
    .page-header{
      background:transparent !important;
      background-color:transparent !important;
      box-shadow:none !important;
      border:none !important;
    }

    /*
      Do NOT force transparent backgrounds on everything inside header,
      because buttons/nav pills may need their own backgrounds.
      Only kill common white containers.
    */
    .site-header .container,
    .main-header .container,
    .top-header .container,
    .navbar .container,
    .header-wrap .container,
    .header-shell .container,
    .page-header .container{
      background:transparent !important;
    }

    /*
      Common legacy white sections
    */
    .bg-white,
    .background-white,
    .white-bg,
    .page-hero,
    .hero,
    .hero-section,
    .top-section{
      background-color:transparent !important;
    }

    /*
      Tables
    */
    table{
      width:100%;
      border-collapse:collapse;
      color:var(--dd-text);
    }

    th,
    td{
      padding:12px 14px;
      border-bottom:1px solid rgba(255,255,255,0.08);
      text-align:left;
    }

    th{
      color:#fff;
      font-weight:700;
    }

    /*
      Selection
    */
    ::selection{
      background:rgba(215,178,106,0.30);
      color:#fff;
    }

    @media (max-width: 760px){
      .container,
      .site-container,
      .page-container,
      .content-container{
        width:min(var(--dd-max), calc(100% - 24px));
      }
    }
  </style>
</head>
<body></body>