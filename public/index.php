<?php
require_once __DIR__ . '/../app/includes/auth.php';
if (isLoggedIn()) {
    header('Location: ' . SITE_URL . '/dashboard');
} else {
    header('Location: ' . SITE_URL . '/login');
}
exit;
