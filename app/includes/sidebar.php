<?php
$currentPath = $_SERVER['PHP_SELF'];
$user        = currentUser();
$baseUrl     = APP_URL;

// Always pull a fresh business name, currency, and type from the DB; force logout if deactivated
if (!empty($user['business_id'])) {
    try { getDB()->exec("ALTER TABLE businesses ADD COLUMN IF NOT EXISTS business_type ENUM('products','services') NOT NULL DEFAULT 'products'"); } catch (\Exception $__e) {}
    $__bStmt = getDB()->prepare('SELECT name, currency, is_active, business_type, logo FROM businesses WHERE id=? LIMIT 1');
    $__bStmt->execute([$user['business_id']]);
    $__bRow = $__bStmt->fetch();
    if ($__bRow) {
        if (!(int)$__bRow['is_active']) {
            session_unset();
            session_destroy();
            header('Location: ' . SITE_URL . '/login?error=suspended');
            exit;
        }
        $user['business_name']         = $__bRow['name'];
        $_SESSION['business_name']     = $__bRow['name'];
        $_SESSION['business_currency'] = $__bRow['currency'];
        $_SESSION['business_country']  = $__bRow['country'] ?? '';
        $_SESSION['business_type']     = $__bRow['business_type'] ?? 'products';
        // Resolve business logo URL
        $__bizLogoFile = $__bRow['logo'] ?? null;
        $__bizLogoUrl  = ($__bizLogoFile && file_exists(__DIR__ . '/../../' . $__bizLogoFile))
                         ? SITE_URL . '/' . $__bizLogoFile : null;
    }
}
if (!isset($__bizLogoUrl)) $__bizLogoUrl = null;

