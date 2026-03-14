<?php
require_once __DIR__ . '/includes/member_config.php';

unset($_SESSION['walker_id']);

header('Location: walker-login.php');
exit;