<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('payments');
requireFeature('finance_payments');

$db    = getDB();
$bizId = currentBusinessId();
$from  = get('from', date('Y-m-01'));
$to    = get('to', date('Y-m-d'));
$method = get('method');
$page  = max(1,(int)get('page',1));

$where  = 'p.business_id=?';
$params = [$bizId];
if ($from) { $where.=' AND p.payment_date>=?'; $params[]=$from; }
if ($to)   { $where.=' AND p.payment_date<=?'; $params[]=$to; }
if ($method){ $where.=' AND p.payment_method=?'; $params[]=$method; }

$totalQ = $db->prepare("SELECT COUNT(*) FROM payments p WHERE $where");
$totalQ->execute($params);
$total = (int)$totalQ->fetchColumn();
$pag = paginate($total, $page);

$stmt = $db->prepare("SELECT p.*, c.name AS customer_name, s.invoice_number, s.walkin_name, u.name AS user_name
    FROM payments p
    LEFT JOIN customers c ON c.id=p.customer_id
    LEFT JOIN sales s ON s.id=p.sale_id
    LEFT JOIN users u ON u.id=p.user_id
    WHERE $where ORDER BY p.payment_date DESC, p.id DESC
    LIMIT {$pag['per_page']} OFFSET {$pag['offset']}");
$stmt->execute($params);
$payments = $stmt->fetchAll();

// Total for period
$sumQ = $db->prepare("SELECT SUM(amount) FROM payments p WHERE $where");
$sumQ->execute($params);
$total_amount = (float)$sumQ->fetchColumn();

// By method breakdown
$byMethod = [];
$methods = ['cash','orange_money','afrimoney','qmoney','bank_transfer'];
foreach ($methods as $m) {
    $q = $db->prepare("SELECT SUM(amount) FROM payments p WHERE p.business_id=? AND p.payment_date>=? AND p.payment_date<=? AND p.payment_method=?");
    $q->execute([$bizId, $from, $to, $m]);
    $v = (float)$q->fetchColumn();
    if ($v > 0) $byMethod[$m] = $v;
}

$pageTitle = 'Payments';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-800">Payment History</h2>
        <p class="text-sm text-gray-500"><?= number_format($total) ?> transactions</p>
    </div>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="lg:col-span-2 bg-green-50 rounded-xl p-4">
        <p class="text-2xl font-black text-green-700"><?= formatMoney($total_amount) ?></p>
        <p class="text-sm text-green-600">Total Collected (Period)</p>
    </div>
    <?php foreach (array_slice($byMethod,0,2,true) as $m => $v): ?>
    <div class="bg-blue-50 rounded-xl p-4">
        <p class="text-xl font-black text-blue-700"><?= formatMoney($v) ?></p>
        <p class="text-sm text-blue-600 capitalize"><?= str_replace('_',' ',$m) ?></p>
    </div>
    <?php endforeach; ?>
</div>

<!-- Filter -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
    <form method="GET" class="flex flex-wrap gap-3">
        <input type="date" name="from" value="<?= h($from) ?>" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        <input type="date" name="to" value="<?= h($to) ?>" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
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

<!-- By Method Breakdown -->
<?php if (!empty($byMethod)): ?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
    <h3 class="font-semibold text-gray-700 mb-3 text-sm">Payment Methods Breakdown</h3>
    <div class="flex flex-wrap gap-3">
        <?php foreach ($byMethod as $m => $v): ?>
        <div class="flex items-center gap-2 bg-gray-50 rounded-lg px-3 py-2">
            <span class="text-sm font-medium text-gray-600 capitalize"><?= str_replace('_',' ',$m) ?>:</span>
            <span class="font-bold text-gray-800"><?= formatMoney($v) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="bg-white rounded-xl shadow-sm border border-gray-100">
    <div class="table-responsive">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-medium text-left">Date</th>
                    <th class="px-4 py-3 font-medium text-left">Customer</th>
                    <th class="px-4 py-3 font-medium text-left">Invoice</th>
                    <th class="px-4 py-3 font-medium text-right">Amount</th>
                    <th class="px-4 py-3 font-medium text-left">Method</th>
                    <th class="px-4 py-3 font-medium text-left">Reference</th>
                    <th class="px-4 py-3 font-medium text-left">Staff</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php if (empty($payments)): ?>
                <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">No payments found.</td></tr>
                <?php else: ?>
                <?php foreach ($payments as $pay): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-500"><?= formatDate($pay['payment_date']) ?></td>
                    <td class="px-4 py-3 font-medium">
                        <?php
                        if (!empty($pay['customer_name'])) echo h($pay['customer_name']);
                        elseif (!empty($pay['walkin_name'])) echo h($pay['walkin_name']) . ' <span class="text-xs text-gray-400 font-normal">(Walk-in)</span>';
                        else echo '—';
                        ?>
                    </td>
                    <td class="px-4 py-3 font-mono text-xs">
                        <?php if ($pay['invoice_number']): ?>
                        <a href="../sales/view.php?id=<?= $pay['sale_id'] ?>" class="text-blue-600 hover:underline"><?= h($pay['invoice_number']) ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right font-bold text-green-700"><?= formatMoney($pay['amount']) ?></td>
                    <td class="px-4 py-3 capitalize text-sm text-gray-600"><?= str_replace('_',' ',$pay['payment_method']) ?></td>
                    <td class="px-4 py-3 font-mono text-xs text-gray-400"><?= h($pay['reference_number']??'-') ?></td>
                    <td class="px-4 py-3 text-gray-500 text-xs"><?= h($pay['user_name']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t">
        <?= renderPagination($pag,'index.php?'.http_build_query(array_filter(compact('from','to','method'))).'&') ?>
    </div>
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
