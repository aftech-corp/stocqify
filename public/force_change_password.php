<?php
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/functions.php';

// Must be logged in AND flagged for password change — no requireLogin() to avoid redirect loops
if (!isLoggedIn()) { redirect(url('login')); }
if (empty($_SESSION['force_password_change'])) { redirect(url('dashboard')); }

$db     = getDB();
$userId = (int)$_SESSION['user_id'];
$error  = '';

if (isPost()) {
    verifyCsrf();
    $pass    = $_POST['password']         ?? '';
    $confirm = $_POST['password_confirm'] ?? '';

    if (strlen($pass) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($pass !== $confirm) {
        $error = 'Passwords do not match. Please try again.';
    } else {
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $db->prepare("UPDATE users SET password = ?, force_password_change = 0 WHERE id = ?")
           ->execute([$hash, $userId]);
        unset($_SESSION['force_password_change']);
        flash('success', 'Password updated successfully. Welcome to ' . APP_NAME . '!');
        redirect(url('dashboard'));
    }
}

$userName = $_SESSION['user_name'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h(APP_NAME) ?> | Set Your Password</title>
    <link rel="icon" type="image/png" href="<?= APP_LOGO ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center; padding: 20px;
            background: linear-gradient(135deg, #0a1628 0%, #1B3263 50%, #0a1628 100%);
        }
        .card {
            background: #fff; border-radius: 20px;
            padding: 40px; width: 100%; max-width: 440px;
            box-shadow: 0 20px 60px rgba(0,0,0,.3);
        }
        .logo { height: 44px; width: auto; border-radius: 10px; display: block; margin: 0 auto 28px; }
        .badge-info {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 13px; color: #1e40af;
            display: flex; align-items: flex-start; gap: 9px;
            margin-bottom: 24px;
            line-height: 1.5;
        }
        h2 { font-size: 20px; font-weight: 700; color: #1e293b; margin-bottom: 4px; }
        .sub { font-size: 13.5px; color: #64748b; margin-bottom: 24px; }
        .error-box {
            background: #fef2f2; border: 1px solid #fecaca;
            color: #dc2626; border-radius: 10px;
            padding: 12px 14px; font-size: 13px;
            display: flex; align-items: center; gap: 8px;
            margin-bottom: 16px;
        }
        label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
        .input-wrap { position: relative; margin-bottom: 14px; }
        .input-icon { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: #9ca3af; font-size: 13px; pointer-events: none; }
        input[type=password], input[type=text] {
            width: 100%; padding: 11px 42px 11px 40px;
            border: 1.5px solid #e2e8f0; border-radius: 10px;
            font-size: 14px; color: #1e293b; background: #f8fafc;
            outline: none; transition: border-color .18s, box-shadow .18s;
        }
        input:focus { border-color: #1B3263; box-shadow: 0 0 0 3px rgba(27,50,99,.12); background: #fff; }
        .pw-btn {
            position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer; color: #9ca3af; font-size: 13px; padding: 4px;
        }
        .pw-btn:hover { color: #475569; }
        .match-hint { font-size: 12px; margin-top: -10px; margin-bottom: 14px; }
        .btn {
            width: 100%; padding: 13px;
            background: #1B3263; color: #fff;
            border: none; border-radius: 10px;
            font-size: 15px; font-weight: 700;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 9px;
            transition: background .18s, box-shadow .18s;
            box-shadow: 0 4px 14px rgba(27,50,99,.3);
            margin-top: 8px;
        }
        .btn:hover { background: #142552; }
        .strength-bar { height: 4px; border-radius: 4px; background: #e2e8f0; margin-top: 6px; overflow: hidden; }
        .strength-fill { height: 100%; border-radius: 4px; transition: width .3s, background .3s; width: 0; }
        .strength-label { font-size: 11px; color: #64748b; margin-top: 4px; }
    </style>
</head>
<body>
<div class="card">
    <img src="<?= APP_LOGO ?>" alt="<?= h(APP_NAME) ?>" class="logo">

    <div class="badge-info">
        <i class="fa-solid fa-key flex-shrink-0 mt-0.5"></i>
        <div>Hello, <strong><?= h($userName) ?></strong>! Your account was created by an administrator. Please set your own secure password before continuing.</div>
    </div>

    <h2>Set Your Password</h2>
    <p class="sub">Choose a strong password with at least 8 characters.</p>

    <?php if ($error): ?>
    <div class="error-box">
        <i class="fa-solid fa-circle-exclamation flex-shrink-0"></i>
        <?= h($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="" id="pwForm">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

        <label for="password">New Password</label>
        <div class="input-wrap">
            <i class="fa-solid fa-lock input-icon"></i>
            <input type="password" id="password" name="password"
                placeholder="Minimum 8 characters" required minlength="8" autofocus
                oninput="checkStrength(this.value)">
            <button type="button" class="pw-btn" onclick="toggleVis('password','eye1')">
                <i class="fa-solid fa-eye" id="eye1"></i>
            </button>
        </div>
        <div class="strength-bar"><div class="strength-fill" id="sbar"></div></div>
        <div class="strength-label" id="slabel"></div>

        <br>
        <label for="password_confirm">Confirm New Password</label>
        <div class="input-wrap">
            <i class="fa-solid fa-lock input-icon"></i>
            <input type="password" id="password_confirm" name="password_confirm"
                placeholder="Re-enter your password" required minlength="8"
                oninput="checkMatch()">
            <button type="button" class="pw-btn" onclick="toggleVis('password_confirm','eye2')">
                <i class="fa-solid fa-eye" id="eye2"></i>
            </button>
        </div>
        <p id="matchHint" class="match-hint" style="display:none;"></p>

        <button type="submit" class="btn">
            <i class="fa-solid fa-shield-check"></i> Set Password &amp; Continue
        </button>
    </form>
</div>

<script>
function toggleVis(id, eyeId) {
    const inp = document.getElementById(id);
    const eye = document.getElementById(eyeId);
    inp.type = inp.type === 'password' ? 'text' : 'password';
    eye.className = inp.type === 'text' ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
}

function checkStrength(val) {
    const bar = document.getElementById('sbar');
    const lbl = document.getElementById('slabel');
    let score = 0;
    if (val.length >= 8)  score++;
    if (val.length >= 12) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const levels = [
        {w:'0%',  bg:'#e2e8f0', t:''},
        {w:'25%', bg:'#ef4444', t:'Weak'},
        {w:'50%', bg:'#f59e0b', t:'Fair'},
        {w:'75%', bg:'#3b82f6', t:'Good'},
        {w:'90%', bg:'#22c55e', t:'Strong'},
        {w:'100%',bg:'#16a34a', t:'Very Strong'},
    ];
    const l = levels[score] || levels[0];
    bar.style.width  = l.w;
    bar.style.background = l.bg;
    lbl.textContent  = l.t;
    lbl.style.color  = l.bg;
}

function checkMatch() {
    const pw  = document.getElementById('password').value;
    const pw2 = document.getElementById('password_confirm').value;
    const hint = document.getElementById('matchHint');
    if (!pw2) { hint.style.display = 'none'; return; }
    hint.style.display = 'block';
    if (pw === pw2) {
        hint.textContent = '✓ Passwords match';
        hint.style.color = '#16a34a';
    } else {
        hint.textContent = '✗ Passwords do not match';
        hint.style.color = '#dc2626';
    }
}
</script>
</body>
</html>
