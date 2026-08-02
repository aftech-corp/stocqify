<?php
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/functions.php';

if (isLoggedIn()) { redirect(url('dashboard')); }

$db = getDB();

// Auto-create verification table
$db->exec("CREATE TABLE IF NOT EXISTS `email_verifications` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `token`      VARCHAR(64)  NOT NULL,
    `expires_at` DATETIME     NOT NULL,
    `used_at`    DATETIME     DEFAULT NULL,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ev_token`  (`token`),
    KEY `idx_ev_user`   (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$token = trim($_GET['token'] ?? '');
$done  = false;
$error = '';

if ($token) {
    $q = $db->prepare(
        "SELECT ev.*, u.name, u.email FROM email_verifications ev
         JOIN users u ON u.id = ev.user_id
         WHERE ev.token = ? AND ev.used_at IS NULL AND ev.expires_at > NOW()
         LIMIT 1"
    );
    $q->execute([$token]);
    $row = $q->fetch();

    if ($row) {
        $db->prepare("UPDATE users SET email_verified_at = NOW() WHERE id = ?")
           ->execute([$row['user_id']]);
        $db->prepare("UPDATE email_verifications SET used_at = NOW() WHERE id = ?")
           ->execute([$row['id']]);
        $done = true;
    } else {
        $error = 'This verification link is invalid or has expired. Please contact your administrator to resend.';
    }
} else {
    $error = 'No verification token provided.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification | <?= h(APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="<?= APP_LOGO ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center; padding: 20px;
        }
        body { background: linear-gradient(135deg, #0a1628 0%, #1B3263 50%, #0a1628 100%); }
        .card {
            background: #fff;
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 420px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,.3);
        }
        .icon-wrap {
            width: 72px; height: 72px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 20px;
            font-size: 32px;
        }
        .icon-success { background: #dcfce7; color: #16a34a; }
        .icon-error   { background: #fee2e2; color: #dc2626; }
        h2 { font-size: 20px; font-weight: 700; color: #1e293b; margin-bottom: 8px; }
        p  { font-size: 14px; color: #64748b; line-height: 1.6; margin-bottom: 24px; }
        .btn {
            display: inline-block;
            padding: 12px 28px;
            border-radius: 10px;
            font-size: 14px; font-weight: 600;
            text-decoration: none;
            transition: background .18s;
        }
        .btn-primary { background: #1B3263; color: #fff; }
        .btn-primary:hover { background: #142552; }
        .logo { height: 40px; width: auto; border-radius: 8px; margin: 0 auto 28px; display: block; }
    </style>
</head>
<body>
<div class="card">
    <img src="<?= APP_LOGO ?>" alt="<?= h(APP_NAME) ?>" class="logo">

    <?php if ($done): ?>
    <div class="icon-wrap icon-success">
        <i class="fa-solid fa-envelope-circle-check"></i>
    </div>
    <h2>Email Verified!</h2>
    <p>Your email address has been verified successfully. You can now sign in to your <?= h(APP_NAME) ?> account.</p>
    <a href="<?= url('login') ?>?msg=verified" class="btn btn-primary">
        <i class="fa-solid fa-right-to-bracket mr-1"></i> Sign In
    </a>

    <?php else: ?>
    <div class="icon-wrap icon-error">
        <i class="fa-solid fa-triangle-exclamation"></i>
    </div>
    <h2>Verification Failed</h2>
    <p><?= h($error) ?></p>
    <a href="<?= url('login') ?>" class="btn btn-primary">Back to Login</a>
    <?php endif; ?>
</div>
</body>
</html>
