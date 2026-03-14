<?php
require_once __DIR__ . '/includes/member_config.php';

session_unset();
session_destroy();

header('Location: login.php');
exit;