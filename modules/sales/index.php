<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('sales');

$db     = getDB();
$bizId  = currentBusinessId();
$search = get('search');
$status = get('status');
$type   = get('type');
$from   = get('from', date('Y-m-01'));
$to     = get('to', date('Y-m-d'));
$page   = max(1, (int)get('page', 1));

$where  = 's.business_id = ?';
$params = [$bizId];

if ($search) {
    $where .= ' AND (s.invoice_number LIKE ? OR c.name LIKE ? OR s.walkin_name LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($status) { $where .= ' AND s.payment_status = ?'; $params[] = $status; }
if ($type)   { $where .= ' AND s.sale_type = ?';      $params[] = $type; }
if ($from)   { $where .= ' AND s.sale_date >= ?';     $params[] = $from; }
if ($to)     { $where .= ' AND s.sale_date <= ?';     $params[] = $to; }

$totalQ = $db->prepare("SELECT COUNT(*) FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE $where");
$totalQ->execute($params);
$total = (int)$totalQ->fetchColumn();
$pag = paginate($total, $page);

$stmt = $db->prepare("SELECT s.*, c.name AS customer_name
    FROM sales s LEFT JOIN customers c ON c.id = s.customer_id
    WHERE $where ORDER BY s.sale_date DESC, s.id DESC
    LIMIT {$pag['per_page']} OFFSET {$pag['offset']}");
// walkin_name is already in s.*
$stmt->execute($params);
$sales = $stmt->fetchAll();

// Summary totals for the filtered period
$sumQ = $db->prepare("SELECT SUM(total_amount) AS total, SUM(amount_paid) AS paid, SUM(balance_due) AS balance, COUNT(*) AS count FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE $where");
$sumQ->execute($params);
$summary = $sumQ->fetch();

$pageTitle = 'Sales';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-800">Sales Management</h2>
        <p class="text-sm text-gray-500"><?= number_format($total) ?> transactions</p>
    </div>
    <a href="add.php" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 flex items-center gap-2">
        <i class="fa-solid fa-plus"></i> New Sale
    </a>
</div>

<!-- Summary Cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-blue-50 rounded-xl p-4 text-center">
        <p class="text-2xl font-black text-blue-700"><?= number_format($summary['count']) ?></p>
        <p class="text-sm text-blue-600">Transactions</p>
    </div>
    <div class="bg-green-50 rounded-xl p-4 text-center">
        <p class="text-xl font-black text-green-700"><?= formatMoney($summary['total'] ?? 0) ?></p>
        <p class="text-sm text-green-600">Total Sales</p>
    </div>
    <div class="bg-teal-50 rounded-xl p-4 text-center">
        <p class="text-xl font-black text-teal-700"><?= formatMoney($summary['paid'] ?? 0) ?></p>
        <p class="text-sm text-teal-600">Amount Collected</p>
    </div>
    <div class="bg-orange-50 rounded-xl p-4 text-center">
        <p class="text-xl font-black text-orange-700"><?= formatMoney($summary['balance'] ?? 0) ?></p>
        <p class="text-sm text-orange-600">Outstanding</p>
    </div>
</div>

<!-- Filters -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
    <form method="GET" class="flex flex-wrap gap-3">
        <input type="text" name="search" value="<?= h($search) ?>" placeholder="Search invoice / customer"
            class="flex-1 min-w-36 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
        <select name="status" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
            <option value="">All Status</option>
            <option value="paid" <?= $status==='paid'?'selected':'' ?>>Paid</option>
            <option value="partial" <?= $status==='partial'?'selected':'' ?>>Partial</option>
            <option value="unpaid" <?= $status==='unpaid'?'selected':'' ?>>Unpaid</option>
        </select>
        <select name="type" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
            <option value="">All Types</option>
            <option value="cash" <?= $type==='cash'?'selected':'' ?>>Cash</option>
            <option value="credit" <?= $type==='credit'?'selected':'' ?>>Credit</option>
        </select>
        <input type="date" name="from" value="<?= h($from) ?>" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        <input type="date" name="to" value="<?= h($to) ?>" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        <button class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm">Filter</button>
        <a href="index.php" class="bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm">Clear</a>
    </form>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-100">
    <div class="table-responsive">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-medium text-left">Invoice</th>
                    <th class="px-4 py-3 font-medium text-left">Customer</th>
                    <th class="px-4 py-3 font-medium text-left">Date</th>
                    <th class="px-4 py-3 font-medium text-left">Type</th>
                    <th class="px-4 py-3 font-medium text-right">Total</th>
                    <th class="px-4 py-3 font-medium text-right">Paid</th>
                    <th class="px-4 py-3 font-medium text-right">Balance</th>
                    <th class="px-4 py-3 font-medium text-left">Status</th>
                    <th class="px-4 py-3 font-medium text-center">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php if (empty($sales)): ?>
                <tr><td colspan="9" class="px-4 py-8 text-center text-gray-400">No sales found.</td></tr>
                <?php else: ?>
                <?php foreach ($sales as $sale): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <a href="view.php?id=<?= $sale['id'] ?>" class="text-blue-600 hover:underline font-mono text-xs font-bold"><?= h($sale['invoice_number']) ?></a>
                    </td>
                    <td class="px-4 py-3 text-gray-700"><?= $sale['customer_name'] ? h($sale['customer_name']) : ($sale['walkin_name'] ? h($sale['walkin_name']) . ' <span class="text-xs text-gray-400">(Walk-in)</span>' : '<span class="text-gray-400">Walk-in</span>') ?></td>
                    <td class="px-4 py-3 text-gray-500 text-xs"><?= formatDate($sale['sale_date']) ?></td>
                    <td class="px-4 py-3"><?= statusBadge($sale['sale_type']) ?></td>
                    <td class="px-4 py-3 text-right font-semibold"><?= formatMoney($sale['total_amount']) ?></td>
                    <td class="px-4 py-3 text-right text-green-700"><?= formatMoney($sale['amount_paid']) ?></td>
                    <td class="px-4 py-3 text-right <?= $sale['balance_due']>0?'text-red-600 font-semibold':'text-gray-400' ?>"><?= formatMoney($sale['balance_due']) ?></td>
                    <td class="px-4 py-3"><?= statusBadge($sale['payment_status']) ?></td>
                    <td class="px-4 py-3 text-center">
                        <div class="flex justify-center gap-1">
                            <a href="view.php?id=<?= $sale['id'] ?>" class="text-blue-600 p-1 hover:text-blue-800" title="View"><i class="fa-solid fa-eye"></i></a>
                            <a href="invoice.php?id=<?= $sale['id'] ?>" class="text-green-600 p-1 hover:text-green-800" title="Invoice" target="_blank"><i class="fa-solid fa-print"></i></a>
                            <?php if (isAdmin() || hasPermission('users')): ?>
                            <a href="edit.php?id=<?= $sale['id'] ?>" class="text-gray-500 p-1 hover:text-gray-800" title="Edit"><i class="fa-solid fa-pen"></i></a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t">
        <?= renderPagination($pag, 'index.php?' . http_build_query(array_filter(compact('search','status','type','from','to'))) . '&') ?>
    </div>
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
