<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('reports');

$db    = getDB();
$bizId = currentBusinessId();
$from  = get('from', date('Y-m-01'));
$to    = get('to', date('Y-m-d'));
$export = get('export');

// Main stats
$stats = [];
foreach (['total_amount'=>'Total Sales','amount_paid'=>'Amount Collected','balance_due'=>'Outstanding','discount_amount'=>'Discounts Given'] as $col => $label) {
    $q = $db->prepare("SELECT COALESCE(SUM($col),0) FROM sales WHERE business_id=? AND sale_date BETWEEN ? AND ?");
    $q->execute([$bizId,$from,$to]);
    $stats[$label] = (float)$q->fetchColumn();
}
$cntQ = $db->prepare("SELECT COUNT(*) FROM sales WHERE business_id=? AND sale_date BETWEEN ? AND ?");
$cntQ->execute([$bizId,$from,$to]);
$txCount = (int)$cntQ->fetchColumn();

// Top products
$topProdQ = $db->prepare("SELECT p.name, SUM(si.quantity) AS qty_sold, SUM(si.total) AS revenue FROM sale_items si JOIN products p ON p.id=si.product_id JOIN sales s ON s.id=si.sale_id WHERE s.business_id=? AND s.sale_date BETWEEN ? AND ? GROUP BY p.id ORDER BY revenue DESC LIMIT 10");
$topProdQ->execute([$bizId,$from,$to]);
$topProducts = $topProdQ->fetchAll();

// Daily breakdown
$dailyQ = $db->prepare("SELECT sale_date, COUNT(*) AS txns, SUM(total_amount) AS total, SUM(amount_paid) AS paid FROM sales WHERE business_id=? AND sale_date BETWEEN ? AND ? GROUP BY sale_date ORDER BY sale_date DESC");
$dailyQ->execute([$bizId,$from,$to]);
$daily = $dailyQ->fetchAll();

// By payment status
$statusQ = $db->prepare("SELECT payment_status, COUNT(*) AS cnt, SUM(total_amount) AS total FROM sales WHERE business_id=? AND sale_date BETWEEN ? AND ? GROUP BY payment_status");
$statusQ->execute([$bizId,$from,$to]);
$byStatus = $statusQ->fetchAll();

// CSV Export
if ($export === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sales_report_' . $from . '_to_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date','Invoice','Customer','Type','Total','Paid','Balance','Status']);
    $expQ = $db->prepare("SELECT s.sale_date, s.invoice_number, c.name AS customer_name, s.sale_type, s.total_amount, s.amount_paid, s.balance_due, s.payment_status FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE s.business_id=? AND s.sale_date BETWEEN ? AND ? ORDER BY s.sale_date DESC");
    $expQ->execute([$bizId,$from,$to]);
    while ($row = $expQ->fetch()) fputcsv($out, array_values($row));
    fclose($out); exit;
}

$pageTitle = 'Sales Report';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-6">
    <h2 class="text-xl font-bold text-gray-800">Sales Report</h2>
    <div class="flex gap-2">
        <a href="?from=<?= h($from) ?>&to=<?= h($to) ?>&export=csv" class="bg-green-600 text-white px-3 py-2 rounded-lg text-sm hover:bg-green-700">
            <i class="fa-solid fa-download mr-1"></i> Export CSV
        </a>
        <button onclick="window.print()" class="bg-gray-700 text-white px-3 py-2 rounded-lg text-sm hover:bg-gray-800">
            <i class="fa-solid fa-print mr-1"></i> Print
        </button>
    </div>
</div>

<!-- Date Filter -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-6">
    <form method="GET" class="flex gap-3 flex-wrap">
        <div class="flex gap-2 items-center">
            <label class="text-sm text-gray-600">From:</label>
            <input type="date" name="from" value="<?= h($from) ?>" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        </div>
        <div class="flex gap-2 items-center">
            <label class="text-sm text-gray-600">To:</label>
            <input type="date" name="to" value="<?= h($to) ?>" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        </div>
        <!-- Quick ranges -->
        <button type="button" onclick="setRange('today')" class="border px-3 py-2 rounded-lg text-sm text-gray-600 hover:bg-gray-50">Today</button>
        <button type="button" onclick="setRange('week')"  class="border px-3 py-2 rounded-lg text-sm text-gray-600 hover:bg-gray-50">This Week</button>
        <button type="button" onclick="setRange('month')" class="border px-3 py-2 rounded-lg text-sm text-gray-600 hover:bg-gray-50">This Month</button>
        <button class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm">Apply</button>
    </form>
