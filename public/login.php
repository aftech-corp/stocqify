<?php
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/functions.php';

// Already logged in
if (isLoggedIn()) {
    redirect(url('dashboard'));
}

$error = '';

if (isPost()) {
    $email    = post('email');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please enter your email and password.';
    } else {
        $result = login($email, $password);
        if ($result['success']) {
            $redirect = $_GET['redirect'] ?? url('dashboard');
            redirect($redirect);
        } else {
            $error = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | <?= h(APP_NAME) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="bg-gradient-to-br from-slate-900 via-blue-950 to-slate-900 min-h-screen flex items-center justify-center p-4">

<div class="w-full max-w-md">
    <!-- Logo Card -->
    <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-16 h-16 bg-blue-600 rounded-2xl mb-4 shadow-lg">
            <i class="fa-solid fa-chart-line text-white text-2xl"></i>
        </div>
        <h1 class="text-3xl font-bold text-white"><?= h(APP_NAME) ?></h1>
        <p class="text-blue-300 text-sm mt-1">Smart Business Management for Freetown SMEs</p>
    </div>

    <!-- Login Form -->
    <div class="bg-white rounded-2xl shadow-2xl p-8">
        <h2 class="text-xl font-bold text-gray-800 mb-1">Welcome back</h2>
        <p class="text-gray-500 text-sm mb-6">Sign in to your account to continue</p>

        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-3 mb-4 flex items-center gap-2 text-sm">
            <i class="fa-solid fa-circle-exclamation"></i>
            <?= h($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

            <div class="mb-4">
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400">
                        <i class="fa-solid fa-envelope text-sm"></i>
                    </span>
                    <input type="email" id="email" name="email"
                        value="<?= h(post('email')) ?>"
                        class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm outline-none"
                        placeholder="you@example.com" required autofocus>
                </div>
            </div>

            <div class="mb-6">
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400">
                        <i class="fa-solid fa-lock text-sm"></i>
                    </span>
                    <input type="password" id="password" name="password"
                        class="w-full pl-10 pr-10 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm outline-none"
                        placeholder="••••••••" required>
                    <button type="button" onclick="togglePassword()" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600">
                        <i class="fa-solid fa-eye text-sm" id="pw-eye"></i>
                    </button>
                </div>
            </div>

            <button type="submit"
                class="w-full bg-blue-600 text-white py-2.5 rounded-lg font-semibold hover:bg-blue-700 active:scale-95 transition-all text-sm">
                <i class="fa-solid fa-right-to-bracket mr-2"></i>Sign In
            </button>
        </form>

        <p class="text-center text-xs text-gray-400 mt-6">
            For account access, contact your system administrator.
        </p>
    </div>

    <!-- Demo credentials -->
    <div class="mt-4 bg-white/10 rounded-xl p-4 text-blue-200 text-xs">
        <p class="font-semibold mb-1 text-white"><i class="fa-solid fa-info-circle mr-1"></i>Demo Login Credentials</p>
        <p>Admin: <span class="font-mono">admin@sme.sl</span> / <span class="font-mono">password</span></p>
        <p>Owner: <span class="font-mono">owner@demo.sl</span> / <span class="font-mono">password</span></p>
        <p class="mt-1 text-yellow-300">&#9888; Run setup.php first to initialize the database.</p>
    </div>
</div>

<script>
function togglePassword() {
    const pw = document.getElementById('password');
    const eye = document.getElementById('pw-eye');
    if (pw.type === 'password') {
        pw.type = 'text';
        eye.className = 'fa-solid fa-eye-slash text-sm';
    } else {
        pw.type = 'password';
        eye.className = 'fa-solid fa-eye text-sm';
    }
}
</script>
</body>
</html>
