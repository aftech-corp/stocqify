<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('reports');


$db    = getDB();
$bizId = currentBusinessId();
$from  = get('from', date('Y-m-01'));
$to    = get('to', date('Y-m-d'));
$status = get('status');
$page  = max(1, (int)get('page', 1));

$where  = 'd.business_id=?';
$params = [$bizId];
if ($from)   { $where .= ' AND d.created_at>=?'; $params[] = $from . ' 00:00:00'; }
if ($to)     { $where .= ' AND d.created_at<=?'; $params[] = $to   . ' 23:59:59'; }
if ($status) { $where .= ' AND d.status=?'; $params[] = $status; }

// Summary stats
$statsQ = $db->prepare("SELECT
    SUM(d.original_amount) AS total_debt,
    SUM(d.amount_paid) AS total_paid,
    SUM(d.balance) AS total_balance,
    COUNT(*) AS total_count,
    SUM(CASE WHEN d.status='overdue' THEN d.balance ELSE 0 END) AS overdue_balance,
    SUM(CASE WHEN d.status='paid' THEN 1 ELSE 0 END) AS paid_count
    FROM debts d WHERE d.business_id=? AND d.created_at>=? AND d.created_at<=?");
$statsQ->execute([$bizId, $from . ' 00:00:00', $to . ' 23:59:59']);
$stats = $statsQ->fetch();

// Status breakdown
$byStatusQ = $db->prepare("SELECT d.status, COUNT(*) AS cnt, SUM(d.balance) AS balance FROM debts d WHERE $where GROUP BY d.status");
$byStatusQ->execute($params);
$byStatus = $byStatusQ->fetchAll();

// Paginated list
$totalQ = $db->prepare("SELECT COUNT(*) FROM debts d WHERE $where");
$totalQ->execute($params);
$total = (int)$totalQ->fetchColumn();
$pag   = paginate($total, $page);

$stmt = $db->prepare("SELECT d.*, c.name AS customer_name, c.phone AS customer_phone,
    s.invoice_number
    FROM debts d
    JOIN customers c ON c.id=d.customer_id
    LEFT JOIN sales s ON s.id=d.sale_id
    WHERE $where
    ORDER BY d.status='overdue' DESC, d.balance DESC
    LIMIT {$pag['per_page']} OFFSET {$pag['offset']}");
$stmt->execute($params);
$debts = $stmt->fetchAll();

// Monthly trend
$trendQ = $db->prepare("SELECT DATE_FORMAT(d.created_at,'%Y-%m') AS month,
    COUNT(*) AS count, SUM(d.original_amount) AS total
    FROM debts d WHERE d.business_id=? AND d.created_at>=DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY month ORDER BY month");
$trendQ->execute([$bizId]);
$trend = $trendQ->fetchAll();

$pageTitle = 'Debt Report';
$extraHead = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-800">Debt Report</h2>
        <p class="text-sm text-gray-500"><?= number_format($total) ?> debts in period</p>
    </div>
    <button onclick="window.print()" class="border border-gray-300 text-gray-700 px-3 py-2 rounded-lg text-sm hover:bg-gray-50">
        <i class="fa-solid fa-print mr-1"></i> Print
    </button>
</div>

<!-- Summary Cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
        <p class="text-xl font-black text-gray-800"><?= formatMoney((float)$stats['total_debt']) ?></p>
        <p class="text-xs text-gray-500 mt-1">Total Credit Given</p>
    </div>
    <div class="bg-green-50 rounded-xl p-4">
        <p class="text-xl font-black text-green-700"><?= formatMoney((float)$stats['total_paid']) ?></p>
        <p class="text-xs text-green-600 mt-1">Total Collected</p>
    </div>
    <div class="bg-orange-50 rounded-xl p-4">
        <p class="text-xl font-black text-orange-700"><?= formatMoney((float)$stats['total_balance']) ?></p>
        <p class="text-xs text-orange-600 mt-1">Outstanding Balance</p>
    </div>
    <div class="bg-red-50 rounded-xl p-4">
        <p class="text-xl font-black text-red-700"><?= formatMoney((float)$stats['overdue_balance']) ?></p>
        <p class="text-xs text-red-600 mt-1">Overdue Balance</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <!-- Status breakdown -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
        <h4 class="text-sm font-semibold text-gray-700 mb-3">Status Breakdown</h4>
        <?php foreach ($byStatus as $bs): ?>
        <div class="flex justify-between items-center py-1.5 border-b last:border-0">
            <span><?= statusBadge($bs['status']) ?></span>
            <div class="text-right">
                <span class="text-sm font-medium"><?= formatMoney((float)$bs['balance']) ?></span>
                <span class="text-xs text-gray-400 ml-1">(<?= $bs['cnt'] ?>)</span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Chart -->
    <div class="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm p-4">
        <h4 class="text-sm font-semibold text-gray-700 mb-3">Monthly Debt Trend (6 Months)</h4>
        <canvas id="debtChart" height="120"></canvas>
    </div>
</div>

<!-- Filters -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
    <form method="GET" class="flex flex-wrap gap-3">
        <input type="date" name="from" value="<?= h($from) ?>" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        <input type="date" name="to"   value="<?= h($to) ?>"   class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        <select name="status" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
            <option value="">All Statuses</option>
            <?php foreach (['active','partial','paid','overdue','written_off'] as $s): ?>
            <option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm">Filter</button>
        <a href="debts.php" class="bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm">Clear</a>
    </form>
</div>

<!-- Table -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100">
    <div class="table-responsive">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-medium text-left">Customer</th>
                    <th class="px-4 py-3 font-medium text-left">Invoice</th>
                    <th class="px-4 py-3 font-medium text-right">Original</th>
                    <th class="px-4 py-3 font-medium text-right">Paid</th>
                    <th class="px-4 py-3 font-medium text-right">Balance</th>
                    <th class="px-4 py-3 font-medium text-left">Due Date</th>
                    <th class="px-4 py-3 font-medium text-center">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php if (empty($debts)): ?>
                <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">No debts found for this period.</td></tr>
                <?php else: ?>
                <?php foreach ($debts as $d): ?>
                <tr class="hover:bg-gray-50 <?= $d['status']==='overdue'?'bg-red-50/30':'' ?>">
                    <td class="px-4 py-3">
                        <p class="font-medium"><?= h($d['customer_name']) ?></p>
                        <p class="text-xs text-gray-400"><?= h($d['customer_phone'] ?? '') ?></p>
                    </td>
                    <td class="px-4 py-3 text-xs font-mono text-blue-600"><?= h($d['invoice_number'] ?? '-') ?></td>
                    <td class="px-4 py-3 text-right"><?= formatMoney($d['original_amount']) ?></td>
                    <td class="px-4 py-3 text-right text-green-600"><?= formatMoney($d['amount_paid']) ?></td>
                    <td class="px-4 py-3 text-right font-semibold <?= $d['balance']>0?'text-orange-600':'' ?>"><?= formatMoney($d['balance']) ?></td>
                    <td class="px-4 py-3 text-xs <?= ($d['due_date'] && $d['due_date']<date('Y-m-d') && $d['status']!='paid')?'text-red-600 font-medium':'' ?>">
                        <?= $d['due_date'] ? formatDate($d['due_date']) : '-' ?>
                    </td>
                    <td class="px-4 py-3 text-center"><?= statusBadge($d['status']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t">
        <?= renderPagination($pag, 'debts.php?' . http_build_query(array_filter(compact('from','to','status'))) . '&') ?>
    </div>
</div>

<script>
const trendData = <?= json_encode($trend) ?>;
new Chart(document.getElementById('debtChart'), {
    type: 'bar',
    data: {
        labels: trendData.map(r => r.month),
        datasets: [{
            label: 'Debt Amount',
            data: trendData.map(r => parseFloat(r.total)),
            backgroundColor: 'rgba(239,68,68,0.6)',
            borderRadius: 4
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
