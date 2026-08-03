<?php
/**
 * TEMPORARY DIAGNOSTIC FILE — DELETE AFTER USE
 * Visit: https://stocqify.com/public/diag.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__);

echo '<pre style="font-family:monospace;font-size:13px;padding:20px">';
echo "=== Stocqify Server Diagnostic ===\n\n";

// PHP version
echo "PHP Version     : " . PHP_VERSION . "\n";
echo "PHP 8.0+ req    : " . (version_compare(PHP_VERSION, '8.0.0', '>=') ? "OK" : "FAIL — must be 8.0 or higher") . "\n";
echo "str_starts_with : " . (function_exists('str_starts_with') ? "available" : "MISSING — PHP 8.0+ required") . "\n\n";

// File existence checks
$files = [
    'app/config/config.php',
    'app/config/config.local.php',
    'app/config/database.php',
    'database/schema.sql',
    'public/index.php',
];
echo "=== File Checks ===\n";
foreach ($files as $f) {
    $path = $root . '/' . $f;
    echo str_pad($f, 35) . (file_exists($path) ? "EXISTS" : "MISSING") . "\n";
}
echo "\n";

// Try loading config
echo "=== Config Load ===\n";
try {
    require_once $root . '/app/config/config.php';
    echo "config.php      : OK\n";
    echo "SITE_URL        : " . (defined('SITE_URL') ? SITE_URL : 'NOT SET') . "\n";
    echo "APP_URL         : " . (defined('APP_URL')  ? APP_URL  : 'NOT SET') . "\n";
} catch (\Throwable $e) {
    echo "config.php ERR  : " . $e->getMessage() . "\n";
}
echo "\n";

// Try loading database
echo "=== Database Load ===\n";
if (!file_exists($root . '/app/config/database.php')) {
    echo "database.php    : MISSING — create it from database.sample.php\n";
} else {
    try {
        if (!function_exists('getDB')) {
            require_once $root . '/app/config/database.php';
        }
        echo "database.php    : loaded OK\n";
        $pdo = getDB();
        $v = $pdo->query('SELECT VERSION()')->fetchColumn();
        echo "MySQL connected : OK (version $v)\n";
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        echo "Tables found    : " . count($tables) . " (" . implode(', ', array_slice($tables, 0, 5)) . (count($tables) > 5 ? '...' : '') . ")\n";
    } catch (\Throwable $e) {
        echo "database.php ERR: " . $e->getMessage() . "\n";
    }
}

echo "\n=== DELETE THIS FILE AFTER REVIEW ===\n";
echo '</pre>';
