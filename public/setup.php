<?php
/**
 * Stocqify - Database Setup & Initialization
 * Run this ONCE to create the database schema and seed data.
 * DELETE this file after setup for security.
 */

define('APP_NAME', 'Stocqify');
define('SETUP_KEY', 'salone_bizness_setup_2024'); // Change this to something unique

$configFile = __DIR__ . '/../app/config/config.php';
$schemaFile = __DIR__ . '/../database/schema.sql';
$seedFile   = __DIR__ . '/../database/seed.sql';

$step    = $_GET['step'] ?? '1';
$error   = '';
$success = '';

// ---- Step 2: Run setup ----
if ($step === '2' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $key    = $_POST['setup_key'] ?? '';
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbPort = (int)($_POST['db_port'] ?? 3306);
    $dbName = trim($_POST['db_name'] ?? 'sme_management');
    $dbUser = trim($_POST['db_user'] ?? 'root');
    $dbPass = $_POST['db_pass'] ?? '';

    if ($key !== SETUP_KEY) {
        $error = 'Invalid setup key. Access denied.';
    } else {
        try {
            // Connect to MySQL without selecting a DB first
            $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            // Create database if it doesn't exist, then select it
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbName}`");

            // Read and execute schema
            $schema = file_get_contents($schemaFile);
            // Split on semicolons, skip empty lines and pure comment blocks
            $statements = array_filter(array_map('trim', explode(';', $schema)));

            foreach ($statements as $sql) {
                if (!empty($sql) && !str_starts_with(ltrim($sql), '--')) {
                    $pdo->exec($sql);
                }
            }

            // Check if seed data already exists
            $existing = $pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();

            if ($existing == 0) {
                $seed = file_get_contents($seedFile);
                $seedStatements = array_filter(array_map('trim', explode(';', $seed)));
                foreach ($seedStatements as $sql) {
                    if (!empty($sql) && !str_starts_with(ltrim($sql), '--') && !str_starts_with(ltrim($sql), 'USE')) {
                        $pdo->exec($sql);
                    }
                }
                $seeded = true;
            } else {
                $seeded = false;
            }

            // Update database.php with provided credentials
            $dbConfigFile = __DIR__ . '/../app/config/database.php';
            $dbConfig = file_get_contents($dbConfigFile);
            $dbConfig = preg_replace("/define\('DB_HOST',\s*'[^']*'\)/", "define('DB_HOST', '{$dbHost}')", $dbConfig);
            $dbConfig = preg_replace("/define\('DB_NAME',\s*'[^']*'\)/", "define('DB_NAME', '{$dbName}')", $dbConfig);
            $dbConfig = preg_replace("/define\('DB_USER',\s*'[^']*'\)/", "define('DB_USER', '{$dbUser}')", $dbConfig);
            $dbConfig = preg_replace("/define\('DB_PASS',\s*'[^']*'\)/", "define('DB_PASS', '{$dbPass}')", $dbConfig);
            file_put_contents($dbConfigFile, $dbConfig);

            $step    = 'done';
            $success = 'Database initialized successfully!';
            $seededMsg = $seeded ? 'Demo data has been loaded.' : 'Database already had data — seed skipped.';

        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
            $step  = '2';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup | <?= htmlspecialchars(APP_NAME) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="bg-gradient-to-br from-slate-900 via-blue-950 to-slate-900 min-h-screen flex items-center justify-center p-4">
<div class="w-full max-w-lg">

    <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-16 h-16 bg-blue-600 rounded-2xl mb-4 shadow-lg">
            <i class="fa-solid fa-database text-white text-2xl"></i>
        </div>
        <h1 class="text-3xl font-bold text-white"><?= htmlspecialchars(APP_NAME) ?></h1>
        <p class="text-blue-300 text-sm mt-1">Database Setup Wizard</p>
    </div>

    <?php if ($error): ?>
    <div class="bg-red-100 border border-red-300 text-red-800 rounded-lg p-4 mb-4 text-sm">
        <i class="fa-solid fa-circle-exclamation mr-2"></i><?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if ($step === 'done'): ?>
    <!-- Success -->
    <div class="bg-white rounded-2xl shadow-2xl p-8 text-center">
        <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fa-solid fa-circle-check text-green-600 text-3xl"></i>
        </div>
        <h2 class="text-xl font-bold text-gray-800 mb-2">Setup Complete!</h2>
        <p class="text-gray-500 text-sm mb-2"><?= htmlspecialchars($success) ?></p>
        <?php if (isset($seededMsg)): ?>
        <p class="text-gray-400 text-xs mb-6"><?= htmlspecialchars($seededMsg) ?></p>
        <?php endif; ?>

        <div class="bg-blue-50 rounded-lg p-4 text-left text-sm mb-6">
            <p class="font-semibold text-blue-800 mb-2"><i class="fa-solid fa-key mr-1"></i>Demo Login Credentials</p>
            <p class="text-blue-700">Admin: <span class="font-mono">admin@sme.sl</span> / <span class="font-mono">password</span></p>
            <p class="text-blue-700">Owner: <span class="font-mono">owner@demo.sl</span> / <span class="font-mono">password</span></p>
            <p class="text-blue-700">Manager: <span class="font-mono">manager@demo.sl</span> / <span class="font-mono">password</span></p>
        </div>

        <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-800 mb-6">
            <i class="fa-solid fa-triangle-exclamation mr-1"></i>
            <strong>Security:</strong> Delete or rename <code>setup.php</code> from the <code>public/</code> folder now.
        </div>

        <a href="login" class="block w-full bg-blue-600 text-white py-3 rounded-lg font-semibold hover:bg-blue-700 transition-all">
            <i class="fa-solid fa-right-to-bracket mr-2"></i>Go to Login
        </a>
    </div>

    <?php elseif ($step === '2'): ?>
    <!-- Step 2: DB Config -->
    <div class="bg-white rounded-2xl shadow-2xl p-8">
        <h2 class="text-xl font-bold text-gray-800 mb-1">Database Configuration</h2>
        <p class="text-gray-500 text-sm mb-6">Enter your MySQL credentials below.</p>
        <form method="POST" action="?step=2">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Setup Key *</label>
                    <input type="password" name="setup_key" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                        placeholder="Enter the setup key">
                    <p class="text-xs text-gray-400 mt-1">Default key: <code class="bg-gray-100 px-1 rounded">salone_bizness_setup_2024</code></p>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">DB Host</label>
                        <input type="text" name="db_host" value="localhost"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Port</label>
                        <input type="number" name="db_port" value="3306"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Database Name</label>
                    <input type="text" name="db_name" value="sme_management"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                        <input type="text" name="db_user" value="root"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                        <input type="password" name="db_pass"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                            placeholder="Leave blank if none">
                    </div>
                </div>
            </div>
            <button type="submit" class="w-full mt-6 bg-blue-600 text-white py-2.5 rounded-lg font-semibold hover:bg-blue-700">
                <i class="fa-solid fa-database mr-2"></i>Initialize Database
            </button>
            <a href="setup" class="block text-center text-sm text-gray-400 mt-3 hover:text-gray-600">Back</a>
        </form>
    </div>

    <?php else: ?>
    <!-- Step 1: Welcome -->
    <div class="bg-white rounded-2xl shadow-2xl p-8">
        <h2 class="text-xl font-bold text-gray-800 mb-1">Welcome to the Setup Wizard</h2>
        <p class="text-gray-500 text-sm mb-6">This wizard will create your database and load demo data.</p>

        <div class="space-y-3 mb-6">
            <div class="flex items-start gap-3 p-3 bg-gray-50 rounded-lg">
                <i class="fa-solid fa-check-circle text-green-500 mt-0.5"></i>
                <div>
                    <p class="text-sm font-medium text-gray-700">Create Database</p>
                    <p class="text-xs text-gray-400">Creates <code>sme_management</code> with all required tables</p>
                </div>
            </div>
            <div class="flex items-start gap-3 p-3 bg-gray-50 rounded-lg">
                <i class="fa-solid fa-check-circle text-green-500 mt-0.5"></i>
                <div>
                    <p class="text-sm font-medium text-gray-700">Load Demo Data</p>
                    <p class="text-xs text-gray-400">Creates sample roles, users, products, and customers</p>
                </div>
            </div>
            <div class="flex items-start gap-3 p-3 bg-gray-50 rounded-lg">
                <i class="fa-solid fa-check-circle text-green-500 mt-0.5"></i>
                <div>
                    <p class="text-sm font-medium text-gray-700">Update Config</p>
                    <p class="text-xs text-gray-400">Saves your database credentials to the config file</p>
                </div>
            </div>
        </div>

        <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-800 mb-6">
            <i class="fa-solid fa-triangle-exclamation mr-1"></i>
            <strong>Requirements:</strong> PHP 8+, MySQL 5.7+, XAMPP or WAMP running. Make sure Apache and MySQL are started.
        </div>

        <a href="?step=2" class="block w-full text-center bg-blue-600 text-white py-2.5 rounded-lg font-semibold hover:bg-blue-700">
            <i class="fa-solid fa-arrow-right mr-2"></i>Begin Setup
        </a>
    </div>
    <?php endif; ?>

</div>
</body>
</html>