</div>

<!-- Summary KPIs -->
<div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
    <div class="bg-blue-50 rounded-xl p-4 text-center">
        <p class="text-2xl font-black text-blue-700"><?= number_format($txCount) ?></p>
        <p class="text-xs text-blue-600">Transactions</p>
    </div>
    <?php foreach ($stats as $label => $val): ?>
    <div class="bg-white rounded-xl shadow-sm border p-4 text-center">
        <p class="text-lg font-black text-gray-800"><?= formatMoney($val) ?></p>
        <p class="text-xs text-gray-500"><?= $label ?></p>
    </div>
    <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <!-- Top Products -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <h3 class="font-semibold text-gray-800 mb-4">Top 10 Products by Revenue</h3>
        <?php if (empty($topProducts)): ?>
        <p class="text-gray-400 text-sm text-center py-4">No data.</p>
        <?php else: ?>
        <?php $maxRev = max(array_column($topProducts,'revenue')); ?>
        <div class="space-y-3">
            <?php foreach ($topProducts as $i => $p): ?>
            <div>
                <div class="flex justify-between text-sm mb-1">
                    <span class="font-medium text-gray-700"><?= $i+1 ?>. <?= h($p['name']) ?></span>
                    <span class="text-gray-500"><?= formatMoney($p['revenue']) ?></span>
                </div>
                <div class="w-full bg-gray-100 rounded-full h-2">
                    <div class="bg-blue-500 h-2 rounded-full" style="width:<?= round(($p['revenue']/$maxRev)*100) ?>%"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- By Payment Status -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <h3 class="font-semibold text-gray-800 mb-4">Sales by Payment Status</h3>
        <div class="space-y-3">
            <?php foreach ($byStatus as $bs): ?>
            <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg">
                <?= statusBadge($bs['payment_status']) ?>
                <div class="flex-1">
                    <p class="text-sm font-semibold text-gray-700"><?= formatMoney($bs['total']) ?></p>
                    <p class="text-xs text-gray-400"><?= number_format($bs['cnt']) ?> transactions</p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Daily Breakdown -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100">
    <div class="px-5 py-4 border-b"><h3 class="font-semibold text-gray-800">Daily Breakdown</h3></div>
    <div class="table-responsive">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-medium text-left">Date</th>
                    <th class="px-4 py-3 font-medium text-center">Transactions</th>
                    <th class="px-4 py-3 font-medium text-right">Total Sales</th>
                    <th class="px-4 py-3 font-medium text-right">Amount Paid</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php if (empty($daily)): ?>
                <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">No data.</td></tr>
                <?php else: ?>
                <?php foreach ($daily as $d): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium"><?= formatDate($d['sale_date']) ?> <span class="text-xs text-gray-400 ml-1"><?= date('l',strtotime($d['sale_date'])) ?></span></td>
                    <td class="px-4 py-3 text-center"><span class="bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full text-xs"><?= $d['txns'] ?></span></td>
                    <td class="px-4 py-3 text-right font-semibold"><?= formatMoney($d['total']) ?></td>
                    <td class="px-4 py-3 text-right text-green-700"><?= formatMoney($d['paid']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function setRange(type) {
    const today = new Date();
    const fmt = d => d.toISOString().split('T')[0];
    let from, to = fmt(today);
    if (type==='today') from = to;
    if (type==='week') { const d = new Date(today); d.setDate(d.getDate()-d.getDay()); from=fmt(d); }
    if (type==='month') from = fmt(new Date(today.getFullYear(),today.getMonth(),1));
    document.querySelector('[name="from"]').value = from;
    document.querySelector('[name="to"]').value = to;
}
</script>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
