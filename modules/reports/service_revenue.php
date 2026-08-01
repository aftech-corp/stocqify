<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('reports');
if (isAdmin()) { flash('error','Admin does not belong to a business.'); redirect(url('admin/businesses')); }

$db    = getDB();
$bizId = currentBusinessId();

$dateFrom = get('date_from', date('Y-m-01'));
$dateTo   = get('date_to',   date('Y-m-t'));
$groupBy  = in_array(get('group_by'), ['day','month','service']) ? get('group_by') : 'month';

// Summary totals for period
$sumQ = $db->prepare("SELECT
    COUNT(*) AS total_orders,
    SUM(status='completed') AS completed_orders,
    SUM(status='cancelled') AS cancelled_orders,
    COALESCE(SUM(CASE WHEN status='completed' THEN total_amount ELSE 0 END),0) AS revenue,
    COALESCE(SUM(amount_paid),0) AS collected,
    COALESCE(SUM(balance_due),0) AS outstanding
    FROM service_orders
    WHERE business_id=? AND DATE(created_at) BETWEEN ? AND ?");
$sumQ->execute([$bizId, $dateFrom, $dateTo]);
$summary = $sumQ->fetch();

// Revenue by period or by service
if ($groupBy === 'service') {
    $rowsQ = $db->prepare("SELECT
        soi.service_name AS label,
        COUNT(DISTINCT so.id) AS orders,
        SUM(soi.quantity) AS qty,
        COALESCE(SUM(soi.total),0) AS revenue
        FROM service_order_items soi
        JOIN service_orders so ON so.id=soi.order_id
        WHERE so.business_id=? AND so.status='completed' AND DATE(so.created_at) BETWEEN ? AND ?
        GROUP BY soi.service_name
        ORDER BY revenue DESC");
} elseif ($groupBy === 'day') {
    $rowsQ = $db->prepare("SELECT
        DATE(created_at) AS label,
        COUNT(*) AS orders,
        NULL AS qty,
        COALESCE(SUM(CASE WHEN status='completed' THEN total_amount ELSE 0 END),0) AS revenue
        FROM service_orders
        WHERE business_id=? AND DATE(created_at) BETWEEN ? AND ?
        GROUP BY DATE(created_at)
        ORDER BY label ASC");
} else { // month
    $rowsQ = $db->prepare("SELECT
        DATE_FORMAT(created_at,'%Y-%m') AS label,
        COUNT(*) AS orders,
        NULL AS qty,
        COALESCE(SUM(CASE WHEN status='completed' THEN total_amount ELSE 0 END),0) AS revenue
        FROM service_orders
        WHERE business_id=? AND DATE(created_at) BETWEEN ? AND ?
        GROUP BY DATE_FORMAT(created_at,'%Y-%m')
        ORDER BY label ASC");
}
$rowsQ->execute([$bizId, $dateFrom, $dateTo]);
$rows = $rowsQ->fetchAll();

// Top services always shown
$topQ = $db->prepare("SELECT soi.service_name, COUNT(DISTINCT so.id) AS orders, COALESCE(SUM(soi.total),0) AS revenue
    FROM service_order_items soi JOIN service_orders so ON so.id=soi.order_id
    WHERE so.business_id=? AND so.status='completed'
    GROUP BY soi.service_name ORDER BY revenue DESC LIMIT 5");
$topQ->execute([$bizId]);
$topServices = $topQ->fetchAll();

$pageTitle = 'Revenue Report';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-6">
    <h2 class="text-xl font-bold text-gray-800">Revenue Report</h2>
</div>

<!-- Filters -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-6">
    <form method="GET" class="flex flex-wrap gap-3 items-end">
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">From</label>
            <input type="date" name="date_from" value="<?= h($dateFrom) ?>" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">To</label>
            <input type="date" name="date_to" value="<?= h($dateTo) ?>" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Group By</label>
            <select name="group_by" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
                <option value="month"   <?= $groupBy==='month'?'selected':'' ?>>Month</option>
                <option value="day"     <?= $groupBy==='day'?'selected':'' ?>>Day</option>
                <option value="service" <?= $groupBy==='service'?'selected':'' ?>>Service</option>
            </select>
        </div>
        <button class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm">Apply</button>
        <a href="service_revenue.php" class="bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm">Reset</a>
    </form>
</div>

<!-- Summary cards -->
<div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-6">
    <?php
    $cards = [
        ['label'=>'Total Orders',    'value'=>number_format($summary['total_orders']),    'icon'=>'fa-clipboard-list',        'color'=>'blue'],
        ['label'=>'Completed',       'value'=>number_format($summary['completed_orders']), 'icon'=>'fa-circle-check',         'color'=>'green'],
        ['label'=>'Revenue',         'value'=>formatMoney($summary['revenue']),             'icon'=>'fa-circle-dollar-to-slot','color'=>'purple'],
        ['label'=>'Collected',       'value'=>formatMoney($summary['collected']),           'icon'=>'fa-money-bill-wave',      'color'=>'green'],
        ['label'=>'Outstanding',     'value'=>formatMoney($summary['outstanding']),         'icon'=>'fa-hourglass-half',       'color'=>'orange'],
        ['label'=>'Cancelled',       'value'=>number_format($summary['cancelled_orders']), 'icon'=>'fa-times-circle',          'color'=>'red'],
    ];
    foreach ($cards as $c):
    $cls = match($c['color']) { 'blue'=>'bg-blue-50 text-blue-600','green'=>'bg-green-50 text-green-600','purple'=>'bg-purple-50 text-purple-600','orange'=>'bg-orange-50 text-orange-600','red'=>'bg-red-50 text-red-600', default=>'bg-gray-50 text-gray-500' };
    ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 <?= $cls ?> rounded-lg flex items-center justify-center flex-shrink-0">
                <i class="fa-solid <?= $c['icon'] ?> text-sm"></i>
            </div>
            <div>
                <p class="text-xs text-gray-400"><?= $c['label'] ?></p>
                <p class="font-bold text-gray-800 text-sm"><?= $c['value'] ?></p>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- Revenue table -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100">
            <div class="px-5 py-4 border-b">
                <h3 class="font-semibold text-gray-800">Revenue Breakdown (<?= ucfirst($groupBy) ?>)</h3>
            </div>
            <div class="table-responsive">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                        <tr>
                            <th class="px-4 py-3 font-medium text-left"><?= $groupBy === 'service' ? 'Service' : 'Period' ?></th>
                            <th class="px-4 py-3 font-medium text-right">Orders</th>
                            <?php if ($groupBy === 'service'): ?><th class="px-4 py-3 font-medium text-right">Qty</th><?php endif; ?>
                            <th class="px-4 py-3 font-medium text-right">Revenue</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php if (empty($rows)): ?>
                        <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No data for this period.</td></tr>
                        <?php else: ?>
                        <?php $maxRev = max(array_column($rows, 'revenue') ?: [0]); ?>
                        <?php foreach ($rows as $r): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium"><?= h($r['label']) ?></td>
                            <td class="px-4 py-3 text-right text-gray-500"><?= number_format($r['orders']) ?></td>
                            <?php if ($groupBy === 'service'): ?><td class="px-4 py-3 text-right text-gray-500"><?= number_format($r['qty'] ?? 0, 2) ?></td><?php endif; ?>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <?php if ($maxRev > 0): ?>
                                    <div class="h-1.5 bg-blue-200 rounded-full" style="width:<?= round(($r['revenue']/$maxRev)*80) ?>px;min-width:2px"></div>
                                    <?php endif; ?>
                                    <span class="font-semibold text-gray-800"><?= formatMoney($r['revenue']) ?></span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Top services -->
    <div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h3 class="font-semibold text-gray-800 mb-4">Top Services (All Time)</h3>
            <?php if (empty($topServices)): ?>
            <p class="text-gray-400 text-sm">No completed orders yet.</p>
            <?php else: ?>
            <div class="space-y-3">
                <?php $maxTop = max(array_column($topServices,'revenue') ?: [0]); ?>
                <?php foreach ($topServices as $i => $svc): ?>
                <div>
                    <div class="flex justify-between text-sm mb-1">
                        <span class="font-medium text-gray-700 truncate"><?= h($svc['service_name']) ?></span>
                        <span class="text-gray-500 text-xs ml-2 flex-shrink-0"><?= number_format($svc['orders']) ?> orders</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="flex-1 bg-gray-100 rounded-full h-2">
                            <div class="bg-blue-500 rounded-full h-2" style="width:<?= $maxTop > 0 ? round(($svc['revenue']/$maxTop)*100) : 0 ?>%"></div>
                        </div>
                        <span class="text-xs font-semibold text-gray-700 w-24 text-right"><?= formatMoney($svc['revenue']) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
