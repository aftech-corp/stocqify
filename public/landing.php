<?php
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/includes/functions.php';
require_once __DIR__ . '/../app/includes/mail.php';
session_name(SESSION_NAME);
if (session_status() === PHP_SESSION_NONE) session_start();

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . SITE_URL . '/dashboard');
    exit;
}

// ─── Email HTML builders ──────────────────────────────────────────────────────
function _emailWrap(string $body): string {
    $year = date('Y'); $app = APP_NAME;
    return "<!DOCTYPE html><html><body style='margin:0;padding:0;background:#f1f5f9;font-family:Inter,Arial,sans-serif'>
<div style='max-width:600px;margin:0 auto;padding:32px 16px'>
<div style='background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.07)'>
<div style='background:linear-gradient(135deg,#0e1f3f,#1B3263);padding:22px 28px;display:flex;align-items:center;gap:12px'>
  <div style='width:36px;height:36px;background:linear-gradient(135deg,#C9A84C,#e8c96d);border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:900;color:#0e1f3f'>S</div>
  <span style='font-size:19px;font-weight:800;color:#fff;letter-spacing:-.5px'>{$app}</span>
</div>
<div style='padding:28px 28px 24px'>{$body}</div>
<div style='background:#f8fafc;border-top:1px solid #e2e8f0;padding:14px 28px;text-align:center'>
  <p style='margin:0;font-size:12px;color:#94a3b8'>&copy; {$year} {$app}. All rights reserved.</p>
</div>
</div></div></body></html>";
}

function _emailRow(string $label, string $value): string {
    return "<tr>
      <td style='padding:8px 14px;background:#f8fafc;font-size:12.5px;font-weight:700;color:#475569;width:110px;border:1px solid #e2e8f0'>{$label}</td>
      <td style='padding:8px 14px;font-size:13px;color:#1e293b;border:1px solid #e2e8f0'>{$value}</td>
    </tr>";
}

function _demoAdminEmail(string $n, string $e, string $b, string $p, string $co, string $la, string $m, string $pref = ''): string {
    $rows  = _emailRow('Name', htmlspecialchars($n))
           . _emailRow('Email', htmlspecialchars($e))
           . _emailRow('Business', htmlspecialchars($b) ?: '—')
           . _emailRow('Phone', htmlspecialchars($p) ?: '—')
           . _emailRow('Country', htmlspecialchars($co) ?: '—')
           . _emailRow('Language', htmlspecialchars($la) ?: '—')
           . ($pref ? _emailRow('Payment Ref', htmlspecialchars($pref)) : '')
           . _emailRow('Message', nl2br(htmlspecialchars($m)) ?: '—');
    $body  = "<div style='background:#fef9ec;border-left:4px solid #C9A84C;padding:11px 16px;border-radius:0 8px 8px 0;margin-bottom:20px'>
                <strong style='color:#92400e;font-size:14px'>&#128197; New Demo Booking</strong>
              </div>
              <p style='font-size:14px;color:#64748b;margin:0 0 16px'>A business has requested a demo. Details below — respond within 24 hours.</p>
              <table style='width:100%;border-collapse:collapse'>{$rows}</table>
              <div style='margin-top:20px;padding:14px 16px;background:#f0fdf4;border-radius:8px;border:1px solid #bbf7d0'>
                <p style='margin:0;font-size:13px;color:#15803d'><strong>Action:</strong> Contact the prospect to confirm their demo slot.</p>
              </div>";
    return _emailWrap($body);
}

function _demoConfirmEmail(string $n): string {
    $app  = APP_NAME;
    $body = "<h2 style='font-size:20px;font-weight:800;color:#0e1f3f;margin:0 0 12px'>Hi " . htmlspecialchars($n) . ", we received your request!</h2>
             <p style='font-size:14.5px;color:#64748b;line-height:1.7;margin:0 0 20px'>Thank you for booking a demo with <strong>{$app}</strong>. Our team will be in touch within <strong>24 hours</strong> to confirm your session.</p>
             <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin-bottom:20px'>
               <p style='font-size:13px;font-weight:700;color:#334155;margin:0 0 12px'>What happens next?</p>
               " . implode('', array_map(fn($i, $t) =>
                 "<div style='display:flex;align-items:flex-start;gap:10px;margin-bottom:10px'>
                    <div style='min-width:22px;height:22px;background:#1B3263;border-radius:50%;text-align:center;line-height:22px;font-size:11px;font-weight:700;color:#C9A84C'>{$i}</div>
                    <span style='font-size:13px;color:#64748b;line-height:1.5'>{$t}</span>
                  </div>",
                 [1,2,3],
                 ['Our team reviews your request and prepares a tailored demo for your business type.',
                  'We contact you within 24 hours to confirm a date and time that suits you.',
                  'Join the live session, ask questions, and see exactly how ' . $app . ' can help your business.']
               )) . "
             </div>
             <p style='font-size:13px;color:#94a3b8;margin:0'>Questions? Reply to this email or reach us at <a href='mailto:hello@stocqify.com' style='color:#1B3263'>hello@stocqify.com</a></p>";
    return _emailWrap($body);
}

function _contactAdminEmail(string $n, string $e, string $s, string $m): string {
    $rows  = _emailRow('Name', htmlspecialchars($n))
           . _emailRow('Email', htmlspecialchars($e))
           . _emailRow('Subject', htmlspecialchars($s) ?: '—')
           . _emailRow('Message', nl2br(htmlspecialchars($m)));
    $body  = "<div style='background:#eff6ff;border-left:4px solid #3b82f6;padding:11px 16px;border-radius:0 8px 8px 0;margin-bottom:20px'>
                <strong style='color:#1e40af;font-size:14px'>&#9993; New Contact Message</strong>
              </div>
              <p style='font-size:14px;color:#64748b;margin:0 0 16px'>A visitor has sent a message via the website contact form.</p>
              <table style='width:100%;border-collapse:collapse'>{$rows}</table>";
    return _emailWrap($body);
}

function _contactConfirmEmail(string $n): string {
    $app  = APP_NAME;
    $body = "<h2 style='font-size:20px;font-weight:800;color:#0e1f3f;margin:0 0 12px'>Hi " . htmlspecialchars($n) . ", we got your message!</h2>
             <p style='font-size:14.5px;color:#64748b;line-height:1.7;margin:0 0 20px'>Thank you for reaching out to <strong>{$app}</strong>. We've received your message and our team will get back to you on the same or next business day.</p>
             <div style='padding:16px;background:#fef9ec;border-radius:10px;border:1px solid rgba(201,168,76,.3);margin-bottom:20px'>
               <p style='margin:0;font-size:13px;color:#78350f'><strong>&#9200; Response time:</strong> We typically respond within 4–8 hours during business hours (Mon–Fri).</p>
             </div>
             <p style='font-size:13px;color:#94a3b8;margin:0'>For urgent matters, please reply to this email or use our contact form.</p>";
    return _emailWrap($body);
}

