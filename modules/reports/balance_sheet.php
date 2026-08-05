<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('reports');
requireFeature('analytics_balance_sheet');

$db    = getDB();
$bizId = currentBusinessId();
$asAt  = get('as_at', date('Y-m-d'));
$plFrom = get('pl_from', date('Y-m-01'));
$plTo   = $asAt; // P&L period ends at the as-at date by default
$errors = [];

// Ensure new tables exist
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `suppliers` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `business_id` INT UNSIGNED NOT NULL,
        `name` VARCHAR(200) NOT NULL, `phone` VARCHAR(30) DEFAULT NULL,
        `email` VARCHAR(100) DEFAULT NULL, `address` TEXT DEFAULT NULL,
        `contact_person` VARCHAR(150) DEFAULT NULL, `opening_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `notes` TEXT DEFAULT NULL, `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), KEY `idx_sup_biz` (`business_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS `supplier_purchases` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `business_id` INT UNSIGNED NOT NULL,
        `supplier_id` INT UNSIGNED NOT NULL, `user_id` INT UNSIGNED NOT NULL,
        `reference` VARCHAR(100) DEFAULT NULL, `description` VARCHAR(500) NOT NULL,
        `total_amount` DECIMAL(15,2) NOT NULL, `amount_paid` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `payment_status` ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
        `purchase_date` DATE NOT NULL, `due_date` DATE DEFAULT NULL, `notes` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), KEY `idx_suppur_biz` (`business_id`), KEY `idx_suppur_sup` (`supplier_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS `drawings` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `business_id` INT UNSIGNED NOT NULL,
        `user_id` INT UNSIGNED NOT NULL, `amount` DECIMAL(15,2) NOT NULL,
        `description` VARCHAR(300) NOT NULL,
        `payment_method` ENUM('cash','orange_money','afrimoney','qmoney','bank_transfer') NOT NULL DEFAULT 'cash',
        `drawing_date` DATE NOT NULL, `notes` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), KEY `idx_draw_biz` (`business_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS `capital_accounts` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `business_id` INT UNSIGNED NOT NULL,
        `user_id` INT UNSIGNED NOT NULL,
        `type` ENUM('opening','injection') NOT NULL DEFAULT 'opening',
        `amount` DECIMAL(15,2) NOT NULL, `description` VARCHAR(300) DEFAULT NULL,
        `entry_date` DATE NOT NULL, `notes` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), KEY `idx_cap_biz` (`business_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS `supplier_payments` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `business_id` INT UNSIGNED NOT NULL,
        `supplier_id` INT UNSIGNED NOT NULL, `purchase_id` INT UNSIGNED DEFAULT NULL,
        `user_id` INT UNSIGNED NOT NULL, `amount` DECIMAL(15,2) NOT NULL,
        `payment_method` ENUM('cash','orange_money','afrimoney','qmoney','bank_transfer') NOT NULL DEFAULT 'cash',
        `reference_number` VARCHAR(100) DEFAULT NULL, `notes` TEXT DEFAULT NULL,
        `payment_date` DATE NOT NULL, `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), KEY `idx_suppay_biz` (`business_id`), KEY `idx_suppay_sup` (`supplier_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {}

// ── Add an updated_at column to capital_accounts if absent ──────
try { $db->exec("ALTER TABLE capital_accounts ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP"); } catch (\Throwable $e) {}

// ── Handle capital entry POST ──────────────────────────────────
$bsRedirect = url('reports/balance-sheet') . '?as_at=' . $asAt . '&pl_from=' . $plFrom;

if (isPost() && post('action') === 'add_capital') {
    verifyCsrf();
    $capType   = post('capital_type') === 'injection' ? 'injection' : 'opening';
    $capAmount = (float)post('capital_amount', 0);
    $capDesc   = post('capital_description');
    $capDate   = post('capital_date', date('Y-m-d'));

    if ($capAmount <= 0) $errors[] = 'Capital amount must be greater than 0.';

    if (empty($errors)) {
        $db->prepare('INSERT INTO capital_accounts (business_id,user_id,type,amount,description,entry_date) VALUES (?,?,?,?,?,?)')
           ->execute([$bizId, currentUser()['id'], $capType, $capAmount, $capDesc?:null, $capDate]);
        flash('success', 'Capital entry recorded.');
        redirect($bsRedirect);
    }
}

if (isPost() && post('action') === 'edit_capital') {
    verifyCsrf();
    $capId     = (int)post('capital_id');
    $capType   = post('capital_type') === 'injection' ? 'injection' : 'opening';
    $capAmount = (float)post('capital_amount', 0);
    $capDesc   = post('capital_description');
    $capDate   = post('capital_date', date('Y-m-d'));

    if ($capAmount <= 0) $errors[] = 'Capital amount must be greater than 0.';
    if (!$capId)         $errors[] = 'Invalid capital entry.';

    if (empty($errors)) {
        $db->prepare('UPDATE capital_accounts SET type=?,amount=?,description=?,entry_date=? WHERE id=? AND business_id=?')
           ->execute([$capType, $capAmount, $capDesc?:null, $capDate, $capId, $bizId]);
        flash('success', 'Capital entry updated.');
        redirect($bsRedirect);
    }
}

if (!isPost() && get('action') === 'delete_capital') {
    verifyCsrf();
    $capId = (int)get('id');
    if ($capId) {
        $db->prepare('DELETE FROM capital_accounts WHERE id=? AND business_id=?')->execute([$capId, $bizId]);
        flash('success', 'Capital entry deleted.');
    }
    redirect($bsRedirect);
}

// ═══════════════════════════════════════════════════
// ASSETS
// ═══════════════════════════════════════════════════

// 1. Inventory at cost (current stock value — not date filtered, represents current holdings)
$invQ = $db->prepare("SELECT COALESCE(SUM(stock_quantity * cost_price),0) FROM products WHERE business_id=? AND is_active=1");
$invQ->execute([$bizId]);
$inventoryValue = (float)$invQ->fetchColumn();

// 2. Trade Debtors (outstanding customer debt balances)
$debtQ = $db->prepare("SELECT COALESCE(SUM(balance),0) FROM debts WHERE business_id=? AND status NOT IN ('paid','written_off')");
$debtQ->execute([$bizId]);
$tradeReceivables = (float)$debtQ->fetchColumn();

// 3. Cash Position (estimated: payments received - outflows)
//    Inflows: payments from customers + other income
$cashInQ = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE business_id=? AND payment_date<=?");
$cashInQ->execute([$bizId, $asAt]);
$cashFromPayments = (float)$cashInQ->fetchColumn();

$incomeQ = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM income WHERE business_id=? AND income_date<=?");
$incomeQ->execute([$bizId, $asAt]);
$otherIncomeIn = (float)$incomeQ->fetchColumn();

//    Outflows: expenses + supplier payments + drawings
$expOutQ = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE business_id=? AND expense_date<=?");
$expOutQ->execute([$bizId, $asAt]);
$expensesOut = (float)$expOutQ->fetchColumn();

$supPayQ = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM supplier_payments WHERE business_id=? AND payment_date<=?");
$supPayQ->execute([$bizId, $asAt]);
$supplierPaymentsOut = (float)$supPayQ->fetchColumn();

$drawQ = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM drawings WHERE business_id=? AND drawing_date<=?");
$drawQ->execute([$bizId, $asAt]);
$drawingsToDate = (float)$drawQ->fetchColumn();

$estimatedCash = $cashFromPayments + $otherIncomeIn - $expensesOut - $supplierPaymentsOut - $drawingsToDate;

$totalAssets = $inventoryValue + $tradeReceivables + max(0, $estimatedCash);

// ═══════════════════════════════════════════════════
// LIABILITIES
// ═══════════════════════════════════════════════════

// Trade Creditors (outstanding supplier purchases)
$payQ = $db->prepare("SELECT COALESCE(SUM(balance),0) FROM supplier_purchases WHERE business_id=? AND payment_status!='paid'");
$payQ->execute([$bizId]);
$tradePayables = (float)$payQ->fetchColumn();

// Opening balance owed to suppliers (from supplier master records)
$supOBQ = $db->prepare("SELECT COALESCE(SUM(opening_balance),0) FROM suppliers WHERE business_id=? AND is_active=1");
$supOBQ->execute([$bizId]);
$supplierOpeningOwed = (float)$supOBQ->fetchColumn();

$totalLiabilities = $tradePayables + $supplierOpeningOwed;

// ═══════════════════════════════════════════════════
// EQUITY / OWNER'S CAPITAL
// ═══════════════════════════════════════════════════

// Capital contributed (opening + injections)
$capQ = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM capital_accounts WHERE business_id=? AND entry_date<=?");
$capQ->execute([$bizId, $asAt]);
$capitalContributed = (float)$capQ->fetchColumn();

// Net Profit for selected P&L period
$salesRevQ = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE business_id=? AND sale_date BETWEEN ? AND ?");
$salesRevQ->execute([$bizId, $plFrom, $plTo]);
$salesRevenue = (float)$salesRevQ->fetchColumn();

$cogsQ = $db->prepare("SELECT COALESCE(SUM(si.cost_price * si.quantity),0) FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.business_id=? AND s.sale_date BETWEEN ? AND ?");
$cogsQ->execute([$bizId, $plFrom, $plTo]);
$cogs = (float)$cogsQ->fetchColumn();

$otherIncQ = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM income WHERE business_id=? AND income_date BETWEEN ? AND ?");
$otherIncQ->execute([$bizId, $plFrom, $plTo]);
$otherIncomePL = (float)$otherIncQ->fetchColumn();

$totalExpQ = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE business_id=? AND expense_date BETWEEN ? AND ?");
$totalExpQ->execute([$bizId, $plFrom, $plTo]);
$totalExpenses = (float)$totalExpQ->fetchColumn();

// Also include service orders revenue
try {
    $svcRevQ = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM service_orders WHERE business_id=? AND DATE(created_at) BETWEEN ? AND ? AND status IN ('completed','in_progress')");
    $svcRevQ->execute([$bizId, $plFrom, $plTo]);
    $svcRevenue = (float)$svcRevQ->fetchColumn();
} catch (\Exception $e) { $svcRevenue = 0; }

$netProfit = $salesRevenue + $svcRevenue + $otherIncomePL - $cogs - $totalExpenses;

// Drawings for P&L period
$drawPeriodQ = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM drawings WHERE business_id=? AND drawing_date BETWEEN ? AND ?");
$drawPeriodQ->execute([$bizId, $plFrom, $plTo]);
$drawingsPeriod = (float)$drawPeriodQ->fetchColumn();

$totalEquity = $capitalContributed + $netProfit - $drawingsToDate;

// Capital entries list
$capEntriesQ = $db->prepare("SELECT ca.*, u.name AS user_name FROM capital_accounts ca JOIN users u ON u.id=ca.user_id WHERE ca.business_id=? ORDER BY ca.entry_date DESC");
$capEntriesQ->execute([$bizId]);
$capitalEntries = $capEntriesQ->fetchAll();

$pageTitle = 'Balance Sheet';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<?= renderFlash() ?>

<?php if ($errors): ?>
<div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
    <?php foreach ($errors as $e): ?><p class="text-red-700 text-sm"><?= h($e) ?></p><?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Header & Controls -->
<div class="flex flex-wrap items-center justify-between gap-4 mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-800">Balance Sheet</h2>
        <p class="text-sm text-gray-500">Financial position as at <strong><?= formatDate($asAt) ?></strong></p>
    </div>
    <form method="GET" class="flex flex-wrap gap-2 items-center">
        <div>
            <label class="text-xs font-medium text-gray-500 block mb-0.5">As At Date</label>
            <input type="date" name="as_at" value="<?= h($asAt) ?>" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        </div>
        <div>
            <label class="text-xs font-medium text-gray-500 block mb-0.5">P&L Period From</label>
            <input type="date" name="pl_from" value="<?= h($plFrom) ?>" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        </div>
        <div class="self-end">
            <button class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm h-[38px]">Apply</button>
        </div>
    </form>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">

    <!-- ASSETS -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-5 py-4 bg-blue-600 text-white">
            <h3 class="font-bold text-base"><i class="fa-solid fa-building mr-2"></i>ASSETS</h3>
            <p class="text-blue-100 text-xs mt-0.5">What the business owns</p>
        </div>
        <div class="p-5 space-y-3 text-sm">
            <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Current Assets</div>
            <div class="flex justify-between items-center">
                <span class="text-gray-600">Stock / Inventory (at cost)</span>
                <span class="font-semibold tabular-nums"><?= formatMoney($inventoryValue) ?></span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-gray-600">Trade Debtors (Receivables)</span>
                <span class="font-semibold tabular-nums"><?= formatMoney($tradeReceivables) ?></span>
            </div>
            <div class="flex justify-between items-center">
                <div>
                    <span class="text-gray-600">Estimated Cash & Bank</span>
                    <p class="text-xs text-gray-400">Based on recorded transactions</p>
                </div>
                <span class="font-semibold tabular-nums <?= $estimatedCash < 0 ? 'text-red-600' : '' ?>"><?= formatMoney(max(0,$estimatedCash)) ?></span>
            </div>
            <?php if ($estimatedCash < 0): ?>
            <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-700">
                <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                Cash position is negative (<?= formatMoney($estimatedCash) ?>). This may indicate unrecorded capital or transactions.
            </div>
            <?php endif; ?>
            <div class="border-t pt-3 mt-3 flex justify-between font-bold text-blue-700 text-base">
                <span>TOTAL ASSETS</span>
                <span class="tabular-nums"><?= formatMoney($totalAssets) ?></span>
            </div>
        </div>
    </div>

    <!-- LIABILITIES -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-5 py-4 bg-red-600 text-white">
            <h3 class="font-bold text-base"><i class="fa-solid fa-file-invoice-dollar mr-2"></i>LIABILITIES</h3>
            <p class="text-red-100 text-xs mt-0.5">What the business owes</p>
        </div>
        <div class="p-5 space-y-3 text-sm">
            <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Current Liabilities</div>
            <?php if ($supplierOpeningOwed > 0): ?>
            <div class="flex justify-between items-center">
                <span class="text-gray-600">Supplier Opening Balances</span>
                <span class="font-semibold tabular-nums text-red-600"><?= formatMoney($supplierOpeningOwed) ?></span>
            </div>
            <?php endif; ?>
            <div class="flex justify-between items-center">
                <span class="text-gray-600">Trade Creditors (Payables)</span>
                <span class="font-semibold tabular-nums text-red-600"><?= formatMoney($tradePayables) ?></span>
            </div>
            <?php if ($totalLiabilities == 0): ?>
            <p class="text-gray-400 text-xs py-2">No outstanding liabilities recorded.</p>
            <?php endif; ?>
            <div class="border-t pt-3 mt-3 flex justify-between font-bold text-red-700 text-base">
                <span>TOTAL LIABILITIES</span>
                <span class="tabular-nums"><?= formatMoney($totalLiabilities) ?></span>
            </div>
        </div>

        <!-- Net Worth Check -->
        <?php $netWorth = $totalAssets - $totalLiabilities; ?>
        <div class="mx-5 mb-5 p-4 bg-gray-50 rounded-xl border border-gray-200">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Net Asset Value</p>
            <p class="text-2xl font-black <?= $netWorth >= 0 ? 'text-green-700' : 'text-red-700' ?>"><?= formatMoney($netWorth) ?></p>
            <p class="text-xs text-gray-400 mt-1">Assets minus Liabilities</p>
        </div>
    </div>

    <!-- EQUITY -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-5 py-4 bg-emerald-600 text-white">
            <h3 class="font-bold text-base"><i class="fa-solid fa-scale-balanced mr-2"></i>OWNER'S EQUITY</h3>
            <p class="text-emerald-100 text-xs mt-0.5">Owner's stake in the business</p>
        </div>
        <div class="p-5 space-y-3 text-sm">
            <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Capital Account</div>
            <div class="flex justify-between items-center">
                <span class="text-gray-600">Capital Contributed</span>
                <span class="font-semibold tabular-nums text-emerald-700"><?= formatMoney($capitalContributed) ?></span>
            </div>
            <?php if ($capitalContributed == 0): ?>
            <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-700">
                <i class="fa-solid fa-circle-info mr-1"></i>
                No opening capital recorded. <a href="#capital-form" class="underline font-medium">Add it below</a>.
            </div>
            <?php endif; ?>
            <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide pt-2">P&L — <?= formatDate($plFrom) ?> to <?= formatDate($plTo) ?></div>
            <div class="flex justify-between items-center">
                <span class="text-gray-600">Sales Revenue</span>
                <span class="tabular-nums"><?= formatMoney($salesRevenue + $svcRevenue) ?></span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-gray-600">Cost of Goods Sold</span>
                <span class="tabular-nums text-red-500">— <?= formatMoney($cogs) ?></span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-gray-600">Other Income</span>
                <span class="tabular-nums"><?= formatMoney($otherIncomePL) ?></span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-gray-600">Operating Expenses</span>
                <span class="tabular-nums text-red-500">— <?= formatMoney($totalExpenses) ?></span>
            </div>
            <div class="flex justify-between items-center border-t pt-2 font-semibold">
                <span>Net Profit / (Loss)</span>
                <span class="tabular-nums <?= $netProfit >= 0 ? 'text-green-700' : 'text-red-600' ?>"><?= $netProfit < 0 ? '(' . formatMoney(abs($netProfit)) . ')' : formatMoney($netProfit) ?></span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-gray-600">Less: Total Drawings</span>
                <span class="tabular-nums text-purple-600">— <?= formatMoney($drawingsToDate) ?></span>
            </div>
            <div class="border-t pt-3 mt-1 flex justify-between font-bold text-emerald-700 text-base">
                <span>TOTAL EQUITY</span>
                <span class="tabular-nums"><?= formatMoney($totalEquity) ?></span>
            </div>
        </div>
    </div>

</div>

<!-- Accounting Identity Check -->
<?php
$liabPlusEquity = $totalLiabilities + $totalEquity;
$diff           = $totalAssets - $liabPlusEquity;
?>
<div class="bg-white rounded-xl shadow-sm border <?= abs($diff) < 1 ? 'border-green-200 bg-green-50' : 'border-amber-200 bg-amber-50' ?> p-5 mb-6">
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <p class="font-bold text-gray-800 text-sm">Balance Sheet Check</p>
            <p class="text-xs text-gray-500 mt-0.5">Assets should equal Liabilities + Equity</p>
        </div>
        <div class="flex gap-6 text-sm">
            <div class="text-center"><p class="font-black text-blue-700 tabular-nums"><?= formatMoney($totalAssets) ?></p><p class="text-xs text-gray-500">Total Assets</p></div>
            <div class="text-center text-gray-400 self-center text-xl font-light">=</div>
            <div class="text-center"><p class="font-black text-red-700 tabular-nums"><?= formatMoney($totalLiabilities) ?></p><p class="text-xs text-gray-500">Liabilities</p></div>
            <div class="text-center text-gray-400 self-center">+</div>
            <div class="text-center"><p class="font-black text-emerald-700 tabular-nums"><?= formatMoney($totalEquity) ?></p><p class="text-xs text-gray-500">Equity</p></div>
        </div>
        <?php if (abs($diff) >= 1): ?>
        <div class="bg-amber-100 rounded-lg px-4 py-2 text-xs text-amber-800">
            <strong>Difference: <?= formatMoney(abs($diff)) ?></strong><br>
            Usually caused by unrecorded opening capital or transactions not in the system.
        </div>
        <?php else: ?>
        <div class="text-green-700 text-sm font-semibold"><i class="fa-solid fa-circle-check mr-1"></i> Balanced</div>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    <!-- Capital Management -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100" id="capital-form">
        <div class="px-5 py-4 border-b flex items-center justify-between">
            <h3 class="font-semibold text-gray-800">Capital Entries</h3>
            <button type="button" onclick="document.getElementById('new-capital-form').classList.toggle('hidden')"
                class="text-xs text-emerald-600 hover:underline font-medium">+ Add Entry</button>
        </div>

        <!-- New Capital Form -->
        <div id="new-capital-form" class="<?= $errors ? '' : 'hidden' ?> p-5 border-b bg-emerald-50">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="add_capital">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Type</label>
                        <select name="capital_type" class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
                            <option value="opening">Opening Capital</option>
                            <option value="injection">Capital Injection</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Amount (<?= currencySymbol() ?>) *</label>
                        <input type="number" name="capital_amount" min="0.01" step="0.01" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none" placeholder="0.00">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Date</label>
                        <input type="date" name="capital_date" value="<?= date('Y-m-d') ?>"
                            class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Description</label>
                        <input type="text" name="capital_description" placeholder="Optional"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none">
                    </div>
                </div>
                <div class="flex gap-2 mt-3">
                    <button type="submit" class="bg-emerald-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-emerald-700">Save</button>
                    <button type="button" onclick="document.getElementById('new-capital-form').classList.add('hidden')"
                        class="bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm hover:bg-gray-200">Cancel</button>
                </div>
            </form>
        </div>

        <!-- Inline Edit Form (shared, populated by JS) -->
        <div id="edit-capital-form" class="hidden p-5 border-b bg-blue-50">
            <p class="text-xs font-semibold text-gray-600 mb-3">Edit Capital Entry</p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="edit_capital">
                <input type="hidden" name="capital_id" id="edit_capital_id">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Type</label>
                        <select name="capital_type" id="edit_capital_type" class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
                            <option value="opening">Opening Capital</option>
                            <option value="injection">Capital Injection</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Amount (<?= currencySymbol() ?>) *</label>
                        <input type="number" name="capital_amount" id="edit_capital_amount" min="0.01" step="0.01" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none" placeholder="0.00">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Date</label>
                        <input type="date" name="capital_date" id="edit_capital_date"
                            class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Description</label>
                        <input type="text" name="capital_description" id="edit_capital_desc" placeholder="Optional"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none">
                    </div>
                </div>
                <div class="flex gap-2 mt-3">
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-700">Update</button>
                    <button type="button" onclick="document.getElementById('edit-capital-form').classList.add('hidden')"
                        class="bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm hover:bg-gray-200">Cancel</button>
                </div>
            </form>
        </div>

        <!-- Capital Entries Table -->
        <?php if (empty($capitalEntries)): ?>
        <p class="px-5 py-6 text-sm text-gray-400">No capital entries recorded yet.</p>
        <?php else: ?>
        <div class="divide-y max-h-64 overflow-y-auto">
            <?php foreach ($capitalEntries as $ce): ?>
            <div class="px-5 py-3 flex justify-between items-center text-sm gap-2">
                <div class="flex-1 min-w-0">
                    <span class="font-medium <?= $ce['type']==='injection'?'text-blue-600':'text-emerald-600' ?>">
                        <?= $ce['type'] === 'opening' ? 'Opening Capital' : 'Capital Injection' ?>
                    </span>
                    <?php if ($ce['description']): ?><span class="text-gray-400 text-xs ml-2"><?= h($ce['description']) ?></span><?php endif; ?>
                    <p class="text-xs text-gray-400 mt-0.5"><?= formatDate($ce['entry_date']) ?> · <?= h($ce['user_name']) ?></p>
                </div>
                <span class="font-bold text-emerald-700 tabular-nums flex-shrink-0"><?= formatMoney($ce['amount']) ?></span>
                <div class="flex gap-1 flex-shrink-0">
                    <button type="button" title="Edit"
                        onclick="openEditCapital(<?= $ce['id'] ?>, '<?= h($ce['type']) ?>', <?= $ce['amount'] ?>, '<?= h($ce['entry_date']) ?>', '<?= h(addslashes($ce['description'] ?? '')) ?>')"
                        class="text-blue-500 hover:text-blue-700 p-1 rounded"><i class="fa-solid fa-pen text-xs"></i></button>
                    <a href="?action=delete_capital&id=<?= $ce['id'] ?>&csrf=<?= csrfToken() ?>&as_at=<?= h($asAt) ?>&pl_from=<?= h($plFrom) ?>"
                        class="text-red-400 hover:text-red-600 p-1 rounded"
                        onclick="return confirm('Delete this capital entry? This cannot be undone.')"
                        title="Delete"><i class="fa-solid fa-trash text-xs"></i></a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <script>
        function openEditCapital(id, type, amount, date, desc) {
            document.getElementById('edit_capital_id').value    = id;
            document.getElementById('edit_capital_type').value  = type;
            document.getElementById('edit_capital_amount').value = amount;
            document.getElementById('edit_capital_date').value  = date;
            document.getElementById('edit_capital_desc').value  = desc;
            document.getElementById('edit-capital-form').classList.remove('hidden');
            document.getElementById('edit-capital-form').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        </script>
    </div>

    <!-- Drawings Summary -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100">
        <div class="px-5 py-4 border-b flex items-center justify-between">
            <h3 class="font-semibold text-gray-800">Drawings Summary</h3>
            <a href="<?= url('drawings') ?>" class="text-xs text-purple-600 hover:underline font-medium">Manage Drawings</a>
        </div>
        <div class="p-5 space-y-3 text-sm">
            <div class="flex justify-between items-center">
                <span class="text-gray-600">Period (<?= formatDate($plFrom) ?> – <?= formatDate($plTo) ?>)</span>
                <span class="font-bold text-purple-600 tabular-nums"><?= formatMoney($drawingsPeriod) ?></span>
            </div>
            <div class="flex justify-between items-center">
                <span class="text-gray-600">Total to Date (up to <?= formatDate($asAt) ?>)</span>
                <span class="font-bold text-purple-700 tabular-nums"><?= formatMoney($drawingsToDate) ?></span>
            </div>
        </div>

        <!-- Payables Summary -->
        <div class="px-5 pb-5">
            <h4 class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3 pt-4 border-t">Supplier Payables Breakdown</h4>
            <?php
            $supBalQ = $db->prepare("SELECT s.name, COALESCE(SUM(sp.balance),0) AS owed FROM supplier_purchases sp JOIN suppliers s ON s.id=sp.supplier_id WHERE sp.business_id=? AND sp.payment_status!='paid' GROUP BY s.id ORDER BY owed DESC LIMIT 5");
            $supBalQ->execute([$bizId]);
            $supBreakdown = $supBalQ->fetchAll();
            ?>
            <?php if (empty($supBreakdown)): ?>
            <p class="text-xs text-gray-400">No outstanding supplier balances.</p>
            <?php else: ?>
            <div class="space-y-2">
                <?php foreach ($supBreakdown as $sb): ?>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-600 truncate max-w-[60%]"><?= h($sb['name']) ?></span>
                    <span class="font-semibold text-red-600 tabular-nums"><?= formatMoney($sb['owed']) ?></span>
                </div>
                <?php endforeach; ?>
                <a href="<?= url('suppliers') ?>" class="text-xs text-indigo-600 hover:underline mt-2 inline-block">View all suppliers →</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
