<?php
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/functions.php';

if (isLoggedIn()) { redirect(url('dashboard')); }

$error = '';

if (isPost()) {
    $email    = post('email');
    $password = $_POST['password'] ?? '';
    if (empty($email) || empty($password)) {
        $error = 'Please enter your email and password.';
    } else {
        $result = login($email, $password);
        if ($result['success']) {
            // force_password_change is set in session by login()
            if (!empty($_SESSION['force_password_change'])) {
                redirect(url('change-password'));
            }
            redirect($_GET['redirect'] ?? url('dashboard'));
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
    <title>Sign In | <?= h(APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="<?= APP_LOGO ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --navy:       #1B3263;
            --navy-dark:  #0e1f3f;
            --navy-mid:   #142552;
            --gold:       #C9A84C;
            --gold-dark:  #a8892e;
        }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            display: flex;
            min-height: 100vh;
            background: #f1f5f9;
        }

        /* ─── LEFT BRANDING PANEL ─────────────────────────────── */
        .auth-left {
            flex: 1;
            position: relative;
            /* No overflow:hidden — decoratives are clipped by inner .decor-wrap */
            display: flex;
            flex-direction: column;
            /* No justify-content:center — panel-content uses margin:auto to center */
            padding: 56px 60px;
            background: linear-gradient(150deg, var(--navy-dark) 0%, var(--navy) 55%, #1e3f7a 100%);
            min-height: 100vh;
        }
        /* Dot grid */
        .auth-left::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: radial-gradient(rgba(255,255,255,.055) 1px, transparent 1px);
            background-size: 30px 30px;
            pointer-events: none;
            z-index: 0;
        }
        /* Decorative overlay — overflow:hidden here, not on the panel */
        .decor-wrap {
            position: absolute;
            inset: 0;
            overflow: hidden;
            pointer-events: none;
            z-index: 1;
        }
        /* Glow spots */
        .glow {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            pointer-events: none;
        }
        .glow-1 { width:420px; height:420px; background:rgba(201,168,76,.10); top:-80px;   right:-100px; }
        .glow-2 { width:320px; height:320px; background:rgba(41,82,163,.22);  bottom:-60px; left:-80px; }

        /* Animated rings */
        .ring {
            position: absolute;
            border-radius: 50%;
            border: 1px solid rgba(201,168,76,.1);
            pointer-events: none;
        }
        .ring-1 { width:500px; height:500px; top:-140px; right:-160px; animation: spin 80s linear infinite; }
        .ring-2 { width:320px; height:320px; bottom:-80px; left:-100px; animation: spin 55s linear infinite reverse; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* margin:auto centers when space is available; collapses to 0 when overflowing */
        .panel-content { position: relative; z-index: 2; margin-top: auto; margin-bottom: auto; }

        .brand-row { display: flex; align-items: center; gap: 16px; margin-bottom: 44px; }
        .brand-logo { height: 56px; width: auto; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,.3); }
        .brand-name { color: #fff; font-size: 26px; font-weight: 800; letter-spacing: -.5px; }
        .brand-tag  { color: var(--gold); font-size: 12px; font-weight: 600; margin-top: 2px; }

        .headline {
            color: #fff;
            font-size: 2.1rem;
            font-weight: 800;
            line-height: 1.18;
            margin-bottom: 12px;
        }
        .headline span { color: var(--gold); }
        .subline {
            color: rgba(255,255,255,.55);
            font-size: 14px;
            line-height: 1.75;
            margin-bottom: 44px;
        }

        @keyframes fadeUp {
            from { opacity:0; transform:translateY(14px); }
            to   { opacity:1; transform:none; }
        }

        /* Compact 2×2 feature grid */
        .feat-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 24px;
        }
        .feat-card {
            display: flex;
            align-items: center;
            gap: 11px;
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(255,255,255,.09);
            border-radius: 12px;
            padding: 13px 14px;
            animation: fadeUp .45s ease both;
        }
        .feat-card:nth-child(1) { animation-delay:.05s; }
        .feat-card:nth-child(2) { animation-delay:.11s; }
        .feat-card:nth-child(3) { animation-delay:.17s; }
        .feat-card:nth-child(4) { animation-delay:.23s; }
        .feat-card-icon {
            width: 32px; height: 32px;
            background: rgba(201,168,76,.15);
            border: 1px solid rgba(201,168,76,.22);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            color: var(--gold);
            font-size: 13px;
            flex-shrink: 0;
        }
        .feat-card-text { min-width: 0; }
        .feat-card-title { color: #fff; font-size: 12px; font-weight: 700; line-height: 1.3; }
        .feat-card-desc  { color: rgba(255,255,255,.45); font-size: 11px; margin-top: 2px; line-height: 1.4; }

        /* Stats strip */
        .stat-strip {
            display: flex;
            align-items: center;
            gap: 0;
        }
        .stat-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
        }
        .stat-num {
            color: var(--gold);
            font-size: 15px;
            font-weight: 800;
            letter-spacing: -.3px;
        }
        .stat-lbl {
            color: rgba(255,255,255,.45);
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .stat-sep {
            width: 1px;
            height: 30px;
            background: rgba(255,255,255,.12);
            flex-shrink: 0;
        }

        /* ─── RIGHT FORM PANEL ────────────────────────────────── */
        .auth-right {
            width: 480px;
            flex-shrink: 0;
            background: #fff;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 60px 48px;
            box-shadow: -4px 0 40px rgba(0,0,0,.06);
            min-height: 100vh;
            overflow-y: auto;
        }

        .form-section { flex: 1; }

        .welcome-head {
            font-size: 22px; font-weight: 800;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .welcome-sub { font-size: 13.5px; color: #64748b; margin-bottom: 28px; }

        /* Alerts */
        .alert {
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 13px;
            display: flex; align-items: center; gap: 9px;
            margin-bottom: 20px;
        }
        .alert-error   { background:#fef2f2; border:1px solid #fecaca; color:#dc2626; }
        .alert-success { background:#f0fdf4; border:1px solid #bbf7d0; color:#16a34a; }

        /* Form fields */
        .field-label {
            display: block;
            font-size: 13px; font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }
        .field-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
        .field-wrap { position: relative; }
        .field-icon {
            position: absolute;
            left: 13px; top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 13px;
            pointer-events: none;
        }
        .form-input {
            width: 100%;
            padding: 11px 14px 11px 40px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            color: #1e293b;
            background: #f8fafc;
            outline: none;
            transition: border-color .18s, box-shadow .18s, background .18s;
        }
        .form-input:focus {
            border-color: var(--navy);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(27,50,99,.12);
        }
        .form-input.pr-big { padding-right: 44px; }

        .pw-toggle {
            position: absolute;
            right: 13px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none; cursor: pointer;
            color: #9ca3af;
            font-size: 13px;
            padding: 4px;
            transition: color .15s;
        }
        .pw-toggle:hover { color: #475569; }

        .mb4 { margin-bottom: 16px; }
        .mb6 { margin-bottom: 24px; }

        .link-accent { color: var(--gold); font-size: 12px; font-weight: 600; text-decoration: none; }
        .link-accent:hover { color: var(--gold-dark); text-decoration: underline; }

        .btn-signin {
            width: 100%;
            padding: 13px;
            background: var(--navy);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 15px; font-weight: 700;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 9px;
            transition: background .18s, transform .1s, box-shadow .18s;
            box-shadow: 0 4px 14px rgba(27,50,99,.3);
        }
        .btn-signin:hover { background: var(--navy-mid); box-shadow: 0 6px 20px rgba(27,50,99,.4); }
        .btn-signin:active { transform: scale(.98); }

        .footer-note { text-align: center; font-size: 11.5px; color: #94a3b8; margin-top: 20px; }

        /* Demo box */
        .demo-box {
            margin-top: 28px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px 16px;
        }
        .demo-title {
            font-size: 11.5px; font-weight: 700;
            color: var(--navy);
            display: flex; align-items: center; gap: 6px;
            margin-bottom: 8px;
        }
        .demo-row { font-size: 12px; color: #475569; margin-bottom: 4px; }
        .demo-code {
            background: #e2e8f0; color: #1e293b;
            border-radius: 4px; padding: 1px 6px;
            font-family: monospace; font-size: 12px;
        }
        .demo-warn { font-size: 11px; color: #d97706; font-weight: 600; margin-top: 8px; }

        /* Copyright */
        .copyright { text-align: center; font-size: 11px; color: #cbd5e1; margin-top: 28px; }

        /* Short viewports — compact adjustments */
        @media (max-height: 680px) {
            .auth-left  { padding: 28px 60px; }
            .brand-row  { margin-bottom: 20px; }
            .subline    { margin-bottom: 18px; font-size: 13px; }
            .feat-grid  { gap: 8px; margin-bottom: 18px; }
            .feat-card  { padding: 10px 12px; }
        }
        @media (max-height: 560px) {
            .subline    { display: none; }
            .brand-logo { height: 42px; }
            .headline   { font-size: 1.65rem; }
        }

        /* Laptop (1024–1280px) — reduce padding */
        @media (max-width: 1280px) {
            .auth-left  { padding: 40px 48px; }
            .auth-right { width: 440px; padding: 48px 36px; }
            .headline   { font-size: 1.85rem; }
            .brand-logo { height: 50px; }
        }
        @media (max-width: 1100px) {
            .auth-left  { padding: 28px 36px; }
            .auth-right { width: 400px; padding: 36px 28px; }
            .headline   { font-size: 1.6rem; }
            .brand-logo { height: 44px; }
            .brand-name { font-size: 22px; }
            .brand-row  { margin-bottom: 24px; }
            .subline    { margin-bottom: 18px; }
            .feat-grid  { gap: 8px; margin-bottom: 18px; }
        }

        /* Mobile / tablet portrait — hide left panel, full-width form */
        @media (max-width: 860px) {
            body { flex-direction: column; }
            .auth-left  { display: none; }
            .auth-right {
                width: 100%;
                min-height: 100vh;
                padding: 48px 28px;
                box-shadow: none;
                background: linear-gradient(150deg, var(--navy-dark) 0%, var(--navy) 55%, #1e3f7a 100%);
            }
            .welcome-head { color: #fff; }
            .welcome-sub  { color: rgba(255,255,255,.65); }
            .field-label  { color: rgba(255,255,255,.8); }
            .form-input   { background: rgba(255,255,255,.1); border-color: rgba(255,255,255,.2); color: #fff; }
            .form-input::placeholder { color: rgba(255,255,255,.4); }
            .form-input:focus { border-color: var(--gold); background: rgba(255,255,255,.15); box-shadow: 0 0 0 3px rgba(201,168,76,.2); }
            .link-accent  { color: var(--gold); }
            .demo-box     { background: rgba(255,255,255,.08); border-color: rgba(255,255,255,.15); }
            .demo-title   { color: var(--gold); }
            .demo-row     { color: rgba(255,255,255,.7); }
            .demo-code    { background: rgba(255,255,255,.15); color: #fff; }
            .footer-note  { color: rgba(255,255,255,.4); }
            .copyright    { color: rgba(255,255,255,.3); }
            .alert-error  { background: rgba(220,38,38,.15); border-color: rgba(220,38,38,.3); color: #fca5a5; }
            .alert-success { background: rgba(22,163,74,.15); border-color: rgba(22,163,74,.3); color: #86efac; }
        }
    </style>
</head>
<body>

<!-- ═══════════════ LEFT PANEL ═══════════════ -->
<div class="auth-left">
    <div class="decor-wrap">
        <div class="glow glow-1"></div>
        <div class="glow glow-2"></div>
        <div class="ring ring-1"></div>
        <div class="ring ring-2"></div>
    </div>

    <div class="panel-content">
        <!-- Brand -->
        <div class="brand-row">
            <img src="<?= APP_LOGO ?>" alt="<?= h(APP_NAME) ?>" class="brand-logo">
            <div>
                <div class="brand-name"><?= h(APP_NAME) ?></div>
                <div class="brand-tag"><?= h(APP_TAGLINE) ?></div>
            </div>
        </div>

        <!-- Headline -->
        <div class="headline">
            The smarter way to<br>
            <span>run your business.</span>
        </div>
        <div class="subline">
            Everything your business needs — inventory, sales,<br>
            customers, finances, and reports — all in one place.
        </div>

        <!-- 2×2 Feature Grid -->
        <div class="feat-grid">
            <div class="feat-card">
                <div class="feat-card-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
                <div class="feat-card-text">
                    <div class="feat-card-title">Inventory & Stock</div>
                    <div class="feat-card-desc">Real-time tracking &amp; smart alerts</div>
                </div>
            </div>
            <div class="feat-card">
                <div class="feat-card-icon"><i class="fa-solid fa-chart-line"></i></div>
                <div class="feat-card-text">
                    <div class="feat-card-title">Financial Reports</div>
                    <div class="feat-card-desc">Profit, revenue &amp; expenses</div>
                </div>
            </div>
            <div class="feat-card">
                <div class="feat-card-icon"><i class="fa-solid fa-users"></i></div>
                <div class="feat-card-text">
                    <div class="feat-card-title">Customer Management</div>
                    <div class="feat-card-desc">Orders, debts &amp; history</div>
                </div>
            </div>
            <div class="feat-card">
                <div class="feat-card-icon"><i class="fa-solid fa-globe"></i></div>
                <div class="feat-card-text">
                    <div class="feat-card-title">Global &amp; Multi-currency</div>
                    <div class="feat-card-desc">170+ countries supported</div>
                </div>
            </div>
        </div>

        <!-- Stats strip -->
        <div class="stat-strip">
            <div class="stat-item">
                <span class="stat-num">170+</span>
                <span class="stat-lbl">Countries</span>
            </div>
            <div class="stat-sep"></div>
            <div class="stat-item">
                <span class="stat-num">Multi</span>
                <span class="stat-lbl">Currency</span>
            </div>
            <div class="stat-sep"></div>
            <div class="stat-item">
                <span class="stat-num">Real-time</span>
                <span class="stat-lbl">Analytics</span>
            </div>
            <div class="stat-sep"></div>
            <div class="stat-item">
                <span class="stat-num">Secure</span>
                <span class="stat-lbl">& Private</span>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════ RIGHT PANEL ═══════════════ -->
<div class="auth-right">

    <div class="welcome-head">Welcome back 👋</div>
    <div class="welcome-sub">Sign in to your <?= h(APP_NAME) ?> account to continue</div>

    <?php if ($error): ?>
    <div class="alert alert-error">
        <i class="fa-solid fa-circle-exclamation flex-shrink-0"></i>
        <?= h($error) ?>
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'logged_out'): ?>
    <div class="alert alert-success">
        <i class="fa-solid fa-check-circle flex-shrink-0"></i>
        You have been signed out successfully.
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'verified'): ?>
    <div class="alert alert-success">
        <i class="fa-solid fa-envelope-circle-check flex-shrink-0"></i>
        Email verified! You can now sign in.
    </div>
    <?php endif; ?>

    <form method="POST" action="" novalidate>
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

        <!-- Email -->
        <div class="mb4">
            <label class="field-label" for="email">Email Address</label>
            <div class="field-wrap">
                <i class="fa-solid fa-envelope field-icon"></i>
                <input type="email" id="email" name="email" class="form-input"
                    value="<?= h(post('email')) ?>"
                    placeholder="you@example.com" required autofocus>
            </div>
        </div>

        <!-- Password -->
        <div class="mb6">
            <div class="field-row">
                <label class="field-label" for="password" style="margin-bottom:0;">Password</label>
                <a href="<?= url('forgot-password') ?>" class="link-accent">Forgot password?</a>
            </div>
            <div class="field-wrap">
                <i class="fa-solid fa-lock field-icon"></i>
                <input type="password" id="password" name="password" class="form-input pr-big"
                    placeholder="••••••••" required>
                <button type="button" class="pw-toggle" onclick="togglePw()">
                    <i class="fa-solid fa-eye" id="pw-eye"></i>
                </button>
            </div>
        </div>

        <button type="submit" class="btn-signin">
            <i class="fa-solid fa-right-to-bracket"></i> Sign In
        </button>
    </form>

    <p class="footer-note">Need access? Contact your system administrator.</p>

    <!-- Demo credentials -->
    <div class="demo-box">
        <div class="demo-title">
            <i class="fa-solid fa-circle-info"></i> Demo Login Credentials
        </div>
        <div class="demo-row">Admin: <span class="demo-code">admin@sme.sl</span> / <span class="demo-code">password</span></div>
        <div class="demo-row">Owner: <span class="demo-code">owner@demo.sl</span> / <span class="demo-code">password</span></div>
        <div class="demo-warn">&#9888; Run setup.php first to initialize the database.</div>
    </div>

    <div class="copyright">&copy; <?= date('Y') ?> <?= h(APP_NAME) ?> &middot; All rights reserved</div>
</div>

<script>
function togglePw() {
    const pw  = document.getElementById('password');
    const eye = document.getElementById('pw-eye');
    if (pw.type === 'password') {
        pw.type = 'text';
        eye.className = 'fa-solid fa-eye-slash';
    } else {
        pw.type = 'password';
        eye.className = 'fa-solid fa-eye';
    }
}
</script>
</body>
</html>