// Load feature flags for the current business
$__bizFeatures = [];
if (!isAdmin() && !empty($user['business_id'])) {
    try {
        $__fdb = getDB();
        $__fdb->exec("CREATE TABLE IF NOT EXISTS `business_features` (
            `business_id` INT UNSIGNED NOT NULL,
            `feature_key` VARCHAR(60) NOT NULL,
            `status` ENUM('enabled','disabled','coming_soon') NOT NULL DEFAULT 'enabled',
            PRIMARY KEY (`business_id`,`feature_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $__fst = $__fdb->prepare("SELECT feature_key, status FROM business_features WHERE business_id=?");
        $__fst->execute([$user['business_id']]);
        $__bizFeatures = $__fst->fetchAll(PDO::FETCH_KEY_PAIR);
        unset($__fdb, $__fst);
    } catch (\Exception $__fe) { unset($__fe); }
}

// Notification bell count (support tickets)
$__bellCount = 0;
try {
    if (isAdmin()) {
        $__bq = getDB()->query("SELECT COUNT(*) FROM support_tickets WHERE admin_read=0");
        $__bellCount = $__bq ? (int)$__bq->fetchColumn() : 0;
    } elseif (!empty($user['business_id'])) {
        $__bq = getDB()->prepare("SELECT COUNT(*) FROM support_tickets WHERE business_id=? AND business_read=0");
        $__bq->execute([$user['business_id']]);
        $__bellCount = (int)$__bq->fetchColumn();
    }
} catch (Exception $__be) { $__bellCount = 0; }

// Load branches for the branch switcher (non-admin business users only)
$__branches = [];
if (!isAdmin() && !empty($user['business_id'])) {
    try {
        $__brsQ = getDB()->prepare('SELECT id, name FROM branches WHERE business_id=? AND is_active=1 ORDER BY name');
        $__brsQ->execute([$user['business_id']]);
        $__branches = $__brsQ->fetchAll();
        unset($__brsQ);
    } catch (\Exception $__bre) { $__branches = []; unset($__bre); }
}

// Pending demo requests count (admin only)
$__demoPendingCount = 0;
if (isAdmin()) {
    try {
        $__dq = getDB()->query("SELECT COUNT(*) FROM landing_demos WHERE status='pending' OR status IS NULL");
        $__demoPendingCount = $__dq ? (int)$__dq->fetchColumn() : 0;
    } catch (\Exception $__de) { $__demoPendingCount = 0; }
}

// Subscription status for notification bar (business users only)
$__subBar = null;
if (!isAdmin() && !empty($user['business_id'])) {
    try {
        $__sbdb = getDB();
        // Ensure tables exist so the query never fails on a fresh install
        $__sbdb->exec("CREATE TABLE IF NOT EXISTS `subscription_plans` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL,
            `slug` VARCHAR(50) NOT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `sort_order` SMALLINT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $__sbdb->exec("CREATE TABLE IF NOT EXISTS `business_subscriptions` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `business_id` INT UNSIGNED NOT NULL,
            `plan_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `status` ENUM('active','trial','expired','cancelled') NOT NULL DEFAULT 'active',
            `expires_at` DATE DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Two-step query: first get the subscription, then get plan separately
        // (avoids JOIN failing when plan_id column is missing in old schemas)
        $__subQ = $__sbdb->prepare(
            "SELECT bs.id, bs.status, bs.expires_at, bs.plan_id
             FROM business_subscriptions bs
             WHERE bs.business_id = ? AND bs.status IN ('active','trial')
             ORDER BY bs.id DESC LIMIT 1"
        );
        $__subQ->execute([$user['business_id']]);
        $__subRow = $__subQ->fetch();
        if ($__subRow) {
            // Look up plan name (graceful fallback if plan_id is 0 or plan not found)
            $__planRow  = false;
            $__nextPlan = false;
            if ((int)$__subRow['plan_id'] > 0) {
                $__planQ = $__sbdb->prepare("SELECT name, sort_order FROM subscription_plans WHERE id=? LIMIT 1");
                $__planQ->execute([$__subRow['plan_id']]);
                $__planRow = $__planQ->fetch();
                if ($__planRow) {
                    $__nextQ = $__sbdb->prepare(
                        "SELECT name FROM subscription_plans WHERE sort_order > ? AND is_active=1 ORDER BY sort_order ASC LIMIT 1"
                    );
                    $__nextQ->execute([$__planRow['sort_order']]);
                    $__nextPlan = $__nextQ->fetch();
                }
            }
            $__planName = $__planRow ? $__planRow['name'] : 'Current Plan';
            $__daysLeft = $__subRow['expires_at']
                ? (int)floor((strtotime($__subRow['expires_at'] . ' 23:59:59') - time()) / 86400)
                : null;
            // Priority 1: countdown when ≤10 days to expiry
            if ($__daysLeft !== null && $__daysLeft <= 10) {
                $__subBar = [
                    'type'    => 'countdown',
                    'days'    => $__daysLeft,
                    'plan'    => $__planName,
                    'expires' => $__subRow['expires_at'],
                    'status'  => $__subRow['status'],
                ];
            } else {
                // Always show upgrade/status bar when plan is safely active
                $__subBar = [
                    'type'      => 'upgrade',
                    'plan'      => $__planName,
                    'next_plan' => $__nextPlan ? $__nextPlan['name'] : '',
                    'expires'   => $__subRow['expires_at'],
                    'days_left' => $__daysLeft,
                ];
            }
        }
        unset($__sbdb, $__subQ);
    } catch (\Exception $__sbe) { $__subBar = null; }
}

// Platform notification items for dropdown
$__notifItems   = [];
$__notifUnread  = 0;
$__latestNotifId = 0;
try {
    $__ndb = getDB();
    $__ndb->exec("CREATE TABLE IF NOT EXISTS `notifications` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`    INT UNSIGNED NOT NULL,
        `type`       VARCHAR(30) NOT NULL DEFAULT 'info',
        `title`      VARCHAR(150) NOT NULL,
        `body`       TEXT DEFAULT NULL,
        `link`       VARCHAR(500) DEFAULT NULL,
        `is_read`    TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `user_read` (`user_id`,`is_read`),
        KEY `created`   (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $__nst = $__ndb->prepare("SELECT id, type, title, body, link, is_read, created_at
                               FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 15");
    $__nst->execute([$user['id']]);
    $__notifItems = $__nst->fetchAll();
    $__nuc = $__ndb->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
    $__nuc->execute([$user['id']]);
    $__notifUnread   = (int)$__nuc->fetchColumn();
    $__latestNotifId = $__notifItems ? (int)$__notifItems[0]['id'] : 0;
    unset($__ndb, $__nst, $__nuc);
} catch (\Exception $__ne) { unset($__ne); }

function _sbTimeAgo(string $dt): string {
    $d = time() - strtotime($dt);
    if ($d < 60)     return 'Just now';
    if ($d < 3600)   return floor($d/60).'m ago';
    if ($d < 86400)  return floor($d/3600).'h ago';
    if ($d < 604800) return floor($d/86400).'d ago';
    return date('d M', strtotime($dt));
}

// Detect which section is active so its dropdown opens on load
function inSection(string $current, array $paths): bool {
    foreach ($paths as $p) {
        if (strpos($current, $p) !== false) return true;
    }
    return false;
}

$__isServiceBiz = !isAdmin() && ($_SESSION['business_type'] ?? 'products') === 'services';

$activeSection = match(true) {
    $__isServiceBiz && inSection($currentPath, ['service_orders','customers'])   => 'orders',
    $__isServiceBiz && inSection($currentPath, ['services','categories'])        => 'service_catalog',
    !$__isServiceBiz && inSection($currentPath, ['sales','customers'])           => 'sales',
    !$__isServiceBiz && inSection($currentPath, ['products','categories'])       => 'inventory',
    inSection($currentPath, ['debts','payments','expenses','income','suppliers','drawings']) => 'finance',
    inSection($currentPath, ['reports','alerts'])                                => 'analytics',
    inSection($currentPath, ['admin','subscriptions','support/tickets','branches']) => 'admin',
    default                                                                      => '',
};

function sidebarLink(string $href, string $icon, string $label, string $current, string $match): string {
    $active = strpos($current, $match) !== false;
    $cls    = $active ? 'nav-link active' : 'nav-link';
    return "<a href=\"{$href}\" class=\"{$cls}\">
                <i class=\"fa-solid {$icon} nav-icon\"></i>
                <span>{$label}</span>
            </a>";
}

function sidebarLinkBadge(string $href, string $icon, string $label, string $current, string $match, int $badge = 0): string {
    $active    = strpos($current, $match) !== false;
    $cls       = $active ? 'nav-link active' : 'nav-link';
    $badgeHtml = $badge > 0
        ? "<span style='margin-left:auto;background:#C9A84C;color:#0e1f3f;font-size:9px;font-weight:800;padding:1px 6px;border-radius:10px;line-height:1.7;flex-shrink:0'>{$badge}</span>"
        : '';
    return "<a href=\"{$href}\" class=\"{$cls}\">
                <i class=\"fa-solid {$icon} nav-icon\"></i>
                <span style='flex:1'>{$label}</span>
                {$badgeHtml}
            </a>";
}

function sidebarLinkSoon(string $href, string $icon, string $label, string $current, string $match): string {
    $active = strpos($current, $match) !== false;
    $cls    = $active ? 'nav-link active' : 'nav-link';
    return "<a href=\"{$href}\" class=\"{$cls} opacity-60\">
                <i class=\"fa-solid {$icon} nav-icon\"></i>
                <span class=\"flex-1\">{$label}</span>
                <span style=\"font-size:9px;font-weight:700;background:#f59e0b;color:#fff;padding:1px 5px;border-radius:4px;letter-spacing:.03em;\">SOON</span>
            </a>";
}

// Feature-aware nav link: respects enabled/disabled/coming_soon flag
function featureNavLink(string $key, array $feats, string $href, string $icon, string $label, string $cur, string $match): string {
    $st = $feats[$key] ?? 'enabled';
    if ($st === 'disabled') return '';
    if ($st === 'coming_soon') {
        $soonHref = url('coming-soon') . '?feature=' . urlencode($key);
        return sidebarLinkSoon($soonHref, $icon, $label, $cur, $match);
    }
    return sidebarLink($href, $icon, $label, $cur, $match);
}

// Renders a collapsible nav group
function navGroup(string $id, string $icon, string $label, string $color, string $content, bool $open): string {
    $openCls  = $open ? 'open' : '';
    return "
    <div class=\"nav-group {$openCls}\" data-group=\"{$id}\">
        <button class=\"group-trigger\" type=\"button\">
            <span class=\"group-icon-wrap\" style=\"background:{$color}22;\">
                <i class=\"fa-solid {$icon}\" style=\"color:{$color};\"></i>
            </span>
            <span class=\"group-label\">{$label}</span>
            <i class=\"fa-solid fa-chevron-right group-chevron\"></i>
        </button>
        <div class=\"group-items\">
            {$content}
        </div>
    </div>";
}
?>

<style>
/* ── Sidebar Variables ───────────────────────────────────── */
:root {
    --sb-bg:      #1B3263;
    --sb-border:  rgba(255,255,255,0.10);
    --sb-text:    rgba(255,255,255,0.65);
    --sb-hover:   rgba(255,255,255,0.08);
    --sb-active:  rgba(201,168,76,0.18);
    --sb-active-border: #C9A84C;
}

/* ── Sidebar Shell ───────────────────────────────────────── */
#sidebar {
    width: 256px;
    background: var(--sb-bg);
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
    height: 100%;
    z-index: 30;
    transition: transform .3s ease;
    border-right: 1px solid var(--sb-border);
}

/* ── Logo ────────────────────────────────────────────────── */
.sb-logo {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 18px 20px;
    border-bottom: 1px solid var(--sb-border);
    flex-shrink: 0;
    background: linear-gradient(135deg, rgba(14,31,63,.3) 0%, rgba(201,168,76,.10) 100%);
}
.sb-logo-icon {
    height: 38px;
    display: flex; align-items: center;
    flex-shrink: 0;
}
.sb-logo-icon img { height: 38px; width: auto; }
.sb-biz-logo {
    height: 38px;
    width: 38px;
    border-radius: 8px;
    object-fit: cover;
    border: 1px solid rgba(255,255,255,.15);
    flex-shrink: 0;
    background: rgba(255,255,255,.1);
}
.sb-biz-initial {
    height: 38px;
    width: 38px;
    border-radius: 8px;
    background: rgba(201,168,76,.22);
    border: 1px solid rgba(201,168,76,.40);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #C9A84C;
    font-weight: 800;
    font-size: 18px;
    flex-shrink: 0;
    letter-spacing: -1px;
}
.sb-logo-name  { color: #fff; font-weight: 700; font-size: 14px; line-height: 1.2; }
.sb-logo-app   { color: rgba(255,255,255,.55); font-size: 11px; margin-top: 1px; }

/* ── Branch Switcher ─────────────────────────────────────── */
.sb-branch-switcher {
    padding: 8px 12px;
    border-bottom: 1px solid var(--sb-border);
    background: rgba(0,0,0,.15);
    flex-shrink: 0;
}
.sb-branch-label {
    color: rgba(255,255,255,.45);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
    margin-bottom: 5px;
    display: flex;
    align-items: center;
    gap: 5px;
}
.sb-branch-select {
    width: 100%;
    background: rgba(255,255,255,.1);
    border: 1px solid rgba(255,255,255,.2);
    color: #fff;
    border-radius: 8px;
    padding: 6px 10px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    outline: none;
}
.sb-branch-select option {
    background: #1B3263;
    color: #fff;
}
.sb-branch-select:focus { border-color: #C9A84C; }
.sb-branch-select:disabled { opacity: .45; cursor: not-allowed; }
.sb-branch-hint { margin-left: auto; font-size: 9px; font-weight: 500; color: rgba(255,255,255,.35); }

/* ── Nav Scroll ──────────────────────────────────────────── */
.sb-nav {
    flex: 1;
    overflow-y: auto;
    padding: 12px 10px;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,.2) transparent;
}
.sb-nav::-webkit-scrollbar { width: 4px; }
.sb-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,.2); border-radius: 4px; }

/* ── Direct nav link (Dashboard, Profile) ────────────────── */
.nav-link {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    border-radius: 8px;
    color: var(--sb-text);
    font-size: 13.5px;
    font-weight: 500;
    text-decoration: none;
    transition: background .15s, color .15s;
    margin-bottom: 2px;
    border-left: 2px solid transparent;
}
.nav-link:hover { background: var(--sb-hover); color: #c9d1d9; }
.nav-link.active {
    background: var(--sb-active);
    color: #C9A84C;
    border-left-color: var(--sb-active-border);
}
.nav-icon { width: 16px; text-align: center; font-size: 14px; flex-shrink: 0; }

/* ── Section Divider ─────────────────────────────────────── */
.sb-divider {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: rgba(255,255,255,.35);
    padding: 14px 12px 4px;
}

/* ── Dropdown Group ──────────────────────────────────────── */
.nav-group { margin-bottom: 2px; }

.group-trigger {
    width: 100%;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    border-radius: 8px;
    background: transparent;
    border: none;
    cursor: pointer;
    color: var(--sb-text);
    font-size: 13.5px;
    font-weight: 500;
    transition: background .15s, color .15s;
    text-align: left;
}
.group-trigger:hover { background: var(--sb-hover); color: #c9d1d9; }
.nav-group.open .group-trigger { color: #c9d1d9; background: var(--sb-hover); }

.group-icon-wrap {
    width: 28px; height: 28px;
    border-radius: 7px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    font-size: 13px;
    transition: transform .2s;
}
.nav-group.open .group-icon-wrap { transform: scale(1.05); }

.group-label { flex: 1; }

.group-chevron {
    font-size: 10px;
    color: rgba(255,255,255,.35);
    transition: transform .25s ease;
    flex-shrink: 0;
}
.nav-group.open .group-chevron { transform: rotate(90deg); color: rgba(255,255,255,.55); }

/* ── Dropdown Items Container ────────────────────────────── */
.group-items {
    max-height: 0;
    overflow: hidden;
    transition: max-height .28s ease;
    padding-left: 8px;
}
.nav-group.open .group-items { max-height: 400px; }

/* Child links inside group */
.group-items .nav-link {
    padding: 7px 12px 7px 14px;
    font-size: 13px;
    border-radius: 7px;
    position: relative;
}
.group-items .nav-link::before {
    content: '';
    position: absolute;
    left: 0; top: 50%;
    transform: translateY(-50%);
    width: 1px; height: 60%;
    background: rgba(255,255,255,.2);
    border-radius: 1px;
}
.group-items .nav-link.active::before { background: var(--sb-active-border); }

/* ── User Footer ─────────────────────────────────────────── */
.sb-user {
    border-top: 1px solid var(--sb-border);
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}
.sb-avatar {
    width: 34px; height: 34px;
    border-radius: 50%;
    background: linear-gradient(135deg, #1B3263, #2952a3);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-weight: 700; font-size: 14px;
    flex-shrink: 0;
}
.sb-user-name  { color: #fff; font-size: 13px; font-weight: 600; }
.sb-user-role  { color: rgba(255,255,255,.55); font-size: 11px; margin-top: 1px; }
.sb-logout {
    color: rgba(255,255,255,.5);
    font-size: 15px;
    text-decoration: none;
    margin-left: auto;
    flex-shrink: 0;
    transition: color .15s;
    padding: 4px;
}
.sb-logout:hover { color: #fca5a5; }
</style>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- SIDEBAR                                                -->
<!-- ═══════════════════════════════════════════════════════ -->
<aside id="sidebar">

    <!-- Logo -->
    <div class="sb-logo">
        <?php if (!isAdmin() && !empty($user['business_id'])): ?>
            <?php if ($__bizLogoUrl): ?>
            <img src="<?= h($__bizLogoUrl) ?>" alt="<?= h($user['business_name']) ?>" class="sb-biz-logo">
            <?php else: ?>
            <div class="sb-biz-initial"><?= strtoupper(substr($user['business_name'], 0, 1)) ?></div>
            <?php endif; ?>
            <div>
                <div class="sb-logo-name"><?= h($user['business_name']) ?></div>
                <div class="sb-logo-app">Powered by <?= h(APP_NAME) ?></div>
            </div>
        <?php else: ?>
        <div class="sb-logo-icon"><img src="<?= APP_LOGO ?>" alt="<?= h(APP_NAME) ?>"></div>
        <div>
            <div class="sb-logo-name"><?= h(APP_NAME) ?></div>
            <div class="sb-logo-app"><?= h($user['business_name'] ?: 'System Admin') ?></div>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!isAdmin() && !empty($user['business_id']) && count($__branches) >= 2): ?>
    <!-- Branch Switcher -->
    <div class="sb-branch-switcher">
        <div class="sb-branch-label">
            <i class="fa-solid fa-code-branch"></i> Branch
        </div>
        <form method="POST" action="<?= url('branches/switch') ?>">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <select name="branch_id" onchange="this.form.submit()" class="sb-branch-select">
                <option value="0" <?= empty($_SESSION['active_branch_id']) ? 'selected' : '' ?>>All Branches</option>
                <?php foreach ($__branches as $__br): ?>
                <option value="<?= $__br['id'] ?>" <?= (int)($_SESSION['active_branch_id'] ?? 0) === (int)$__br['id'] ? 'selected' : '' ?>>
                    <?= h($__br['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <?php endif; ?>

    <!-- Navigation -->
    <nav class="sb-nav">

        <!-- Dashboard -->
        <?= sidebarLink(url('dashboard'), 'fa-gauge-high', 'Dashboard', $currentPath, 'dashboard') ?>

        <?php if (!isAdmin()): ?>
        <?php if (empty($user['business_id'])): ?>
        <!-- No business assigned — show minimal nav only -->
        <div style="margin:24px 12px 8px;padding:14px 16px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);border-radius:12px;">
            <p style="color:rgba(255,255,255,.75);font-size:12px;font-weight:600;line-height:1.5;margin:0;">
                <i class="fa-solid fa-building-circle-xmark" style="color:#C9A84C;margin-right:6px;"></i>No business assigned
            </p>
            <p style="color:rgba(255,255,255,.45);font-size:11px;margin:4px 0 0;line-height:1.5;">Contact your administrator to get access to business features.</p>
        </div>
        <?php else: ?>
        <?php if ($__isServiceBiz): ?>
        <!-- ═══ SERVICE BUSINESS NAVIGATION ═══ -->

        <!-- ORDERS group -->
        <?php if (hasPermission('sales')): ?>
        <?php
        $ordContent = '';
        $ordContent .= featureNavLink('sales_pos',       $__bizFeatures, url('service-orders'), 'fa-clipboard-list', 'Service Orders', $currentPath, 'service_orders');
        $ordContent .= featureNavLink('sales_customers', $__bizFeatures, url('customers'),       'fa-users',          'Customers',      $currentPath, 'customers');
        if (!hasPermission('reports')) {
            $ordContent .= featureNavLink('analytics_smart_alerts', $__bizFeatures, url('alerts'), 'fa-bell', 'Smart Alerts', $currentPath, 'alerts');
        }
        if ($ordContent) echo navGroup('orders', 'fa-bag-shopping', 'Orders', '#22c55e', $ordContent, $activeSection === 'orders');
        ?>
        <?php endif; ?>

        <!-- SERVICE CATALOG group -->
        <?php if (hasPermission('inventory') || hasPermission('sales')): ?>
        <?php
        $svcContent = sidebarLink(url('services'), 'fa-hand-holding-heart', 'Our Services', $currentPath, 'services');
        if (hasPermission('inventory')) {
            $svcContent .= featureNavLink('inventory_products', $__bizFeatures, url('products/categories'), 'fa-tags', 'Categories', $currentPath, 'categories');
        }
        if ($svcContent) echo navGroup('service_catalog', 'fa-briefcase', 'Service Catalog', '#f59e0b', $svcContent, $activeSection === 'service_catalog');
        ?>
        <?php endif; ?>

        <?php else: ?>
        <!-- ═══ PRODUCT BUSINESS NAVIGATION ═══ -->

        <!-- SALES group -->
        <?php if (hasPermission('sales')): ?>
        <?php
        $salesContent = '';
        $salesContent .= featureNavLink('sales_pos',       $__bizFeatures, url('sales'),     'fa-receipt',       'Sales',             $currentPath, '/sales/');
        $salesContent .= featureNavLink('sales_customers', $__bizFeatures, url('customers'), 'fa-users',         'Customers',         $currentPath, 'customers');
        if (!hasPermission('reports')) {
            $salesContent .= featureNavLink('analytics_smart_alerts', $__bizFeatures, url('alerts'), 'fa-bell', 'Smart Alerts', $currentPath, 'alerts');
        }
        if (!hasPermission('inventory')) {
            $salesContent .= featureNavLink('inventory_products', $__bizFeatures, url('products'), 'fa-boxes-stacked', 'Products (Stock)', $currentPath, 'products');
        }
        if ($salesContent) echo navGroup('sales', 'fa-bag-shopping', 'Sales', '#22c55e', $salesContent, $activeSection === 'sales');
        ?>
        <?php endif; ?>

        <!-- INVENTORY group -->
        <?php if (hasPermission('inventory')): ?>
        <?php
        $invContent = '';
        $invContent .= featureNavLink('inventory_products', $__bizFeatures, url('products'),            'fa-boxes-stacked', 'Products',   $currentPath, 'products');
        $invContent .= featureNavLink('inventory_products', $__bizFeatures, url('products/categories'), 'fa-tags',           'Categories', $currentPath, 'categories');
        if ($invContent) echo navGroup('inventory', 'fa-warehouse', 'Inventory', '#f59e0b', $invContent, $activeSection === 'inventory');
        ?>
        <?php endif; ?>

        <?php endif; // $__isServiceBiz ?>

        <!-- FINANCE group (shared by both business types) -->
        <?php if (hasPermission('debts') || hasPermission('payments') || hasPermission('expenses')): ?>
        <?php
        $finContent = '';
        if (hasPermission('debts'))    $finContent .= featureNavLink('finance_debts',    $__bizFeatures, url('debts'),    'fa-file-invoice-dollar',   'Debts',    $currentPath, 'debts');
        if (hasPermission('payments')) $finContent .= featureNavLink('finance_payments', $__bizFeatures, url('payments'), 'fa-money-bill-transfer',   'Payments', $currentPath, 'payments');
        if (hasPermission('expenses')) $finContent .= featureNavLink('finance_expenses', $__bizFeatures, url('expenses'), 'fa-wallet',                'Expenses', $currentPath, 'expenses');
        if (hasPermission('expenses')) $finContent .= featureNavLink('finance_income',     $__bizFeatures, url('income'),     'fa-circle-dollar-to-slot', 'Income',     $currentPath, 'income');
        if (hasPermission('expenses')) $finContent .= featureNavLink('finance_suppliers',  $__bizFeatures, url('suppliers'),  'fa-truck',                 'Suppliers',  $currentPath, 'suppliers');
        if (hasPermission('expenses')) $finContent .= featureNavLink('finance_drawings',   $__bizFeatures, url('drawings'),   'fa-money-bill-wave',        'Drawings',   $currentPath, 'drawings');
        if ($finContent) echo navGroup('finance', 'fa-coins', 'Finance', '#ec4899', $finContent, $activeSection === 'finance');
        ?>
        <?php endif; ?>

        <!-- ANALYTICS group (both types, service businesses get service-specific reports) -->
        <?php if (hasPermission('reports')): ?>
        <?php
        if ($__isServiceBiz) {
            $repContent =
                featureNavLink('analytics_revenue_report',  $__bizFeatures, url('reports/service-revenue'), 'fa-chart-bar',           'Revenue Report',  $currentPath, 'reports/service_revenue') .
                featureNavLink('analytics_payment_report',  $__bizFeatures, url('reports/payments'),        'fa-money-bill-trend-up', 'Payment Report',  $currentPath, 'reports/payments') .
                featureNavLink('analytics_customer_report', $__bizFeatures, url('reports/customers'),       'fa-users-line',          'Customer Report', $currentPath, 'reports/customers') .
                featureNavLink('analytics_smart_alerts',    $__bizFeatures, url('alerts'),                  'fa-bell',                'Smart Alerts',    $currentPath, 'alerts') .
                featureNavLink('analytics_balance_sheet',   $__bizFeatures, url('reports/balance-sheet'), 'fa-scale-balanced',   'Balance Sheet',   $currentPath, 'balance_sheet');
        } else {
            $repContent =
                featureNavLink('analytics_sales_report',      $__bizFeatures, url('reports/sales'),          'fa-chart-bar',           'Sales Report',     $currentPath, 'reports/sales') .
                featureNavLink('analytics_financial_report',  $__bizFeatures, url('reports/financial'),      'fa-chart-pie',           'Financial Report', $currentPath, 'reports/financial') .
                featureNavLink('analytics_inventory_report',  $__bizFeatures, url('reports/inventory'),      'fa-warehouse',           'Inventory Report', $currentPath, 'reports/inventory') .
                featureNavLink('analytics_debt_report',       $__bizFeatures, url('reports/debts'),          'fa-file-invoice-dollar', 'Debt Report',      $currentPath, 'reports/debts') .
                featureNavLink('analytics_payment_report',    $__bizFeatures, url('reports/payments'),       'fa-money-bill-trend-up', 'Payment Report',   $currentPath, 'reports/payments') .
                featureNavLink('analytics_customer_report',   $__bizFeatures, url('reports/customers'),      'fa-users-line',          'Customer Report',  $currentPath, 'reports/customers') .
                featureNavLink('analytics_balance_sheet',     $__bizFeatures, url('reports/balance-sheet'),  'fa-scale-balanced',      'Balance Sheet',    $currentPath, 'balance_sheet') .
                featureNavLink('analytics_smart_alerts',      $__bizFeatures, url('alerts'),                 'fa-bell',                'Smart Alerts',     $currentPath, 'alerts');
        }
        if ($repContent) echo navGroup('analytics', 'fa-chart-line', 'Analytics', '#a78bfa', $repContent, $activeSection === 'analytics');
        ?>
        <?php endif; ?>

        <?php endif; // business_id check ?>
        <?php endif; // !isAdmin() ?>

        <!-- ADMINISTRATION group -->
        <?php if (isAdmin() || (!empty($user['business_id']) && hasPermission('users'))): ?>
        <?php
        $adminContent = sidebarLink(url('admin/users'), 'fa-user-shield', 'Users', $currentPath, 'admin/users');
        if (!isAdmin()) $adminContent .= featureNavLink('branches_management', $__bizFeatures, url('branches'), 'fa-code-branch', 'Branches', $currentPath, 'branches');
        $adminContent .= sidebarLink(url('import'), 'fa-file-arrow-up', 'Import Data', $currentPath, 'import');
        if (isAdmin()) $adminContent .= sidebarLink(url('admin/businesses'),   'fa-building',   'Businesses',      $currentPath, 'admin/businesses');
        if (isAdmin()) $adminContent .= sidebarLink(url('admin/subscriptions'),'fa-credit-card','Subscriptions',   $currentPath, 'subscriptions');
        if (isAdmin()) $adminContent .= sidebarLink(url('admin/settings'),     'fa-sliders',    'Settings',        $currentPath, 'admin/settings');
        if (isAdmin()) $adminContent .= sidebarLink(url('support/tickets'),    'fa-ticket',     'Support Tickets', $currentPath, 'support/tickets');
        if (isAdmin()) $adminContent .= sidebarLinkBadge(url('admin/demos'), 'fa-calendar-check', 'Demo Requests', $currentPath, 'admin/demos', $__demoPendingCount);
        echo navGroup('admin', 'fa-screwdriver-wrench', 'Administration', '#38bdf8', $adminContent, $activeSection === 'admin');
        ?>
        <?php endif; ?>

        <!-- Profile -->
        <div class="sb-divider">Account</div>
        <?php if (!isAdmin()): ?>
        <?= featureNavLink('support_tickets', $__bizFeatures, url('support'), 'fa-headset', 'Support', $currentPath, 'support/index') ?>
        <?php endif; ?>
        <?= sidebarLink(url('profile'), 'fa-circle-user', 'My Profile', $currentPath, 'profile') ?>

    </nav>

    <!-- User footer -->
    <div class="sb-user">
        <?php if (!empty($user['avatar'])): ?>
        <img src="<?= $baseUrl . '/' . h($user['avatar']) ?>" alt=""
             class="sb-avatar object-cover" style="display:flex;">
        <?php else: ?>
        <div class="sb-avatar"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
        <?php endif; ?>
        <div class="min-w-0 flex-1">
            <div class="sb-user-name truncate"><?= h($user['name']) ?></div>
            <div class="sb-user-role truncate"><?= h($user['role_name']) ?></div>
        </div>
        <a href="<?= url('logout') ?>" class="sb-logout" title="Logout">
            <i class="fa-solid fa-right-from-bracket"></i>
        </a>
    </div>

</aside>

<!-- Toast container (fixed, outside scroll flow) -->
<div id="sme-toasts" aria-live="polite"></div>

<style>
#sme-toasts {
    position: fixed;
    bottom: 24px;
    right: 24px;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-width: 360px;
    pointer-events: none;
}
@media (max-width: 640px) {
    #sme-toasts { top: 16px; bottom: auto; left: 12px; right: 12px; max-width: none; }
}
.sme-toast {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 8px 30px rgba(0,0,0,.14), 0 2px 8px rgba(0,0,0,.08);
    padding: 14px 16px;
    position: relative;
    overflow: hidden;
    pointer-events: all;
    cursor: pointer;
    animation: smeSlideIn .35s cubic-bezier(.34,1.56,.64,1);
    transition: opacity .4s ease, transform .4s ease;
}
.sme-toast.removing { opacity: 0; transform: translateX(30px); }
@media (max-width: 640px) {
    .sme-toast { animation: smeSlideDown .35s cubic-bezier(.34,1.56,.64,1); }
    .sme-toast.removing { transform: translateY(-20px); }
    @keyframes smeSlideDown { from { opacity:0; transform:translateY(-20px); } to { opacity:1; transform:none; } }
}
@keyframes smeSlideIn { from { opacity:0; transform:translateX(30px); } to { opacity:1; transform:none; } }
.sme-toast-icon {
    width: 36px; height: 36px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    font-size: 16px;
}
.sme-toast-body { flex: 1; min-width: 0; }
.sme-toast-title { font-size: 13px; font-weight: 700; color: #1f2937; line-height: 1.3; }
.sme-toast-msg   { font-size: 11.5px; color: #6b7280; margin-top: 2px; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.sme-toast-close {
    background: none; border: none; color: #9ca3af; cursor: pointer; font-size: 18px;
    padding: 0 0 0 6px; line-height: 1; flex-shrink: 0; pointer-events: all;
}
.sme-toast-close:hover { color: #374151; }
.sme-toast-bar {
    position: absolute; bottom: 0; left: 0; height: 3px;
    width: 100%; border-radius: 0 0 14px 14px;
    transition: width 5.5s linear;
}
</style>

<script>
(function () {
    // ── Sidebar group toggles ─────────────────────────────────
    document.querySelectorAll('.group-trigger').forEach(btn => {
        btn.addEventListener('click', function () {
            const group  = this.closest('.nav-group');
            const isOpen = group.classList.contains('open');
            document.querySelectorAll('.nav-group.open').forEach(g => {
                if (g !== group) g.classList.remove('open');
            });
            group.classList.toggle('open', !isOpen);
        });
    });
})();

// Notification system initialises after the full page renders (bell button is in the topbar,
// which appears after this script block in the HTML stream).
document.addEventListener('DOMContentLoaded', function() {
    // ── Notification system ───────────────────────────────────
    const API      = '<?= APP_URL ?>';
    const CSRF_TOK = '<?= csrfToken() ?>';
    let   lastId   = <?= (int)$__latestNotifId ?>;

    const notifBtn   = document.getElementById('notif-btn');
    const notifDrop  = document.getElementById('notif-dropdown');
    const notifBadge = document.getElementById('notif-badge');
    const notifList  = document.getElementById('notif-list');
    const markAllBtn = document.getElementById('notif-mark-all');
    const hdrCount   = document.getElementById('notif-hdr-count');

    if (!notifBtn) return;

    // Toggle dropdown
    notifBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        notifDrop.classList.toggle('hidden');
    });
    document.addEventListener('click', function(e) {
        if (notifWrap && !notifWrap.contains(e.target)) notifDrop.classList.add('hidden');
    });
    const notifWrap = document.getElementById('notif-wrap');

    // Update badge count
    function updateBadge(n) {
        if (n > 0) {
            notifBadge.textContent = n > 99 ? '99+' : n;
            notifBadge.classList.remove('hidden');
            hdrCount.textContent = n;
            hdrCount.classList.remove('hidden');
        } else {
            notifBadge.classList.add('hidden');
            hdrCount.classList.add('hidden');
        }
    }

    // Mark all read
    markAllBtn && markAllBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        const fd = new FormData();
        fd.append('csrf_token', CSRF_TOK);
        fd.append('ids', 'all');
        fetch(API + '/api/notifications_read.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                updateBadge(data.unread_count ?? 0);
                notifList.querySelectorAll('.notif-item').forEach(el => {
                    el.style.borderLeft = '';
                    el.classList.remove('bg-blue-50/50');
                    el.querySelector('.notif-dot')?.remove();
                });
            }).catch(() => {});
    });

    // Click on individual notification
    notifList.addEventListener('click', function(e) {
        const item = e.target.closest('.notif-item');
        if (!item) return;
        e.stopPropagation();
        const id   = item.dataset.id;
        const link = item.dataset.link;
        if (id) {
            const fd = new FormData();
            fd.append('csrf_token', CSRF_TOK);
            fd.append('ids', id);
            fetch(API + '/api/notifications_read.php', { method: 'POST', body: fd }).catch(() => {});
            item.style.borderLeft = '';
            item.classList.remove('bg-blue-50/50');
            item.querySelector('.notif-dot')?.remove();
        }
        if (link) window.location.href = link;
    });

    // Escape HTML
    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    // Prepend new notification to dropdown list
    function prependNotif(n) {
        const empty = document.getElementById('notif-empty');
        if (empty) empty.remove();
        const colors = { success:'#22c55e', warning:'#f59e0b', error:'#ef4444', support:'#a855f7' };
        const icons  = { success:'fa-circle-check', warning:'fa-triangle-exclamation', error:'fa-circle-xmark', support:'fa-headset' };
        const color  = colors[n.type] || '#1B3263';
        const icon   = icons[n.type]  || 'fa-circle-info';
        const div    = document.createElement('div');
        div.className = 'notif-item flex items-start gap-3 px-4 py-3 border-b border-gray-50 cursor-pointer hover:bg-gray-50 transition-colors bg-blue-50/50';
        div.style.borderLeft = '3px solid #1B3263';
        div.dataset.id   = n.id;
        div.dataset.link = n.link || '';
        div.innerHTML = `
            <div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5" style="background:${color}20;">
                <i class="fa-solid ${icon} text-sm" style="color:${color};"></i>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-800 leading-snug">${esc(n.title)}</p>
                ${n.body ? `<p class="text-xs text-gray-500 mt-0.5 leading-snug line-clamp-2">${esc(n.body)}</p>` : ''}
                <p class="text-[10px] text-gray-400 mt-1">Just now</p>
            </div>
            <div class="w-2 h-2 rounded-full bg-blue-600 flex-shrink-0 mt-2 notif-dot"></div>`;
        notifList.prepend(div);
    }

    // Show toast popup
    function showToast(n) {
        const colors = { success:'#22c55e', warning:'#f59e0b', error:'#ef4444', support:'#a855f7' };
        const icons  = { success:'fa-circle-check', warning:'fa-triangle-exclamation', error:'fa-circle-xmark', support:'fa-headset' };
        const color  = colors[n.type] || '#1B3263';
        const icon   = icons[n.type]  || 'fa-circle-info';
        const toast  = document.createElement('div');
        toast.className = 'sme-toast';
        toast.innerHTML = `
            <div class="sme-toast-icon" style="background:${color}18;color:${color};">
                <i class="fa-solid ${icon}"></i>
            </div>
            <div class="sme-toast-body">
                <p class="sme-toast-title">${esc(n.title)}</p>
                ${n.body ? `<p class="sme-toast-msg">${esc(n.body)}</p>` : ''}
            </div>
            <button class="sme-toast-close" title="Dismiss">×</button>
            <div class="sme-toast-bar" style="background:${color};"></div>`;
        document.getElementById('sme-toasts').prepend(toast);
        // Progress bar countdown
        const bar = toast.querySelector('.sme-toast-bar');
        requestAnimationFrame(() => requestAnimationFrame(() => { bar.style.width = '0%'; }));
        // Close button
        toast.querySelector('.sme-toast-close').addEventListener('click', function(e) {
            e.stopPropagation(); dismissToast(toast);
        });
        // Click to navigate
        if (n.link) toast.addEventListener('click', () => window.location.href = n.link);
        // Auto dismiss
        const t1 = setTimeout(() => dismissToast(toast), 5500);
        toast._timer = t1;
    }

    function dismissToast(toast) {
        clearTimeout(toast._timer);
        toast.classList.add('removing');
        setTimeout(() => toast.remove(), 450);
    }

    // Poll every 15 seconds
    function poll() {
        fetch(API + '/api/notifications.php?since=' + lastId)
            .then(r => r.json())
            .then(data => {
                if (!data.ok) return;
                const items = data.new || [];
                items.forEach(n => {
                    showToast(n);
                    prependNotif(n);
                    if (parseInt(n.id) > lastId) lastId = parseInt(n.id);
                });
                if (items.length > 0) updateBadge(data.unread_count);
            })
            .catch(() => {});
    }

    setInterval(poll, 15000);
});
</script>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- MAIN CONTENT WRAPPER                                   -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="flex-1 flex flex-col overflow-hidden">

    <?php if ($__subBar): ?>
    <?php
        $__ticketUrl = url('support/tickets');
        if ($__subBar['type'] === 'countdown'):
            $__d = $__subBar['days'];
            // Colour: ≤3 days = red, 4-10 = amber
            if ($__d <= 0) {
                $__barBg  = 'bg-red-600';
                $__barTxt = 'text-white';
                $__btnCls = 'bg-white/20 hover:bg-white/30 text-white border border-white/30';
                $__iconCls= 'text-red-200';
                $__msg    = 'Your <strong>' . h($__subBar['plan']) . '</strong> plan has <strong>expired</strong>. Contact your admin to restore access.';
                $__label  = 'Renew Now';
            } elseif ($__d <= 3) {
                $__barBg  = 'bg-red-500';
                $__barTxt = 'text-white';
                $__btnCls = 'bg-white/20 hover:bg-white/30 text-white border border-white/30';
                $__iconCls= 'text-red-200';
                $__msg    = 'Your <strong>' . h($__subBar['plan']) . '</strong> plan expires in <strong>' . $__d . ' day' . ($__d !== 1 ? 's' : '') . '</strong>. Act now to avoid losing access.';
                $__label  = 'Contact Admin';
            } else {
                $__barBg  = 'bg-amber-500';
                $__barTxt = 'text-white';
                $__btnCls = 'bg-white/20 hover:bg-white/30 text-white border border-white/30';
                $__iconCls= 'text-amber-200';
                $__msg    = 'Your <strong>' . h($__subBar['plan']) . '</strong> plan expires in <strong>' . $__d . ' days</strong> (' . date('d M Y', strtotime($__subBar['expires'])) . '). Renew before then to avoid interruption.';
                $__label  = 'Contact Admin';
            }
        endif;
    ?>
    <?php if ($__subBar['type'] === 'countdown'): ?>
    <div class="<?= $__barBg ?> <?= $__barTxt ?> flex items-center gap-3 px-4 py-2 text-sm flex-shrink-0" style="min-height:38px;">
        <i class="fa-solid fa-triangle-exclamation <?= $__iconCls ?> flex-shrink-0 text-sm"></i>
        <span class="flex-1 leading-tight"><?= $__msg ?></span>
        <a href="<?= $__ticketUrl ?>"
           class="<?= $__btnCls ?> text-xs font-semibold px-3 py-1 rounded-lg flex-shrink-0 no-underline transition-colors">
            <i class="fa-solid fa-headset mr-1"></i><?= $__label ?>
        </a>
        <button onclick="this.closest('div').remove()"
                class="flex-shrink-0 opacity-60 hover:opacity-100 transition-opacity ml-1" aria-label="Dismiss">
            <i class="fa-solid fa-xmark text-sm"></i>
        </button>
    </div>
    <?php else:
        // Build upgrade bar message depending on whether a higher plan exists
        $__upMsg = $__subBar['next_plan']
            ? 'You\'re on <strong>' . h($__subBar['plan']) . '</strong>. Upgrade to <strong>' . h($__subBar['next_plan']) . '</strong> to unlock more functionalities.'
            : 'You\'re on <strong>' . h($__subBar['plan']) . '</strong>.'
              . ($__subBar['expires'] ? ' Plan active until <strong>' . date('d M Y', strtotime($__subBar['expires'])) . '</strong>.' : '')
              . ' Contact admin to manage your subscription.';
        $__upBtn  = $__subBar['next_plan'] ? 'Contact Admin' : 'Contact Admin';
    ?>
    <div class="bg-blue-700 text-white flex items-center gap-3 px-4 py-2 text-sm flex-shrink-0" style="min-height:38px;">
        <i class="fa-solid fa-<?= $__subBar['next_plan'] ? 'arrow-up-right-dots' : 'credit-card' ?> text-blue-300 flex-shrink-0 text-sm"></i>
        <span class="flex-1 leading-tight"><?= $__upMsg ?></span>
        <a href="<?= $__ticketUrl ?>"
           class="bg-white/15 hover:bg-white/25 text-white border border-white/20 text-xs font-semibold px-3 py-1 rounded-lg flex-shrink-0 no-underline transition-colors whitespace-nowrap">
            <i class="fa-solid fa-headset mr-1"></i><?= $__upBtn ?>
        </a>
        <button onclick="this.closest('div').remove()"
                class="flex-shrink-0 opacity-60 hover:opacity-100 transition-opacity ml-1" aria-label="Dismiss">
            <i class="fa-solid fa-xmark text-sm"></i>
        </button>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Top Bar -->
    <header class="bg-white border-b border-gray-200 px-6 py-3 flex items-center justify-between flex-shrink-0 shadow-sm">
        <div class="flex items-center gap-4">
            <button id="sidebar-toggle" class="text-gray-500 hover:text-gray-700 lg:hidden">
                <i class="fa-solid fa-bars text-xl"></i>
            </button>
            <div>
                <h1 class="text-base font-semibold text-gray-800"><?= h($pageTitle ?? 'Dashboard') ?></h1>
                <p class="text-xs text-gray-400 hidden sm:block"><?= date('l, d F Y') ?></p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <!-- Notification Bell -->
            <?php
            $__totalBadge = $__notifUnread + $__bellCount;
            ?>
            <div class="relative" id="notif-wrap">
                <button id="notif-btn" class="relative text-gray-500 hover:text-blue-600 transition-colors p-1 focus:outline-none" aria-label="Notifications">
                    <i class="fa-solid fa-bell text-lg"></i>
                    <span id="notif-badge" class="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 px-0.5 bg-red-500 text-white text-[9px] font-bold rounded-full flex items-center justify-center <?= $__totalBadge === 0 ? 'hidden' : '' ?>">
                        <?= $__totalBadge > 99 ? '99+' : $__totalBadge ?>
                    </span>
                </button>
                <!-- Notification Dropdown -->
                <div id="notif-dropdown" class="hidden absolute right-0 top-full mt-2 w-80 bg-white rounded-xl shadow-2xl border border-gray-100 overflow-hidden" style="z-index:500;">
                    <!-- Header -->
                    <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100 bg-gray-50">
                        <div class="flex items-center gap-2">
                            <span class="font-semibold text-gray-800 text-sm">Notifications</span>
                            <?php if ($__notifUnread > 0): ?>
                            <span id="notif-hdr-count" class="bg-red-500 text-white text-[9px] font-bold px-1.5 py-0.5 rounded-full"><?= $__notifUnread ?></span>
                            <?php else: ?>
                            <span id="notif-hdr-count" class="hidden bg-red-500 text-white text-[9px] font-bold px-1.5 py-0.5 rounded-full"></span>
                            <?php endif; ?>
                        </div>
                        <button id="notif-mark-all" class="text-xs font-medium text-blue-600 hover:text-blue-800">Mark all read</button>
                    </div>
                    <!-- List -->
                    <div id="notif-list" class="overflow-y-auto" style="max-height:340px;">
                        <?php if (empty($__notifItems)): ?>
                        <div id="notif-empty" class="text-center py-10 text-gray-400">
                            <i class="fa-solid fa-bell-slash text-2xl mb-2 block"></i>
                            <p class="text-sm">No notifications yet</p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($__notifItems as $__ni):
                            $__niIcon = match($__ni['type']) {
                                'success' => 'fa-circle-check',
                                'warning' => 'fa-triangle-exclamation',
                                'error'   => 'fa-circle-xmark',
                                'support' => 'fa-headset',
                                default   => 'fa-circle-info',
                            };
                            $__niColor = match($__ni['type']) {
                                'success' => '#22c55e',
                                'warning' => '#f59e0b',
                                'error'   => '#ef4444',
                                'support' => '#a855f7',
                                default   => '#1B3263',
                            };
                        ?>
                        <div class="notif-item flex items-start gap-3 px-4 py-3 border-b border-gray-50 cursor-pointer hover:bg-gray-50 transition-colors <?= !$__ni['is_read'] ? 'bg-blue-50/50' : '' ?>"
                             data-id="<?= (int)$__ni['id'] ?>"
                             data-link="<?= h($__ni['link'] ?? '') ?>"
                             style="<?= !$__ni['is_read'] ? 'border-left:3px solid #1B3263;' : '' ?>">
                            <div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5" style="background:<?= $__niColor ?>20;">
                                <i class="fa-solid <?= $__niIcon ?> text-sm" style="color:<?= $__niColor ?>;"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-800 leading-snug"><?= h($__ni['title']) ?></p>
                                <?php if ($__ni['body']): ?>
                                <p class="text-xs text-gray-500 mt-0.5 leading-snug line-clamp-2"><?= h($__ni['body']) ?></p>
                                <?php endif; ?>
                                <p class="text-[10px] text-gray-400 mt-1"><?= _sbTimeAgo($__ni['created_at']) ?></p>
                            </div>
                            <?php if (!$__ni['is_read']): ?>
                            <div class="w-2 h-2 rounded-full bg-blue-600 flex-shrink-0 mt-2 notif-dot"></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <!-- Footer -->
                    <div class="px-4 py-2.5 border-t border-gray-100 bg-gray-50 flex items-center justify-between">
                        <a href="<?= isAdmin() ? url('support/tickets') : url('support') ?>" class="text-xs font-medium text-blue-600 hover:text-blue-800 flex items-center gap-1.5">
                            <i class="fa-solid fa-ticket"></i> Support Tickets
                            <?php if ($__bellCount > 0): ?>
                            <span class="bg-red-500 text-white text-[8px] font-bold px-1 py-0.5 rounded"><?= $__bellCount ?></span>
                            <?php endif; ?>
                        </a>
                    </div>
                </div>
            </div>
            <a href="<?= url('profile') ?>"
               class="flex items-center gap-2 text-sm text-gray-600 hover:text-blue-600 transition-colors border border-gray-200 rounded-lg px-3 py-1.5">
                <?php if (!empty($user['avatar'])): ?>
                <img src="<?= $baseUrl . '/' . h($user['avatar']) ?>" alt=""
                     class="w-6 h-6 rounded-full object-cover flex-shrink-0">
                <?php else: ?>
                <div class="w-6 h-6 rounded-full bg-blue-600 text-white text-xs font-bold flex items-center justify-center flex-shrink-0">
                    <?= strtoupper(substr($user['name'], 0, 1)) ?>
                </div>
                <?php endif; ?>
                <span class="hidden md:inline font-medium"><?= h($user['name']) ?></span>
            </a>
        </div>
    </header>

    <!-- Page Content -->
    <main class="flex-1 overflow-y-auto p-6 bg-gray-50">
        <?= renderFlash() ?>
