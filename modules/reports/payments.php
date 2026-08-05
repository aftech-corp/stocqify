<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('reports');
requireFeature('analytics_payment_report');


$db    = getDB();
$bizId = currentBusinessId();
$from  = get('from', date('Y-m-01'));
$to    = get('to', date('Y-m-d'));
$method = get('method');
$page  = max(1, (int)get('page', 1));

$where  = 'p.business_id=?';
$params = [$bizId];
if ($from)   { $where .= ' AND p.payment_date>=?'; $params[] = $from; }
if ($to)     { $where .= ' AND p.payment_date<=?'; $params[] = $to; }
if ($method) { $where .= ' AND p.payment_method=?'; $params[] = $method; }

// Summary
$statsQ = $db->prepare("SELECT SUM(p.amount) AS total, COUNT(*) AS count,
    SUM(CASE WHEN p.payment_method='cash' THEN p.amount ELSE 0 END) AS cash,
    SUM(CASE WHEN p.payment_method='orange_money' THEN p.amount ELSE 0 END) AS orange,
    SUM(CASE WHEN p.payment_method='afrimoney' THEN p.amount ELSE 0 END) AS afrimoney,
    SUM(CASE WHEN p.payment_method='qmoney' THEN p.amount ELSE 0 END) AS qmoney,
    SUM(CASE WHEN p.payment_method='bank_transfer' THEN p.amount ELSE 0 END) AS bank
    FROM payments p WHERE p.business_id=? AND p.payment_date>=? AND p.payment_date<=?");
$statsQ->execute([$bizId, $from, $to]);
$stats = $statsQ->fetch();

// Also get debt payments total
$dpQ = $db->prepare("SELECT SUM(dp.amount) AS total FROM debt_payments dp WHERE dp.business_id=? AND dp.payment_date>=? AND dp.payment_date<=?");
$dpQ->execute([$bizId, $from, $to]);
$debtPayTotal = (float)$dpQ->fetchColumn();

// Daily trend
$dailyQ = $db->prepare("SELECT p.payment_date, SUM(p.amount) AS total FROM payments p WHERE p.business_id=? AND p.payment_date>=? AND p.payment_date<=? GROUP BY p.payment_date ORDER BY p.payment_date");
$dailyQ->execute([$bizId, $from, $to]);
$daily = $dailyQ->fetchAll();

// Paginated list
$totalQ = $db->prepare("SELECT COUNT(*) FROM payments p WHERE $where");
$totalQ->execute($params);
$total = (int)$totalQ->fetchColumn();
$pag   = paginate($total, $page);

$stmt = $db->prepare("SELECT p.*, c.name AS customer_name, s.invoice_number, s.walkin_name, u.name AS user_name
    FROM payments p
    LEFT JOIN customers c ON c.id=p.customer_id
    LEFT JOIN sales s ON s.id=p.sale_id
    LEFT JOIN users u ON u.id=p.user_id
    WHERE $where
    ORDER BY p.payment_date DESC, p.id DESC
    LIMIT {$pag['per_page']} OFFSET {$pag['offset']}");
$stmt->execute($params);
$payments = $stmt->fetchAll();

$methods = [
    'cash'          => ['label'=>'Cash',          'icon'=>'fa-money-bill', 'color'=>'green'],
    'orange_money'  => ['label'=>'Orange Money',  'icon'=>'fa-mobile', 'color'=>'orange'],
    'afrimoney'     => ['label'=>'Afrimoney',      'icon'=>'fa-mobile', 'color'=>'blue'],
    'qmoney'        => ['label'=>'QMoney',         'icon'=>'fa-mobile', 'color'=>'purple'],
    'bank_transfer' => ['label'=>'Bank Transfer',  'icon'=>'fa-building-columns', 'color'=>'indigo'],
];

$pageTitle = 'Payment Report';
$extraHead = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-800">Payment Report</h2>
        <p class="text-sm text-gray-500"><?= number_format($total) ?> payment records</p>
    </div>
    <button onclick="window.print()" class="border border-gray-300 text-gray-700 px-3 py-2 rounded-lg text-sm hover:bg-gray-50">
        <i class="fa-solid fa-print mr-1"></i> Print
    </button>
</div>

<!-- Summary -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-blue-50 rounded-xl p-4 lg:col-span-2">
        <p class="text-3xl font-black text-blue-700"><?= formatMoney((float)$stats['total']) ?></p>
        <p class="text-sm text-blue-600">Total Payments Received</p>
        <p class="text-xs text-blue-400 mt-1"><?= number_format($stats['count']) ?> transactions</p>
    </div>
    <div class="bg-green-50 rounded-xl p-4">
        <p class="text-xl font-black text-green-700"><?= formatMoney((float)$stats['cash']) ?></p>
        <p class="text-xs text-green-600">Cash Payments</p>
    </div>
    <div class="bg-orange-50 rounded-xl p-4">
        <p class="text-xl font-black text-orange-700"><?= formatMoney((float)$stats['orange'] + $stats['afrimoney'] + $stats['qmoney']) ?></p>
        <p class="text-xs text-orange-600">Mobile Money</p>
    </div>
</div>

<!-- Method Breakdown -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
        <h4 class="text-sm font-semibold text-gray-700 mb-3">By Payment Method</h4>
        <?php
        $methodTotals = [
            'cash'         => (float)$stats['cash'],
            'orange_money' => (float)$stats['orange'],
            'afrimoney'    => (float)$stats['afrimoney'],
            'qmoney'       => (float)$stats['qmoney'],
            'bank_transfer'=> (float)$stats['bank'],
        ];
        $grandTotal = array_sum($methodTotals);
        foreach ($methodTotals as $key => $val):
            if ($val <= 0) continue;
            $m = $methods[$key];
            $pct = $grandTotal > 0 ? round(($val / $grandTotal) * 100) : 0;
        ?>
        <div class="mb-3">
            <div class="flex justify-between text-sm mb-1">
                <span class="text-gray-600"><i class="fa-solid <?= $m['icon'] ?> text-<?= $m['color'] ?>-500 mr-1"></i><?= $m['label'] ?></span>
                <span class="font-medium"><?= formatMoney($val) ?></span>
            </div>
            <div class="h-1.5 bg-gray-100 rounded-full"><div class="h-full bg-<?= $m['color'] ?>-500 rounded-full" style="width:<?= $pct ?>%"></div></div>
        </div>
        <?php endforeach; ?>
        <?php if ($debtPayTotal > 0): ?>
        <div class="pt-2 border-t text-xs text-gray-400">
            + <?= formatMoney($debtPayTotal) ?> from debt payments
        </div>
        <?php endif; ?>
    </div>

    <div class="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm p-4">
        <h4 class="text-sm font-semibold text-gray-700 mb-3">Daily Payment Trend</h4>
        <canvas id="payChart" height="120"></canvas>
    </div>
</div>

<!-- Filters -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
    <form method="GET" class="flex flex-wrap gap-3">
        <input type="date" name="from" value="<?= h($from) ?>" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        <input type="date" name="to"   value="<?= h($to) ?>"   class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        <select name="method" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
            <option value="">All Methods</option>
            <?php foreach ($methods as $k => $m): ?>
            <option value="<?= $k ?>" <?= $method===$k?'selected':'' ?>><?= $m['label'] ?></option>
            <?php endforeach; ?>
        </select>
        <button class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm">Filter</button>
        <a href="payments.php" class="bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm">Clear</a>
    </form>
</div>

<!-- Table -->
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
                    <th class="px-4 py-3 font-medium text-left">Recorded By</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php if (empty($payments)): ?>
                <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">No payments found for this period.</td></tr>
                <?php else: ?>
                <?php foreach ($payments as $p): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-500"><?= formatDate($p['payment_date']) ?></td>
                    <td class="px-4 py-3 font-medium">
                        <?php if (!empty($p['customer_name'])): ?>
                            <?= h($p['customer_name']) ?>
                        <?php elseif (!empty($p['walkin_name'])): ?>
                            <?= h($p['walkin_name']) ?> <span class="text-xs text-gray-400 font-normal">(Walk-in)</span>
                        <?php else: ?>
                            <span class="text-gray-400">Walk-in</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-xs font-mono text-blue-600"><?= h($p['invoice_number'] ?? '-') ?></td>
                    <td class="px-4 py-3 text-right font-semibold text-green-700"><?= formatMoney($p['amount']) ?></td>
                    <td class="px-4 py-3 text-xs capitalize text-gray-500"><?= str_replace('_', ' ', $p['payment_method']) ?></td>
                    <td class="px-4 py-3 text-xs text-gray-400 font-mono"><?= h($p['reference_number'] ?? '-') ?></td>
                    <td class="px-4 py-3 text-xs text-gray-400"><?= h($p['user_name']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t">
        <?= renderPagination($pag, 'payments.php?' . http_build_query(array_filter(compact('from','to','method'))) . '&') ?>
    </div>
</div>

<script>
const dailyData = <?= json_encode($daily) ?>;
new Chart(document.getElementById('payChart'), {
    type: 'line',
    data: {
        labels: dailyData.map(r => r.payment_date),
        datasets: [{
            label: 'Payments',
            data: dailyData.map(r => parseFloat(r.total)),
            borderColor: '#2563eb',
            backgroundColor: 'rgba(37,99,235,0.1)',
            fill: true,
            tension: 0.3,
            pointRadius: 3
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { callback: v => 'Le ' + v.toLocaleString() } } }
    }
});
</script>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
