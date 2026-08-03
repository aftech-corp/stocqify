<?php
// ============================================================
// Application Configuration
// Override any constant below by creating app/config/config.local.php
// config.local.php is gitignored — safe for production credentials.
// ============================================================

// Load local/production overrides first (if present)
$__localConfig = __DIR__ . '/config.local.php';
if (file_exists($__localConfig)) {
    require_once $__localConfig;
}
unset($__localConfig);

// ── URLs (defaults: local XAMPP) ─────────────────────────────
if (!defined('APP_URL'))  define('APP_URL',  'http://localhost/SME/public');
if (!defined('SITE_URL')) define('SITE_URL', 'http://localhost/SME');

// ── Branding ─────────────────────────────────────────────────
define('APP_NAME',    'Stocqify');
define('APP_TAGLINE', 'Manage Smarter. Grow Faster.');
define('APP_LOGO',    APP_URL . '/assets/img/logo.png');
define('APP_VERSION', '1.0.0');

// ── Paths ─────────────────────────────────────────────────────
define('BASE_PATH',    dirname(__DIR__, 2));
define('APP_PATH',     dirname(__DIR__));
define('PUBLIC_PATH',  BASE_PATH . '/public');
define('MODULES_PATH', BASE_PATH . '/modules');

// ── Currency ─────────────────────────────────────────────────
define('CURRENCY',        'USD');
define('CURRENCY_SYMBOL', '$');

// ── Date / Time ───────────────────────────────────────────────
define('DATE_FORMAT',     'd/m/Y');
define('DATETIME_FORMAT', 'd/m/Y H:i');
if (!defined('APP_TIMEZONE')) define('APP_TIMEZONE', 'UTC');
date_default_timezone_set(APP_TIMEZONE);

// ── Session ───────────────────────────────────────────────────
define('SESSION_NAME',     'sme_session');
define('SESSION_LIFETIME', 7200); // 2 hours

// ── Pagination ────────────────────────────────────────────────
define('RECORDS_PER_PAGE', 25);

// ── Invoice ───────────────────────────────────────────────────
define('INVOICE_PREFIX', 'INV-');

// ── Inventory ────────────────────────────────────────────────
define('DEFAULT_REORDER_LEVEL', 5);
