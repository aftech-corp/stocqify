<?php
$currentPath = $_SERVER['PHP_SELF'];
$user        = currentUser();
$baseUrl     = APP_URL;

// Always pull a fresh business name, currency, and type from the DB; force logout if deactivated
if (!empty($user['business_id'])) {
    try { getDB()->exec("ALTER TABLE businesses ADD COLUMN IF NOT EXISTS business_type ENUM('products','services') NOT NULL DEFAULT 'products'"); } catch (\Exception $__e) {}
    $__bStmt = getDB()->prepare('SELECT name, currency, is_active, business_type FROM businesses WHERE id=? LIMIT 1');
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
        $_SESSION['business_type']     = $__bRow['business_type'] ?? 'products';
    }
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
    inSection($currentPath, ['debts','payments','expenses','income'])            => 'finance',
    inSection($currentPath, ['reports','alerts'])                                => 'analytics',
    inSection($currentPath, ['admin','support/tickets'])                         => 'admin',
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

function sidebarLinkSoon(string $href, string $icon, string $label, string $current, string $match): string {
    $active = strpos($current, $match) !== false;
    $cls    = $active ? 'nav-link active' : 'nav-link';
    return "<a href=\"{$href}\" class=\"{$cls} opacity-60\">
                <i class=\"fa-solid {$icon} nav-icon\"></i>
                <span class=\"flex-1\">{$label}</span>
                <span style=\"font-size:9px;font-weight:700;background:#f59e0b;color:#fff;padding:1px 5px;border-radius:4px;letter-spacing:.03em;\">SOON</span>
            </a>";
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
    --sb-bg:      #0d1117;
    --sb-border:  rgba(255,255,255,0.06);
    --sb-text:    #8b949e;
    --sb-hover:   rgba(255,255,255,0.06);
    --sb-active:  rgba(59,130,246,0.18);
    --sb-active-border: #3b82f6;
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
    background: linear-gradient(135deg, rgba(59,130,246,.12) 0%, rgba(99,102,241,.08) 100%);
}
.sb-logo-icon {
    width: 38px; height: 38px;
    background: linear-gradient(135deg, #3b82f6, #6366f1);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(59,130,246,.35);
}
.sb-logo-icon i { color: #fff; font-size: 17px; }
.sb-logo-name  { color: #f0f6fc; font-weight: 700; font-size: 14px; line-height: 1.2; }
.sb-logo-app   { color: #8b949e; font-size: 11px; margin-top: 1px; }

/* ── Nav Scroll ──────────────────────────────────────────── */
.sb-nav {
    flex: 1;
    overflow-y: auto;
    padding: 12px 10px;
    scrollbar-width: thin;
    scrollbar-color: #30363d transparent;
}
.sb-nav::-webkit-scrollbar { width: 4px; }
.sb-nav::-webkit-scrollbar-thumb { background: #30363d; border-radius: 4px; }

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
    color: #58a6ff;
    border-left-color: var(--sb-active-border);
}
.nav-icon { width: 16px; text-align: center; font-size: 14px; flex-shrink: 0; }

/* ── Section Divider ─────────────────────────────────────── */
.sb-divider {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: #484f58;
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
    color: #484f58;
    transition: transform .25s ease;
    flex-shrink: 0;
}
.nav-group.open .group-chevron { transform: rotate(90deg); color: #8b949e; }

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
    background: #30363d;
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
    background: linear-gradient(135deg, #3b82f6, #6366f1);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-weight: 700; font-size: 14px;
    flex-shrink: 0;
}
.sb-user-name  { color: #f0f6fc; font-size: 13px; font-weight: 600; }
.sb-user-role  { color: #8b949e; font-size: 11px; margin-top: 1px; }
.sb-logout {
    color: #8b949e;
    font-size: 15px;
    text-decoration: none;
    margin-left: auto;
    flex-shrink: 0;
    transition: color .15s;
    padding: 4px;
}
.sb-logout:hover { color: #f85149; }
</style>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- SIDEBAR                                                -->
<!-- ═══════════════════════════════════════════════════════ -->
<aside id="sidebar">

    <!-- Logo -->
    <div class="sb-logo">
        <div class="sb-logo-icon"><i class="fa-solid fa-chart-line"></i></div>
        <div>
            <div class="sb-logo-name"><?= h(APP_NAME) ?></div>
            <div class="sb-logo-app"><?= h($user['business_name'] ?: 'System Admin') ?></div>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="sb-nav">

        <!-- Dashboard -->
        <?= sidebarLink(url('dashboard'), 'fa-gauge-high', 'Dashboard', $currentPath, 'dashboard') ?>

        <?php if (!isAdmin()): ?>

        <?php if ($__isServiceBiz): ?>
        <!-- ═══ SERVICE BUSINESS NAVIGATION ═══ -->

        <!-- ORDERS group -->
        <?php if (hasPermission('sales')): ?>
        <?php
        $ordContent =
            sidebarLink(url('service-orders'), 'fa-clipboard-list', 'Service Orders', $currentPath, 'service_orders') .
            sidebarLink(url('customers'),       'fa-users',          'Customers',      $currentPath, 'customers');
        if (!hasPermission('reports')) {
            $ordContent .= sidebarLink(url('alerts'), 'fa-bell', 'Smart Alerts', $currentPath, 'alerts');
        }
        echo navGroup('orders', 'fa-bag-shopping', 'Orders', '#22c55e', $ordContent, $activeSection === 'orders');
        ?>
        <?php endif; ?>

        <!-- SERVICE CATALOG group -->
        <?php if (hasPermission('inventory') || hasPermission('sales')): ?>
        <?php
        $svcContent = sidebarLink(url('services'), 'fa-hand-holding-heart', 'Our Services', $currentPath, 'services');
        if (hasPermission('inventory')) {
            $svcContent .= sidebarLink(url('products/categories'), 'fa-tags', 'Categories', $currentPath, 'categories');
        }
        echo navGroup('service_catalog', 'fa-briefcase', 'Service Catalog', '#f59e0b', $svcContent, $activeSection === 'service_catalog');
        ?>
        <?php endif; ?>

        <?php else: ?>
        <!-- ═══ PRODUCT BUSINESS NAVIGATION ═══ -->

        <!-- SALES group -->
        <?php if (hasPermission('sales')): ?>
        <?php
        $salesContent =
            sidebarLink(url('sales'),     'fa-receipt', 'Sales',     $currentPath, '/sales/') .
            sidebarLink(url('customers'), 'fa-users',   'Customers', $currentPath, 'customers');
        if (!hasPermission('reports')) {
            $salesContent .= sidebarLink(url('alerts'), 'fa-bell', 'Smart Alerts', $currentPath, 'alerts');
        }
        if (!hasPermission('inventory')) {
            $salesContent .= sidebarLink(url('products'), 'fa-boxes-stacked', 'Products (Stock)', $currentPath, 'products');
        }
        echo navGroup('sales', 'fa-bag-shopping', 'Sales', '#22c55e', $salesContent, $activeSection === 'sales');
        ?>
        <?php endif; ?>

        <!-- INVENTORY group -->
        <?php if (hasPermission('inventory')): ?>
        <?php
        $invContent =
            sidebarLink(url('products'),            'fa-boxes-stacked', 'Products',   $currentPath, 'products') .
            sidebarLink(url('products/categories'), 'fa-tags',           'Categories', $currentPath, 'categories');
        echo navGroup('inventory', 'fa-warehouse', 'Inventory', '#f59e0b', $invContent, $activeSection === 'inventory');
        ?>
        <?php endif; ?>

        <?php endif; // $__isServiceBiz ?>

        <!-- FINANCE group (shared by both business types) -->
        <?php if (hasPermission('debts') || hasPermission('payments') || hasPermission('expenses')): ?>
        <?php
        $finContent = '';
        if (hasPermission('debts'))    $finContent .= sidebarLink(url('debts'),    'fa-file-invoice-dollar',   'Debts',    $currentPath, 'debts');
        if (hasPermission('payments')) $finContent .= sidebarLink(url('payments'), 'fa-money-bill-transfer',   'Payments', $currentPath, 'payments');
        if (hasPermission('expenses')) $finContent .= sidebarLink(url('expenses'), 'fa-wallet',                'Expenses', $currentPath, 'expenses');
        if (hasPermission('expenses')) $finContent .= sidebarLink(url('income'),   'fa-circle-dollar-to-slot', 'Income',   $currentPath, 'income');
        echo navGroup('finance', 'fa-coins', 'Finance', '#ec4899', $finContent, $activeSection === 'finance');
        ?>
        <?php endif; ?>

        <!-- ANALYTICS group (both types, service businesses get service-specific reports) -->
        <?php if (hasPermission('reports')): ?>
        <?php
        if ($__isServiceBiz) {
            $repContent =
                sidebarLink(url('reports/service-revenue'), 'fa-chart-bar',           'Revenue Report',   $currentPath, 'reports/service_revenue') .
                sidebarLink(url('reports/payments'),        'fa-money-bill-trend-up', 'Payment Report',   $currentPath, 'reports/payments') .
                sidebarLink(url('reports/customers'),       'fa-users-line',          'Customer Report',  $currentPath, 'reports/customers') .
                sidebarLink(url('alerts'),                  'fa-bell',                'Smart Alerts',     $currentPath, 'alerts');
        } else {
            $repContent =
                sidebarLink(url('reports/sales'),        'fa-chart-bar',           'Sales Report',     $currentPath, 'reports/sales') .
                sidebarLink(url('reports/financial'),    'fa-chart-pie',           'Financial Report', $currentPath, 'reports/financial') .
                sidebarLink(url('reports/inventory'),    'fa-warehouse',           'Inventory Report', $currentPath, 'reports/inventory') .
                sidebarLink(url('reports/debts'),        'fa-file-invoice-dollar', 'Debt Report',      $currentPath, 'reports/debts') .
                sidebarLink(url('reports/payments'),     'fa-money-bill-trend-up', 'Payment Report',   $currentPath, 'reports/payments') .
                sidebarLink(url('reports/customers'),    'fa-users-line',          'Customer Report',  $currentPath, 'reports/customers') .
                sidebarLink(url('alerts'),               'fa-bell',                'Smart Alerts',     $currentPath, 'alerts');
        }
        echo navGroup('analytics', 'fa-chart-line', 'Analytics', '#a78bfa', $repContent, $activeSection === 'analytics');
        ?>
        <?php endif; ?>

        <?php endif; // !isAdmin() ?>

        <!-- ADMINISTRATION group -->
        <?php if (isAdmin() || hasPermission('users')): ?>
        <?php
        $adminContent = sidebarLink(url('admin/users'), 'fa-user-shield', 'Users', $currentPath, 'admin/users');
        if (isAdmin()) $adminContent .= sidebarLink(url('admin/businesses'), 'fa-building', 'Businesses',      $currentPath, 'admin/businesses');
        if (isAdmin()) $adminContent .= sidebarLink(url('admin/settings'),   'fa-sliders',  'Settings',        $currentPath, 'admin/settings');
        if (isAdmin()) $adminContent .= sidebarLink(url('support/tickets'),  'fa-ticket',   'Support Tickets', $currentPath, 'support/tickets');
        echo navGroup('admin', 'fa-screwdriver-wrench', 'Administration', '#38bdf8', $adminContent, $activeSection === 'admin');
        ?>
        <?php endif; ?>

        <!-- Profile -->
        <div class="sb-divider">Account</div>
        <?php if (!isAdmin()): ?>
        <?= sidebarLink(url('support'), 'fa-headset', 'Support', $currentPath, 'support/index') ?>
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

<script>
(function () {
    // Toggle dropdown groups
    document.querySelectorAll('.group-trigger').forEach(btn => {
        btn.addEventListener('click', function () {
            const group = this.closest('.nav-group');
            const isOpen = group.classList.contains('open');

            // Close all other groups
            document.querySelectorAll('.nav-group.open').forEach(g => {
                if (g !== group) g.classList.remove('open');
            });

            group.classList.toggle('open', !isOpen);
        });
    });
})();
</script>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- MAIN CONTENT WRAPPER                                   -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="flex-1 flex flex-col overflow-hidden">

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
            <a href="<?= isAdmin() ? url('support/tickets') : url('support') ?>"
               class="relative text-gray-500 hover:text-blue-600 transition-colors p-1"
               title="<?= isAdmin() ? 'Support Tickets' : 'Support' ?>">
                <i class="fa-solid fa-bell text-lg"></i>
                <?php if ($__bellCount > 0): ?>
                <span class="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 px-0.5 bg-red-500 text-white text-[9px] font-bold rounded-full flex items-center justify-center">
                    <?= $__bellCount > 99 ? '99+' : $__bellCount ?>
                </span>
                <?php endif; ?>
            </a>
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
