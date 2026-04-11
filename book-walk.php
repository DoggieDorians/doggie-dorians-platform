<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
/*
|--------------------------------------------------------------------------
| Doggie Dorian's
| book-walk.php
|--------------------------------------------------------------------------
| Legacy booking entry point.
|
| This page now exists only to preserve older links, buttons, bookmarks,
| SEO references, and any external traffic that still points to book-walk.php.
|
| All booking now runs through:
| - book-service.php for logged-in members
| - non-member-booking.php for public / non-member clients
|--------------------------------------------------------------------------
*/
$isLoggedIn = isset($_SESSION['member_id']) && (int) $_SESSION['member_id'] > 0;

$query = $_GET;
$query['service'] = 'walk';

$destination = $isLoggedIn ? 'book-service.php' : 'non-member-booking.php';

$redirectUrl = $destination;
if (!empty($query)) {
    $redirectUrl .= '?' . http_build_query($query);
}

header('Location: ' . $redirectUrl, true, 302);
exit;