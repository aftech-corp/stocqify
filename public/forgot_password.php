<?php
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/functions.php';
require_once __DIR__ . '/../app/includes/mail.php';

if (isLoggedIn()) { redirect(url('dashboard')); }

$db = getDB();

// Auto-create password_resets table
$db->exec("CREATE TABLE IF NOT EXISTS `password_resets` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`      VARCHAR(255) NOT NULL,
    `token`      VARCHAR(64)  NOT NULL,
    `expires_at` DATETIME     NOT NULL,
    `used_at`    DATETIME     DEFAULT NULL,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pr_token` (`token`),
    KEY `idx_pr_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$success = false;
$error   = '';

if (isPost()) {
    verifyCsrf();
    $email = strtolower(trim(post('email')));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Rate limit: block if a valid token was sent within the last 5 minutes
        $rateQ = $db->prepare(
            "SELECT id FROM password_resets
             WHERE email=? AND used_at IS NULL AND expires_at > NOW()
               AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
             LIMIT 1"
        );
        $rateQ->execute([$email]);

        if ($rateQ->fetchColumn()) {
            $error = 'A reset link was recently sent to this address. Please wait a few minutes before trying again.';
        } else {
            // Look up user — must be active and registered in the system
            $userQ = $db->prepare(
                "SELECT id, name, email FROM users WHERE email=? AND is_active=1 LIMIT 1"
            );
            $userQ->execute([$email]);
            $user = $userQ->fetch();

            if (!$user) {
                $error = 'No account found with that email address. Please check and try again, or contact your administrator.';
            } else {
                // Invalidate any previous unused tokens for this email
                $db->prepare("UPDATE password_resets SET used_at=NOW() WHERE email=? AND used_at IS NULL")
                   ->execute([$email]);

                // Generate a cryptographically secure 64-char token
                $token   = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $db->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?,?,?)")
                   ->execute([$email, $token, $expires]);

                // Build and send the email
                $resetLink = url('reset-password') . '?token=' . $token;
                $subject   = 'Reset your ' . APP_NAME . ' password';
                appMail($user['email'], $subject, _resetEmailHtml($user['name'], $resetLink), $user['name']);

                $success = true;
            }
        }
    }
}

function _resetEmailHtml(string $name, string $link): string {
    $app      = h(APP_NAME);
    $safeName = h($name);
    $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    return "<!DOCTYPE html><html><head><meta charset='UTF-8'>
<meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body style='margin:0;padding:0;background:#f1f5f9;font-family:system-ui,-apple-system,sans-serif;'>
  <div style='max-width:520px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);'>
    <div style='background:linear-gradient(135deg,#1B3263,#2952a3);padding:32px 40px;text-align:center;'>
      <div style='width:52px;height:52px;background:rgba(255,255,255,.18);border-radius:14px;margin:0 auto 14px;line-height:52px;font-size:26px;'>🔑</div>
      <h1 style='color:#fff;font-size:21px;font-weight:700;margin:0;'>{$app}</h1>
    </div>
    <div style='padding:36px 40px;'>
      <h2 style='font-size:18px;font-weight:700;color:#1e293b;margin:0 0 8px;'>Password Reset Request</h2>
      <p style='color:#64748b;font-size:14px;margin:0 0 24px;line-height:1.6;'>
        Hi <strong>{$safeName}</strong>, we received a request to reset the password for your {$app} account.
      </p>
      <a href='{$safeLink}'
         style='display:block;background:#1B3263;color:#fff;text-decoration:none;text-align:center;
                padding:14px 24px;border-radius:8px;font-weight:600;font-size:15px;margin-bottom:24px;'>
        Reset My Password
      </a>
      <p style='color:#94a3b8;font-size:12px;line-height:1.7;margin:0 0 8px;'>
        This link expires in <strong>1 hour</strong>.
        If you did not request a password reset, you can safely ignore this email — your password will remain unchanged.
      </p>
      <p style='color:#94a3b8;font-size:12px;line-height:1.7;margin:0;'>
        If the button above does not work, copy and paste this URL into your browser:<br>
        <span style='color:#1B3263;word-break:break-all;'>{$safeLink}</span>
      </p>
    </div>
    <div style='background:#f8fafc;padding:16px 40px;text-align:center;border-top:1px solid #e2e8f0;'>
      <p style='color:#94a3b8;font-size:11px;margin:0;'>
        &copy; {$app} &middot; This is an automated message, please do not reply.
      </p>
    </div>
  </div>
</body></html>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | <?= h(APP_NAME) ?></title>
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

        <?php if ($success): ?>
        <!-- Success state -->
        <div class="text-center">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-green-100 rounded-full mb-4">
                <i class="fa-solid fa-envelope-circle-check text-green-600 text-3xl"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-800 mb-2">Check your email</h2>
            <p class="text-gray-500 text-sm mb-6 leading-relaxed">
                If that email address is registered in our system, you will receive a password reset link shortly.
                The link expires in <strong>1 hour</strong>.
            </p>
            <p class="text-xs text-gray-400 mb-6">
                Don't see it? Check your spam or junk folder.
            </p>
            <a href="<?= url('login') ?>"
               class="inline-flex items-center gap-2 text-sm link-accent font-medium">
                <i class="fa-solid fa-arrow-left text-xs"></i> Back to Login
            </a>
        </div>

        <?php else: ?>
        <!-- Request form -->
        <h2 class="text-xl font-bold text-gray-800 mb-1">Forgot your password?</h2>
        <p class="text-gray-500 text-sm mb-6">Enter your registered email and we'll send you a reset link.</p>

        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-3 mb-4 flex items-center gap-2 text-sm">
            <i class="fa-solid fa-circle-exclamation flex-shrink-0"></i>
            <?= h($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

            <div class="mb-5">
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400">
                        <i class="fa-solid fa-envelope text-sm"></i>
                    </span>
                    <input type="email" id="email" name="email"
                        value="<?= h(post('email')) ?>"
                        class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg text-sm outline-none"
                        placeholder="you@example.com" required autofocus>
                </div>
            </div>

            <button type="submit"
                class="btn-primary w-full py-2.5 rounded-lg font-semibold active:scale-95 transition-all text-sm">
                <i class="fa-solid fa-paper-plane mr-2"></i>Send Reset Link
            </button>
        </form>

        <p class="text-center text-sm text-gray-500 mt-6">
            Remember your password?
            <a href="<?= url('login') ?>" class="link-accent hover:underline font-medium">Sign in</a>
        </p>
        <?php endif; ?>

    </div>
</div>

</body>
</html>
