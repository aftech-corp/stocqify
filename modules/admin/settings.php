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

// Ensure the system_settings table exists
$db->exec("CREATE TABLE IF NOT EXISTS `system_settings` (
    `key` VARCHAR(100) NOT NULL,
    `value` TEXT DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Load all settings as key => value map
$settingsRows = $db->query("SELECT `key`, `value` FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

$activeTab = get('tab', 'smtp');
$errors    = [];

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
</div>

<?php if ($activeTab === 'smtp'): ?>
<!-- ─── SMTP Tab ─────────────────────────────────────────── -->
<div class="max-w-2xl">
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

    <!-- Test Email -->
    <?php if (!empty($settingsRows['smtp_host'])): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mt-4">
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

        <form method="POST" class="flex gap-3 flex-wrap">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="_tab"    value="smtp">
            <input type="hidden" name="_action" value="test">
            <input type="email" name="test_to" required
                   value="<?= h($smtpTestResult['to'] ?? '') ?>"
                   placeholder="Recipient email address…"
                   class="flex-1 min-w-48 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
            <button type="submit" class="bg-indigo-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-indigo-700">
                <i class="fa-solid fa-paper-plane mr-1"></i> Send Test
            </button>
        </form>
    </div>
    <?php else: ?>
    <div class="mt-4 p-4 bg-blue-50 border border-blue-200 rounded-xl text-sm text-blue-700">
        <i class="fa-solid fa-info-circle mr-1"></i>
        Save your SMTP settings above to enable the test email feature.
    </div>
    <?php endif; ?>
</div>

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
<?php endif; ?>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
