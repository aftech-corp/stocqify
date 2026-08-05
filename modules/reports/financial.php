<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('reports');
requireFeature('analytics_financial_report');


$db    = getDB();
$bizId = currentBusinessId();
$from  = get('from', date('Y-m-01'));
$to    = get('to', date('Y-m-d'));

// Branch filter
$branchCond  = '';
$branchParam = [];
if (currentBranchId()) { $branchCond = ' AND branch_id = ?'; $branchParam = [currentBranchId()]; }
$sBranchCond = $branchCond ? str_replace('branch_id','s.branch_id',$branchCond) : ''; // alias for JOINed queries

$q = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE business_id=? AND sale_date BETWEEN ? AND ?" . $branchCond); $q->execute([$bizId,$from,$to,...$branchParam]); $salesRev = (float)$q->fetchColumn();
$q2 = $db->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM sales WHERE business_id=? AND sale_date BETWEEN ? AND ?" . $branchCond); $q2->execute([$bizId,$from,$to,...$branchParam]); $salesPaid = (float)$q2->fetchColumn();
$q3 = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM income WHERE business_id=? AND income_date BETWEEN ? AND ?" . $branchCond); $q3->execute([$bizId,$from,$to,...$branchParam]); $otherIncome = (float)$q3->fetchColumn();
$q4 = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE business_id=? AND expense_date BETWEEN ? AND ?" . $branchCond); $q4->execute([$bizId,$from,$to,...$branchParam]); $totalExpenses = (float)$q4->fetchColumn();

// Cost of Goods Sold
$q5 = $db->prepare("SELECT COALESCE(SUM(si.cost_price * si.quantity),0) FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.business_id=? AND s.sale_date BETWEEN ? AND ?" . $sBranchCond); $q5->execute([$bizId,$from,$to,...$branchParam]); $cogs = (float)$q5->fetchColumn();

$grossProfit     = $salesRev - $cogs;
$totalIncome     = $salesRev + $otherIncome;
$operatingProfit = $grossProfit - $totalExpenses;
$netProfit       = $totalIncome - $cogs - $totalExpenses;

// Expense breakdown by category
$expByCatQ = $db->prepare("SELECT COALESCE(ec.name,'Uncategorized') AS cat, SUM(e.amount) AS total FROM expenses e LEFT JOIN expense_categories ec ON ec.id=e.category_id WHERE e.business_id=? AND e.expense_date BETWEEN ? AND ?" . $branchCond . " GROUP BY ec.id ORDER BY total DESC");
$expByCatQ->execute([$bizId,$from,$to,...$branchParam]);
$expByCat = $expByCatQ->fetchAll();

// Monthly trend (last 6 months)
$monthlyQ = $db->prepare("SELECT DATE_FORMAT(sale_date,'%Y-%m') AS month, SUM(total_amount) AS revenue, SUM(amount_paid) AS collected FROM sales WHERE business_id=? AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)" . $branchCond . " GROUP BY month ORDER BY month");
$monthlyQ->execute([$bizId,...$branchParam]);
$monthly = $monthlyQ->fetchAll();

$pageTitle = 'Financial Report (P&L)';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<?= renderBranchBanner() ?>
<div class="flex items-center justify-between mb-6">
    <h2 class="text-xl font-bold text-gray-800">Profit & Loss Report</h2>
    <form method="GET" class="flex gap-2">
        <input type="date" name="from" value="<?= h($from) ?>" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        <input type="date" name="to"   value="<?= h($to) ?>"   class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        <button class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm">Apply</button>
    </form>
</div>

<p class="text-sm text-gray-500 mb-4">Period: <strong><?= formatDate($from) ?></strong> to <strong><?= formatDate($to) ?></strong></p>

