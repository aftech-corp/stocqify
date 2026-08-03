<?php
// ============================================================
// Database Configuration — SAMPLE FILE
// Copy this file to database.php and fill in your credentials.
// database.php is gitignored — never commit real credentials.
//
// HOSTINGER: DB_HOST is usually 'localhost'
//            DB_NAME / DB_USER look like: u123456789_yourdb
// ============================================================

define('DB_HOST',    'localhost');        // Hostinger: 'localhost'
define('DB_NAME',    'u_your_db_name');  // e.g. u123456789_stocqify
define('DB_USER',    'u_your_db_user');  // e.g. u123456789_stocqify
define('DB_PASS',    'your_password');   // your database password
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            die('<div style="font-family:sans-serif;padding:40px;text-align:center;color:#dc2626;">
                <h2>Database Connection Error</h2>
                <p>Could not connect to the database. Please check your XAMPP/WAMP MySQL service is running and database credentials in <code>app/config/database.php</code>.</p>
            </div>');
        }
    }
    return $pdo;
}
