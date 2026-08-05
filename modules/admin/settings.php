<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requireLogin();
if (!isAdmin()) {
    http_response_code(403);
    include APP_PATH . '/includes/403.php';
    exit;
}

$db = getDB();

// Ensure core tables exist
$db->exec("CREATE TABLE IF NOT EXISTS `business_features` (
    `business_id` INT UNSIGNED NOT NULL,
    `feature_key` VARCHAR(60) NOT NULL,
    `status` ENUM('enabled','disabled','coming_soon') NOT NULL DEFAULT 'enabled',
    PRIMARY KEY (`business_id`,`feature_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS `system_settings` (
    `key` VARCHAR(100) NOT NULL,
    `value` TEXT DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Load all settings as key => value map
$settingsRows = $db->query("SELECT `key`, `value` FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

$activeTab    = get('tab', 'smtp');
$errors       = [];
$dangerErrors = [];

$smtpTestResult = null; // [bool $ok, string[] $log]

// ==================== SAVE SMTP ====================
if (isPost() && post('_tab') === 'smtp' && post('_action') !== 'test') {
    verifyCsrf();
    $smtpFields = ['smtp_host','smtp_port','smtp_username','smtp_password',
                   'smtp_from_email','smtp_from_name','smtp_encryption'];
    $upsert = $db->prepare("INSERT INTO system_settings (`key`,`value`) VALUES (?,?)
                            ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
    foreach ($smtpFields as $f) {
        $upsert->execute([$f, post($f)]);
    }
    flash('success', 'SMTP settings saved successfully.');
    redirect(url('admin/settings') . '?tab=smtp');
}

// ==================== TEST EMAIL ====================
if (isPost() && post('_tab') === 'smtp' && post('_action') === 'test') {
    verifyCsrf();
    require_once __DIR__ . '/../../app/lib/Mailer.php';
    require_once __DIR__ . '/../../app/includes/mail.php';
    $testTo = trim(post('test_to'));
    if (!$testTo || !filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid test email address.';
        $activeTab = 'smtp';
    } else {
        // Reload fresh settings row
        $freshRows = $db->query("SELECT `key`,`value` FROM system_settings WHERE `key` LIKE 'smtp_%'")->fetchAll(\PDO::FETCH_KEY_PAIR);
        $mailer = new \App\Lib\Mailer([
            'host'       => $freshRows['smtp_host']       ?? '',
            'port'       => (int)($freshRows['smtp_port'] ?? 587),
            'username'   => $freshRows['smtp_username']   ?? '',
            'password'   => $freshRows['smtp_password']   ?? '',
            'encryption' => $freshRows['smtp_encryption'] ?? 'tls',
            'fromEmail'  => $freshRows['smtp_from_email'] ?: ($freshRows['smtp_username'] ?? ''),
            'fromName'   => $freshRows['smtp_from_name']  ?? APP_NAME,
        ]);
        $html = '<h2 style="font-family:sans-serif">SMTP Test</h2>
                 <p style="font-family:sans-serif">This is a test email from <strong>' . h(APP_NAME) . '</strong>.<br>
                 If you received this, your SMTP configuration is working correctly.</p>
                 <p style="font-family:sans-serif;color:#6b7280;font-size:12px">Sent: ' . date('r') . '</p>';
        $ok  = $mailer->sendHtml($testTo, 'SMTP Test — ' . APP_NAME, $html);
        $smtpTestResult = ['ok' => $ok, 'log' => $mailer->getLog(), 'to' => $testTo];
        $activeTab = 'smtp';
    }
}

// ==================== ADD CURRENCY ====================
if (isPost() && post('_tab') === 'currencies') {
    verifyCsrf();
    $code   = strtoupper(trim(post('curr_code')));
    $name   = trim(post('curr_name'));
    $symbol = trim(post('curr_symbol'));
    if (!$code)   $errors[] = 'Currency code is required.';
    if (!$name)   $errors[] = 'Currency name is required.';
    if (!$symbol) $errors[] = 'Currency symbol is required.';
    if (empty($errors)) {
        $list = _settingsLoadCurrencies($settingsRows);
        if (in_array($code, array_column($list, 'code'))) {
            $errors[] = "Currency code '{$code}' already exists.";
        } else {
            $list[] = ['code'=>$code, 'name'=>$name, 'symbol'=>$symbol];
            _settingsSaveCurrencies($db, $list);
            flash('success', "Currency '{$code}' added.");
            redirect(url('admin/settings') . '?tab=currencies');
        }
    }
    $activeTab = 'currencies';
}

// ==================== DELETE CURRENCY ====================
if (get('action') === 'delcurrency' && get('code')) {
    verifyCsrf();
    $code = strtoupper(get('code'));
    $list = _settingsLoadCurrencies($settingsRows);
    $list = array_values(array_filter($list, fn($c) => $c['code'] !== $code));
    _settingsSaveCurrencies($db, $list);
    flash('success', "Currency '{$code}' removed.");
    redirect(url('admin/settings') . '?tab=currencies');
}

// ==================== AJAX: TOGGLE SINGLE FEATURE ====================
if (isPost() && post('_tab') === 'feature_toggle') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        verifyCsrf();
        $ajaxBizId = (int)($_POST['biz_id'] ?? 0);
        $ajaxKey   = preg_replace('/[^a-z_]/', '', $_POST['key'] ?? '');
        $ajaxSt    = $_POST['status'] ?? '';
        if ($ajaxBizId < 1 || !$ajaxKey || !in_array($ajaxSt, ['enabled','disabled','coming_soon'])) {
            echo json_encode(['ok' => false, 'error' => 'Invalid input']);
        } else {
            $db->prepare("INSERT INTO business_features (business_id, feature_key, status) VALUES (?,?,?)
                         ON DUPLICATE KEY UPDATE status=VALUES(status)")
               ->execute([$ajaxBizId, $ajaxKey, $ajaxSt]);
            echo json_encode(['ok' => true]);
        }
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Server error']);
    }
    exit;
}

function _allFeatureKeys(): array {
    return [
        // Sales & Commerce
        'sales_pos'                  => ['label' => 'Sales & POS',           'section' => 'Sales & Commerce'],
        'sales_customers'            => ['label' => 'Customer Management',   'section' => 'Sales & Commerce'],
        'finance_debts'              => ['label' => 'Debts & Credit Sales',  'section' => 'Sales & Commerce'],
        'finance_payments'           => ['label' => 'Debt Payments',         'section' => 'Sales & Commerce'],
        // Finance
        'finance_expenses'           => ['label' => 'Expenses',              'section' => 'Finance'],
        'finance_income'             => ['label' => 'Income',                'section' => 'Finance'],
        'finance_drawings'           => ['label' => 'Owner Drawings',        'section' => 'Finance'],
        // Inventory
        'inventory_products'         => ['label' => 'Products & Inventory',  'section' => 'Inventory'],
        'inventory_adjustments'      => ['label' => 'Stock Adjustments',     'section' => 'Inventory'],
        // Branches
        'branches_management'        => ['label' => 'Branch Management',     'section' => 'Branches'],
        // Analytics
        'analytics_sales_report'     => ['label' => 'Sales Report',          'section' => 'Analytics'],
        'analytics_financial_report' => ['label' => 'Financial Report (P&L)','section' => 'Analytics'],
        'analytics_inventory_report' => ['label' => 'Inventory Report',      'section' => 'Analytics'],
        'analytics_debt_report'      => ['label' => 'Debt Report',           'section' => 'Analytics'],
        'analytics_payment_report'   => ['label' => 'Payment Report',        'section' => 'Analytics'],
        'analytics_customer_report'  => ['label' => 'Customer Report',       'section' => 'Analytics'],
        'analytics_revenue_report'   => ['label' => 'Revenue Report',        'section' => 'Analytics'],
        'analytics_balance_sheet'    => ['label' => 'Balance Sheet',         'section' => 'Analytics'],
        'analytics_smart_alerts'     => ['label' => 'Smart Alerts',          'section' => 'Analytics'],
        // Support
        'support_tickets'            => ['label' => 'Support Tickets',       'section' => 'Support'],
    ];
}

function _settingsLoadCurrencies(array $rows): array {
    $list = json_decode($rows['currencies'] ?? '[]', true) ?: [];
    if (empty($list)) {
        $list = [
            ['code'=>'NLE','name'=>'Sierra Leone Leone (New)','symbol'=>'NLE'],
            ['code'=>'SLE','name'=>'Sierra Leone Leone','symbol'=>'Le'],
            ['code'=>'USD','name'=>'US Dollar','symbol'=>'$'],
            ['code'=>'GBP','name'=>'British Pound','symbol'=>'£'],
            ['code'=>'EUR','name'=>'Euro','symbol'=>'€'],
            ['code'=>'NGN','name'=>'Nigerian Naira','symbol'=>'₦'],
            ['code'=>'GHS','name'=>'Ghanaian Cedi','symbol'=>'₵'],
        ];
    }
    return $list;
}

function _settingsSaveCurrencies(PDO $db, array $list): void {
    $stmt = $db->prepare("INSERT INTO system_settings (`key`,`value`) VALUES ('currencies',?)
                          ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
    $stmt->execute([json_encode($list, JSON_UNESCAPED_UNICODE)]);
}

function _dangerCategories(): array {
    return [
        'sales'         => ['label' => 'Sales & Invoices',         'tables' => ['sale_items', 'sales'],                                     'icon' => 'fa-chart-line',    'desc' => 'All sale records and line items'],
        'debts'         => ['label' => 'Debts & Payments',         'tables' => ['debt_payments', 'debts', 'payments'],                      'icon' => 'fa-coins',         'desc' => 'Debt records and payment history'],
        'finance'       => ['label' => 'Expenses & Income',        'tables' => ['expenses', 'income'],                                      'icon' => 'fa-wallet',        'desc' => 'Expense and income entries'],
        'drawings'      => ['label' => 'Owner Drawings',           'tables' => ['drawings'],                                                'icon' => 'fa-money-bill-wave','desc' => 'Owner withdrawal / drawing records'],
        'customers'     => ['label' => 'Customers',                'tables' => ['customers'],                                               'icon' => 'fa-users',         'desc' => 'Customer directory'],
        'products'      => ['label' => 'Products & Categories',    'tables' => ['products', 'categories'],                                               'icon' => 'fa-box',           'desc' => 'Product catalogue and categories'],
        'suppliers'     => ['label' => 'Suppliers & Purchases',    'tables' => ['supplier_payments', 'supplier_purchases', 'suppliers'],              'icon' => 'fa-truck',         'desc' => 'Supplier directory, purchase orders and supplier payments'],
        'inventory'     => ['label' => 'Inventory Log',            'tables' => ['inventory_transactions'],                                           'icon' => 'fa-warehouse',     'desc' => 'Stock adjustment and purchase history'],
        'branches'      => ['label' => 'Branch Data',              'tables' => ['branches'],                                                'icon' => 'fa-code-branch',   'desc' => 'Branch records (Main Branch re-created on next visit)'],
        'support'       => ['label' => 'Support Tickets',          'tables' => ['support_replies', 'support_tickets'],                      'icon' => 'fa-headset',       'desc' => 'All support tickets and replies'],
        'notifications' => ['label' => 'Notifications',            'tables' => ['notifications'],                                           'icon' => 'fa-bell',          'desc' => 'In-app notification history'],
        'businesses'    => ['label' => 'Businesses',               'tables' => ['businesses'],                                              'icon' => 'fa-building',      'desc' => 'Business accounts'],
        'users'         => ['label' => 'Non-Admin Users',          'tables' => [],                                                          'icon' => 'fa-user-xmark',    'desc' => 'All user accounts except yours'],
        'subscriptions' => ['label' => 'Subscriptions & Features', 'tables' => ['business_subscriptions', 'business_features'],             'icon' => 'fa-credit-card',   'desc' => 'Business plans and feature settings'],
        'landing'       => ['label' => 'Demo Requests & Contacts', 'tables' => ['landing_demos', 'landing_contacts', 'landing_newsletter'], 'icon' => 'fa-calendar-check','desc' => 'Demo bookings, contact forms, newsletter'],
    ];
}

// ==================== SAVE GENERAL SETTINGS ====================
if (isPost() && post('_tab') === 'general') {
    verifyCsrf();
    $generalFields = [
        'contact_email', 'contact_phone', 'contact_whatsapp',
        'contact_location', 'contact_hours',
        'social_facebook', 'social_twitter', 'social_instagram',
        'social_whatsapp', 'social_linkedin',
    ];
    $upsertG = $db->prepare("INSERT INTO system_settings (`key`,`value`) VALUES (?,?)
                             ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
    foreach ($generalFields as $f) {
        $upsertG->execute([$f, trim((string)(post($f) ?? ''))]);
    }
    flash('success', 'General settings saved successfully.');
    redirect(url('admin/settings') . '?tab=general');
}

// ==================== SAVE DEMO FEE ====================
if (isPost() && post('_tab') === 'demo') {
    verifyCsrf();
    $demoFields = ['demo_fee_enabled','demo_fee_amount','demo_fee_currency','demo_payment_instructions'];
    $upsertD = $db->prepare("INSERT INTO system_settings (`key`,`value`) VALUES (?,?)
                             ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
    $upsertD->execute(['demo_fee_enabled',  post('demo_fee_enabled') === '1' ? '1' : '0']);
    $upsertD->execute(['demo_fee_amount',   trim((string)(post('demo_fee_amount') ?? ''))]);
    $upsertD->execute(['demo_fee_currency', trim((string)(post('demo_fee_currency') ?? ''))]);
    $upsertD->execute(['demo_payment_instructions', trim((string)(post('demo_payment_instructions') ?? ''))]);
    flash('success', 'Demo fee settings saved.');
    redirect(url('admin/settings') . '?tab=demo');
}

// ==================== CLEAR SELECTED DATA ====================
if (isPost() && post('_tab') === 'danger' && post('_action') === 'clear_selected') {
    verifyCsrf();
    $me           = currentUser();
    $confirm      = trim((string)(post('confirm_phrase') ?? ''));
    $selected     = array_filter((array)(post('categories') ?? []));
    $allCats      = _dangerCategories();
    $dangerErrors = [];

    if (empty($selected)) {
        $dangerErrors[] = 'Select at least one data category to delete.';
    }
    if ($confirm !== 'PERMANENTLY DELETE') {
        $dangerErrors[] = 'Confirmation phrase does not match. Type exactly: PERMANENTLY DELETE';
    }
    $selected = array_values(array_intersect($selected, array_keys($allCats)));

    if (empty($dangerErrors)) {
        $db->exec("SET FOREIGN_KEY_CHECKS=0");
        foreach ($selected as $catKey) {
            $cat = $allCats[$catKey] ?? null;
            if (!$cat) continue;
            foreach ($cat['tables'] as $t) {
                try { $db->exec("TRUNCATE TABLE `{$t}`"); } catch (\Exception $e) {}
            }
            if ($catKey === 'users') {
                try { $db->prepare("DELETE FROM users WHERE id != ?")->execute([$me['id']]); } catch (\Exception $e) {}
            }
            if ($catKey === 'businesses') {
                try { $db->prepare("UPDATE users SET business_id=NULL WHERE id=?")->execute([$me['id']]); } catch (\Exception $e) {}
            }
        }
        $db->exec("SET FOREIGN_KEY_CHECKS=1");
        $catLbls = implode(', ', array_map(fn($k) => $allCats[$k]['label'], $selected));
        flash('success', "Data cleared: {$catLbls}. Your admin account and system settings are preserved.");
        redirect(url('admin/settings') . '?tab=danger');
    }
    $activeTab = 'danger';
}

// Reload in case of edits
$settingsRows = $db->query("SELECT `key`, `value` FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currencies   = _settingsLoadCurrencies($settingsRows);

$pageTitle = 'System Settings';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-800">System Settings</h2>
        <p class="text-sm text-gray-500">Platform-wide configuration</p>
    </div>
</div>

<!-- Tab Bar -->
<div class="flex gap-0 mb-6 border-b border-gray-200">
    <a href="settings.php?tab=smtp"
       class="px-5 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors
              <?= $activeTab === 'smtp' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">
        <i class="fa-solid fa-envelope mr-1.5"></i> SMTP Config
    </a>
    <a href="settings.php?tab=currencies"
       class="px-5 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors
              <?= $activeTab === 'currencies' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">
        <i class="fa-solid fa-coins mr-1.5"></i> Currencies
    </a>
    <a href="settings.php?tab=features"
       class="px-5 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors
              <?= $activeTab === 'features' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">
        <i class="fa-solid fa-toggle-on mr-1.5"></i> Features
    </a>
    <a href="settings.php?tab=general"
       class="px-5 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors
              <?= $activeTab === 'general' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">
        <i class="fa-solid fa-sliders mr-1.5"></i> General
    </a>
    <a href="settings.php?tab=demo"
       class="px-5 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors
              <?= $activeTab === 'demo' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">
        <i class="fa-solid fa-calendar-check mr-1.5"></i> Demo Fee
    </a>
    <a href="settings.php?tab=danger"
       class="ml-auto px-5 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors
              <?= $activeTab === 'danger' ? 'border-red-500 text-red-600' : 'border-transparent text-red-400 hover:text-red-600' ?>">
        <i class="fa-solid fa-triangle-exclamation mr-1.5"></i> Danger Zone
    </a>
</div>

<?php if ($activeTab === 'smtp'): ?>
<!-- ─── SMTP Tab ─────────────────────────────────────────── -->
<div class="grid grid-cols-1 xl:grid-cols-5 gap-6">
<div class="xl:col-span-3">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h3 class="font-semibold text-gray-800 mb-1">SMTP Configuration</h3>
        <p class="text-sm text-gray-500 mb-5">Outgoing email settings for invoices, notifications, and reports.</p>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="_tab"       value="smtp">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">SMTP Host</label>
                    <input type="text" name="smtp_host" value="<?= h($settingsRows['smtp_host'] ?? '') ?>"
                        placeholder="smtp.gmail.com"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Port</label>
                    <input type="number" name="smtp_port" value="<?= h($settingsRows['smtp_port'] ?? '587') ?>"
                        placeholder="587"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Encryption</label>
                    <select name="smtp_encryption" class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
                        <option value="tls"  <?= ($settingsRows['smtp_encryption'] ?? 'tls') === 'tls'  ? 'selected' : '' ?>>TLS (port 587 — recommended)</option>
                        <option value="ssl"  <?= ($settingsRows['smtp_encryption'] ?? '') === 'ssl'  ? 'selected' : '' ?>>SSL (port 465)</option>
                        <option value="none" <?= ($settingsRows['smtp_encryption'] ?? '') === 'none' ? 'selected' : '' ?>>None (port 25)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                    <input type="text" name="smtp_username" value="<?= h($settingsRows['smtp_username'] ?? '') ?>"
                        placeholder="your@email.com"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <input type="password" name="smtp_password" value="<?= h($settingsRows['smtp_password'] ?? '') ?>"
                        placeholder="App password or SMTP password"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">From Email</label>
                    <input type="email" name="smtp_from_email" value="<?= h($settingsRows['smtp_from_email'] ?? '') ?>"
                        placeholder="noreply@yourdomain.com"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">From Name</label>
                    <input type="text" name="smtp_from_name" value="<?= h($settingsRows['smtp_from_name'] ?? APP_NAME) ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
            </div>

            <div class="mt-6">
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
                    <i class="fa-solid fa-save mr-1"></i> Save SMTP Settings
                </button>
            </div>
        </form>

        <?php if (!empty($settingsRows['smtp_host'])): ?>
        <div class="mt-4 p-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-700 flex items-center gap-2">
            <i class="fa-solid fa-circle-check"></i>
            SMTP configured:
            <strong><?= h($settingsRows['smtp_host']) ?>:<?= h($settingsRows['smtp_port'] ?? '587') ?></strong>
            · <?= h(strtoupper($settingsRows['smtp_encryption'] ?? 'tls')) ?>
            · from <strong><?= h($settingsRows['smtp_from_email'] ?? '') ?></strong>
        </div>
        <?php endif; ?>
    </div>
</div><!-- end xl:col-span-3 -->

<!-- Right column: test email + tips -->
<div class="xl:col-span-2 space-y-4">
    <!-- Test Email -->
    <?php if (!empty($settingsRows['smtp_host'])): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h4 class="font-semibold text-gray-800 mb-1">Send Test Email</h4>
        <p class="text-sm text-gray-500 mb-4">Verify your SMTP settings work by sending a test message right now.</p>

        <?php if ($smtpTestResult !== null): ?>
        <div class="mb-4 p-4 rounded-lg border <?= $smtpTestResult['ok'] ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200' ?>">
            <p class="font-semibold <?= $smtpTestResult['ok'] ? 'text-green-700' : 'text-red-700' ?> mb-2">
                <i class="fa-solid <?= $smtpTestResult['ok'] ? 'fa-circle-check' : 'fa-circle-xmark' ?> mr-1"></i>
                <?= $smtpTestResult['ok']
                    ? 'Test email sent successfully to <strong>' . h($smtpTestResult['to']) . '</strong>.'
                    : 'Test email failed. Check the log below.' ?>
            </p>
            <details class="text-xs">
                <summary class="cursor-pointer text-gray-500 hover:text-gray-700 mb-1">Show SMTP log</summary>
                <div class="bg-gray-900 text-green-300 rounded-lg p-3 font-mono mt-2 overflow-x-auto">
                    <?php foreach ($smtpTestResult['log'] as $line): ?>
                    <div><?= h($line) ?></div>
                    <?php endforeach; ?>
                </div>
            </details>
        </div>
        <?php endif; ?>

        <form method="POST" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="_tab"    value="smtp">
            <input type="hidden" name="_action" value="test">
            <input type="email" name="test_to" required
                   value="<?= h($smtpTestResult['to'] ?? '') ?>"
                   placeholder="Recipient email address…"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
            <button type="submit" class="w-full bg-indigo-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-indigo-700">
                <i class="fa-solid fa-paper-plane mr-1"></i> Send Test Email
            </button>
        </form>
    </div>
    <?php else: ?>
    <div class="p-4 bg-blue-50 border border-blue-200 rounded-xl text-sm text-blue-700">
        <i class="fa-solid fa-info-circle mr-1"></i>
        Save your SMTP settings to enable the test email feature.
    </div>
    <?php endif; ?>

    <!-- Tips card -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-5 py-3 bg-amber-50 border-b border-amber-100 flex items-center gap-2">
            <i class="fa-solid fa-lightbulb text-amber-500 text-sm"></i>
            <h4 class="font-semibold text-amber-800 text-sm">Quick Setup Tips</h4>
        </div>
        <div class="p-4 space-y-3">

            <!-- Port Guide -->
            <div class="rounded-lg border border-gray-100 bg-gray-50 overflow-hidden">
                <p class="px-3 py-1.5 text-[10px] font-bold uppercase tracking-widest text-gray-400 bg-gray-100 border-b border-gray-100">Port Guide</p>
                <div class="divide-y divide-gray-100">
                    <div class="flex items-center gap-3 px-3 py-2">
                        <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-green-100 text-green-700 flex-shrink-0">
                            <i class="fa-solid fa-shield-halved"></i> 587 TLS
                        </span>
                        <span class="text-xs text-gray-600">Recommended — Gmail, Hostinger, most providers</span>
                    </div>
                    <div class="flex items-center gap-3 px-3 py-2">
                        <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 flex-shrink-0">
                            <i class="fa-solid fa-lock"></i> 465 SSL
                        </span>
                        <span class="text-xs text-gray-600">Fallback if TLS port 587 does not connect</span>
                    </div>
                    <div class="flex items-center gap-3 px-3 py-2">
                        <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-gray-100 text-gray-500 flex-shrink-0">
                            <i class="fa-solid fa-triangle-exclamation"></i> 25
                        </span>
                        <span class="text-xs text-gray-500">Unencrypted — avoid unless required</span>
                    </div>
                </div>
            </div>

            <!-- Gmail tip -->
            <div class="rounded-lg border border-blue-100 bg-blue-50 p-3 flex gap-3">
                <div class="w-7 h-7 rounded-md bg-white border border-blue-100 flex items-center justify-center flex-shrink-0 shadow-sm">
                    <span class="text-sm font-black" style="color:#EA4335">G</span>
                </div>
                <div>
                    <p class="text-xs font-semibold text-blue-800 mb-0.5">Gmail requires an App Password</p>
                    <p class="text-[11px] text-blue-600 leading-relaxed">Go to Google Account → Security → 2-Step Verification → App Passwords. Use the generated 16-character password here, <em>not</em> your Google login password.</p>
                </div>
            </div>

            <!-- Best practices -->
            <div class="space-y-1.5">
                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Best Practices</p>
                <div class="flex items-start gap-2">
                    <i class="fa-solid fa-circle-check text-green-500 text-xs mt-0.5 flex-shrink-0"></i>
                    <span class="text-xs text-gray-600"><strong>From Email</strong> should match your SMTP username to avoid spam filters</span>
                </div>
                <div class="flex items-start gap-2">
                    <i class="fa-solid fa-circle-check text-green-500 text-xs mt-0.5 flex-shrink-0"></i>
                    <span class="text-xs text-gray-600">Always send a <strong>test email</strong> after saving to confirm delivery</span>
                </div>
                <div class="flex items-start gap-2">
                    <i class="fa-solid fa-circle-check text-green-500 text-xs mt-0.5 flex-shrink-0"></i>
                    <span class="text-xs text-gray-600">Use a dedicated <strong>transactional email</strong> address (not a personal inbox)</span>
                </div>
            </div>

        </div>
    </div>
</div><!-- end right column -->
</div><!-- end smtp grid -->

<?php elseif ($activeTab === 'currencies'): ?>
<!-- ─── Currencies Tab ───────────────────────────────────── -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- Currency list -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100">
            <div class="p-4 border-b border-gray-100">
                <h3 class="font-semibold text-gray-800">Available Currencies</h3>
                <p class="text-sm text-gray-500 mt-0.5">These appear in the Business currency dropdown when editing a business.</p>
            </div>
            <div class="table-responsive">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                        <tr>
                            <th class="px-4 py-3 font-medium text-left">Code</th>
                            <th class="px-4 py-3 font-medium text-left">Name</th>
                            <th class="px-4 py-3 font-medium text-center">Symbol</th>
                            <th class="px-4 py-3 font-medium text-center">Remove</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach ($currencies as $curr): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-mono font-bold text-blue-700"><?= h($curr['code']) ?></td>
                            <td class="px-4 py-3 text-gray-700"><?= h($curr['name']) ?></td>
                            <td class="px-4 py-3 text-center font-semibold text-gray-800"><?= h($curr['symbol']) ?></td>
                            <td class="px-4 py-3 text-center">
                                <a href="settings.php?tab=currencies&action=delcurrency&code=<?= urlencode($curr['code']) ?>&csrf=<?= csrfToken() ?>"
                                   class="text-red-400 hover:text-red-700 p-1"
                                   title="Remove"
                                   onclick="return confirm('Remove <?= h(addslashes($curr['code'])) ?> from the currency list?')">
                                    <i class="fa-solid fa-trash text-xs"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($currencies)): ?>
                        <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">No currencies configured.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add currency form -->
    <div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h4 class="font-semibold text-gray-800 mb-4">Add Currency</h4>
            <?php if (!empty($errors)): ?>
            <div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-4">
                <?php foreach ($errors as $e): ?>
                <p class="text-red-700 text-sm"><?= h($e) ?></p>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="_tab"       value="currencies">
                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Code <span class="text-gray-400 font-normal">(3–4 letters, e.g. USD)</span>
                        </label>
                        <input type="text" name="curr_code" maxlength="10" placeholder="USD"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                            style="text-transform:uppercase">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                        <input type="text" name="curr_name" placeholder="US Dollar"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Symbol <span class="text-gray-400 font-normal">(shown in transactions)</span>
                        </label>
                        <input type="text" name="curr_symbol" maxlength="10" placeholder="$"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded-lg text-sm font-medium hover:bg-blue-700 mt-1">
                        <i class="fa-solid fa-plus mr-1"></i> Add Currency
                    </button>
                </div>
            </form>
        </div>

        <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-xl text-xs text-blue-700">
            <i class="fa-solid fa-info-circle mr-1"></i>
            When a business's currency is changed, the symbol updates automatically across all transactions for that business on the next page load.
        </div>
    </div>
</div>
<?php elseif ($activeTab === 'features'): ?>
<!-- ─── Features Tab ──────────────────────────────────────── -->
<?php
$featBizId  = (int)get('biz', 0);
$allBizList = $db->query("SELECT id, name FROM businesses WHERE is_active=1 ORDER BY name")->fetchAll();

$curFeats = [];
if ($featBizId) {
    $cfStmt = $db->prepare("SELECT feature_key, status FROM business_features WHERE business_id=?");
    $cfStmt->execute([$featBizId]);
    $curFeats = $cfStmt->fetchAll(PDO::FETCH_KEY_PAIR);
}
$allFeatureKeys = _allFeatureKeys();

$featSections = [];
foreach ($allFeatureKeys as $key => $meta) {
    $featSections[$meta['section']][$key] = $meta['label'];
}

$__sectionIconMap = [
    'Sales & Commerce' => 'fa-shopping-cart text-blue-500',
    'Finance'          => 'fa-coins text-pink-500',
    'Inventory'        => 'fa-boxes-stacked text-orange-500',
    'Branches'         => 'fa-code-branch text-teal-500',
    'Analytics'        => 'fa-chart-line text-purple-500',
    'Support'          => 'fa-headset text-indigo-500',
];
?>
<style>
/* Fix the page scroll entirely — features tab manages its own scroll internally */
html, body { overflow: hidden !important; }
main {
    overflow: hidden !important;
    display: flex !important;
    flex-direction: column !important;
}
#feat-outer {
    flex: 1 1 0;
    min-height: 0; /* allow flex child to shrink below content size */
    display: flex;
    gap: 24px;
    overflow: hidden;
}
#feat-left {
    width: 256px;
    flex-shrink: 0;
    overflow-y: auto;
    scrollbar-width: thin;
}
#feat-right {
    flex: 1;
    min-width: 0;
    overflow-y: auto;
    scrollbar-width: thin;
    padding-right: 2px;
}
#feat-right::-webkit-scrollbar,
#feat-left::-webkit-scrollbar  { width: 4px; }
#feat-right::-webkit-scrollbar-track,
#feat-left::-webkit-scrollbar-track  { background: transparent; }
#feat-right::-webkit-scrollbar-thumb,
#feat-left::-webkit-scrollbar-thumb  { background: #d1d5db; border-radius: 2px; }

.feat-btn {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 600;
    cursor: pointer; border: none; background: none;
    transition: background .12s, color .12s, opacity .12s;
}
.feat-btn:disabled { opacity: .5; cursor: wait; }
.feat-off             { background: #f3f4f6; color: #9ca3af; }
.feat-off:hover       { background: #e5e7eb; color: #6b7280; }
.feat-enabled-active  { background: #d1fae5; color: #059669; }
.feat-soon-active     { background: #fef3c7; color: #d97706; }
.feat-disabled-active { background: #fee2e2; color: #dc2626; }
</style>

<div id="feat-outer">

    <!-- ── Left: Business Selector ──────────────────────────── -->
    <div id="feat-left">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <h4 class="font-semibold text-gray-700 text-sm mb-2">Select Business</h4>
            <?php if (empty($allBizList)): ?>
            <p class="text-sm text-gray-400">No active businesses found.</p>
            <?php else: ?>
            <div class="relative mb-3">
                <i class="fa-solid fa-magnifying-glass absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text" id="feat-biz-search" placeholder="Search business…"
                       class="w-full pl-7 pr-3 py-1.5 text-sm border border-gray-200 rounded-lg outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-400">
            </div>
            <div class="space-y-1" id="feat-biz-list">
                <?php foreach ($allBizList as $biz): ?>
                <a href="settings.php?tab=features&biz=<?= $biz['id'] ?>"
                   data-name="<?= h(strtolower($biz['name'])) ?>"
                   class="feat-biz-item flex items-center gap-2 px-3 py-2 rounded-lg text-sm transition-colors
                          <?= $featBizId === (int)$biz['id'] ? 'bg-blue-50 text-blue-700 font-semibold' : 'text-gray-600 hover:bg-gray-50' ?>">
                    <i class="fa-solid fa-building text-xs w-4 text-center <?= $featBizId === (int)$biz['id'] ? 'text-blue-500' : 'text-gray-400' ?>"></i>
                    <span class="truncate"><?= h($biz['name']) ?></span>
                </a>
                <?php endforeach; ?>
                <p id="feat-biz-none" class="hidden text-xs text-gray-400 px-3 py-2">No match found.</p>
            </div>
            <script>
            document.getElementById('feat-biz-search')?.addEventListener('input', function() {
                const q = this.value.toLowerCase().trim();
                let hits = 0;
                document.querySelectorAll('.feat-biz-item').forEach(el => {
                    const show = !q || el.dataset.name.includes(q);
                    el.style.display = show ? '' : 'none';
                    if (show) hits++;
                });
                document.getElementById('feat-biz-none').style.display = (hits === 0 && q) ? '' : 'none';
            });
            </script>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Right: Feature Panels (auto-saves on each toggle click) ── -->
    <div id="feat-right">
        <?php if (!$featBizId): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-16 text-center">
            <div class="w-16 h-16 bg-blue-50 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fa-solid fa-toggle-on text-blue-400 text-2xl"></i>
            </div>
            <h3 class="font-semibold text-gray-700 mb-1">Select a business</h3>
            <p class="text-sm text-gray-400">Choose a business from the left to manage its feature access.</p>
        </div>
        <?php else: ?>
        <?php $selBiz = array_values(array_filter($allBizList, fn($b) => (int)$b['id'] === $featBizId))[0] ?? null; ?>
        <div class="mb-4 flex items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-lg bg-blue-100 flex items-center justify-center flex-shrink-0">
                    <i class="fa-solid fa-building text-blue-600"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-gray-800"><?= h($selBiz['name'] ?? '') ?></h3>
                    <p class="text-xs text-gray-400">Changes save automatically when you click a toggle</p>
                </div>
            </div>
            <div id="feat-status" class="text-xs text-gray-400 flex items-center gap-1.5">
                <i class="fa-solid fa-circle-check text-gray-300"></i> Ready
            </div>
        </div>

        <div class="space-y-5" id="feat-sections">
            <?php foreach ($featSections as $section => $keys):
                $sectionIcon = $__sectionIconMap[$section] ?? 'fa-circle-dot text-gray-400';
            ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-5 py-3 bg-gray-50 border-b border-gray-100 flex items-center gap-2">
                    <i class="fa-solid <?= $sectionIcon ?> text-sm"></i>
                    <h4 class="font-semibold text-gray-700 text-sm"><?= h($section) ?></h4>
                    <span class="ml-auto text-xs text-gray-400"><?= count($keys) ?> feature<?= count($keys) !== 1 ? 's' : '' ?></span>
                </div>
                <div class="p-5 grid grid-cols-1 md:grid-cols-2 gap-3">
                    <?php foreach ($keys as $key => $label):
                        $cur = $curFeats[$key] ?? 'enabled';
                    ?>
                    <div class="flex items-center justify-between gap-3 p-3 rounded-lg border border-gray-100 bg-gray-50/50 hover:border-gray-200 transition-colors">
                        <span class="text-sm font-medium text-gray-700 truncate"><?= h($label) ?></span>
                        <div class="flex gap-1 flex-shrink-0 feat-toggle-group"
                             data-biz="<?= $featBizId ?>"
                             data-key="<?= h($key) ?>"
                             data-current="<?= h($cur) ?>">
                            <button type="button" class="feat-btn <?= $cur === 'enabled'     ? 'feat-enabled-active'  : 'feat-off' ?>" data-val="enabled">
                                <i class="fa-solid fa-circle-check text-xs"></i> On
                            </button>
                            <button type="button" class="feat-btn <?= $cur === 'coming_soon' ? 'feat-soon-active'     : 'feat-off' ?>" data-val="coming_soon">
                                <i class="fa-solid fa-clock text-xs"></i> Soon
                            </button>
                            <button type="button" class="feat-btn <?= $cur === 'disabled'    ? 'feat-disabled-active' : 'feat-off' ?>" data-val="disabled">
                                <i class="fa-solid fa-ban text-xs"></i> Off
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="mt-4 pb-6 text-xs text-gray-400 flex items-center gap-2">
            <i class="fa-solid fa-bolt"></i> Each toggle saves instantly to the database — no save button needed.
        </div>

        <script>
        (function() {
            const CSRF  = <?= json_encode(csrfToken()) ?>;
            const URL   = <?= json_encode(url('admin/settings')) ?>;
            const CLASS = { enabled: 'feat-enabled-active', coming_soon: 'feat-soon-active', disabled: 'feat-disabled-active' };
            const statusEl = document.getElementById('feat-status');

            function setStatus(msg, color) {
                statusEl.innerHTML = `<i class="fa-solid fa-${color === 'green' ? 'circle-check text-green-500' : color === 'red' ? 'triangle-exclamation text-red-500' : 'spinner fa-spin text-blue-400'}"></i> ${msg}`;
            }

            let toastTimer;
            function showToast(msg, isErr) {
                let t = document.getElementById('feat-toast');
                if (!t) {
                    t = document.createElement('div');
                    t.id = 'feat-toast';
                    t.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;padding:10px 20px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.12);transition:opacity .25s;pointer-events:none;';
                    document.body.appendChild(t);
                }
                t.textContent = msg;
                t.style.background = isErr ? '#fee2e2' : '#d1fae5';
                t.style.color      = isErr ? '#dc2626' : '#059669';
                t.style.opacity    = '1';
                clearTimeout(toastTimer);
                toastTimer = setTimeout(() => { t.style.opacity = '0'; }, 2200);
            }

            document.querySelectorAll('.feat-toggle-group').forEach(group => {
                group.querySelectorAll('.feat-btn').forEach(btn => {
                    btn.addEventListener('click', async function() {
                        const key     = group.dataset.key;
                        const bizId   = group.dataset.biz;
                        const current = group.dataset.current;
                        const status  = this.dataset.val;
                        if (status === current) return;

                        // Optimistic UI
                        const allBtns = group.querySelectorAll('.feat-btn');
                        allBtns.forEach(b => { b.disabled = true; b.classList.remove(...Object.values(CLASS)); b.classList.add('feat-off'); });
                        this.classList.remove('feat-off');
                        this.classList.add(CLASS[status] || 'feat-off');
                        setStatus('Saving…', 'spin');

                        try {
                            const fd = new FormData();
                            fd.append('csrf_token', CSRF);
                            fd.append('_tab',       'feature_toggle');
                            fd.append('biz_id',     bizId);
                            fd.append('key',        key);
                            fd.append('status',     status);

                            const res  = await fetch(URL, { method: 'POST', body: fd });
                            const data = await res.json();

                            if (data.ok) {
                                group.dataset.current = status;
                                setStatus('Saved', 'green');
                                showToast('Saved', false);
                            } else {
                                throw new Error(data.error || 'Error');
                            }
                        } catch (e) {
                            // Revert
                            allBtns.forEach(b => { b.classList.remove(...Object.values(CLASS)); b.classList.add('feat-off'); });
                            const revertBtn = group.querySelector(`[data-val="${current}"]`);
                            if (revertBtn) { revertBtn.classList.remove('feat-off'); revertBtn.classList.add(CLASS[current] || 'feat-off'); }
                            setStatus('Error — try again', 'red');
                            showToast('Failed to save — please retry', true);
                        } finally {
                            allBtns.forEach(b => { b.disabled = false; });
                        }
                    });
                });
            });
        })();
        </script>
        <?php endif; ?>
    </div><!-- #feat-right -->

</div><!-- #feat-outer -->

<?php elseif ($activeTab === 'general'): ?>
<!-- ─── General Settings Tab ────────────────────────────────── -->
<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
    <input type="hidden" name="_tab" value="general">

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">

        <!-- Contact Information -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <div class="flex items-center gap-3 mb-5">
                <div class="w-9 h-9 rounded-lg bg-blue-50 flex items-center justify-center flex-shrink-0">
                    <i class="fa-solid fa-address-card text-blue-600"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-gray-800">Contact Information</h3>
                    <p class="text-xs text-gray-500">Displayed on the landing page contact section</p>
                </div>
            </div>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Contact Email</label>
                    <input type="email" name="contact_email" value="<?= h($settingsRows['contact_email'] ?? '') ?>"
                        placeholder="hello@yourcompany.com"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone Number</label>
                    <input type="text" name="contact_phone" value="<?= h($settingsRows['contact_phone'] ?? '') ?>"
                        placeholder="+1 555 000 0000"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">WhatsApp Number</label>
                    <input type="text" name="contact_whatsapp" value="<?= h($settingsRows['contact_whatsapp'] ?? '') ?>"
                        placeholder="+15550000000"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    <p class="text-xs text-gray-400 mt-1">Include country code without spaces (e.g. +23279000000)</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Location / Address</label>
                    <input type="text" name="contact_location" value="<?= h($settingsRows['contact_location'] ?? '') ?>"
                        placeholder="City, Country"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Support Hours</label>
                    <input type="text" name="contact_hours" value="<?= h($settingsRows['contact_hours'] ?? '') ?>"
                        placeholder="Mon – Fri, 9 AM – 5 PM"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
            </div>
        </div>

        <!-- Social Media Links -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <div class="flex items-center gap-3 mb-5">
                <div class="w-9 h-9 rounded-lg bg-indigo-50 flex items-center justify-center flex-shrink-0">
                    <i class="fa-solid fa-share-nodes text-indigo-600"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-gray-800">Social Media Links</h3>
                    <p class="text-xs text-gray-500">Shown as clickable icons in the landing page footer</p>
                </div>
            </div>
            <div class="space-y-4">
                <?php foreach ([
                    ['social_facebook',  'fa-brands fa-facebook-f',  '#3b5998', 'Facebook',    'https://facebook.com/yourpage'],
                    ['social_twitter',   'fa-brands fa-x-twitter',   '#000000', 'Twitter / X', 'https://x.com/yourhandle'],
                    ['social_instagram', 'fa-brands fa-instagram',   '#e1306c', 'Instagram',   'https://instagram.com/yourhandle'],
                    ['social_whatsapp',  'fa-brands fa-whatsapp',    '#25d366', 'WhatsApp',    'https://wa.me/15550000000'],
                    ['social_linkedin',  'fa-brands fa-linkedin-in', '#0a66c2', 'LinkedIn',    'https://linkedin.com/company/yourcompany'],
                ] as [$sKey, $sIcon, $sColor, $sLabel, $sPh]): ?>
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0"
                         style="background:<?= $sColor ?>22">
                        <i class="<?= $sIcon ?> text-sm" style="color:<?= $sColor ?>"></i>
                    </div>
                    <div class="flex-1">
                        <label class="block text-xs font-medium text-gray-600 mb-1"><?= $sLabel ?></label>
                        <input type="text" name="<?= $sKey ?>" value="<?= h($settingsRows[$sKey] ?? '') ?>"
                            placeholder="<?= $sPh ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div>
        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
            <i class="fa-solid fa-save mr-1"></i> Save General Settings
        </button>
    </div>
</form>

<?php elseif ($activeTab === 'demo'): ?>
<!-- ─── Demo Fee Tab ─────────────────────────────────────────── -->
<div class="max-w-xl">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h3 class="font-semibold text-gray-800 mb-1">Demo Booking Fee</h3>
        <p class="text-sm text-gray-500 mb-5">Charge a fee for demo requests submitted via the landing page.</p>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="_tab" value="demo">

            <div class="space-y-5">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="demo_fee_enabled" value="1"
                        <?= ($settingsRows['demo_fee_enabled'] ?? '0') === '1' ? 'checked' : '' ?>
                        class="w-4 h-4 rounded text-blue-600">
                    <div>
                        <p class="text-sm font-medium text-gray-700">Enable demo fee</p>
                        <p class="text-xs text-gray-400">Shows a payment notice on the booking form and collects a payment reference.</p>
                    </div>
                </label>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Fee Amount</label>
                        <input type="number" name="demo_fee_amount" step="0.01" min="0"
                            value="<?= h($settingsRows['demo_fee_amount'] ?? '') ?>"
                            placeholder="0.00"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Currency</label>
                        <select name="demo_fee_currency"
                            class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">
                            <?php foreach ($currencies as $curr): ?>
                            <option value="<?= h($curr['code']) ?>"
                                <?= ($settingsRows['demo_fee_currency'] ?? 'NLE') === $curr['code'] ? 'selected' : '' ?>>
                                <?= h($curr['code']) ?> — <?= h($curr['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Instructions</label>
                    <textarea name="demo_payment_instructions" rows="4"
                        placeholder="e.g. Send payment via Orange Money to 078 123 4567 (John Doe). Use your name and email as the reference."
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none resize-none"><?= h($settingsRows['demo_payment_instructions'] ?? '') ?></textarea>
                    <p class="text-xs text-gray-400 mt-1">Displayed above the booking form when the fee is enabled.</p>
                </div>

                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
                    <i class="fa-solid fa-save mr-1"></i> Save Demo Fee Settings
                </button>
            </div>
        </form>
    </div>
</div>

<?php elseif ($activeTab === 'danger'): ?>
<!-- ─── Danger Zone Tab ────────────────────────────────────── -->
<div class="space-y-6">

    <!-- Warning banner -->
    <div class="rounded-xl border border-red-200 bg-red-50 p-4 flex gap-3">
        <div class="w-10 h-10 rounded-lg bg-red-100 flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-skull text-red-500 text-lg"></i>
        </div>
        <div>
            <p class="font-bold text-red-800 text-sm">Irreversible actions ahead</p>
            <p class="text-xs text-red-600 mt-0.5 leading-relaxed">
                The actions on this page permanently delete data from the database.
                There is <strong>no undo</strong>. Make sure you have a backup before proceeding.
            </p>
        </div>
    </div>

    <!-- Select Data to Delete -->
    <div class="bg-white rounded-xl shadow-sm border border-red-200 overflow-hidden">
        <div class="px-6 py-4 bg-red-50 border-b border-red-100 flex items-center gap-3">
            <i class="fa-solid fa-trash-can text-red-500"></i>
            <div>
                <h3 class="font-bold text-red-800 text-sm">Select Data to Delete</h3>
                <p class="text-xs text-red-500">Choose which categories to permanently remove, then confirm below</p>
            </div>
        </div>

        <form method="POST" id="clearDataForm" onsubmit="return confirmClearData(event)">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="_tab"    value="danger">
            <input type="hidden" name="_action" value="clear_selected">

            <div class="p-6 space-y-5">

                <!-- Category checkboxes -->
                <div>
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Data Categories</p>
                        <div class="flex gap-2">
                            <button type="button" onclick="toggleAllDanger(true)"
                                class="text-xs text-red-600 hover:text-red-800 font-medium px-2 py-1 rounded hover:bg-red-50">
                                Select All
                            </button>
                            <button type="button" onclick="toggleAllDanger(false)"
                                class="text-xs text-gray-500 hover:text-gray-700 font-medium px-2 py-1 rounded hover:bg-gray-50">
                                Deselect All
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2" id="dangerCatGrid">
                        <?php
                        $__prevSel = (array)(post('categories') ?? []);
                        foreach (_dangerCategories() as $catKey => $cat):
                            $catChecked = in_array($catKey, $__prevSel) ? 'checked' : '';
                        ?>
                        <label class="danger-cat-item flex items-start gap-3 p-3 rounded-lg border <?= $catChecked ? 'border-red-300 bg-red-50/60' : 'border-gray-200' ?> cursor-pointer hover:border-red-300 hover:bg-red-50/50 transition-colors">
                            <input type="checkbox" name="categories[]" value="<?= h($catKey) ?>" <?= $catChecked ?>
                                class="danger-chk mt-0.5 rounded border-gray-300 text-red-600 focus:ring-red-500">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <i class="fa-solid <?= h($cat['icon']) ?> text-red-400 text-xs w-3.5 text-center"></i>
                                    <span class="danger-lbl text-sm font-semibold text-gray-700"><?= h($cat['label']) ?></span>
                                </div>
                                <p class="text-xs text-gray-400 mt-0.5"><?= h($cat['desc']) ?></p>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- What will be preserved -->
                <div class="rounded-lg border border-green-100 bg-green-50 px-4 py-3 flex items-center gap-3">
                    <i class="fa-solid fa-shield-halved text-green-500"></i>
                    <p class="text-xs text-green-700">
                        <strong>Your admin account</strong> and <strong>SMTP / system settings</strong> will always be preserved.
                    </p>
                </div>

                <!-- Errors -->
                <?php if (!empty($dangerErrors ?? [])): ?>
                <div class="bg-red-50 border border-red-200 rounded-lg p-3">
                    <?php foreach ($dangerErrors as $e): ?>
                    <p class="text-red-700 text-sm"><i class="fa-solid fa-circle-xmark mr-1"></i><?= h($e) ?></p>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Confirmation -->
                <div class="space-y-3">
                    <div class="p-3 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-700">
                        <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                        <span id="dangerSelCount">No categories selected.</span>
                    </div>
                    <label class="block text-sm font-medium text-gray-700">
                        Type <code class="bg-gray-100 px-1.5 py-0.5 rounded text-red-700 font-mono text-xs">PERMANENTLY DELETE</code> to confirm
                    </label>
                    <input type="text" name="confirm_phrase" id="confirmPhrase"
                           placeholder="PERMANENTLY DELETE" autocomplete="off"
                           class="w-full px-3 py-2.5 border-2 border-red-200 rounded-lg text-sm font-mono focus:ring-2 focus:ring-red-400 focus:border-red-400 outline-none bg-red-50 placeholder-red-300">
                    <button type="submit" id="clearBtn"
                            class="w-full bg-red-600 text-white py-2.5 rounded-lg text-sm font-bold hover:bg-red-700 disabled:opacity-40 disabled:cursor-not-allowed transition-opacity"
                            disabled>
                        <i class="fa-solid fa-trash-can mr-2"></i> Delete Selected Data
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
(function() {
    const grid   = document.getElementById('dangerCatGrid');
    const phrase = document.getElementById('confirmPhrase');
    const btn    = document.getElementById('clearBtn');
    const selTxt = document.getElementById('dangerSelCount');

    function getChecked() {
        return grid ? [...grid.querySelectorAll('.danger-chk:checked')] : [];
    }
    function update() {
        const checked = getChecked();
        const labels  = checked.map(c => c.closest('label')?.querySelector('.danger-lbl')?.textContent?.trim()).filter(Boolean);
        selTxt.textContent = checked.length
            ? checked.length + ' selected: ' + labels.join(', ')
            : 'No categories selected.';
        btn.disabled = !(checked.length > 0 && phrase?.value === 'PERMANENTLY DELETE');
    }
    grid?.addEventListener('change', update);
    phrase?.addEventListener('input', update);
    update(); // run on load in case of re-render with errors
})();
function toggleAllDanger(check) {
    document.querySelectorAll('#dangerCatGrid .danger-chk').forEach(c => c.checked = check);
    document.querySelectorAll('#dangerCatGrid .danger-cat-item').forEach(el => {
        el.classList.toggle('border-red-300', check);
        el.classList.toggle('bg-red-50/60', check);
        el.classList.toggle('border-gray-200', !check);
    });
    document.getElementById('dangerCatGrid')?.dispatchEvent(new Event('change'));
}
function confirmClearData(e) {
    const checked = [...document.querySelectorAll('#dangerCatGrid .danger-chk:checked')];
    if (!checked.length) { e.preventDefault(); alert('Please select at least one data category.'); return false; }
    if (document.getElementById('confirmPhrase')?.value !== 'PERMANENTLY DELETE') { e.preventDefault(); return false; }
    const labels = checked.map(c => c.closest('label')?.querySelector('.danger-lbl')?.textContent?.trim()).filter(Boolean);
    return confirm('⚠️ FINAL WARNING\n\nYou are about to permanently delete:\n• ' + labels.join('\n• ') + '\n\nThis cannot be undone. Continue?');
}
</script>

<?php endif; ?>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
