<?php
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/functions.php';
requireLogin();

$db         = getDB();
$today      = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');

function dbStat(PDO $db, string $sql, array $params = []): mixed {
    try {
        $s = $db->prepare($sql);
        $s->execute($params);
        return $s->fetchColumn();
    } catch (\Exception $e) {
        return 0;
    }
}

// ═══════════════════════════════════════════════════════════
// SYSTEM ADMIN DASHBOARD
// ═══════════════════════════════════════════════════════════
if (isAdmin()) {

    $totalBusinesses  = (int) dbStat($db, "SELECT COUNT(*) FROM businesses");
    $activeBusinesses = (int) dbStat($db, "SELECT COUNT(*) FROM businesses WHERE is_active=1");
    $newBizMonth      = (int) dbStat($db, "SELECT COUNT(*) FROM businesses WHERE created_at >= ?", [$monthStart]);
    $totalUsers       = (int) dbStat($db, "SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug != 'admin'");
    $activeUsers      = (int) dbStat($db, "SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug != 'admin' AND u.is_active=1");
    $newUsersMonth    = (int) dbStat($db, "SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug != 'admin' AND u.created_at >= ?", [$monthStart]);
    $loginsToday      = (int) dbStat($db, "SELECT COUNT(*) FROM users WHERE last_login >= ?", [$today]);

    // Recent businesses
    try {
        $recentBizStmt = $db->query("SELECT b.name, b.email, b.is_active, b.created_at,
            (SELECT COUNT(*) FROM users u2 WHERE u2.business_id=b.id) AS user_count
            FROM businesses b ORDER BY b.created_at DESC LIMIT 8");
        $recentBiz = $recentBizStmt->fetchAll();
    } catch (\Exception $e) { $recentBiz = []; }

    // Recent user registrations (non-admin)
    try {
        $recentUsersStmt = $db->query("SELECT u.name, u.email, u.created_at, r.name AS role_name, b.name AS biz_name
            FROM users u
            JOIN roles r ON r.id=u.role_id
            LEFT JOIN businesses b ON b.id=u.business_id
            WHERE r.slug != 'admin'
            ORDER BY u.created_at DESC LIMIT 6");
        $recentUsers = $recentUsersStmt->fetchAll();
    } catch (\Exception $e) { $recentUsers = []; }

    // Business registrations — last 6 months
    $chartLabels = [];
    $chartData   = [];
    for ($i = 5; $i >= 0; $i--) {
        $m = date('Y-m', strtotime("-{$i} months"));
        $chartLabels[] = date('M Y', strtotime("{$m}-01"));
        $chartData[]   = (int) dbStat($db,
            "SELECT COUNT(*) FROM businesses WHERE DATE_FORMAT(created_at,'%Y-%m')=?", [$m]);
    }

    $pageTitle = 'System Dashboard';
    include __DIR__ . '/../app/includes/header.php';
    include __DIR__ . '/../app/includes/sidebar.php';
    ?>

    <!-- KPI Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <?php
        $kpis = [
            ['label'=>'Total Businesses',   'value'=>number_format($totalBusinesses),  'icon'=>'fa-building',          'color'=>'bg-blue-500',    'sub'=>'Registered on platform'],
            ['label'=>'Active Businesses',  'value'=>number_format($activeBusinesses), 'icon'=>'fa-circle-check',      'color'=>'bg-green-500',   'sub'=>'Currently active'],
            ['label'=>'New This Month',     'value'=>number_format($newBizMonth),      'icon'=>'fa-building-circle-arrow-right', 'color'=>'bg-indigo-500', 'sub'=>date('F Y')],
            ['label'=>'Total Users',        'value'=>number_format($totalUsers),       'icon'=>'fa-users',             'color'=>'bg-purple-500',  'sub'=>'Across all businesses'],
            ['label'=>'Active Users',       'value'=>number_format($activeUsers),      'icon'=>'fa-user-check',        'color'=>'bg-emerald-500', 'sub'=>'Enabled accounts'],
            ['label'=>'New Users This Month','value'=>number_format($newUsersMonth),   'icon'=>'fa-user-plus',         'color'=>'bg-cyan-500',    'sub'=>date('F Y')],
            ['label'=>'Logins Today',       'value'=>number_format($loginsToday),      'icon'=>'fa-right-to-bracket',  'color'=>'bg-amber-500',   'sub'=>'Active sessions today'],
            ['label'=>'Inactive Businesses','value'=>number_format($totalBusinesses - $activeBusinesses), 'icon'=>'fa-building-lock', 'color'=>'bg-rose-500', 'sub'=>'Disabled accounts'],
        ];
        foreach ($kpis as $k): ?>
        <div class="bg-white rounded-xl shadow-sm p-5 flex items-center gap-4 border border-gray-100 hover:shadow-md transition-shadow">
            <div class="<?= $k['color'] ?> w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0">
                <i class="fa-solid <?= $k['icon'] ?> text-white text-lg"></i>
            </div>
            <div class="min-w-0">
                <p class="text-xs text-gray-500 font-medium truncate"><?= $k['label'] ?></p>
                <p class="text-xl font-bold text-gray-800"><?= $k['value'] ?></p>
                <p class="text-xs text-gray-400"><?= $k['sub'] ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Chart + Recent Users -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">

        <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-semibold text-gray-800">Business Registrations — Last 6 Months</h3>
            </div>
            <canvas id="bizChart" height="110"></canvas>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-semibold text-gray-800">Recent Registrations</h3>
                <a href="<?= url('admin/users') ?>" class="text-blue-600 text-sm hover:underline">View All</a>
            </div>
            <?php if (empty($recentUsers)): ?>
            <p class="text-gray-400 text-sm text-center py-6">No users yet.</p>
            <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($recentUsers as $u): ?>
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-700 font-bold text-sm flex items-center justify-center flex-shrink-0">
                        <?= strtoupper(substr($u['name'], 0, 1)) ?>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-800 truncate"><?= h($u['name']) ?></p>
                        <p class="text-xs text-gray-400 truncate"><?= h($u['biz_name'] ?? '—') ?> · <?= h($u['role_name']) ?></p>
                    </div>
                    <span class="text-xs text-gray-400 flex-shrink-0"><?= formatDate($u['created_at']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Businesses -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mb-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-gray-800">Recent Businesses</h3>
            <a href="<?= url('admin/businesses') ?>" class="text-blue-600 text-sm hover:underline">View All</a>
        </div>
        <div class="table-responsive">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase border-b">
                        <th class="pb-2 font-medium">Business</th>
                        <th class="pb-2 font-medium">Email</th>
                        <th class="pb-2 font-medium text-center">Users</th>
                        <th class="pb-2 font-medium text-center">Status</th>
                        <th class="pb-2 font-medium">Joined</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php if (empty($recentBiz)): ?>
                    <tr><td colspan="5" class="py-6 text-center text-gray-400">No businesses registered yet.</td></tr>
                    <?php else: ?>
                    <?php foreach ($recentBiz as $b): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="py-2.5 font-medium text-gray-800"><?= h($b['name']) ?></td>
                        <td class="py-2.5 text-gray-500"><?= h($b['email'] ?? '—') ?></td>
                        <td class="py-2.5 text-center text-gray-700"><?= $b['user_count'] ?></td>
                        <td class="py-2.5 text-center">
                            <?php if ($b['is_active']): ?>
                            <span class="px-2 py-0.5 text-xs rounded-full bg-green-100 text-green-700 font-medium">Active</span>
                            <?php else: ?>
                            <span class="px-2 py-0.5 text-xs rounded-full bg-red-100 text-red-600 font-medium">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-2.5 text-gray-400 text-xs"><?= formatDate($b['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mb-6">
        <h3 class="font-semibold text-gray-800 mb-4">Quick Actions</h3>
        <div class="flex flex-wrap gap-3">
            <a href="<?= url('admin/businesses') . '?action=add' ?>"
               class="flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-700 transition-colors">
                <i class="fa-solid fa-building-circle-check"></i> Add Business
            </a>
            <a href="<?= url('admin/users') . '?action=add' ?>"
               class="flex items-center gap-2 bg-green-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-green-700 transition-colors">
                <i class="fa-solid fa-user-plus"></i> Add User
            </a>
            <a href="<?= url('admin/businesses') ?>"
               class="flex items-center gap-2 bg-gray-700 text-white px-4 py-2 rounded-lg text-sm hover:bg-gray-800 transition-colors">
                <i class="fa-solid fa-list"></i> All Businesses
            </a>
            <a href="<?= url('admin/users') ?>"
               class="flex items-center gap-2 bg-purple-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-purple-700 transition-colors">
                <i class="fa-solid fa-users-gear"></i> Manage Users
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>
    new Chart(document.getElementById('bizChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($chartLabels) ?>,
            datasets: [{
                label: 'New Businesses',
                data: <?= json_encode($chartData) ?>,
                backgroundColor: 'rgba(99,102,241,0.15)',
                borderColor:     'rgba(99,102,241,1)',
                borderWidth: 2,
                borderRadius: 6,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } }
            }
        }
    });
    </script>

    <?php include __DIR__ . '/../app/includes/footer.php'; ?>
    <?php
    exit;
}

// ═══════════════════════════════════════════════════════════
// BUSINESS USER DASHBOARD
// ═══════════════════════════════════════════════════════════
$bizId = currentBusinessId();

// Non-admin user without a business assignment — block data access entirely
if (!$bizId) {
    $pageTitle = 'Welcome to ' . APP_NAME;
    include __DIR__ . '/../app/includes/header.php';
    include __DIR__ . '/../app/includes/sidebar.php';
    ?>
    <div class="flex flex-col items-center justify-center py-24 text-center max-w-md mx-auto">
        <div class="w-20 h-20 rounded-2xl bg-blue-50 flex items-center justify-center mb-6">
            <i class="fa-solid fa-building-circle-xmark text-4xl" style="color:#1B3263;"></i>
        </div>
        <h2 class="text-2xl font-bold text-gray-800 mb-3">No Business Assigned</h2>
        <p class="text-gray-500 leading-relaxed mb-6">
            Your account has not been linked to a business yet.<br>
            Contact your system administrator to get started.
        </p>
        <a href="<?= url('support') ?>"
           class="inline-flex items-center gap-2 bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition-colors">
            <i class="fa-solid fa-headset"></i> Contact Support
        </a>
    </div>
    <?php
    include __DIR__ . '/../app/includes/footer.php';
    exit;
}

function bizFilter(string $column, ?int $bizId): array {
    if ($bizId) {
        return ['cond' => "$column = ?", 'params' => [$bizId]];
    }
    // Fallback safety: never expose cross-business data
    return ['cond' => '1=0', 'params' => []];
}

$bf = bizFilter('business_id', $bizId);

$salesToday    = (float) dbStat($db,
    "SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE {$bf['cond']} AND sale_date = ?",
    [...$bf['params'], $today]);

$salesMonth    = (float) dbStat($db,
    "SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE {$bf['cond']} AND sale_date BETWEEN ? AND ?",
    [...$bf['params'], $monthStart, $monthEnd]);

$revenueMonth  = (float) dbStat($db,
    "SELECT COALESCE(SUM(amount_paid),0) FROM sales WHERE {$bf['cond']} AND sale_date BETWEEN ? AND ?",
    [...$bf['params'], $monthStart, $monthEnd]);

$expensesMonth = (float) dbStat($db,
    "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE {$bf['cond']} AND expense_date BETWEEN ? AND ?",
    [...$bf['params'], $monthStart, $monthEnd]);

$totalDebts    = (float) dbStat($db,
    "SELECT COALESCE(SUM(balance),0) FROM debts WHERE {$bf['cond']} AND status NOT IN ('paid','written_off')",
    $bf['params']);

$overdueDebts  = (float) dbStat($db,
    "SELECT COALESCE(SUM(balance),0) FROM debts WHERE {$bf['cond']} AND due_date < ? AND status NOT IN ('paid','written_off')",
    [...$bf['params'], $today]);

$totalCustomers = (int) dbStat($db,
    "SELECT COUNT(*) FROM customers WHERE {$bf['cond']} AND is_active = 1",
    $bf['params']);

$lowStockCount  = (int) dbStat($db,
    "SELECT COUNT(*) FROM products WHERE {$bf['cond']} AND is_active = 1 AND stock_quantity <= reorder_level",
    $bf['params']);

$netProfit = $salesMonth - $expensesMonth;

$sbf = bizFilter('s.business_id', $bizId);
try {
    $recentSalesStmt = $db->prepare(
        "SELECT s.id, s.invoice_number, s.total_amount, s.payment_status, s.sale_date,
                c.name AS customer_name
         FROM sales s
         LEFT JOIN customers c ON c.id = s.customer_id
         WHERE {$sbf['cond']}
         ORDER BY s.created_at DESC
         LIMIT 8");
    $recentSalesStmt->execute($sbf['params']);
    $recentSales = $recentSalesStmt->fetchAll();
} catch (\Exception $e) { $recentSales = []; }

try {
    $lowStockStmt = $db->prepare(
        "SELECT name, stock_quantity, reorder_level, unit
         FROM products
         WHERE {$bf['cond']} AND is_active = 1 AND stock_quantity <= reorder_level
         ORDER BY stock_quantity ASC
         LIMIT 6");
    $lowStockStmt->execute($bf['params']);
    $lowStockItems = $lowStockStmt->fetchAll();
} catch (\Exception $e) { $lowStockItems = []; }

$pbf = bizFilter('p.business_id', $bizId);
try {
    $recentPayStmt = $db->prepare(
        "SELECT p.amount, p.payment_method, p.payment_date,
                c.name AS customer_name
         FROM payments p
         LEFT JOIN customers c ON c.id = p.customer_id
         WHERE {$pbf['cond']}
         ORDER BY p.created_at DESC
         LIMIT 5");
    $recentPayStmt->execute($pbf['params']);
    $recentPayments = $recentPayStmt->fetchAll();
} catch (\Exception $e) { $recentPayments = []; }

try {
    $topCustStmt = $db->prepare(
        "SELECT c.name, COUNT(s.id) AS sales_count, SUM(s.total_amount) AS total
         FROM sales s
         JOIN customers c ON c.id = s.customer_id
         WHERE {$sbf['cond']} AND s.sale_date BETWEEN ? AND ?
         GROUP BY c.id, c.name
         ORDER BY total DESC
         LIMIT 5");
    $topCustStmt->execute([...$sbf['params'], $monthStart, $monthEnd]);
    $topCustomers = $topCustStmt->fetchAll();
} catch (\Exception $e) { $topCustomers = []; }

$chartLabels = [];
$chartData   = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $chartLabels[] = date('D d', strtotime($day));
    $chartData[]   = (float) dbStat($db,
        "SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE {$bf['cond']} AND sale_date = ?",
        [...$bf['params'], $day]);
}

$pageTitle = 'Dashboard';
include __DIR__ . '/../app/includes/header.php';
include __DIR__ . '/../app/includes/sidebar.php';
?>

<!-- KPI Cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <?php
    $kpis = [
        ['label' => 'Sales Today',          'value' => formatMoney($salesToday),           'icon' => 'fa-cart-shopping',        'color' => 'bg-blue-500',    'sub' => 'Today\'s transactions'],
        ['label' => 'Revenue This Month',   'value' => formatMoney($revenueMonth),         'icon' => 'fa-sack-dollar',           'color' => 'bg-green-500',   'sub' => date('F Y')],
        ['label' => 'Expenses This Month',  'value' => formatMoney($expensesMonth),         'icon' => 'fa-money-bill-wave',       'color' => 'bg-red-500',     'sub' => date('F Y')],
        ['label' => 'Net Profit',           'value' => formatMoney($netProfit),             'icon' => 'fa-chart-line',            'color' => $netProfit >= 0 ? 'bg-emerald-500' : 'bg-red-500', 'sub' => date('F Y')],
        ['label' => 'Outstanding Debts',    'value' => formatMoney($totalDebts),            'icon' => 'fa-file-invoice-dollar',   'color' => 'bg-orange-500',  'sub' => 'Total unpaid'],
        ['label' => 'Overdue Debts',        'value' => formatMoney($overdueDebts),          'icon' => 'fa-triangle-exclamation',  'color' => 'bg-rose-500',    'sub' => 'Past due date'],
        ['label' => 'Active Customers',     'value' => number_format($totalCustomers),      'icon' => 'fa-users',                 'color' => 'bg-purple-500',  'sub' => 'Registered'],
        ['label' => 'Low Stock Items',      'value' => number_format($lowStockCount),       'icon' => 'fa-boxes-stacked',         'color' => 'bg-amber-500',   'sub' => 'Need reorder'],
    ];
    foreach ($kpis as $k):
    ?>
    <div class="bg-white rounded-xl shadow-sm p-5 flex items-center gap-4 border border-gray-100 hover:shadow-md transition-shadow">
        <div class="<?= $k['color'] ?> w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0">
            <i class="fa-solid <?= $k['icon'] ?> text-white text-lg"></i>
        </div>
        <div class="min-w-0">
            <p class="text-xs text-gray-500 font-medium truncate"><?= $k['label'] ?></p>
            <p class="text-xl font-bold text-gray-800"><?= $k['value'] ?></p>
            <p class="text-xs text-gray-400"><?= $k['sub'] ?></p>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Sales Chart + Low Stock -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">

    <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-gray-800">Sales — Last 7 Days</h3>
            <a href="<?= url('reports/sales') ?>" class="text-blue-600 text-sm hover:underline">Full Report</a>
        </div>
        <canvas id="salesChart" height="110"></canvas>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-gray-800 flex items-center gap-2">
                <i class="fa-solid fa-triangle-exclamation text-amber-500"></i> Low Stock
            </h3>
            <a href="<?= url('products') ?>" class="text-blue-600 text-sm hover:underline">View All</a>
        </div>
        <?php if (empty($lowStockItems)): ?>
        <div class="text-center py-6">
            <i class="fa-solid fa-circle-check text-green-400 text-3xl mb-2"></i>
            <p class="text-gray-400 text-sm">All items well stocked!</p>
        </div>
        <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($lowStockItems as $item): ?>
            <div class="flex items-center justify-between">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-800 truncate"><?= h($item['name']) ?></p>
                    <p class="text-xs text-gray-400">Min: <?= number_format($item['reorder_level'], 0) ?> <?= h($item['unit']) ?></p>
                </div>
                <span class="ml-2 text-sm font-bold flex-shrink-0 <?= $item['stock_quantity'] <= 0 ? 'text-red-600' : 'text-orange-500' ?>">
                    <?= number_format($item['stock_quantity'], 0) ?> left
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Sales + Top Customers -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">

    <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-gray-800">Recent Sales</h3>
            <a href="<?= url('sales') ?>" class="text-blue-600 text-sm hover:underline">View All</a>
        </div>
        <div class="table-responsive">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase border-b">
                        <th class="pb-2 font-medium">Invoice</th>
                        <th class="pb-2 font-medium">Customer</th>
                        <th class="pb-2 font-medium text-right">Amount</th>
                        <th class="pb-2 font-medium">Status</th>
                        <th class="pb-2 font-medium">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php if (empty($recentSales)): ?>
                    <tr><td colspan="5" class="py-6 text-center text-gray-400">No sales recorded yet.</td></tr>
                    <?php else: ?>
                    <?php foreach ($recentSales as $sale): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="py-2.5">
                            <a href="<?= url('sales/view') . '?id=' . $sale['id'] ?>"
                               class="text-blue-600 hover:underline font-mono text-xs">
                                <?= h($sale['invoice_number']) ?>
                            </a>
                        </td>
                        <td class="py-2.5 text-gray-700"><?= h($sale['customer_name'] ?? 'Walk-in') ?></td>
                        <td class="py-2.5 font-semibold text-gray-800 text-right"><?= formatMoney($sale['total_amount']) ?></td>
                        <td class="py-2.5"><?= statusBadge($sale['payment_status']) ?></td>
                        <td class="py-2.5 text-gray-400 text-xs"><?= formatDate($sale['sale_date']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-gray-800">Top Customers</h3>
            <span class="text-xs text-gray-400"><?= date('F Y') ?></span>
        </div>
        <?php if (empty($topCustomers)): ?>
        <p class="text-gray-400 text-sm text-center py-6">No sales this month yet.</p>
        <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($topCustomers as $i => $cust): ?>
            <div class="flex items-center gap-3">
                <span class="w-6 h-6 rounded-full bg-blue-100 text-blue-700 font-bold text-xs flex items-center justify-center flex-shrink-0">
                    <?= $i + 1 ?>
                </span>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-800 truncate"><?= h($cust['name']) ?></p>
                    <p class="text-xs text-gray-400"><?= number_format($cust['sales_count']) ?> sale<?= $cust['sales_count'] != 1 ? 's' : '' ?></p>
                </div>
                <span class="text-sm font-bold text-gray-700 flex-shrink-0"><?= formatMoney($cust['total']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Quick Actions -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mb-6">
    <h3 class="font-semibold text-gray-800 mb-4">Quick Actions</h3>
    <div class="flex flex-wrap gap-3">
        <?php if (hasPermission('sales')): ?>
        <a href="<?= url('sales/add') ?>"
           class="flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-700 transition-colors">
            <i class="fa-solid fa-plus"></i> New Sale
        </a>
        <a href="<?= url('customers/add') ?>"
           class="flex items-center gap-2 bg-green-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-green-700 transition-colors">
            <i class="fa-solid fa-user-plus"></i> Add Customer
        </a>
        <?php endif; ?>
        <?php if (hasPermission('inventory')): ?>
        <a href="<?= url('products/add') ?>"
           class="flex items-center gap-2 bg-purple-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-purple-700 transition-colors">
            <i class="fa-solid fa-box-open"></i> Add Product
        </a>
        <?php endif; ?>
        <?php if (hasPermission('expenses')): ?>
        <a href="<?= url('expenses/add') ?>"
           class="flex items-center gap-2 bg-red-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-red-700 transition-colors">
            <i class="fa-solid fa-receipt"></i> Record Expense
        </a>
        <?php endif; ?>
        <?php if (hasPermission('debts')): ?>
        <a href="<?= url('debts') ?>"
           class="flex items-center gap-2 bg-orange-500 text-white px-4 py-2 rounded-lg text-sm hover:bg-orange-600 transition-colors">
            <i class="fa-solid fa-file-invoice-dollar"></i> View Debts
        </a>
        <?php endif; ?>
        <?php if (hasPermission('reports')): ?>
        <a href="<?= url('reports/sales') ?>"
           class="flex items-center gap-2 bg-gray-700 text-white px-4 py-2 rounded-lg text-sm hover:bg-gray-800 transition-colors">
            <i class="fa-solid fa-chart-bar"></i> Sales Report
        </a>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('salesChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets: [{
            label: 'Sales (<?= CURRENCY_SYMBOL ?>)',
            data: <?= json_encode($chartData) ?>,
            backgroundColor: 'rgba(59,130,246,0.15)',
            borderColor:     'rgba(59,130,246,1)',
            borderWidth: 2,
            borderRadius: 6,
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { callback: v => '<?= CURRENCY_SYMBOL ?> ' + v.toLocaleString() }
            }
        }
    }
});
</script>

<?php include __DIR__ . '/../app/includes/footer.php'; ?>
