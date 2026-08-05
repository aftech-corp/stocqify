<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('reports');
requireFeature('analytics_customer_report');


$db    = getDB();
$bizId = currentBusinessId();
$from  = get('from', date('Y-m-01'));
$to    = get('to', date('Y-m-d'));
$sort  = get('sort', 'revenue');
$page  = max(1, (int)get('page', 1));

$sortMap = [
    'revenue'   => 'total_revenue DESC',
    'sales'     => 'sale_count DESC',
    'debt'      => 'outstanding_debt DESC',
    'name'      => 'c.name ASC',
    'recent'    => 'last_purchase DESC',
];
$orderBy = $sortMap[$sort] ?? 'total_revenue DESC';

// Summary stats
$statsQ = $db->prepare("SELECT
    COUNT(DISTINCT c.id) AS total_customers,
    COUNT(DISTINCT CASE WHEN s.sale_date>=? AND s.sale_date<=? THEN c.id END) AS active_customers,
    COALESCE(SUM(CASE WHEN s.sale_date>=? AND s.sale_date<=? THEN s.total_amount ELSE 0 END),0) AS period_revenue,
    COALESCE(SUM(d.balance),0) AS total_debt
    FROM customers c
    LEFT JOIN sales s ON s.customer_id=c.id AND s.business_id=?
    LEFT JOIN debts d ON d.customer_id=c.id AND d.business_id=? AND d.status NOT IN ('paid','written_off')
    WHERE c.business_id=? AND c.is_active=1");
$statsQ->execute([$from, $to, $from, $to, $bizId, $bizId, $bizId]);
$stats = $statsQ->fetch();

// Customer list with aggregates
$totalQ = $db->prepare("SELECT COUNT(*) FROM customers c WHERE c.business_id=? AND c.is_active=1");
$totalQ->execute([$bizId]);
$total = (int)$totalQ->fetchColumn();
$pag   = paginate($total, $page);

$stmt = $db->prepare("SELECT c.*,
    COUNT(DISTINCT s.id) AS sale_count,
    COALESCE(SUM(s.total_amount),0) AS total_revenue,
    COALESCE(SUM(CASE WHEN s.sale_date>=? AND s.sale_date<=? THEN s.total_amount ELSE 0 END),0) AS period_revenue,
    MAX(s.sale_date) AS last_purchase,
    COALESCE((SELECT SUM(d.balance) FROM debts d WHERE d.customer_id=c.id AND d.business_id=? AND d.status NOT IN ('paid','written_off')),0) AS outstanding_debt
    FROM customers c
    LEFT JOIN sales s ON s.customer_id=c.id AND s.business_id=?
    WHERE c.business_id=? AND c.is_active=1
    GROUP BY c.id
    ORDER BY $orderBy
    LIMIT {$pag['per_page']} OFFSET {$pag['offset']}");
$stmt->execute([$from, $to, $bizId, $bizId, $bizId]);
$customers = $stmt->fetchAll();

// New customers per month (last 6 months)
$newCustQ = $db->prepare("SELECT DATE_FORMAT(c.created_at,'%Y-%m') AS month, COUNT(*) AS count
    FROM customers c WHERE c.business_id=? AND c.created_at>=DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY month ORDER BY month");
$newCustQ->execute([$bizId]);
$newCust = $newCustQ->fetchAll();

$pageTitle = 'Customer Report';
$extraHead = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-800">Customer Report</h2>
        <p class="text-sm text-gray-500"><?= number_format($stats['total_customers']) ?> total customers</p>
    </div>
    <button onclick="window.print()" class="border border-gray-300 text-gray-700 px-3 py-2 rounded-lg text-sm hover:bg-gray-50">
        <i class="fa-solid fa-print mr-1"></i> Print
    </button>
</div>

<!-- Summary Cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
        <p class="text-2xl font-black text-gray-800"><?= number_format($stats['total_customers']) ?></p>
        <p class="text-xs text-gray-500 mt-1">Total Customers</p>
    </div>
    <div class="bg-blue-50 rounded-xl p-4">
        <p class="text-2xl font-black text-blue-700"><?= number_format($stats['active_customers']) ?></p>
        <p class="text-xs text-blue-600 mt-1">Active in Period</p>
    </div>
    <div class="bg-green-50 rounded-xl p-4">
        <p class="text-xl font-black text-green-700"><?= formatMoney((float)$stats['period_revenue']) ?></p>
        <p class="text-xs text-green-600 mt-1">Period Revenue</p>
    </div>
    <div class="bg-orange-50 rounded-xl p-4">
        <p class="text-xl font-black text-orange-700"><?= formatMoney((float)$stats['total_debt']) ?></p>
        <p class="text-xs text-orange-600 mt-1">Outstanding Debts</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <!-- New customers chart -->
    <div class="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm p-4">
        <h4 class="text-sm font-semibold text-gray-700 mb-3">New Customers (Last 6 Months)</h4>
        <canvas id="custChart" height="120"></canvas>
    </div>

    <!-- Quick stats -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
        <h4 class="text-sm font-semibold text-gray-700 mb-3">Quick Stats</h4>
        <?php
        $avgRevStmt = $db->prepare("SELECT AVG(sub.total) FROM (SELECT SUM(s.total_amount) AS total FROM sales s WHERE s.business_id=? AND s.customer_id IS NOT NULL GROUP BY s.customer_id) AS sub");
        $avgRevStmt->execute([$bizId]);
        $avgRev = (float)$avgRevStmt->fetchColumn();

        $repeatQ = $db->prepare("SELECT COUNT(*) FROM (SELECT customer_id FROM sales WHERE business_id=? AND customer_id IS NOT NULL GROUP BY customer_id HAVING COUNT(*)>1) AS sub");
        $repeatQ->execute([$bizId]);
        $repeatCount = (int)$repeatQ->fetchColumn();
        ?>
        <div class="space-y-3">
            <div class="flex justify-between text-sm border-b pb-2">
                <span class="text-gray-500">Avg Revenue/Customer</span>
                <span class="font-semibold"><?= formatMoney($avgRev) ?></span>
            </div>
            <div class="flex justify-between text-sm border-b pb-2">
                <span class="text-gray-500">Repeat Customers</span>
                <span class="font-semibold"><?= number_format($repeatCount) ?></span>
            </div>
            <div class="flex justify-between text-sm border-b pb-2">
                <span class="text-gray-500">With Debt</span>
                <?php
                $withDebt = $db->prepare("SELECT COUNT(DISTINCT customer_id) FROM debts WHERE business_id=? AND status NOT IN ('paid','written_off')");
                $withDebt->execute([$bizId]);
                ?>
                <span class="font-semibold text-orange-600"><?= number_format($withDebt->fetchColumn()) ?></span>
            </div>
            <div class="flex justify-between text-sm">
                <span class="text-gray-500">Inactive (90 days)</span>
                <?php
                $inactiveQ = $db->prepare("SELECT COUNT(DISTINCT c.id) FROM customers c WHERE c.business_id=? AND c.id NOT IN (SELECT DISTINCT customer_id FROM sales WHERE business_id=? AND sale_date>=DATE_SUB(NOW(), INTERVAL 90 DAY) AND customer_id IS NOT NULL)");
                $inactiveQ->execute([$bizId, $bizId]);
                ?>
                <span class="font-semibold text-red-600"><?= number_format($inactiveQ->fetchColumn()) ?></span>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
    <form method="GET" class="flex flex-wrap gap-3">
        <input type="date" name="from" value="<?= h($from) ?>" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        <input type="date" name="to"   value="<?= h($to) ?>"   class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        <select name="sort" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
            <option value="revenue" <?= $sort==='revenue'?'selected':'' ?>>Sort by Revenue</option>
            <option value="sales"   <?= $sort==='sales'?'selected':'' ?>>Sort by Sales Count</option>
            <option value="debt"    <?= $sort==='debt'?'selected':'' ?>>Sort by Debt</option>
            <option value="recent"  <?= $sort==='recent'?'selected':'' ?>>Sort by Recent</option>
            <option value="name"    <?= $sort==='name'?'selected':'' ?>>Sort by Name</option>
        </select>
        <button class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm">Apply</button>
        <a href="customers.php" class="bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm">Clear</a>
    </form>
</div>

<!-- Table -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100">
    <div class="table-responsive">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-medium text-left">#</th>
                    <th class="px-4 py-3 font-medium text-left">Customer</th>
                    <th class="px-4 py-3 font-medium text-center">Total Sales</th>
                    <th class="px-4 py-3 font-medium text-right">Lifetime Revenue</th>
                    <th class="px-4 py-3 font-medium text-right">Period Revenue</th>
                    <th class="px-4 py-3 font-medium text-right">Outstanding Debt</th>
                    <th class="px-4 py-3 font-medium text-left">Last Purchase</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php if (empty($customers)): ?>
                <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">No customers found.</td></tr>
                <?php else: ?>
                <?php $rank = $pag['offset'] + 1; foreach ($customers as $c): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-400 font-medium">#<?= $rank++ ?></td>
                    <td class="px-4 py-3">
                        <a href="<?= url('customers/view') ?>?id=<?= $c['id'] ?>" class="font-medium text-blue-700 hover:underline"><?= h($c['name']) ?></a>
                        <p class="text-xs text-gray-400"><?= h($c['phone'] ?? $c['email'] ?? '') ?></p>
                    </td>
                    <td class="px-4 py-3 text-center text-gray-700"><?= number_format($c['sale_count']) ?></td>
                    <td class="px-4 py-3 text-right font-medium"><?= formatMoney((float)$c['total_revenue']) ?></td>
                    <td class="px-4 py-3 text-right text-green-700 font-medium"><?= formatMoney((float)$c['period_revenue']) ?></td>
                    <td class="px-4 py-3 text-right <?= $c['outstanding_debt']>0?'text-orange-600 font-semibold':'text-gray-400' ?>">
                        <?= $c['outstanding_debt']>0 ? formatMoney((float)$c['outstanding_debt']) : '-' ?>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500"><?= $c['last_purchase'] ? formatDate($c['last_purchase']) : 'Never' ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t">
        <?= renderPagination($pag, 'customers.php?' . http_build_query(array_filter(compact('from','to','sort'))) . '&') ?>
    </div>
</div>

<script>
const newCustData = <?= json_encode($newCust) ?>;
new Chart(document.getElementById('custChart'), {
    type: 'bar',
    data: {
        labels: newCustData.map(r => r.month),
        datasets: [{
            label: 'New Customers',
            data: newCustData.map(r => parseInt(r.count)),
            backgroundColor: 'rgba(37,99,235,0.7)',
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
    }
});
</script>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
