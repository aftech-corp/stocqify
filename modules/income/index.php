<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('expenses'); // same financial permission as expenses
requireFeature('finance_income');

$db    = getDB();
$bizId = currentBusinessId();
$from  = get('from', date('Y-m-01'));
$to    = get('to', date('Y-m-d'));
$method = get('method');
$page  = max(1, (int)get('page', 1));

$where  = 'i.business_id=?';
$params = [$bizId];
if ($from)   { $where .= ' AND i.income_date>=?'; $params[] = $from; }
if ($to)     { $where .= ' AND i.income_date<=?'; $params[] = $to; }
if ($method) { $where .= ' AND i.payment_method=?'; $params[] = $method; }

// Branch filter
try { $db->exec("ALTER TABLE income ADD COLUMN IF NOT EXISTS branch_id INT UNSIGNED DEFAULT NULL"); } catch (\Throwable $e) {}
$__activeBranch = currentBranchId();
if ($__activeBranch !== null) {
    $where .= ' AND i.branch_id = ?';
    $params[] = $__activeBranch;
}

$totalQ = $db->prepare("SELECT COUNT(*) FROM income i WHERE $where");
$totalQ->execute($params);
$total = (int)$totalQ->fetchColumn();
$pag   = paginate($total, $page);

$stmt = $db->prepare("SELECT i.*, u.name AS user_name
    FROM income i
    LEFT JOIN users u ON u.id=i.user_id
    WHERE $where ORDER BY i.income_date DESC, i.id DESC
    LIMIT {$pag['per_page']} OFFSET {$pag['offset']}");
$stmt->execute($params);
$incomes = $stmt->fetchAll();

$sumQ = $db->prepare("SELECT SUM(i.amount) FROM income i WHERE $where");
$sumQ->execute($params);
$totalAmount = (float)$sumQ->fetchColumn();

// Method breakdown
$byMethodQ = $db->prepare("SELECT i.payment_method, SUM(i.amount) AS total FROM income i WHERE i.business_id=? AND i.income_date>=? AND i.income_date<=? GROUP BY i.payment_method ORDER BY total DESC");
$byMethodQ->execute([$bizId, $from ?: date('Y-m-01'), $to ?: date('Y-m-d')]);
$byMethod = $byMethodQ->fetchAll();

$pageTitle = 'Income';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<?= renderBranchBanner() ?>
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-800">Income Records</h2>
        <p class="text-sm text-gray-500"><?= number_format($total) ?> entries</p>
    </div>
    <a href="add.php" class="bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-green-700">
        <i class="fa-solid fa-plus mr-1"></i> Record Income
    </a>
</div>

<!-- Summary Cards -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <div class="bg-green-50 rounded-xl p-4 md:col-span-2">
        <p class="text-3xl font-black text-green-700"><?= formatMoney($totalAmount) ?></p>
        <p class="text-sm text-green-600 mt-1">Total Income (Period)</p>
    </div>
    <?php if (!empty($byMethod)): ?>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
        <p class="text-xs font-semibold text-gray-500 uppercase mb-2">By Method</p>
        <?php foreach ($byMethod as $bm): ?>
        <div class="flex justify-between text-sm mb-1">
            <span class="text-gray-600 capitalize"><?= str_replace('_', ' ', $bm['payment_method']) ?></span>
            <span class="font-medium"><?= formatMoney($bm['total']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Filter -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
    <form method="GET" class="flex flex-wrap gap-3">
        <input type="date" name="from" value="<?= h($from) ?>" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        <input type="date" name="to"   value="<?= h($to) ?>"   class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        <select name="method" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
            <option value="">All Methods</option>
            <option value="cash" <?= $method==='cash'?'selected':'' ?>>Cash</option>
            <option value="orange_money" <?= $method==='orange_money'?'selected':'' ?>>Orange Money</option>
            <option value="afrimoney" <?= $method==='afrimoney'?'selected':'' ?>>Afrimoney</option>
            <option value="qmoney" <?= $method==='qmoney'?'selected':'' ?>>QMoney</option>
            <option value="bank_transfer" <?= $method==='bank_transfer'?'selected':'' ?>>Bank Transfer</option>
        </select>
        <button class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm">Filter</button>
        <a href="index.php" class="bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm">Clear</a>
    </form>
</div>

<!-- Table -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100">
    <div class="table-responsive">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-medium text-left">Date</th>
                    <th class="px-4 py-3 font-medium text-left">Title</th>
                    <th class="px-4 py-3 font-medium text-right">Amount</th>
                    <th class="px-4 py-3 font-medium text-left">Method</th>
                    <th class="px-4 py-3 font-medium text-left">Reference</th>
                    <th class="px-4 py-3 font-medium text-left">Recorded By</th>
                    <th class="px-4 py-3 font-medium text-center">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php if (empty($incomes)): ?>
                <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">No income records found.</td></tr>
                <?php else: ?>
                <?php foreach ($incomes as $inc): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-500"><?= formatDate($inc['income_date']) ?></td>
                    <td class="px-4 py-3">
                        <p class="font-medium text-gray-800"><?= h($inc['title']) ?></p>
                        <?php if ($inc['description']): ?>
                        <p class="text-xs text-gray-400 truncate max-w-xs"><?= h($inc['description']) ?></p>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right font-semibold text-green-700"><?= formatMoney($inc['amount']) ?></td>
                    <td class="px-4 py-3 text-gray-500 text-xs capitalize"><?= str_replace('_', ' ', $inc['payment_method']) ?></td>
                    <td class="px-4 py-3 text-gray-400 text-xs font-mono"><?= h($inc['reference_number'] ?? '-') ?></td>
                    <td class="px-4 py-3 text-gray-400 text-xs"><?= h($inc['user_name']) ?></td>
                    <td class="px-4 py-3 text-center">
                        <div class="flex justify-center gap-1">
                            <a href="edit.php?id=<?= $inc['id'] ?>" class="text-blue-600 p-1 hover:text-blue-800"><i class="fa-solid fa-pen"></i></a>
                            <a href="delete.php?id=<?= $inc['id'] ?>&csrf=<?= csrfToken() ?>"
                               class="text-red-500 p-1 hover:text-red-700"
                               onclick="return confirm('Delete this income record?')"><i class="fa-solid fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t">
        <?= renderPagination($pag, 'index.php?' . http_build_query(array_filter(compact('from','to','method'))) . '&') ?>
    </div>
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