<!-- P&L Summary -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-blue-50 rounded-xl p-4 text-center">
        <p class="text-xl font-black text-blue-700"><?= formatMoney($salesRev) ?></p>
        <p class="text-sm text-blue-600">Total Sales</p>
    </div>
    <div class="bg-orange-50 rounded-xl p-4 text-center">
        <p class="text-xl font-black text-orange-700"><?= formatMoney($cogs) ?></p>
        <p class="text-sm text-orange-600">Cost of Goods Sold</p>
    </div>
    <div class="bg-teal-50 rounded-xl p-4 text-center">
        <p class="text-xl font-black text-teal-700"><?= formatMoney($grossProfit) ?></p>
        <p class="text-sm text-teal-600">Gross Profit</p>
    </div>
    <div class="<?= $netProfit>=0?'bg-green-50':'bg-red-50' ?> rounded-xl p-4 text-center">
        <p class="text-xl font-black <?= $netProfit>=0?'text-green-700':'text-red-700' ?>"><?= formatMoney($netProfit) ?></p>
        <p class="text-sm <?= $netProfit>=0?'text-green-600':'text-red-600' ?>">Net Profit</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <!-- P&L Statement -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h3 class="font-semibold text-gray-800 mb-4">Income Statement</h3>
        <table class="w-full text-sm">
            <tbody>
                <tr class="border-b border-gray-100"><td class="py-2 font-semibold text-gray-700" colspan="2">INCOME</td></tr>
                <tr><td class="py-1.5 pl-4 text-gray-600">Sales Revenue</td><td class="py-1.5 text-right font-medium"><?= formatMoney($salesRev) ?></td></tr>
                <?php if ($otherIncome > 0): ?><tr><td class="py-1.5 pl-4 text-gray-600">Other Income</td><td class="py-1.5 text-right"><?= formatMoney($otherIncome) ?></td></tr><?php endif; ?>
                <tr class="bg-blue-50"><td class="py-2 pl-4 font-semibold">Total Income</td><td class="py-2 text-right font-bold text-blue-700"><?= formatMoney($totalIncome) ?></td></tr>
                <tr class="border-b border-gray-100"><td class="py-2 font-semibold text-gray-700 pt-3" colspan="2">COST OF GOODS</td></tr>
                <tr><td class="py-1.5 pl-4 text-gray-600">Cost of Goods Sold</td><td class="py-1.5 text-right text-red-600">(<?= formatMoney($cogs) ?>)</td></tr>
                <tr class="bg-teal-50"><td class="py-2 pl-4 font-semibold">Gross Profit</td><td class="py-2 text-right font-bold text-teal-700"><?= formatMoney($grossProfit) ?></td></tr>
                <tr class="border-b border-gray-100"><td class="py-2 font-semibold text-gray-700 pt-3" colspan="2">OPERATING EXPENSES</td></tr>
                <?php foreach ($expByCat as $ec): ?>
                <tr><td class="py-1.5 pl-4 text-gray-600"><?= h($ec['cat']) ?></td><td class="py-1.5 text-right text-red-500">(<?= formatMoney($ec['total']) ?>)</td></tr>
                <?php endforeach; ?>
                <tr class="bg-red-50"><td class="py-2 pl-4 font-semibold">Total Expenses</td><td class="py-2 text-right font-bold text-red-700">(<?= formatMoney($totalExpenses) ?>)</td></tr>
                <tr class="border-t-2 <?= $netProfit>=0?'bg-green-50':'bg-red-50' ?>">
                    <td class="py-3 pl-2 font-black text-gray-800 text-base">NET PROFIT</td>
                    <td class="py-3 text-right font-black text-base <?= $netProfit>=0?'text-green-700':'text-red-700' ?>"><?= formatMoney($netProfit) ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Expense Breakdown + Chart -->
    <div class="space-y-4">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h3 class="font-semibold text-gray-800 mb-4">Expense by Category</h3>
            <?php if (empty($expByCat)): ?>
            <p class="text-gray-400 text-sm text-center py-4">No expenses in this period.</p>
            <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($expByCat as $ec): ?>
                <?php $pct = $totalExpenses > 0 ? round(($ec['total']/$totalExpenses)*100) : 0; ?>
                <div>
                    <div class="flex justify-between text-sm mb-1">
                        <span class="text-gray-700 font-medium"><?= h($ec['cat']) ?></span>
                        <span class="text-gray-500"><?= formatMoney($ec['total']) ?> (<?= $pct ?>%)</span>
                    </div>
                    <div class="w-full bg-gray-100 rounded-full h-2">
                        <div class="bg-red-500 h-2 rounded-full" style="width:<?= $pct ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h3 class="font-semibold text-gray-800 mb-4">Monthly Revenue Trend</h3>
            <canvas id="trendChart" height="150"></canvas>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($monthly,'month')) ?>,
        datasets: [
            { label: 'Revenue', data: <?= json_encode(array_map(fn($r)=>(float)$r['revenue'],$monthly)) ?>, borderColor:'#3b82f6',tension:0.4,fill:false },
            { label: 'Collected', data: <?= json_encode(array_map(fn($r)=>(float)$r['collected'],$monthly)) ?>, borderColor:'#10b981',tension:0.4,fill:false }
        ]
    },
    options: { responsive:true, plugins:{legend:{position:'bottom'}}, scales:{y:{beginAtZero:true}} }
});
</script>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
