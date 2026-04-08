<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/membership-balance.php';

$membershipId = 1; // change if needed

$balance = dd_get_membership_balance($pdo, $membershipId);

echo "Balance: " . $balance;