// ─── Form Handlers ────────────────────────────────────────────────────────────
$fSuccess = []; $fErrors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['_action'] ?? '');
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS `landing_demos` (`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`name` VARCHAR(100),`email` VARCHAR(255),`business_name` VARCHAR(150),`phone` VARCHAR(30),`country` VARCHAR(100),`language` VARCHAR(50),`payment_reference` VARCHAR(200),`message` TEXT,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try { $db->exec("ALTER TABLE landing_demos ADD COLUMN IF NOT EXISTS country VARCHAR(100) DEFAULT NULL"); } catch (\Exception $__ex) {}
        try { $db->exec("ALTER TABLE landing_demos ADD COLUMN IF NOT EXISTS language VARCHAR(50) DEFAULT NULL"); } catch (\Exception $__ex) {}
        try { $db->exec("ALTER TABLE landing_demos ADD COLUMN IF NOT EXISTS payment_reference VARCHAR(200) DEFAULT NULL"); } catch (\Exception $__ex) {}
        $db->exec("CREATE TABLE IF NOT EXISTS `landing_newsletter` (`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`email` VARCHAR(255) UNIQUE NOT NULL,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS `landing_contacts` (`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`name` VARCHAR(100),`email` VARCHAR(255),`subject` VARCHAR(200),`message` TEXT,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Get admin email for notifications
        $adminEmail = '';
        try {
            $adminEmail = $db->query("SELECT value FROM system_settings WHERE `key`='smtp_from_email'")->fetchColumn() ?: '';
            if (!$adminEmail) {
                $adminEmail = $db->query("SELECT email FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetchColumn() ?: '';
            }
        } catch (\Exception $ex) {}

        if ($action === 'demo') {
            $n    = trim($_POST['d_name']    ?? ''); $e  = trim($_POST['d_email']   ?? '');
            $b    = trim($_POST['d_biz']     ?? ''); $p  = trim($_POST['d_phone']   ?? '');
            $co   = trim($_POST['d_country'] ?? ''); $la = trim($_POST['d_lang']    ?? '');
            $pref = trim($_POST['d_pref']    ?? ''); $m  = trim($_POST['d_msg']     ?? '');
            if (!$n) $fErrors['demo'] = 'Your name is required.';
            elseif (!filter_var($e, FILTER_VALIDATE_EMAIL)) $fErrors['demo'] = 'A valid email address is required.';
            else {
                $db->prepare("INSERT INTO landing_demos (name,email,business_name,phone,country,language,payment_reference,message) VALUES (?,?,?,?,?,?,?,?)")->execute([$n,$e,$b,$p,$co,$la,$pref?:null,$m]);
                try {
                    $__notifAdmins = $db->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug='admin' AND u.is_active=1")->fetchAll();
                    $__notifBody   = $n . ($b ? " ({$b})" : '') . ' has requested a demo session.';
                    foreach ($__notifAdmins as $__adm) {
                        createNotification((int)$__adm['id'], 'info', 'New Demo Request', $__notifBody, url('admin/demos'));
                    }
                } catch (\Exception $__ne) {}
                if ($adminEmail) appMail($adminEmail, "New Demo Booking — {$n}", _demoAdminEmail($n,$e,$b,$p,$co,$la,$m,$pref));
                appMail($e, 'Demo Request Received — ' . APP_NAME, _demoConfirmEmail($n), $n);
                $fSuccess['demo'] = true;
            }
        }
        if ($action === 'newsletter') {
            $e = trim($_POST['nl_email'] ?? '');
            if (!filter_var($e, FILTER_VALIDATE_EMAIL)) $fErrors['nl'] = 'Enter a valid email address.';
            else {
                try {
                    $db->prepare("INSERT INTO landing_newsletter (email) VALUES (?)")->execute([$e]);
                    if ($adminEmail) {
                        $app = APP_NAME;
                        $nlAdminHtml = _emailWrap("
                            <div style='background:#f0f9ff;border-left:4px solid #0ea5e9;padding:11px 16px;border-radius:0 8px 8px 0;margin-bottom:20px'>
                              <strong style='color:#0369a1;font-size:14px'>&#128140; New Newsletter Subscriber</strong>
                            </div>
                            <p style='font-size:14px;color:#64748b;margin:0 0 16px'>A visitor has subscribed to {$app} updates.</p>
                            <table style='width:100%;border-collapse:collapse'>" . _emailRow('Email', htmlspecialchars($e)) . "</table>
                        ");
                        appMail($adminEmail, "New Newsletter Subscriber — {$e}", $nlAdminHtml);
                    }
                    $fSuccess['nl'] = true;
                } catch (\Exception $ex) { $fErrors['nl'] = 'You are already subscribed — thank you!'; }
            }
        }
        if ($action === 'contact') {
            $n = trim($_POST['c_name'] ?? ''); $e = trim($_POST['c_email'] ?? '');
            $s = trim($_POST['c_subj'] ?? ''); $m = trim($_POST['c_msg'] ?? '');
            if (!$n || !$m) $fErrors['contact'] = 'Name and message are required.';
            elseif (!filter_var($e, FILTER_VALIDATE_EMAIL)) $fErrors['contact'] = 'A valid email address is required.';
            else {
                $db->prepare("INSERT INTO landing_contacts (name,email,subject,message) VALUES (?,?,?,?)")->execute([$n,$e,$s,$m]);
                if ($adminEmail) appMail($adminEmail, "New Contact Message — {$n}", _contactAdminEmail($n,$e,$s,$m));
                appMail($e, 'We received your message — ' . APP_NAME, _contactConfirmEmail($n), $n);
                $fSuccess['contact'] = true;
            }
        }
    } catch (\Exception $ex) { /* db not ready */ }
}

// Load contact/social settings for landing page
$__gs = [];
try {
    $__gs = getDB()->query("SELECT `key`,`value` FROM system_settings WHERE `key` LIKE 'contact_%' OR `key` LIKE 'social_%' OR `key` LIKE 'demo_%'")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
} catch (\Exception $__gse) {}

$loginUrl = SITE_URL . '/login';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= APP_NAME ?> | <?= APP_TAGLINE ?></title>
<meta name="description" content="The all-in-one business management platform empowering growing businesses worldwide. Track sales, manage inventory, analyse performance and grow faster.">
<link rel="icon" type="image/png" href="<?= APP_LOGO ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ─── Variables ───────────────────────────────────────── */
:root{
  --navy:#1B3263;--navy-dark:#0e1f3f;--navy-deep:#070f1f;--navy-mid:#142552;
  --gold:#C9A84C;--gold-lt:#e8c96d;--gold-pale:#fef9ec;
  --gray-50:#f8fafc;--gray-100:#f1f5f9;--gray-200:#e2e8f0;
  --gray-500:#64748b;--gray-700:#334155;--gray-900:#0f172a;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{font-family:'Inter',system-ui,sans-serif;color:var(--gray-900);overflow-x:hidden;line-height:1.6}
a{text-decoration:none}

/* ─── NAVBAR ──────────────────────────────────────────── */
.nav{position:fixed;top:0;left:0;right:0;z-index:1000;padding:18px 0;transition:all .35s}
.nav.scrolled{background:rgba(7,15,31,.96);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);padding:10px 0;box-shadow:0 1px 0 rgba(255,255,255,.05)}
.nav-inner{max-width:1200px;margin:0 auto;padding:0 24px;display:flex;align-items:center;justify-content:space-between;gap:20px}
.nav-logo{display:flex;align-items:center;gap:10px}
.nav-logo-icon{width:36px;height:36px;background:linear-gradient(135deg,var(--gold),var(--gold-lt));border-radius:9px;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:15px;color:var(--navy-dark);letter-spacing:-1px;flex-shrink:0}
.nav-logo-text{font-size:18px;font-weight:800;color:#fff;letter-spacing:-.5px}
.nav-links{display:flex;align-items:center;gap:2px;list-style:none}
.nav-links a{color:rgba(255,255,255,.7);font-size:14px;font-weight:500;padding:8px 14px;border-radius:8px;transition:all .2s}
.nav-links a:hover{color:#fff;background:rgba(255,255,255,.08)}
.nav-cta{display:flex;align-items:center;gap:10px;flex-shrink:0}
.btn-ghost{color:rgba(255,255,255,.85);font-size:14px;font-weight:600;padding:8px 18px;border-radius:8px;border:1px solid rgba(255,255,255,.2);transition:all .2s}
.btn-ghost:hover{border-color:var(--gold);color:var(--gold)}
.btn-gold{background:linear-gradient(135deg,var(--gold),var(--gold-lt));color:var(--navy-dark);font-size:14px;font-weight:700;padding:9px 20px;border-radius:9px;transition:all .2s;box-shadow:0 2px 12px rgba(201,168,76,.35)}
.btn-gold:hover{transform:translateY(-1px);box-shadow:0 5px 20px rgba(201,168,76,.5)}
.nav-ham{display:none;background:none;border:none;color:#fff;font-size:20px;cursor:pointer;padding:8px}

/* ─── HERO ───────────────────────────────────────────── */
.hero{min-height:100vh;background:linear-gradient(145deg,#060d1a 0%,#0b1a38 40%,#1B3263 75%,#091526 100%);position:relative;display:flex;align-items:center;overflow:hidden}
.hero::before{content:'';position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.025) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.025) 1px,transparent 1px);background-size:55px 55px;pointer-events:none}

/* Orbs */
.orb{position:absolute;border-radius:50%;filter:blur(90px);pointer-events:none}
.orb-1{width:520px;height:520px;background:radial-gradient(circle,rgba(201,168,76,.18),transparent);top:-140px;left:-160px;animation:orbA 9s ease-in-out infinite}
.orb-2{width:640px;height:640px;background:radial-gradient(circle,rgba(27,50,99,.5),rgba(52,96,176,.2),transparent);bottom:-220px;right:-200px;animation:orbB 11s ease-in-out infinite}
.orb-3{width:280px;height:280px;background:radial-gradient(circle,rgba(201,168,76,.1),transparent);top:45%;right:38%;animation:orbA 13s ease-in-out infinite reverse}
@keyframes orbA{0%,100%{transform:translate(0,0)}50%{transform:translate(28px,18px)}}
@keyframes orbB{0%,100%{transform:translate(0,0)}50%{transform:translate(-35px,-22px)}}

/* Shapes */
.shape{position:absolute;pointer-events:none;animation:shapeFloat 0s ease-in-out infinite}
.sh1{width:58px;height:58px;border:2px solid rgba(201,168,76,.22);border-radius:12px;top:22%;left:7%;animation-duration:6.5s;transform:rotate(18deg)}
.sh2{width:36px;height:36px;background:rgba(201,168,76,.08);border:1px solid rgba(201,168,76,.18);border-radius:8px;top:63%;left:4%;animation-duration:8s;animation-delay:.8s;transform:rotate(45deg)}
.sh3{width:72px;height:72px;border:1.5px solid rgba(255,255,255,.06);border-radius:50%;top:14%;right:7%;animation-duration:7.5s;animation-delay:1.5s}
.sh4{width:22px;height:22px;background:rgba(201,168,76,.18);border-radius:5px;top:42%;left:11%;animation-duration:5s;animation-delay:.3s;transform:rotate(30deg)}
.sh5{width:48px;height:48px;border:1.5px solid rgba(255,255,255,.06);border-radius:10px;bottom:28%;right:5%;animation-duration:9s;animation-delay:1.2s;transform:rotate(45deg)}
.sh6{width:14px;height:14px;background:rgba(201,168,76,.28);border-radius:50%;top:73%;left:18%;animation-duration:4.5s;animation-delay:2s}
.sh7{width:32px;height:32px;border:2px solid rgba(201,168,76,.14);border-radius:8px;top:52%;right:11%;animation-duration:7s;animation-delay:2.8s;transform:rotate(20deg)}
@keyframes shapeFloat{0%,100%{transform:translateY(0) rotate(var(--r,0deg))}50%{transform:translateY(-18px) rotate(calc(var(--r,0deg) + 10deg))}}

.hero-inner{max-width:1200px;margin:0 auto;padding:130px 24px 80px;display:grid;grid-template-columns:1fr 1fr;align-items:center;gap:56px;position:relative;z-index:1;width:100%}

.hero-badge{display:inline-flex;align-items:center;gap:8px;background:rgba(201,168,76,.12);border:1px solid rgba(201,168,76,.28);color:var(--gold-lt);font-size:12.5px;font-weight:700;padding:6px 14px;border-radius:100px;margin-bottom:22px;animation:fadeUp .6s ease both}
.hero-badge span{width:6px;height:6px;background:var(--gold);border-radius:50%;animation:pulse 2s ease infinite}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(1.4)}}

.hero-h1{font-size:clamp(34px,4.8vw,62px);font-weight:900;color:#fff;line-height:1.04;letter-spacing:-2.5px;margin-bottom:20px;animation:fadeUp .6s ease .1s both}
.hero-h1 .gradient-text{background:linear-gradient(135deg,var(--gold) 0%,var(--gold-lt) 60%,#f5d98a 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}

.hero-sub{font-size:17px;color:rgba(255,255,255,.55);line-height:1.75;margin-bottom:34px;max-width:460px;animation:fadeUp .6s ease .2s both}

.hero-btns{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:40px;animation:fadeUp .6s ease .3s both}
.btn-hero-primary{display:inline-flex;align-items:center;gap:9px;background:linear-gradient(135deg,var(--gold),var(--gold-lt));color:var(--navy-dark);font-size:15px;font-weight:700;padding:14px 28px;border-radius:12px;transition:all .25s;box-shadow:0 4px 22px rgba(201,168,76,.38)}
.btn-hero-primary:hover{transform:translateY(-2px);box-shadow:0 8px 32px rgba(201,168,76,.52)}
.btn-hero-sec{display:inline-flex;align-items:center;gap:9px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.14);color:#fff;font-size:15px;font-weight:600;padding:14px 28px;border-radius:12px;transition:all .25s;backdrop-filter:blur(8px)}
.btn-hero-sec:hover{background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.24);transform:translateY(-2px)}

.hero-trust{display:flex;align-items:center;gap:22px;flex-wrap:wrap;animation:fadeUp .6s ease .4s both}
.trust-item{display:flex;align-items:center;gap:6px;color:rgba(255,255,255,.4);font-size:12px;font-weight:500}
.trust-item i{color:var(--gold);font-size:10px}

/* ─── HERO MOCKUP ─────────────────────────────────────── */
.hero-visual{display:flex;justify-content:center;align-items:center;animation:slideRight .8s ease .15s both;position:relative}
.mockup-wrap{position:relative;width:100%;max-width:520px}
.mockup-glow{position:absolute;inset:-40px;background:radial-gradient(ellipse at center,rgba(201,168,76,.12),transparent 70%);border-radius:40px;pointer-events:none}

.hero-mockup{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.09);border-radius:16px;overflow:hidden;box-shadow:0 32px 80px rgba(0,0,0,.42),0 0 0 1px rgba(255,255,255,.04),inset 0 1px 0 rgba(255,255,255,.08);backdrop-filter:blur(18px);animation:mockupFloat 4.5s ease-in-out infinite;transform:perspective(1400px) rotateX(-5deg) rotateY(-12deg);transform-style:preserve-3d}
@keyframes mockupFloat{
  0%,100%{transform:perspective(1400px) rotateX(-5deg) rotateY(-12deg) translateY(0);box-shadow:0 32px 80px rgba(0,0,0,.42)}
  50%{transform:perspective(1400px) rotateX(-5deg) rotateY(-12deg) translateY(-16px);box-shadow:0 48px 100px rgba(0,0,0,.52)}
}

/* Browser bar */
.m-bar{background:rgba(255,255,255,.05);border-bottom:1px solid rgba(255,255,255,.07);padding:9px 14px;display:flex;align-items:center;gap:10px}
.m-dots{display:flex;gap:5px}
.m-dot{width:8px;height:8px;border-radius:50%}
.m-dot.r{background:#ff5f56}.m-dot.y{background:#ffbd2e}.m-dot.g{background:#27c93f}
.m-addr{flex:1;background:rgba(255,255,255,.05);border-radius:4px;padding:3px 10px;font-size:10px;color:rgba(255,255,255,.3);font-family:monospace}

/* Dashboard body */
.m-body{display:flex;height:340px}
.m-sidebar{width:44px;background:rgba(8,18,44,.85);border-right:1px solid rgba(255,255,255,.05);display:flex;flex-direction:column;align-items:center;padding:12px 0;gap:0}
.m-sb-logo{width:28px;height:28px;background:linear-gradient(135deg,var(--gold),var(--gold-lt));border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;color:var(--navy-dark);margin-bottom:14px}
.m-sb-sep{width:22px;height:1px;background:rgba(255,255,255,.06);margin:6px 0}
.m-sbi{width:26px;height:5px;background:rgba(255,255,255,.08);border-radius:3px;margin:4px 0}
.m-sbi.on{background:rgba(201,168,76,.7)}
.m-content{flex:1;background:#f8fafc;padding:12px;display:flex;flex-direction:column;gap:8px;overflow:hidden}

/* Mockup header */
.m-hdr{display:flex;align-items:center;justify-content:space-between}
.m-hdr-title{font-size:10px;font-weight:800;color:#1B3263}
.m-hdr-av{width:20px;height:20px;background:linear-gradient(135deg,var(--gold),var(--gold-lt));border-radius:50%}

/* Stat cards */
.m-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:5px}
.m-sc{background:#fff;border-radius:5px;padding:6px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.m-sc-lbl{font-size:6.5px;color:#64748b;font-weight:600;margin-bottom:2px}
.m-sc-val{font-size:9px;font-weight:900;color:#1B3263;line-height:1}
.m-sc-chg{font-size:6px;color:#22c55e;font-weight:700;margin-top:2px}

/* Chart */
.m-chart-card{background:#fff;border-radius:5px;padding:8px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.m-chart-ttl{font-size:7.5px;font-weight:700;color:#334155;margin-bottom:6px}
.m-chart{display:flex;align-items:flex-end;gap:4px;height:54px}
.m-bar-el{flex:1;border-radius:3px 3px 0 0;animation:barUp 1.2s cubic-bezier(.34,1.56,.64,1) both}
@keyframes barUp{from{transform:scaleY(0);opacity:0}to{transform:scaleY(1);opacity:1}}
.m-chart-labels{display:flex;gap:4px;margin-top:3px}
.m-chart-lbl{flex:1;font-size:5.5px;color:#94a3b8;text-align:center}

/* Table */
.m-table-card{background:#fff;border-radius:5px;padding:6px 8px;box-shadow:0 1px 3px rgba(0,0,0,.06);flex:1}
.m-table-ttl{font-size:7.5px;font-weight:700;color:#334155;margin-bottom:5px}
.m-tr{display:flex;align-items:center;gap:6px;padding:3px 0;border-bottom:1px solid #f1f5f9}
.m-tr:last-child{border:none}
.m-tr-ic{width:14px;height:14px;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:6px;color:#fff;font-weight:700;flex-shrink:0}
.m-tr-name{flex:1;font-size:7px;color:#334155;font-weight:500}
.m-tr-amt{font-size:7px;font-weight:800;color:#1B3263}
.m-tr-badge{font-size:5.5px;padding:1px 5px;border-radius:10px;font-weight:700;margin-left:4px}
.badge-paid{background:#dcfce7;color:#15803d}
.badge-pend{background:#fef9c3;color:#92400e}

/* Floating accent cards */
.acc-card{position:absolute;background:#fff;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.14);padding:10px 14px;display:flex;align-items:center;gap:10px;pointer-events:none;z-index:10;white-space:nowrap}
.acc-1{bottom:-18px;left:-28px;animation:accFloat1 3.2s ease-in-out infinite}
.acc-2{top:24px;right:-24px;animation:accFloat2 3.8s ease-in-out infinite}
@keyframes accFloat1{0%,100%{transform:translateY(0) rotate(-2deg)}50%{transform:translateY(-9px) rotate(-2deg)}}
@keyframes accFloat2{0%,100%{transform:translateY(0) rotate(2deg)}50%{transform:translateY(-11px) rotate(2deg)}}
.acc-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0}
.acc-lbl{font-size:9px;color:#64748b;font-weight:600;line-height:1}
.acc-val{font-size:15px;font-weight:900;color:#1B3263;line-height:1.2}

/* ─── STATS BAR ───────────────────────────────────────── */
.stats-bar{background:#fff;border-bottom:1px solid var(--gray-100);padding:28px 0}
.stats-inner{max-width:1200px;margin:0 auto;padding:0 24px;display:grid;grid-template-columns:repeat(4,1fr);gap:20px;text-align:center}
.stat-item{}
.stat-num{font-size:34px;font-weight:900;color:var(--navy);letter-spacing:-1.5px;line-height:1}
.stat-num .g{color:var(--gold)}
.stat-lbl{font-size:13px;color:var(--gray-500);margin-top:4px;font-weight:500}
@media(min-width:768px){.stat-item+.stat-item{border-left:1px solid var(--gray-200);padding-left:20px}}

/* ─── SECTIONS ────────────────────────────────────────── */
section{padding:92px 0}
.container{max-width:1200px;margin:0 auto;padding:0 24px}
.sec-eye{font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:3px;color:var(--gold);margin-bottom:12px}
.sec-title{font-size:clamp(26px,3.8vw,44px);font-weight:900;color:var(--gray-900);letter-spacing:-1.5px;line-height:1.1;margin-bottom:14px;text-wrap:balance}
.sec-title.light{color:#fff}
.sec-sub{font-size:16.5px;color:var(--gray-500);max-width:520px;line-height:1.75}
.sec-sub.light{color:rgba(255,255,255,.55)}
.sec-hdr{margin-bottom:56px}
.sec-hdr.center{text-align:center}
.sec-hdr.center .sec-sub{margin:0 auto}

/* ─── FEATURES ────────────────────────────────────────── */
.features{background:var(--gray-50)}
.feat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:22px}
.feat-card{background:#fff;border-radius:18px;padding:28px;border:1px solid var(--gray-100);box-shadow:0 2px 8px rgba(0,0,0,.04);transition:all .3s cubic-bezier(.34,1.56,.64,1);position:relative;overflow:hidden;cursor:default}
.feat-card::after{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--navy),var(--gold));transform:scaleX(0);transform-origin:left;transition:transform .35s ease}
.feat-card:hover{transform:translateY(-9px) scale(1.01);box-shadow:0 22px 48px rgba(27,50,99,.11);border-color:rgba(201,168,76,.18)}
.feat-card:hover::after{transform:scaleX(1)}
.feat-icon{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:22px;margin-bottom:18px}
.feat-title{font-size:16.5px;font-weight:700;color:var(--gray-900);margin-bottom:9px}
.feat-desc{font-size:13.5px;color:var(--gray-500);line-height:1.65}
.feat-arr{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:var(--navy);margin-top:16px;transition:gap .2s}
.feat-card:hover .feat-arr{gap:10px}

/* ─── ABOUT ───────────────────────────────────────────── */
.about{background:#fff}
.about-grid{display:grid;grid-template-columns:1fr 1fr;gap:80px;align-items:center}
.about-list{list-style:none;margin-top:28px;display:flex;flex-direction:column;gap:16px}
.about-list li{display:flex;align-items:flex-start;gap:14px;font-size:14.5px;color:var(--gray-700);line-height:1.6}
.al-ic{width:28px;height:28px;border-radius:8px;background:rgba(27,50,99,.07);display:flex;align-items:center;justify-content:center;color:var(--navy);font-size:11px;flex-shrink:0}
.about-vis{position:relative}
.about-main-card{background:var(--navy-dark);border-radius:22px;padding:28px;color:#fff;box-shadow:0 24px 64px rgba(27,50,99,.28);position:relative;overflow:hidden}
.about-main-card::before{content:'';position:absolute;top:-50px;right:-50px;width:180px;height:180px;border-radius:50%;background:rgba(201,168,76,.09)}
.about-main-card::after{content:'';position:absolute;bottom:-40px;left:-40px;width:140px;height:140px;border-radius:50%;background:rgba(255,255,255,.02)}
.amc-lbl{font-size:11px;color:rgba(255,255,255,.45);font-weight:600;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:4px}
.amc-val{font-size:40px;font-weight:900;color:var(--gold);letter-spacing:-2px;line-height:1}
.amc-sub{font-size:13px;color:rgba(255,255,255,.55);margin-top:4px}
.amc-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:16px;position:relative;z-index:1}
.amc-mini{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:14px;text-align:center}
.amc-mini-val{font-size:22px;font-weight:900;color:#fff;letter-spacing:-.5px}
.amc-mini-lbl{font-size:10px;color:rgba(255,255,255,.45);margin-top:2px}
.about-float{position:absolute;top:-16px;right:-16px;background:#fff;border-radius:12px;padding:11px 16px;box-shadow:0 8px 28px rgba(0,0,0,.12);display:flex;align-items:center;gap:10px;animation:accFloat2 3.5s ease-in-out infinite}
.af-ic{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px}
.af-lbl{font-size:10px;color:var(--gray-500);font-weight:600}
.af-val{font-size:14px;font-weight:900;color:var(--gray-900)}

/* ─── HOW IT WORKS ────────────────────────────────────── */
.how{background:var(--navy-dark);position:relative;overflow:hidden}
.how::before{content:'';position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.02) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.02) 1px,transparent 1px);background-size:55px 55px}
.steps-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:32px;position:relative}
.steps-grid::before{content:'';position:absolute;top:35px;left:calc(16.67% + 24px);right:calc(16.67% + 24px);height:2px;background:linear-gradient(90deg,var(--gold),rgba(201,168,76,.2),var(--gold));opacity:.25}
.step-card{text-align:center;position:relative;z-index:1}
.step-num{width:70px;height:70px;border-radius:50%;background:rgba(201,168,76,.1);border:2px solid rgba(201,168,76,.28);display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:900;color:var(--gold);margin:0 auto 20px;position:relative}
.step-num::after{content:'';position:absolute;inset:-5px;border-radius:50%;border:1px solid rgba(201,168,76,.12)}
.step-title{font-size:18px;font-weight:700;color:#fff;margin-bottom:10px}
.step-desc{font-size:14px;color:rgba(255,255,255,.48);line-height:1.7}

/* ─── DEMO ────────────────────────────────────────────── */
.demo{background:var(--gray-50)}
.demo-grid{display:grid;grid-template-columns:1fr 1fr;gap:64px;align-items:center}
.demo-info h3{font-size:28px;font-weight:900;color:var(--gray-900);letter-spacing:-.5px;margin-bottom:14px}
.demo-info p{color:var(--gray-500);font-size:15px;line-height:1.75;margin-bottom:24px}
.demo-perks{list-style:none;display:flex;flex-direction:column;gap:11px}
.demo-perks li{display:flex;align-items:center;gap:10px;font-size:14px;color:var(--gray-700)}
.demo-perks i{color:var(--gold);font-size:11px;width:14px}

/* ─── Forms ───────────────────────────────────────────── */
.form-card{background:#fff;border-radius:20px;padding:32px;box-shadow:0 4px 24px rgba(0,0,0,.06);border:1px solid var(--gray-100)}
.form-card h3{font-size:20px;font-weight:800;color:var(--gray-900);margin-bottom:5px}
.form-card p{font-size:13px;color:var(--gray-500);margin-bottom:22px}
.form-row-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.fg{margin-bottom:14px}
.fg label{display:block;font-size:12.5px;font-weight:600;color:var(--gray-700);margin-bottom:5px}
.fc{width:100%;padding:10px 14px;border:1.5px solid var(--gray-200);border-radius:10px;font-size:14px;font-family:inherit;color:var(--gray-900);background:var(--gray-50);transition:border .2s,box-shadow .2s;outline:none}
.fc:focus{border-color:var(--navy);background:#fff;box-shadow:0 0 0 3px rgba(27,50,99,.07)}
textarea.fc{resize:vertical;min-height:88px}
.btn-submit{width:100%;background:linear-gradient(135deg,var(--navy),var(--navy-mid));color:#fff;border:none;padding:13px;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .25s;display:flex;align-items:center;justify-content:center;gap:8px;margin-top:4px}
.btn-submit:hover{transform:translateY(-2px);box-shadow:0 8px 26px rgba(27,50,99,.28)}
.f-success{text-align:center;padding:22px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;margin-bottom:14px}
.f-success i{font-size:26px;color:#22c55e;display:block;margin-bottom:8px}
.f-success p{color:#15803d;font-weight:600;font-size:15px}
.f-error{background:#fff1f2;border:1px solid #fecdd3;border-radius:10px;padding:10px 14px;font-size:13px;color:#be123c;margin-bottom:14px}
.demo-fee-notice{background:rgba(201,168,76,.12);border:1px solid rgba(201,168,76,.4);border-radius:12px;padding:14px 16px;margin-bottom:18px}
.demo-fee-header{font-size:14px;font-weight:700;color:#92400e;margin-bottom:4px}
.demo-fee-header i{color:#C9A84C;margin-right:6px}
.demo-fee-inst{font-size:13px;color:#78350f;margin:0;line-height:1.5}
.fee-req{font-size:11px;font-weight:600;color:#C9A84C;margin-left:6px;text-transform:uppercase;letter-spacing:.03em}

/* ─── NEWSLETTER ──────────────────────────────────────── */
.newsletter{background:linear-gradient(140deg,var(--navy-dark),var(--navy-mid));position:relative;overflow:hidden}
.newsletter::before{content:'';position:absolute;top:-100px;right:-100px;width:400px;height:400px;border-radius:50%;background:rgba(201,168,76,.06)}
.newsletter::after{content:'';position:absolute;bottom:-80px;left:-80px;width:300px;height:300px;border-radius:50%;background:rgba(255,255,255,.02)}
.nl-inner{text-align:center;position:relative;z-index:1}
.nl-inner h2{font-size:clamp(22px,3.5vw,36px);font-weight:900;color:#fff;letter-spacing:-1px;margin-bottom:12px}
.nl-inner p{color:rgba(255,255,255,.52);font-size:16px;margin-bottom:32px}
.nl-inner .f-success p{color:#15803d;font-size:15px;margin-bottom:0}
.nl-form-row{display:flex;max-width:480px;margin:0 auto;gap:10px;flex-wrap:wrap}
.nl-input{flex:1;min-width:230px;padding:13px 18px;border:1.5px solid rgba(255,255,255,.13);border-radius:12px;background:rgba(255,255,255,.07);color:#fff;font-size:15px;font-family:inherit;outline:none;transition:all .2s;backdrop-filter:blur(8px)}
.nl-input::placeholder{color:rgba(255,255,255,.32)}
.nl-input:focus{border-color:var(--gold);background:rgba(255,255,255,.1)}
.nl-btn{background:linear-gradient(135deg,var(--gold),var(--gold-lt));color:var(--navy-dark);border:none;padding:13px 28px;border-radius:12px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .25s;white-space:nowrap}
.nl-btn:hover{transform:translateY(-2px);box-shadow:0 6px 22px rgba(201,168,76,.42)}

/* ─── CONTACT ─────────────────────────────────────────── */
.contact{background:#fff}
.contact-grid{display:grid;grid-template-columns:1fr 2fr;gap:60px;align-items:start}
.contact-info h3{font-size:22px;font-weight:800;color:var(--gray-900);margin-bottom:12px}
.contact-info > p{color:var(--gray-500);font-size:14px;line-height:1.75;margin-bottom:28px}
.ci-list{display:flex;flex-direction:column;gap:18px}
.ci-item{display:flex;align-items:flex-start;gap:14px}
.ci-ico{width:40px;height:40px;border-radius:10px;background:rgba(27,50,99,.07);display:flex;align-items:center;justify-content:center;color:var(--navy);font-size:15px;flex-shrink:0}
.ci-lbl{font-size:10.5px;color:var(--gray-500);font-weight:700;text-transform:uppercase;letter-spacing:1px}
.ci-val{font-size:14px;color:var(--gray-700);font-weight:500;margin-top:2px}

/* ─── FOOTER ──────────────────────────────────────────── */
.footer{background:var(--navy-deep);color:rgba(255,255,255,.5);padding:64px 0 0}
.footer-grid{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:48px;padding-bottom:48px;border-bottom:1px solid rgba(255,255,255,.06)}
.fl-brand .fl-logo{display:flex;align-items:center;gap:10px;margin-bottom:14px}
.fl-logo-icon{width:36px;height:36px;background:linear-gradient(135deg,var(--gold),var(--gold-lt));border-radius:9px;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:15px;color:var(--navy-dark)}
.fl-logo-text{font-size:18px;font-weight:800;color:#fff}
.fl-brand p{font-size:14px;line-height:1.75;max-width:250px}
.fl-socials{display:flex;gap:9px;margin-top:20px}
.fl-socials a{width:36px;height:36px;border-radius:8px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.07);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.45);font-size:13px;transition:all .2s}
.fl-socials a:hover{background:rgba(201,168,76,.14);border-color:rgba(201,168,76,.28);color:var(--gold)}
.fl-col h4{font-size:11.5px;font-weight:800;color:#fff;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:16px}
.fl-col ul{list-style:none;display:flex;flex-direction:column;gap:10px}
.fl-col ul a{color:rgba(255,255,255,.47);font-size:14px;transition:color .2s}
.fl-col ul a:hover{color:var(--gold-lt)}
.footer-bottom{padding:20px 0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;font-size:13px}
.footer-bottom a{color:rgba(255,255,255,.38);transition:color .2s}
.footer-bottom a:hover{color:var(--gold-lt)}

/* ─── REVEAL ANIMATIONS ───────────────────────────────── */
.reveal{opacity:0;transform:translateY(28px);transition:opacity .65s ease,transform .65s ease}
.reveal.visible{opacity:1;transform:translateY(0)}
.rd1{transition-delay:.08s}.rd2{transition-delay:.16s}.rd3{transition-delay:.24s}
.rd4{transition-delay:.32s}.rd5{transition-delay:.4s}.rd6{transition-delay:.48s}

@keyframes fadeUp{from{opacity:0;transform:translateY(26px)}to{opacity:1;transform:translateY(0)}}
@keyframes slideRight{from{opacity:0;transform:translateX(55px)}to{opacity:1;transform:translateX(0)}}

/* ─── CONTACT INFO LINKS ─────────────────────────────────── */
.ci-link{color:var(--navy);font-weight:600;font-size:14px;transition:color .2s;word-break:break-all}
.ci-link:hover{color:var(--gold)}

/* ─── MOBILE OVERLAY MENU ─────────────────────────────────── */
.mob-overlay{position:fixed;inset:0;z-index:1100;display:flex;flex-direction:column;overflow-y:auto;background:linear-gradient(160deg,rgba(245,250,255,.97) 0%,rgba(235,245,255,.96) 100%);backdrop-filter:blur(32px);-webkit-backdrop-filter:blur(32px);opacity:0;visibility:hidden;transform:translateX(100%);transition:opacity .3s ease,visibility .3s ease,transform .38s cubic-bezier(.4,0,.2,1)}
.mob-overlay.open{opacity:1;visibility:visible;transform:translateX(0)}
.mob-ov-inner{display:flex;flex-direction:column;min-height:100%;padding:20px 24px 40px}
.mob-ov-topbar{display:flex;justify-content:flex-end;margin-bottom:18px}
.mob-ov-close{width:42px;height:42px;border:1.5px solid rgba(27,50,99,.13);border-radius:11px;background:rgba(27,50,99,.05);color:var(--navy);font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .2s;flex-shrink:0}
.mob-ov-close:hover{background:rgba(27,50,99,.1);border-color:rgba(27,50,99,.25)}
.mob-ov-label{font-size:10.5px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:rgba(27,50,99,.38);margin-bottom:14px;padding-left:4px}
.mob-ov-nav{display:flex;flex-direction:column;gap:3px}
.mob-ov-link{display:flex;align-items:center;gap:14px;padding:11px 12px;border-radius:13px;font-size:18px;font-weight:700;color:var(--navy);transition:background .2s,color .2s}
.mob-ov-link:hover{background:rgba(27,50,99,.06)}
.mob-ov-icon{width:38px;height:38px;background:rgba(27,50,99,.07);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:15px;color:var(--navy);flex-shrink:0;transition:all .2s}
.mob-ov-link:hover .mob-ov-icon{background:rgba(201,168,76,.18);color:var(--gold)}
.mob-ov-divider{height:1px;background:rgba(27,50,99,.09);margin:18px 0}
.mob-ov-cta{display:flex;align-items:center;justify-content:center;gap:9px;background:linear-gradient(135deg,var(--gold),var(--gold-lt));color:var(--navy-dark);font-size:15.5px;font-weight:700;padding:15px 24px;border-radius:13px;box-shadow:0 4px 22px rgba(201,168,76,.3);transition:all .25s;letter-spacing:.01em}
.mob-ov-cta:hover{box-shadow:0 8px 32px rgba(201,168,76,.52);transform:translateY(-1px)}
.mob-ov-bottom{margin-top:auto;padding-top:32px}
.mob-ov-secondary{display:flex;gap:22px;flex-wrap:wrap;margin-bottom:18px}
.mob-ov-secondary a{font-size:13px;font-weight:500;color:rgba(27,50,99,.38);transition:color .2s}
.mob-ov-secondary a:hover{color:var(--navy)}
.mob-ov-socials{display:flex;gap:10px}
.mob-ov-soc{width:40px;height:40px;background:var(--navy-deep);border-radius:10px;display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.65);font-size:15px;transition:all .2s}
.mob-ov-soc:hover{background:var(--navy);color:var(--gold-lt)}

/* ─── BACK TO TOP ────────────────────────────────────────── */
.back-to-top{position:fixed;bottom:28px;right:28px;width:46px;height:46px;background:linear-gradient(135deg,var(--gold),var(--gold-lt));color:var(--navy-dark);border:none;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:17px;cursor:pointer;box-shadow:0 4px 18px rgba(201,168,76,.42);opacity:0;visibility:hidden;transform:translateY(12px);transition:opacity .3s,visibility .3s,transform .3s;z-index:998}
.back-to-top.visible{opacity:1;visibility:visible;transform:translateY(0)}
.back-to-top:hover{transform:translateY(-4px);box-shadow:0 10px 30px rgba(201,168,76,.55)}

/* ─── RESPONSIVE ──────────────────────────────────────── */
@media(max-width:1024px){
  .btn-hero-primary i,.btn-hero-sec i{display:none}
  .nav-links{display:none}
  .nav-ham{display:block}
  .nav-logo-text{color:var(--gold)}
  .nav-cta .btn-gold{display:none}
  .hero-inner{grid-template-columns:1fr;text-align:center}
  .hero-visual{display:none}
  .hero-badge,.hero-btns,.hero-trust{justify-content:center}
  .hero-sub{margin:0 auto 34px;max-width:100%}
  .feat-grid{grid-template-columns:repeat(2,1fr)}
  .about-grid,.demo-grid,.contact-grid{grid-template-columns:1fr}
  .about-vis{margin-top:40px}
  .about-float{top:-10px;right:0}
  .steps-grid{grid-template-columns:1fr;gap:28px}
  .steps-grid::before{display:none}
  .footer-grid{grid-template-columns:1fr 1fr;gap:32px}
  .fl-brand{grid-column:1/-1}
}
@media(max-width:640px){
  section{padding:64px 0}
  .nav-cta .btn-ghost{display:none}
  .stats-inner{grid-template-columns:1fr 1fr}
  .feat-grid{grid-template-columns:1fr}
  .feat-card{padding:22px}
  .form-row-2{grid-template-columns:1fr}
  .sec-sub{max-width:100%}
  .demo-info h3{font-size:22px}
  .footer-bottom{flex-direction:column;text-align:center}
  .back-to-top{bottom:20px;right:20px;width:42px;height:42px;font-size:15px}
}
</style>
</head>
<body>

<!-- ═══════════════════════════════════ NAVBAR ═══════════════════════════════ -->
<nav class="nav" id="navbar">
  <div class="nav-inner">
    <a href="#" class="nav-logo">
      <img src="<?= APP_LOGO ?>" alt="<?= APP_NAME ?>" style="height:36px;width:auto;border-radius:9px;object-fit:contain">
      <span class="nav-logo-text"><?= APP_NAME ?></span>
    </a>

    <ul class="nav-links" id="navLinks">
      <li><a href="#features">Features</a></li>
      <li><a href="#about">About</a></li>
      <li><a href="#how">How it Works</a></li>
      <li><a href="#demo">Book Demo</a></li>
      <li><a href="#contact">Contact</a></li>
    </ul>

    <div class="nav-cta">
      <a href="<?= $loginUrl ?>" class="btn-ghost">Login</a>
      <a href="#demo" class="btn-gold">Get Started</a>
    </div>

    <button class="nav-ham" id="navHam" aria-label="Menu"><i class="fa-solid fa-bars"></i></button>
  </div>
</nav>

<!-- ═══════════════════════════════════ HERO ═════════════════════════════════ -->
<section class="hero" id="home">
  <!-- Ambient orbs -->
  <div class="orb orb-1"></div>
  <div class="orb orb-2"></div>
  <div class="orb orb-3"></div>

  <!-- Floating shapes -->
  <div class="shape sh1"></div><div class="shape sh2"></div><div class="shape sh3"></div>
  <div class="shape sh4"></div><div class="shape sh5"></div><div class="shape sh6"></div>
  <div class="shape sh7"></div>

  <div class="hero-inner">
    <!-- Left: Copy -->
    <div class="hero-copy">
      <div class="hero-badge">
        <span></span>
        Built for Growing Businesses Worldwide
      </div>

      <h1 class="hero-h1">
        Manage <span class="gradient-text">Smarter.</span><br>
        Grow <span class="gradient-text">Faster.</span>
      </h1>

      <p class="hero-sub">
        The all-in-one platform that gives growing businesses the tools to track sales, manage inventory, handle finances, and understand their performance — all in one place.
      </p>

      <div class="hero-btns">
        <a href="<?= $loginUrl ?>" class="btn-hero-primary">
          <i class="fa-solid fa-rocket"></i> Start Managing Now
        </a>
        <a href="#demo" class="btn-hero-sec">
          <i class="fa-solid fa-calendar-check"></i> Book a Demo
        </a>
      </div>

      <div class="hero-trust">
        <div class="trust-item"><i class="fa-solid fa-shield-halved"></i> Secure & private</div>
        <div class="trust-item"><i class="fa-solid fa-bolt"></i> Fast setup</div>
        <div class="trust-item"><i class="fa-solid fa-headset"></i> Dedicated support</div>
      </div>
    </div>

    <!-- Right: 3D Mockup -->
    <div class="hero-visual">
      <div class="mockup-wrap">
        <div class="mockup-glow"></div>

        <!-- Accent floating cards -->
        <div class="acc-card acc-1">
          <div class="acc-icon" style="background:#fef9ec"><i class="fa-solid fa-coins" style="color:var(--gold)"></i></div>
          <div>
            <div class="acc-lbl">Revenue Today</div>
            <div class="acc-val">NLE 2,450</div>
          </div>
        </div>
        <div class="acc-card acc-2">
          <div class="acc-icon" style="background:#f0fdf4"><i class="fa-solid fa-arrow-trend-up" style="color:#22c55e"></i></div>
          <div>
            <div class="acc-lbl">Growth</div>
            <div class="acc-val" style="color:#15803d">+12%</div>
          </div>
        </div>

        <!-- Dashboard mockup -->
        <div class="hero-mockup" id="mockupCard">
          <!-- Browser bar -->
          <div class="m-bar">
            <div class="m-dots">
              <div class="m-dot r"></div><div class="m-dot y"></div><div class="m-dot g"></div>
            </div>
            <div class="m-addr">stocqify.com/dashboard</div>
          </div>

          <!-- Body -->
          <div class="m-body">
            <!-- Sidebar -->
            <div class="m-sidebar">
              <div class="m-sb-logo">S</div>
              <div class="m-sb-sep"></div>
              <div class="m-sbi on"></div>
              <div class="m-sbi"></div><div class="m-sbi"></div><div class="m-sbi"></div>
              <div class="m-sbi"></div><div class="m-sbi"></div>
              <div class="m-sb-sep"></div>
              <div class="m-sbi"></div><div class="m-sbi"></div>
            </div>

            <!-- Content -->
            <div class="m-content">
              <!-- Header -->
              <div class="m-hdr">
                <div class="m-hdr-title">Dashboard</div>
                <div style="display:flex;align-items:center;gap:6px">
                  <div style="width:28px;height:5px;background:#e2e8f0;border-radius:3px"></div>
                  <div class="m-hdr-av"></div>
                </div>
              </div>

              <!-- Stats -->
              <div class="m-stats">
                <div class="m-sc"><div class="m-sc-lbl">Revenue</div><div class="m-sc-val">24.5K</div><div class="m-sc-chg">↑ 12%</div></div>
                <div class="m-sc"><div class="m-sc-lbl">Orders</div><div class="m-sc-val">148</div><div class="m-sc-chg">↑ 8%</div></div>
                <div class="m-sc"><div class="m-sc-lbl">Products</div><div class="m-sc-val">62</div><div class="m-sc-chg">↑ 3</div></div>
                <div class="m-sc"><div class="m-sc-lbl">Customers</div><div class="m-sc-val">340</div><div class="m-sc-chg">↑ 5%</div></div>
              </div>

              <!-- Chart -->
              <div class="m-chart-card">
                <div class="m-chart-ttl">Sales — Last 7 Days</div>
                <div class="m-chart">
                  <?php
                  $bars=[['38%','#cbd5e1'],['58%','#1B3263'],['44%','#cbd5e1'],['78%','#C9A84C'],['62%','#1B3263'],['85%','#1B3263'],['70%','#C9A84C']];
                  foreach($bars as $i=>[$h,$c]):?>
                  <div class="m-bar-el" style="height:<?=$h?>;background:<?=$c?>;animation-delay:<?=$i*.1?>s"></div>
                  <?php endforeach;?>
                </div>
                <div class="m-chart-labels">
                  <?php foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $d):?>
                  <div class="m-chart-lbl"><?=$d?></div>
                  <?php endforeach;?>
                </div>
              </div>

              <!-- Recent transactions -->
              <div class="m-table-card">
                <div class="m-table-ttl">Recent Sales</div>
                <div class="m-tr">
                  <div class="m-tr-ic" style="background:#1B3263">R</div>
                  <div class="m-tr-name">Rice & Grains (x5)</div>
                  <div class="m-tr-amt">NLE 450</div>
                  <span class="m-tr-badge badge-paid">Paid</span>
                </div>
                <div class="m-tr">
                  <div class="m-tr-ic" style="background:#C9A84C">M</div>
                  <div class="m-tr-name">Mobile Recharge</div>
                  <div class="m-tr-amt">NLE 120</div>
                  <span class="m-tr-badge badge-paid">Paid</span>
                </div>
                <div class="m-tr">
                  <div class="m-tr-ic" style="background:#7c3aed">S</div>
                  <div class="m-tr-name">School Bags (x3)</div>
                  <div class="m-tr-amt">NLE 890</div>
                  <span class="m-tr-badge badge-pend">Pending</span>
                </div>
              </div>
            </div><!-- /m-content -->
          </div><!-- /m-body -->
        </div><!-- /hero-mockup -->
      </div><!-- /mockup-wrap -->
    </div><!-- /hero-visual -->
  </div><!-- /hero-inner -->
</section>

<!-- ═══════════════════════════════════ STATS BAR ════════════════════════════ -->
<div class="stats-bar">
  <div class="stats-inner">
    <div class="stat-item reveal"><div class="stat-num">500<span class="g">+</span></div><div class="stat-lbl">Businesses onboarded</div></div>
    <div class="stat-item reveal rd1"><div class="stat-num">50<span class="g">K+</span></div><div class="stat-lbl">Transactions tracked</div></div>
    <div class="stat-item reveal rd2"><div class="stat-num">99<span class="g">%</span></div><div class="stat-lbl">Uptime reliability</div></div>
    <div class="stat-item reveal rd3"><div class="stat-num">24<span class="g">/7</span></div><div class="stat-lbl">Dedicated support</div></div>
  </div>
</div>

<!-- ═══════════════════════════════════ FEATURES ═════════════════════════════ -->
<section class="features" id="features">
  <div class="container">
    <div class="sec-hdr center reveal">
      <div class="sec-eye">Platform Features</div>
      <h2 class="sec-title">Everything your business needs</h2>
      <p class="sec-sub">From the market stall to the office — Stocqify gives you professional tools designed for how modern businesses actually work.</p>
    </div>

    <div class="feat-grid">
      <?php
      $feats=[
        ['fa-chart-line','#eef2ff','#3b82f6','Sales Management','Record every sale, issue invoices, and track payments with ease — from single items to bulk orders.'],
        ['fa-boxes-stacked','#f0fdf4','#22c55e','Inventory Control','Know exactly what you have in stock. Get low-stock alerts before you run out and lose sales.'],
        ['fa-coins','#fffbeb','#f59e0b','Finance & Debts','Track who owes you money, log expenses, record income, and stay on top of your cash flow.'],
        ['fa-chart-bar','#faf5ff','#8b5cf6','Analytics & Reports','Visual sales reports, financial summaries, and smart alerts that help you spot trends fast.'],
        ['fa-people-arrows','#fff1f2','#ef4444','Customer Management','Build your customer database, view purchase history, and manage debts per customer.'],
        ['fa-headset','#f0f9ff','#0ea5e9','Support System','Get help when you need it. Submit requests directly inside the platform and track responses.'],
      ];
      foreach($feats as $i=>[$icon,$bg,$color,$title,$desc]):?>
      <div class="feat-card reveal rd<?=$i+1?>">
        <div class="feat-icon" style="background:<?=$bg?>;color:<?=$color?>"><i class="fa-solid <?=$icon?>"></i></div>
        <div class="feat-title"><?=$title?></div>
        <p class="feat-desc"><?=$desc?></p>
        <span class="feat-arr">Learn more <i class="fa-solid fa-arrow-right"></i></span>
      </div>
      <?php endforeach;?>
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════ ABOUT ════════════════════════════════ -->
<section class="about" id="about">
  <div class="container">
    <div class="about-grid">
      <div class="reveal">
        <div class="sec-eye">About Stocqify</div>
        <h2 class="sec-title">Built for businesses everywhere, by people who understand the hustle</h2>
        <p class="sec-sub" style="margin-top:14px">We built Stocqify after seeing too many hardworking business owners struggle with paper records, missed debts, and no clear picture of their finances. You deserve better tools.</p>
        <ul class="about-list">
          <li><div class="al-ic"><i class="fa-solid fa-check"></i></div><span>Works for both product-based and service-based businesses</span></li>
          <li><div class="al-ic"><i class="fa-solid fa-check"></i></div><span>Multi-currency support — use the currency that suits your business</span></li>
          <li><div class="al-ic"><i class="fa-solid fa-check"></i></div><span>Multi-user access — give your team the right level of access</span></li>
          <li><div class="al-ic"><i class="fa-solid fa-check"></i></div><span>Secure cloud storage — never lose your business data again</span></li>
          <li><div class="al-ic"><i class="fa-solid fa-check"></i></div><span>Admin portal to manage multiple businesses from one account</span></li>
        </ul>
      </div>

      <div class="about-vis reveal rd2">
        <div class="about-float">
          <div class="af-ic" style="background:#fef9ec"><i class="fa-solid fa-award" style="color:var(--gold)"></i></div>
          <div>
            <div class="af-lbl">Trusted Platform</div>
            <div class="af-val">Globally Trusted</div>
          </div>
        </div>
        <div class="about-main-card">
          <div style="position:relative;z-index:1">
            <div class="amc-lbl">Active Businesses</div>
            <div class="amc-val">500+</div>
            <div class="amc-sub">Across multiple countries and industries</div>
            <div class="amc-grid">
              <div class="amc-mini"><div class="amc-mini-val">50K+</div><div class="amc-mini-lbl">Transactions</div></div>
              <div class="amc-mini"><div class="amc-mini-val">98%</div><div class="amc-mini-lbl">Satisfaction</div></div>
              <div class="amc-mini"><div class="amc-mini-val">Multi</div><div class="amc-mini-lbl">Currency</div></div>
              <div class="amc-mini"><div class="amc-mini-val">24/7</div><div class="amc-mini-lbl">Support</div></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════ HOW IT WORKS ═════════════════════════ -->
<section class="how" id="how">
  <div class="container">
    <div class="sec-hdr center reveal">
      <div class="sec-eye">How It Works</div>
      <h2 class="sec-title light">Three simple steps to get started</h2>
      <p class="sec-sub light">We handle the setup so you can focus on what matters — running your business.</p>
    </div>

    <div class="steps-grid">
      <?php
      $steps=[
        ['1','fa-comments','Contact Our Team','Reach out by submitting a demo request or contact form. Tell us about your business and what you need.'],
        ['2','fa-gears','We Set You Up','Our team creates your business account and user credentials within 24 hours — no technical effort from you.'],
        ['3','fa-rocket','Login &amp; Start Growing','Receive your login details, sign in, and immediately start tracking sales, inventory, and finances.'],
      ];
      foreach($steps as $i=>[$num,$icon,$title,$desc]):?>
      <div class="step-card reveal rd<?=$i+1?>">
        <div class="step-num">
          <i class="fa-solid <?=$icon?>"></i>
        </div>
        <h3 class="step-title"><?=$title?></h3>
        <p class="step-desc"><?=$desc?></p>
      </div>
      <?php endforeach;?>
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════ DEMO ═════════════════════════════════ -->
<section class="demo" id="demo">
  <div class="container">
    <div class="demo-grid">
      <div class="reveal">
        <div class="sec-eye">Book a Demo</div>
        <h3>See Stocqify in action — live, for free</h3>
        <p>Get a personalised walkthrough of the platform with one of our team members. We'll show you exactly how it works for your type of business.</p>
        <ul class="demo-perks">
          <li><i class="fa-solid fa-circle-check"></i> 30-minute live session</li>
          <li><i class="fa-solid fa-circle-check"></i> Tailored to your business type</li>
          <li><i class="fa-solid fa-circle-check"></i> No commitment required</li>
          <li><i class="fa-solid fa-circle-check"></i> Q&amp;A session included</li>
          <li><i class="fa-solid fa-circle-check"></i> Available in multiple languages</li>
        </ul>
      </div>

      <div class="reveal rd2">
        <div class="form-card">
          <h3>Book a Demo with our team</h3>
          <p>Fill in your details and we'll get in touch within 24 hours.</p>

          <?php if(!empty($fSuccess['demo'])):?>
          <div class="f-success"><i class="fa-solid fa-circle-check"></i><p>Thank you! We'll contact you within 24 hours to confirm your demo slot.</p></div>
          <?php else:?>

          <?php if(!empty($fErrors['demo'])):?><div class="f-error"><i class="fa-solid fa-circle-xmark"></i> <?=htmlspecialchars($fErrors['demo'])?></div><?php endif;?>

          <?php if(($__gs['demo_fee_enabled']??'0')==='1' && ($__gs['demo_fee_amount']??'')):
            $__dAmt  = number_format((float)$__gs['demo_fee_amount'],2);
            $__dCurr = htmlspecialchars($__gs['demo_fee_currency'] ?? 'NLE');
            $__dInst = htmlspecialchars($__gs['demo_payment_instructions'] ?? '');
          ?>
          <div class="demo-fee-notice">
            <div class="demo-fee-header"><i class="fa-solid fa-coins"></i> Demo Booking Fee: <?=$__dCurr?> <?=$__dAmt?></div>
            <?php if($__dInst):?><p class="demo-fee-inst"><?=nl2br($__dInst)?></p><?php endif;?>
          </div>
          <?php endif;?>

          <form method="POST" action="#demo">
            <input type="hidden" name="_action" value="demo">
            <div class="form-row-2">
              <div class="fg"><label>Full Name *</label><input class="fc" type="text" name="d_name" placeholder="Aminata Kamara" value="<?=htmlspecialchars($_POST['d_name']??'')?>"></div>
              <div class="fg"><label>Email Address *</label><input class="fc" type="email" name="d_email" placeholder="you@example.com" value="<?=htmlspecialchars($_POST['d_email']??'')?>"></div>
            </div>
            <div class="form-row-2">
              <div class="fg"><label>Business Name</label><input class="fc" type="text" name="d_biz" placeholder="Your Business Name" value="<?=htmlspecialchars($_POST['d_biz']??'')?>"></div>
              <div class="fg"><label>Phone Number</label><input class="fc" type="tel" name="d_phone" placeholder="+232 76 000000" value="<?=htmlspecialchars($_POST['d_phone']??'')?>"></div>
            </div>
            <div class="form-row-2">
              <div class="fg">
                <label>Country</label>
                <select class="fc" name="d_country">
                  <option value="">Select your country…</option>
                  <optgroup label="West Africa">
                    <option value="Sierra Leone" <?=($_POST['d_country']??'')==='Sierra Leone'?'selected':''?>>Sierra Leone</option>
                    <option value="Ghana" <?=($_POST['d_country']??'')==='Ghana'?'selected':''?>>Ghana</option>
                    <option value="Nigeria" <?=($_POST['d_country']??'')==='Nigeria'?'selected':''?>>Nigeria</option>
                    <option value="Liberia" <?=($_POST['d_country']??'')==='Liberia'?'selected':''?>>Liberia</option>
                    <option value="Guinea" <?=($_POST['d_country']??'')==='Guinea'?'selected':''?>>Guinea</option>
                    <option value="Senegal" <?=($_POST['d_country']??'')==='Senegal'?'selected':''?>>Senegal</option>
                    <option value="Gambia" <?=($_POST['d_country']??'')==='Gambia'?'selected':''?>>Gambia</option>
                    <option value="Côte d'Ivoire" <?=($_POST['d_country']??'')==="Côte d'Ivoire"?'selected':''?>>Côte d'Ivoire</option>
                    <option value="Mali" <?=($_POST['d_country']??'')==='Mali'?'selected':''?>>Mali</option>
                    <option value="Burkina Faso" <?=($_POST['d_country']??'')==='Burkina Faso'?'selected':''?>>Burkina Faso</option>
                  </optgroup>
                  <optgroup label="East Africa">
                    <option value="Kenya" <?=($_POST['d_country']??'')==='Kenya'?'selected':''?>>Kenya</option>
                    <option value="Uganda" <?=($_POST['d_country']??'')==='Uganda'?'selected':''?>>Uganda</option>
                    <option value="Tanzania" <?=($_POST['d_country']??'')==='Tanzania'?'selected':''?>>Tanzania</option>
                    <option value="Rwanda" <?=($_POST['d_country']??'')==='Rwanda'?'selected':''?>>Rwanda</option>
                    <option value="Ethiopia" <?=($_POST['d_country']??'')==='Ethiopia'?'selected':''?>>Ethiopia</option>
                  </optgroup>
                  <optgroup label="Southern Africa">
                    <option value="South Africa" <?=($_POST['d_country']??'')==='South Africa'?'selected':''?>>South Africa</option>
                    <option value="Zimbabwe" <?=($_POST['d_country']??'')==='Zimbabwe'?'selected':''?>>Zimbabwe</option>
                    <option value="Zambia" <?=($_POST['d_country']??'')==='Zambia'?'selected':''?>>Zambia</option>
                  </optgroup>
                  <optgroup label="Rest of World">
                    <option value="United Kingdom" <?=($_POST['d_country']??'')==='United Kingdom'?'selected':''?>>United Kingdom</option>
                    <option value="United States" <?=($_POST['d_country']??'')==='United States'?'selected':''?>>United States</option>
                    <option value="Canada" <?=($_POST['d_country']??'')==='Canada'?'selected':''?>>Canada</option>
                    <option value="Australia" <?=($_POST['d_country']??'')==='Australia'?'selected':''?>>Australia</option>
                    <option value="France" <?=($_POST['d_country']??'')==='France'?'selected':''?>>France</option>
                    <option value="Germany" <?=($_POST['d_country']??'')==='Germany'?'selected':''?>>Germany</option>
                    <option value="Portugal" <?=($_POST['d_country']??'')==='Portugal'?'selected':''?>>Portugal</option>
                    <option value="Other" <?=($_POST['d_country']??'')==='Other'?'selected':''?>>Other</option>
                  </optgroup>
                </select>
              </div>
              <div class="fg">
                <label>Preferred Language</label>
                <select class="fc" name="d_lang">
                  <option value="">Select language…</option>
                  <option value="English" <?=($_POST['d_lang']??'')==='English'?'selected':''?>>English</option>
                  <option value="French" <?=($_POST['d_lang']??'')==='French'?'selected':''?>>French</option>
                  <option value="Arabic" <?=($_POST['d_lang']??'')==='Arabic'?'selected':''?>>Arabic</option>
                  <option value="Krio" <?=($_POST['d_lang']??'')==='Krio'?'selected':''?>>Krio</option>
                  <option value="Hausa" <?=($_POST['d_lang']??'')==='Hausa'?'selected':''?>>Hausa</option>
                  <option value="Yoruba" <?=($_POST['d_lang']??'')==='Yoruba'?'selected':''?>>Yoruba</option>
                  <option value="Swahili" <?=($_POST['d_lang']??'')==='Swahili'?'selected':''?>>Swahili</option>
                  <option value="Portuguese" <?=($_POST['d_lang']??'')==='Portuguese'?'selected':''?>>Portuguese</option>
                  <option value="Spanish" <?=($_POST['d_lang']??'')==='Spanish'?'selected':''?>>Spanish</option>
                </select>
              </div>
            </div>
            <?php if(($__gs['demo_fee_enabled']??'0')==='1' && ($__gs['demo_fee_amount']??'')):?>
            <div class="fg">
              <label>Payment Reference <span class="fee-req">*required</span></label>
              <input class="fc" type="text" name="d_pref" value="<?=htmlspecialchars($_POST['d_pref']??'')?>" placeholder="e.g. OM-123456789 or transaction ID">
            </div>
            <?php endif;?>
            <div class="fg"><label>What do you want to see?</label><textarea class="fc" name="d_msg" placeholder="e.g. I run a retail shop and want to see how inventory tracking works..."><?=htmlspecialchars($_POST['d_msg']??'')?></textarea></div>
            <button type="submit" class="btn-submit"><i class="fa-solid fa-calendar-check"></i> Book My Free Demo</button>
          </form>
          <?php endif;?>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════ NEWSLETTER ═══════════════════════════ -->
<section class="newsletter">
  <div class="container">
    <div class="nl-inner reveal">
      <div class="sec-eye" style="color:var(--gold-lt)">Stay Updated</div>
      <h2>Get business tips &amp; platform updates</h2>
      <p>Join hundreds of business owners getting monthly insights, tips, and platform updates delivered to their inbox.</p>

      <?php if(!empty($fSuccess['nl'])):?>
      <div class="f-success" style="max-width:420px;margin:0 auto"><i class="fa-solid fa-circle-check"></i><p>You're subscribed! Welcome aboard.</p></div>
      <?php else:?>
      <?php if(!empty($fErrors['nl'])):?><p style="color:var(--gold-lt);font-size:13px;margin-bottom:12px"><?=htmlspecialchars($fErrors['nl'])?></p><?php endif;?>
      <form method="POST" class="nl-form-row">
        <input type="hidden" name="_action" value="newsletter">
        <input class="nl-input" type="email" name="nl_email" placeholder="Enter your email address…" required>
        <button type="submit" class="nl-btn"><i class="fa-solid fa-paper-plane"></i> Subscribe</button>
      </form>
      <p style="margin-top:14px;font-size:12px;opacity:.4">No spam. Unsubscribe anytime.</p>
      <?php endif;?>
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════ CONTACT ══════════════════════════════ -->
<section class="contact" id="contact">
  <div class="container">
    <div class="contact-grid">
      <div class="reveal">
        <div class="sec-eye">Contact Us</div>
        <h3>We'd love to hear from you</h3>
        <p>Have a question, partnership enquiry, or need help? Our team is here and happy to assist.</p>
        <?php
        $__ce = $__gs['contact_email']    ?? '';
        $__cp = $__gs['contact_phone']    ?? '';
        $__cw = $__gs['contact_whatsapp'] ?? '';
        $__cl = $__gs['contact_location'] ?? '';
        $__ch = $__gs['contact_hours']    ?? '';
        ?>
        <div class="ci-list">
          <?php if ($__ce): ?>
          <div class="ci-item">
            <div class="ci-ico"><i class="fa-solid fa-envelope"></i></div>
            <div><div class="ci-lbl">Email</div><div class="ci-val"><a href="mailto:<?=htmlspecialchars($__ce)?>" class="ci-link"><?=htmlspecialchars($__ce)?></a></div></div>
          </div>
          <?php endif; ?>
          <?php if ($__cp || $__cw): $__tel = $__cp ?: $__cw; ?>
          <div class="ci-item">
            <div class="ci-ico"><i class="fa-solid fa-phone"></i></div>
            <div><div class="ci-lbl">Phone<?= $__cw ? ' / WhatsApp' : '' ?></div><div class="ci-val">
              <?php if ($__cw): ?><a href="https://wa.me/<?=preg_replace('/[^0-9]/','',$__cw)?>" class="ci-link"><?=htmlspecialchars($__tel)?></a>
              <?php else: ?><a href="tel:<?=htmlspecialchars($__cp)?>" class="ci-link"><?=htmlspecialchars($__cp)?></a><?php endif; ?>
            </div></div>
          </div>
          <?php endif; ?>
          <?php if ($__cl): ?>
          <div class="ci-item">
            <div class="ci-ico"><i class="fa-solid fa-location-dot"></i></div>
            <div><div class="ci-lbl">Location</div><div class="ci-val"><?=htmlspecialchars($__cl)?></div></div>
          </div>
          <?php endif; ?>
          <?php if ($__ch): ?>
          <div class="ci-item">
            <div class="ci-ico"><i class="fa-solid fa-clock"></i></div>
            <div><div class="ci-lbl">Support Hours</div><div class="ci-val"><?=htmlspecialchars($__ch)?></div></div>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="reveal rd2">
        <div class="form-card">
          <h3>Send a Message</h3>
          <p>We usually respond within a few hours on business days.</p>

          <?php if(!empty($fSuccess['contact'])):?>
          <div class="f-success"><i class="fa-solid fa-circle-check"></i><p>Message received! We'll get back to you shortly.</p></div>
          <?php else:?>
          <?php if(!empty($fErrors['contact'])):?><div class="f-error"><i class="fa-solid fa-circle-xmark"></i> <?=htmlspecialchars($fErrors['contact'])?></div><?php endif;?>
          <form method="POST" action="#contact">
            <input type="hidden" name="_action" value="contact">
            <div class="form-row-2">
              <div class="fg"><label>Your Name *</label><input class="fc" type="text" name="c_name" placeholder="Aminata Kamara" value="<?=htmlspecialchars($_POST['c_name']??'')?>"></div>
              <div class="fg"><label>Email Address *</label><input class="fc" type="email" name="c_email" placeholder="you@example.com" value="<?=htmlspecialchars($_POST['c_email']??'')?>"></div>
            </div>
            <div class="fg"><label>Subject</label><input class="fc" type="text" name="c_subj" placeholder="What is this about?" value="<?=htmlspecialchars($_POST['c_subj']??'')?>"></div>
            <div class="fg"><label>Message *</label><textarea class="fc" name="c_msg" placeholder="Tell us how we can help…" rows="5"><?=htmlspecialchars($_POST['c_msg']??'')?></textarea></div>
            <button type="submit" class="btn-submit"><i class="fa-solid fa-paper-plane"></i> Send Message</button>
          </form>
          <?php endif;?>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════ FOOTER ═══════════════════════════════ -->
<footer class="footer">
  <div class="container">
    <div class="footer-grid">
      <div class="fl-brand">
        <div class="fl-logo">
          <img src="<?= APP_LOGO ?>" alt="<?= APP_NAME ?>" style="height:36px;width:auto;border-radius:9px;object-fit:contain">
          <span class="fl-logo-text"><?= APP_NAME ?></span>
        </div>
        <p>The all-in-one business management platform empowering growing businesses worldwide. <?= APP_TAGLINE ?></p>
        <div class="fl-socials">
          <?php foreach ([
            ['social_facebook',  'fa-brands fa-facebook-f',  'Facebook'],
            ['social_twitter',   'fa-brands fa-x-twitter',   'Twitter/X'],
            ['social_instagram', 'fa-brands fa-instagram',   'Instagram'],
            ['social_whatsapp',  'fa-brands fa-whatsapp',    'WhatsApp'],
            ['social_linkedin',  'fa-brands fa-linkedin-in', 'LinkedIn'],
          ] as [$sKey, $sIcon, $sTitle]):
            $sHref = $__gs[$sKey] ?? '';
          ?>
          <a href="<?= $sHref ? htmlspecialchars($sHref) : '#' ?>" title="<?= $sTitle ?>"
             <?= $sHref ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
            <i class="<?= $sIcon ?>"></i>
          </a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="fl-col">
        <h4>Platform</h4>
        <ul>
          <li><a href="#features">Features</a></li>
          <li><a href="#how">How it Works</a></li>
          <li><a href="#demo">Book a Demo</a></li>
          <li><a href="<?= $loginUrl ?>">Login</a></li>
        </ul>
      </div>

      <div class="fl-col">
        <h4>Company</h4>
        <ul>
          <li><a href="#about">About Us</a></li>
          <li><a href="#contact">Contact</a></li>
          <li><a href="#">Blog</a></li>
          <li><a href="#">Careers</a></li>
        </ul>
      </div>

      <div class="fl-col">
        <h4>Support</h4>
        <ul>
          <li><a href="#">Help Centre</a></li>
          <li><a href="#">Privacy Policy</a></li>
          <li><a href="#">Terms of Service</a></li>
          <li><a href="#">Security</a></li>
        </ul>
      </div>
    </div>

    <div class="footer-bottom">
      <span>&copy; <?= date('Y') ?> <?= APP_NAME ?>. All rights reserved.</span>
      <div style="display:flex;gap:20px">
        <a href="#">Privacy Policy</a>
        <a href="#">Terms</a>
        <a href="#">Cookies</a>
      </div>
      <span>Made with <i class="fa-solid fa-heart" style="color:var(--gold)"></i> by the <?= APP_NAME ?> team</span>
    </div>
  </div>
</footer>

<!-- ═══════════════════════════════════ MOBILE OVERLAY ══════════════════════ -->
<div class="mob-overlay" id="mobOverlay" role="dialog" aria-modal="true" aria-label="Navigation menu">
  <div class="mob-ov-inner">

    <!-- Close button -->
    <div class="mob-ov-topbar">
      <button class="mob-ov-close" id="mobClose" aria-label="Close menu">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <!-- Menu label -->
    <div class="mob-ov-label">Menu</div>

    <!-- Nav links with icons -->
    <nav class="mob-ov-nav">
      <a href="#home" class="mob-ov-link">
        <span class="mob-ov-icon"><i class="fa-solid fa-house"></i></span> Home
      </a>
      <a href="#features" class="mob-ov-link">
        <span class="mob-ov-icon"><i class="fa-solid fa-layer-group"></i></span> Features
      </a>
      <a href="#about" class="mob-ov-link">
        <span class="mob-ov-icon"><i class="fa-solid fa-circle-info"></i></span> About Us
      </a>
      <a href="#how" class="mob-ov-link">
        <span class="mob-ov-icon"><i class="fa-solid fa-gears"></i></span> How it Works
      </a>
      <a href="#demo" class="mob-ov-link">
        <span class="mob-ov-icon"><i class="fa-solid fa-calendar-check"></i></span> Book Demo
      </a>
      <a href="#contact" class="mob-ov-link">
        <span class="mob-ov-icon"><i class="fa-solid fa-paper-plane"></i></span> Contact
      </a>
    </nav>

    <!-- Divider -->
    <div class="mob-ov-divider"></div>

    <!-- Get Started CTA -->
    <a href="#demo" class="mob-ov-cta">
      <i class="fa-solid fa-rocket"></i> Get Started Free
    </a>

    <!-- Bottom: legal + social -->
    <div class="mob-ov-bottom">
      <div class="mob-ov-secondary">
        <a href="#">Privacy Policy</a>
        <a href="#">Terms of Service</a>
      </div>
      <?php
      $__mobSocials = [
        ['social_facebook',  'fa-brands fa-facebook-f'],
        ['social_twitter',   'fa-brands fa-x-twitter'],
        ['social_instagram', 'fa-brands fa-instagram'],
        ['social_whatsapp',  'fa-brands fa-whatsapp'],
        ['social_linkedin',  'fa-brands fa-linkedin-in'],
      ];
      $__hasSocial = array_filter($__mobSocials, fn($s) => !empty($__gs[$s[0]]));
      if ($__hasSocial): ?>
      <div class="mob-ov-socials">
        <?php foreach ($__mobSocials as [$__sk, $__si]):
          if (empty($__gs[$__sk])) continue; ?>
        <a href="<?= htmlspecialchars($__gs[$__sk]) ?>" class="mob-ov-soc" target="_blank" rel="noopener noreferrer">
          <i class="<?= $__si ?>"></i>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<!-- ═══════════════════════════════════ BACK TO TOP ══════════════════════════ -->
<button class="back-to-top" id="backToTop" aria-label="Back to top">
  <i class="fa-solid fa-arrow-up"></i>
</button>

<!-- ═══════════════════════════════════ SCRIPTS ══════════════════════════════ -->
<script>
(function () {
  /* Navbar scroll */
  const nav = document.getElementById('navbar');
  window.addEventListener('scroll', () => {
    nav.classList.toggle('scrolled', window.scrollY > 30);
  }, { passive: true });

  /* Mobile overlay menu */
  const mobOverlay = document.getElementById('mobOverlay');
  const mobClose   = document.getElementById('mobClose');

  function openMobMenu() {
    mobOverlay.classList.add('open');
    document.body.style.overflow = 'hidden';
  }
  function closeMobMenu() {
    mobOverlay.classList.remove('open');
    document.body.style.overflow = '';
  }

  document.getElementById('navHam').addEventListener('click', openMobMenu);
  mobClose.addEventListener('click', closeMobMenu);

  /* Close on any overlay nav link or CTA click */
  document.querySelectorAll('.mob-ov-link, .mob-ov-cta').forEach(a => {
    a.addEventListener('click', closeMobMenu);
  });

  /* Close on Escape key */
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && mobOverlay.classList.contains('open')) closeMobMenu();
  });

  /* Scroll reveal */
  const io = new IntersectionObserver((entries) => {
    entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); io.unobserve(e.target); } });
  }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
  document.querySelectorAll('.reveal').forEach(el => io.observe(el));

  /* 3D mouse parallax on mockup */
  const mockup = document.getElementById('mockupCard');
  if (mockup) {
    const heroEl = document.querySelector('.hero');
    heroEl.addEventListener('mousemove', (e) => {
      const rect = heroEl.getBoundingClientRect();
      const cx = rect.left + rect.width / 2;
      const cy = rect.top + rect.height / 2;
      const dx = (e.clientX - cx) / rect.width;
      const dy = (e.clientY - cy) / rect.height;
      mockup.style.transform = `perspective(1400px) rotateX(${-5 + dy * 4}deg) rotateY(${-12 + dx * 6}deg)`;
      mockup.style.animation = 'none';
    }, { passive: true });
    heroEl.addEventListener('mouseleave', () => {
      mockup.style.animation = '';
      mockup.style.transform = '';
    });
  }

  /* Smooth active nav highlight on scroll */
  const sections = document.querySelectorAll('section[id], div[id="home"]');
  const navLinks = document.querySelectorAll('.nav-links a');
  window.addEventListener('scroll', () => {
    let cur = '';
    sections.forEach(s => { if (window.scrollY >= s.offsetTop - 120) cur = s.id; });
    navLinks.forEach(a => {
      a.style.color = a.getAttribute('href') === '#' + cur ? '#fff' : '';
      a.style.background = a.getAttribute('href') === '#' + cur ? 'rgba(255,255,255,.1)' : '';
    });
  }, { passive: true });

  /* Back to top */
  const btt = document.getElementById('backToTop');
  window.addEventListener('scroll', () => {
    btt.classList.toggle('visible', window.scrollY > 380);
  }, { passive: true });
  btt.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));

  /* Counter animation on stats */
  const counters = document.querySelectorAll('.stat-num');
  const cio = new IntersectionObserver(entries => {
    entries.forEach(e => {
      if (!e.isIntersecting) return;
      const el = e.target;
      const target = parseInt(el.dataset.target || '0');
      if (!target) return;
      let n = 0; const step = target / 60;
      const tick = () => { n = Math.min(n + step, target); el.dataset.live = Math.floor(n); if (n < target) requestAnimationFrame(tick); };
      requestAnimationFrame(tick);
      cio.unobserve(el);
    });
  }, { threshold: 0.5 });
  counters.forEach(c => cio.observe(c));
})();
</script>
</body>
</html>
