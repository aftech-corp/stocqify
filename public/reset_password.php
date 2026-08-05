<?php
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/functions.php';

if (isLoggedIn()) { redirect(url('dashboard')); }

$db    = getDB();
$token = trim($_GET['token'] ?? '');
$error = '';
$done  = false;

// Validate token on GET (to show the form or an error immediately)
$tokenRow = null;
if ($token) {
    $q = $db->prepare(
        "SELECT * FROM password_resets
         WHERE token=? AND used_at IS NULL AND expires_at > NOW() LIMIT 1"
    );
    $q->execute([$token]);
    $tokenRow = $q->fetch();
}

if (isPost()) {
    verifyCsrf();

    $token   = trim(post('token'));
    $pass    = $_POST['password']         ?? '';
    $confirm = $_POST['password_confirm'] ?? '';

    // Re-validate token on POST (prevents token-swap attacks)
    $q = $db->prepare(
        "SELECT * FROM password_resets
         WHERE token=? AND used_at IS NULL AND expires_at > NOW() LIMIT 1"
    );
    $q->execute([$token]);
    $tokenRow = $q->fetch();

    if (!$tokenRow) {
        $error = 'This reset link is invalid or has already expired. Please request a new one.';
    } elseif (strlen($pass) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($pass !== $confirm) {
        $error = 'Passwords do not match. Please try again.';
    } else {
        // Update password
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $db->prepare("UPDATE users SET password=? WHERE email=? AND is_active=1")
           ->execute([$hash, $tokenRow['email']]);

        // Invalidate ALL unused tokens for this email (not just this one)
        $db->prepare("UPDATE password_resets SET used_at=NOW() WHERE email=? AND used_at IS NULL")
           ->execute([$tokenRow['email']]);

        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h(APP_NAME) ?> | Reset Password</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .btn-primary { background: #1B3263; color: #fff; }
        .btn-primary:hover { background: #142552; }
        .link-accent { color: #C9A84C; }
        input:focus { border-color: #1B3263 !important; box-shadow: 0 0 0 3px rgba(27,50,99,.15) !important; outline: none; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4" style="background:linear-gradient(135deg,#0a1628 0%,#1B3263 50%,#0a1628 100%);">

<div class="w-full max-w-md">
    <!-- Logo -->
    <div class="text-center mb-8">
        <img src="<?= APP_LOGO ?>" alt="<?= h(APP_NAME) ?>" class="h-16 w-auto mx-auto mb-4 drop-shadow-lg">
        <h1 class="text-3xl font-bold text-white"><?= h(APP_NAME) ?></h1>
        <p class="text-sm mt-1" style="color:#C9A84C;"><?= h(APP_TAGLINE) ?></p>
    </div>

    <div class="bg-white rounded-2xl shadow-2xl p-8">

        <?php if ($done): ?>
        <!-- Success -->
        <div class="text-center">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-green-100 rounded-full mb-4">
                <i class="fa-solid fa-shield-check text-green-600 text-3xl"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-800 mb-2">Password updated!</h2>
            <p class="text-gray-500 text-sm mb-6 leading-relaxed">
                Your password has been reset successfully. You can now sign in with your new password.
            </p>
            <a href="<?= url('login') ?>"
               class="btn-primary w-full inline-block text-center py-2.5 rounded-lg font-semibold transition-all text-sm">
                <i class="fa-solid fa-right-to-bracket mr-2"></i>Sign In
            </a>
        </div>

        <?php elseif (!$tokenRow && !isPost()): ?>
        <!-- Invalid / expired token shown on GET -->
        <div class="text-center">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-red-100 rounded-full mb-4">
                <i class="fa-solid fa-triangle-exclamation text-red-500 text-3xl"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-800 mb-2">Link invalid or expired</h2>
            <p class="text-gray-500 text-sm mb-6 leading-relaxed">
                This password reset link is no longer valid. Reset links expire after 1 hour and can only be used once.
            </p>
            <a href="<?= url('forgot-password') ?>"
               class="btn-primary w-full inline-block text-center py-2.5 rounded-lg font-semibold transition-all text-sm">
                Request a new link
            </a>
        </div>

        <?php else: ?>
        <!-- Reset form -->
        <h2 class="text-xl font-bold text-gray-800 mb-1">Set a new password</h2>
        <p class="text-gray-500 text-sm mb-6">Choose a strong password with at least 8 characters.</p>

        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-3 mb-4 flex items-center gap-2 text-sm">
            <i class="fa-solid fa-circle-exclamation flex-shrink-0"></i>
            <?= h($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="" id="resetForm">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="token" value="<?= h($token) ?>">

            <!-- New password -->
            <div class="mb-4">
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400">
                        <i class="fa-solid fa-lock text-sm"></i>
                    </span>
                    <input type="password" id="password" name="password"
                        class="w-full pl-10 pr-10 py-2.5 border border-gray-300 rounded-lg text-sm outline-none"
                        placeholder="Minimum 8 characters" required minlength="8" autofocus>
                    <button type="button" onclick="toggleVis('password','eye1')"
                        class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600">
                        <i class="fa-solid fa-eye text-sm" id="eye1"></i>
                    </button>
                </div>
            </div>

            <!-- Confirm password -->
            <div class="mb-6">
                <label for="password_confirm" class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400">
                        <i class="fa-solid fa-lock text-sm"></i>
                    </span>
                    <input type="password" id="password_confirm" name="password_confirm"
                        class="w-full pl-10 pr-10 py-2.5 border border-gray-300 rounded-lg text-sm outline-none"
                        placeholder="Re-enter your password" required minlength="8">
                    <button type="button" onclick="toggleVis('password_confirm','eye2')"
                        class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600">
                        <i class="fa-solid fa-eye text-sm" id="eye2"></i>
                    </button>
                </div>
                <!-- Live match indicator -->
                <p id="matchHint" class="text-xs mt-1 hidden"></p>
            </div>

            <button type="submit"
                class="btn-primary w-full py-2.5 rounded-lg font-semibold active:scale-95 transition-all text-sm">
                <i class="fa-solid fa-key mr-2"></i>Update Password
            </button>
        </form>

        <p class="text-center text-sm text-gray-500 mt-6">
            <a href="<?= url('login') ?>" class="link-accent hover:underline font-medium">
                <i class="fa-solid fa-arrow-left text-xs mr-1"></i>Back to Login
            </a>
        </p>
        <?php endif; ?>

    </div>
</div>

<script>
function toggleVis(inputId, eyeId) {
    const inp = document.getElementById(inputId);
    const eye = document.getElementById(eyeId);
    if (inp.type === 'password') {
        inp.type = 'text';
        eye.className = 'fa-solid fa-eye-slash text-sm';
    } else {
        inp.type = 'password';
        eye.className = 'fa-solid fa-eye text-sm';
    }
}

// Live password match feedback
const pw  = document.getElementById('password');
const pw2 = document.getElementById('password_confirm');
const hint = document.getElementById('matchHint');

if (pw2 && hint) {
    pw2.addEventListener('input', function () {
        if (!pw2.value) { hint.classList.add('hidden'); return; }
        hint.classList.remove('hidden');
        if (pw.value === pw2.value) {
            hint.textContent = '✓ Passwords match';
            hint.className = 'text-xs mt-1 text-green-600';
        } else {
            hint.textContent = '✗ Passwords do not match';
            hint.className = 'text-xs mt-1 text-red-500';
        }
    });
}
</script>
</body>
</html>
