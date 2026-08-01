<?php
require_once __DIR__ . '/../app/includes/auth.php';
logout();
header('Location: ' . SITE_URL . '/login?msg=logged_out');
exit;
