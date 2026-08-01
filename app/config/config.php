<?php
// ============================================================
// Application Configuration
// ============================================================

define('APP_NAME', 'Salone Bizness');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost/SME/public');
define('SITE_URL', 'http://localhost/SME');
define('BASE_PATH', dirname(__DIR__, 2));
define('APP_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
define('MODULES_PATH', BASE_PATH . '/modules');
define('CURRENCY', 'NLE');
define('CURRENCY_SYMBOL', 'NLE');
define('DATE_FORMAT', 'd/m/Y');
define('DATETIME_FORMAT', 'd/m/Y H:i');

// Session settings
define('SESSION_NAME', 'sme_session');
define('SESSION_LIFETIME', 7200); // 2 hours

// Pagination
define('RECORDS_PER_PAGE', 25);

// Invoice prefix
define('INVOICE_PREFIX', 'INV-');

// Low stock threshold (fallback)
define('DEFAULT_REORDER_LEVEL', 5);

// Timezone - Freetown, Sierra Leone (GMT)
date_default_timezone_set('Africa/Freetown');
