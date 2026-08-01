<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requireLogin();
if (!hasPermission('reports') && !hasPermission('sales')) {
    http_response_code(403);
    include APP_PATH . '/includes/403.php';
    exit;
}

$db    = getDB();
$bizId = currentBusinessId();
$today = date('Y-m-d');

// ════════════════════════════════════════════════════════════════
// ADMIN: activity log (no financial/sales data from businesses)
// ════════════════════════════════════════════════════════════════
if (isAdmin()) {
    $allowedModules = ['users', 'customers', 'businesses'];
    $ph  = implode(',', array_fill(0, count($allowedModules), '?'));
    $actQ = $db->prepare("SELECT al.*, u.name AS actor_name, r.name AS actor_role,
                b.name AS biz_name
            FROM audit_logs al
            LEFT JOIN users u  ON u.id  = al.user_id
            LEFT JOIN roles r  ON r.id  = u.role_id
            LEFT JOIN businesses b ON b.id = al.business_id
            WHERE al.module IN ($ph)
            ORDER BY al.created_at DESC LIMIT 100");
    $actQ->execute($allowedModules);
    $activities = $actQ->fetchAll();

    // Module label map
    $modLabels = ['users'=>'User', 'customers'=>'Customer', 'businesses'=>'Business'];
    // Action style map
    $actStyles = [
        'create' => ['bg'=>'bg-green-500',  'icon'=>'fa-plus',       'text'=>'Created'],
        'update' => ['bg'=>'bg-blue-500',   'icon'=>'fa-pen',        'text'=>'Updated'],
        'delete' => ['bg'=>'bg-red-500',    'icon'=>'fa-trash',      'text'=>'Deleted'],
        'login'  => ['bg'=>'bg-purple-500', 'icon'=>'fa-right-to-bracket', 'text'=>'Logged in'],
    ];

    $pageTitle = 'Activity Log';
    include __DIR__ . '/../../app/includes/header.php';
    include __DIR__ . '/../../app/includes/sidebar.php';
    ?>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-xl font-bold text-gray-800">Business Activity Log</h2>
            <p class="text-sm text-gray-500">Recent user and customer management actions across all businesses</p>
        </div>
        <button onclick="location.reload()" class="border border-gray-300 text-gray-600 px-3 py-2 rounded-lg text-sm hover:bg-gray-50">
            <i class="fa-solid fa-rotate-right mr-1"></i> Refresh
        </button>
    </div>

    <?php if (empty($activities)): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-16 text-center">
        <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fa-solid fa-clock-rotate-left text-gray-400 text-3xl"></i>
        </div>
        <h3 class="text-xl font-bold text-gray-800 mb-2">No activity yet</h3>
        <p class="text-gray-500 text-sm">Activity from business users will appear here.</p>
    </div>
    <?php else: ?>

    <!-- Summary chips -->
    <?php
    $modCounts = array_count_values(array_column($activities,'module'));
    $actCounts = array_count_values(array_column($activities,'action'));
    ?>
    <div class="flex flex-wrap gap-2 mb-5">
        <?php foreach ($modCounts as $mod => $cnt): ?>
        <span class="bg-white border border-gray-200 text-gray-700 text-xs font-medium px-3 py-1 rounded-full">
            <?= h($modLabels[$mod] ?? ucfirst($mod)) ?>s: <strong><?= $cnt ?></strong>
        </span>
        <?php endforeach; ?>
        <span class="bg-green-50 border border-green-200 text-green-700 text-xs font-medium px-3 py-1 rounded-full">
            Created: <strong><?= $actCounts['create'] ?? 0 ?></strong>
        </span>
        <span class="bg-blue-50 border border-blue-200 text-blue-700 text-xs font-medium px-3 py-1 rounded-full">
            Updated: <strong><?= $actCounts['update'] ?? 0 ?></strong>
        </span>
        <?php if (!empty($actCounts['delete'])): ?>
        <span class="bg-red-50 border border-red-200 text-red-700 text-xs font-medium px-3 py-1 rounded-full">
            Deleted: <strong><?= $actCounts['delete'] ?></strong>
        </span>
        <?php endif; ?>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100">
        <div class="divide-y">
        <?php foreach ($activities as $act):
            $style    = $actStyles[$act['action']] ?? ['bg'=>'bg-gray-400','icon'=>'fa-circle','text'=>ucfirst($act['action'])];
            $modLabel = $modLabels[$act['module']] ?? ucfirst($act['module']);
            $bizTag   = $act['biz_name'] ? ' at <strong>' . h($act['biz_name']) . '</strong>' : '';
            $roleTag  = $act['actor_role'] ? ' <span class="text-xs text-gray-400">(' . h($act['actor_role']) . ')</span>' : '';
        ?>
        <div class="flex items-start gap-4 px-5 py-4 hover:bg-gray-50">
            <div class="w-9 h-9 rounded-full <?= $style['bg'] ?> flex items-center justify-center flex-shrink-0 mt-0.5">
                <i class="fa-solid <?= $style['icon'] ?> text-white text-sm"></i>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm text-gray-800">
                    <strong><?= h($act['actor_name'] ?? 'System') ?></strong><?= $roleTag ?>
                    <?= $style['text'] ?> a <strong><?= $modLabel ?></strong><?= $bizTag ?>
                </p>
                <p class="text-xs text-gray-400 mt-0.5"><?= formatDateTime($act['created_at']) ?></p>
            </div>
            <span class="text-xs bg-gray-100 text-gray-500 px-2 py-0.5 rounded flex-shrink-0"><?= h($act['module']) ?></span>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php
    include __DIR__ . '/../../app/includes/footer.php';
    exit;
}

// ════════════════════════════════════════════════════════════════
// SERVICE BUSINESS: pending/overdue orders, unpaid, trends
// ════════════════════════════════════════════════════════════════
if (($_SESSION['business_type'] ?? 'products') === 'services') {
    $alerts = [];

    // Ensure service_orders table exists before querying
    try {
        // Pending orders
        $pendQ = $db->prepare("SELECT COUNT(*) FROM service_orders WHERE business_id=? AND status='pending'");
        $pendQ->execute([$bizId]);
        $pendCount = (int)$pendQ->fetchColumn();
        if ($pendCount > 0) {
            $alerts[] = ['type'=>'warning','icon'=>'fa-clock','title'=>'Pending Orders','message'=>"You have <strong>{$pendCount} pending service order(s)</strong> waiting to be started.",'action'=>['url'=>'../../modules/service_orders/index.php?status=pending','label'=>'View Orders']];
        }

        // Unpaid completed orders
        $unpaidQ = $db->prepare("SELECT COUNT(*), COALESCE(SUM(balance_due),0) FROM service_orders WHERE business_id=? AND payment_status='unpaid' AND status='completed'");
        $unpaidQ->execute([$bizId]);
        $unpaidRow = $unpaidQ->fetch(PDO::FETCH_NUM);
        if ($unpaidRow[0] > 0) {
            $alerts[] = ['type'=>'danger','icon'=>'fa-money-bill','title'=>'Unpaid Completed Orders','message'=>"<strong>{$unpaidRow[0]} completed order(s)</strong> still have outstanding balances totalling <strong>" . formatMoney($unpaidRow[1]) . "</strong>.",'action'=>['url'=>'../../modules/service_orders/index.php?status=completed&pay_status=unpaid','label'=>'View']];
        }

        // Partial payments
        $partQ = $db->prepare("SELECT COUNT(*), COALESCE(SUM(balance_due),0) FROM service_orders WHERE business_id=? AND payment_status='partial'");
        $partQ->execute([$bizId]);
        $partRow = $partQ->fetch(PDO::FETCH_NUM);
        if ($partRow[0] > 0) {
            $alerts[] = ['type'=>'warning','icon'=>'fa-hourglass-half','title'=>'Partially Paid Orders','message'=>"<strong>{$partRow[0]} order(s)</strong> have partial payments. Total outstanding: <strong>" . formatMoney($partRow[1]) . "</strong>.",'action'=>['url'=>'../../modules/service_orders/index.php?pay_status=partial','label'=>'View']];
        }

        // Top service this month
        $topSvcQ = $db->prepare("SELECT soi.service_name, COUNT(DISTINCT so.id) AS orders, SUM(soi.total) AS revenue FROM service_order_items soi JOIN service_orders so ON so.id=soi.order_id WHERE so.business_id=? AND so.status='completed' AND DATE(so.created_at) >= DATE_FORMAT(CURDATE(),'%Y-%m-01') GROUP BY soi.service_name ORDER BY revenue DESC LIMIT 1");
        $topSvcQ->execute([$bizId]);
        $topSvc = $topSvcQ->fetch();
        if ($topSvc) {
            $alerts[] = ['type'=>'success','icon'=>'fa-star','title'=>'Top Service This Month','message'=>"<strong>{$topSvc['service_name']}</strong> is your best performing service this month with <strong>" . formatMoney($topSvc['revenue']) . "</strong> revenue across {$topSvc['orders']} order(s).",'action'=>null];
        }

        // Revenue trend
        $prevRevQ = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM service_orders WHERE business_id=? AND status='completed' AND DATE(created_at) BETWEEN DATE_FORMAT(DATE_SUB(CURDATE(),INTERVAL 1 MONTH),'%Y-%m-01') AND DATE_FORMAT(DATE_SUB(CURDATE(),INTERVAL 1 MONTH),'%Y-%m-31')");
        $prevRevQ->execute([$bizId]);
        $prevRev = (float)$prevRevQ->fetchColumn();
        $thisRevQ = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM service_orders WHERE business_id=? AND status='completed' AND DATE(created_at) >= DATE_FORMAT(CURDATE(),'%Y-%m-01')");
        $thisRevQ->execute([$bizId]);
        $thisRev = (float)$thisRevQ->fetchColumn();
        if ($prevRev > 0) {
            $chg = (($thisRev - $prevRev) / $prevRev) * 100;
            $alerts[] = ['type'=>$chg>=0?'success':'warning','icon'=>$chg>=0?'fa-trending-up':'fa-trending-down','title'=>'Revenue Trend','message'=>"Revenue this month is <strong>" . ($chg>=0?'+':'') . round($chg,1) . "%</strong> vs last month. This month: " . formatMoney($thisRev) . " | Last month: " . formatMoney($prevRev) . ".",'action'=>['url'=>'../../modules/reports/service_revenue.php','label'=>'View Report']];
        }

        // Cash flow (still uses payments + expenses tables)
        $cashInQ = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE business_id=? AND service_order_id IS NOT NULL AND payment_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01')");
        $cashInQ->execute([$bizId]);
        $cashIn = (float)$cashInQ->fetchColumn();
        $cashOutQ = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE business_id=? AND expense_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01')");
        $cashOutQ->execute([$bizId]);
        $cashOut = (float)$cashOutQ->fetchColumn();
        if ($cashIn > 0 || $cashOut > 0) {
            $cashFlow = $cashIn - $cashOut;
            $alerts[] = ['type'=>$cashFlow>=0?'success':'danger','icon'=>'fa-money-bill-trend-up','title'=>'Cash Flow This Month','message'=>"Net cash flow: <strong>" . formatMoney($cashFlow) . "</strong>. Cash in: " . formatMoney($cashIn) . " | Expenses: " . formatMoney($cashOut) . ".",'action'=>null];
        }
    } catch (\Exception $e) {
        $alerts[] = ['type'=>'info','icon'=>'fa-info-circle','title'=>'Getting Started','message'=>'Create your first service order to start seeing smart alerts here.','action'=>['url'=>'../../modules/service_orders/add.php','label'=>'New Order']];
    }

    usort($alerts, function($a,$b){ $o=['danger'=>0,'warning'=>1,'info'=>2,'success'=>3]; return ($o[$a['type']]??4)-($o[$b['type']]??4); });

    $pageTitle = 'Smart Alerts';
    include __DIR__ . '/../../app/includes/header.php';
    include __DIR__ . '/../../app/includes/sidebar.php';
    ?>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-xl font-bold text-gray-800">Smart Business Alerts</h2>
            <p class="text-sm text-gray-500"><?= count($alerts) ?> alerts generated for <?= date('d F Y') ?></p>
        </div>
        <button onclick="location.reload()" class="border border-gray-300 text-gray-600 px-3 py-2 rounded-lg text-sm hover:bg-gray-50">
            <i class="fa-solid fa-rotate-right mr-1"></i> Refresh
        </button>
    </div>
    <?php if (empty($alerts)): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-16 text-center">
        <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fa-solid fa-circle-check text-green-500 text-4xl"></i>
        </div>
        <h3 class="text-xl font-bold text-gray-800 mb-2">Everything looks great!</h3>
        <p class="text-gray-500">No alerts at this time. Your service business is running smoothly.</p>
    </div>
    <?php else: ?>
    <div class="space-y-3">
        <?php foreach ($alerts as $alert): ?>
        <?php $cls = match($alert['type']) { 'danger'=>'border-red-200 bg-red-50','warning'=>'border-yellow-200 bg-yellow-50','success'=>'border-green-200 bg-green-50', default=>'border-blue-200 bg-blue-50' };
              $icCls = match($alert['type']) { 'danger'=>'bg-red-500','warning'=>'bg-yellow-500','success'=>'bg-green-500', default=>'bg-blue-500' };
              $txtCls = match($alert['type']) { 'danger'=>'text-red-800','warning'=>'text-yellow-800','success'=>'text-green-800', default=>'text-blue-800' }; ?>
        <div class="border <?= $cls ?> rounded-xl p-4 flex items-start gap-4">
            <div class="w-9 h-9 rounded-full <?= $icCls ?> flex items-center justify-center flex-shrink-0 mt-0.5">
                <i class="fa-solid <?= $alert['icon'] ?> text-white text-sm"></i>
            </div>
            <div class="flex-1">
                <p class="font-semibold <?= $txtCls ?> text-sm"><?= $alert['title'] ?></p>
                <p class="text-sm mt-0.5 <?= $txtCls ?> opacity-90"><?= $alert['message'] ?></p>
                <?php if ($alert['action']): ?>
                <a href="<?= $alert['action']['url'] ?>" class="inline-block mt-2 text-xs font-semibold underline <?= $txtCls ?>">
                    <?= h($alert['action']['label']) ?> →
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php
    include __DIR__ . '/../../app/includes/footer.php';
    exit;
}

$alerts = [];

// ================================================================
// 1. LOW STOCK & STOCK FORECAST
// ================================================================
$lowQ = $db->prepare("SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.business_id=? AND p.is_active=1 AND p.stock_quantity <= p.reorder_level ORDER BY p.stock_quantity ASC");
$lowQ->execute([$bizId]);
$lowItems = $lowQ->fetchAll();

foreach ($lowItems as $item) {
    // Avg daily sales over last 30 days
    $avgQ = $db->prepare("SELECT COALESCE(SUM(si.quantity),0)/30 FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE si.product_id=? AND s.sale_date >= DATE_SUB(CURDATE(),INTERVAL 30 DAY)");
    $avgQ->execute([$item['id']]);
    $avgDaily = (float)$avgQ->fetchColumn();

    if ($item['stock_quantity'] <= 0) {
        $alerts[] = ['type'=>'danger','icon'=>'fa-box-open','title'=>'Out of Stock','message'=>"<strong>{$item['name']}</strong> is completely out of stock. Reorder immediately.",'action'=>['url'=>'../../modules/products/adjust.php?id='.$item['id'],'label'=>'Restock']];
    } elseif ($avgDaily > 0) {
        $daysLeft = floor($item['stock_quantity'] / $avgDaily);
        $alerts[] = ['type'=>'warning','icon'=>'fa-triangle-exclamation','title'=>'Low Stock – Forecast','message'=>"<strong>{$item['name']}</strong> may run out in approximately <strong>{$daysLeft} day(s)</strong>. Current stock: " . number_format($item['stock_quantity'],0) . " {$item['unit']}. Reorder level: {$item['reorder_level']}.",'action'=>['url'=>'../../modules/products/adjust.php?id='.$item['id'],'label'=>'Restock Now']];
    } else {
        $alerts[] = ['type'=>'warning','icon'=>'fa-exclamation-circle','title'=>'Low Stock','message'=>"<strong>{$item['name']}</strong> stock is low (currently " . number_format($item['stock_quantity'],0) . " {$item['unit']}, reorder level: {$item['reorder_level']}).",'action'=>['url'=>'../../modules/products/adjust.php?id='.$item['id'],'label'=>'Restock']];
    }
}

// ================================================================
// 2. OVERSTOCK WARNING
// ================================================================
$overstockQ = $db->prepare("SELECT p.* FROM products p WHERE p.business_id=? AND p.is_active=1 AND p.max_stock IS NOT NULL AND p.stock_quantity > p.max_stock");
$overstockQ->execute([$bizId]);
foreach ($overstockQ->fetchAll() as $item) {
    $alerts[] = ['type'=>'info','icon'=>'fa-warehouse','title'=>'Overstock Warning','message'=>"<strong>{$item['name']}</strong> has {$item['stock_quantity']} {$item['unit']} in stock, which exceeds max stock level of {$item['max_stock']}. Consider reducing orders or running a promotion.",'action'=>null];
}

// ================================================================
// 3. OVERDUE DEBTS
// ================================================================
$overdueQ = $db->prepare("SELECT d.*, c.name AS customer_name, c.phone AS customer_phone FROM debts d JOIN customers c ON c.id=d.customer_id WHERE d.business_id=? AND d.due_date < ? AND d.status NOT IN ('paid','written_off') ORDER BY d.balance DESC LIMIT 10");
$overdueQ->execute([$bizId, $today]);
$overdueDebts = $overdueQ->fetchAll();
foreach ($overdueDebts as $debt) {
    $daysOverdue = (int)((strtotime($today) - strtotime($debt['due_date'])) / 86400);
    $alerts[] = ['type'=>'danger','icon'=>'fa-file-invoice-dollar','title'=>'Overdue Debt','message'=>"<strong>{$debt['customer_name']}</strong> has an overdue debt of <strong>" . formatMoney($debt['balance']) . "</strong> ({$daysOverdue} day(s) overdue since " . formatDate($debt['due_date']) . "). Phone: {$debt['customer_phone']}.",'action'=>['url'=>'../../modules/debts/view.php?id='.$debt['id'],'label'=>'View Debt']];
}

// ================================================================
// 4. BEST SELLING PRODUCTS (this month)
// ================================================================
$bestQ = $db->prepare("SELECT p.name, SUM(si.quantity) AS qty_sold, SUM(si.total) AS revenue FROM sale_items si JOIN products p ON p.id=si.product_id JOIN sales s ON s.id=si.sale_id WHERE s.business_id=? AND s.sale_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01') GROUP BY p.id ORDER BY revenue DESC LIMIT 3");
$bestQ->execute([$bizId]);
$bestSellers = $bestQ->fetchAll();
if (!empty($bestSellers)) {
    $top = $bestSellers[0];
    $alerts[] = ['type'=>'success','icon'=>'fa-star','title'=>'Best Seller This Month','message'=>"<strong>{$top['name']}</strong> is your best selling product this month with revenue of <strong>" . formatMoney($top['revenue']) . "</strong> (" . number_format($top['qty_sold'],0) . " units sold).",'action'=>null];
}

// ================================================================
// 5. SLOW MOVING PRODUCTS (no sales in 30 days, but in stock)
// ================================================================
$slowQ = $db->prepare("SELECT p.name, p.stock_quantity, p.unit FROM products p WHERE p.business_id=? AND p.is_active=1 AND p.stock_quantity > 0 AND p.id NOT IN (SELECT DISTINCT si.product_id FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.business_id=? AND s.sale_date >= DATE_SUB(CURDATE(),INTERVAL 30 DAY)) LIMIT 5");
$slowQ->execute([$bizId, $bizId]);
$slowMovers = $slowQ->fetchAll();
foreach ($slowMovers as $item) {
    $alerts[] = ['type'=>'info','icon'=>'fa-snooze','title'=>'Slow Moving Product','message'=>"<strong>{$item['name']}</strong> has had no sales in the last 30 days but has {$item['stock_quantity']} {$item['unit']} in stock. Consider running a promotion or reviewing pricing.",'action'=>null];
}

// ================================================================
// 6. TOP CUSTOMERS (this month)
// ================================================================
$topCustQ = $db->prepare("SELECT c.name, COUNT(s.id) AS txns, SUM(s.total_amount) AS total FROM sales s JOIN customers c ON c.id=s.customer_id WHERE s.business_id=? AND s.sale_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01') GROUP BY c.id ORDER BY total DESC LIMIT 3");
$topCustQ->execute([$bizId]);
$topCustomers = $topCustQ->fetchAll();
if (!empty($topCustomers)) {
    $tc = $topCustomers[0];
    $alerts[] = ['type'=>'success','icon'=>'fa-trophy','title'=>'Top Customer This Month','message'=>"<strong>{$tc['name']}</strong> is your top customer this month with <strong>" . formatMoney($tc['total']) . "</strong> across {$tc['txns']} transaction(s).",'action'=>null];
}

// ================================================================
// 7. SALES TREND SUMMARY
// ================================================================
$prevMonthQ = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE business_id=? AND sale_date BETWEEN DATE_FORMAT(DATE_SUB(CURDATE(),INTERVAL 1 MONTH),'%Y-%m-01') AND DATE_FORMAT(DATE_SUB(CURDATE(),INTERVAL 1 MONTH),'%Y-%m-31')");
$prevMonthQ->execute([$bizId]);
$prevMonth = (float)$prevMonthQ->fetchColumn();
$thisMonthQ = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE business_id=? AND sale_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01')");
$thisMonthQ->execute([$bizId]);
$thisMonth = (float)$thisMonthQ->fetchColumn();

if ($prevMonth > 0) {
    $change = (($thisMonth - $prevMonth) / $prevMonth) * 100;
    $dir = $change >= 0 ? 'up' : 'down';
    $alerts[] = ['type'=>$change>=0?'success':'warning','icon'=>$change>=0?'fa-trending-up':'fa-trending-down','title'=>'Sales Trend','message'=>"Sales this month are <strong>" . ($change>=0?'+':'') . round($change,1) . "%</strong> compared to last month. This month: " . formatMoney($thisMonth) . " | Last month: " . formatMoney($prevMonth) . ".",'action'=>['url'=>'../../modules/reports/sales.php','label'=>'View Report']];
}

// ================================================================
// 8. HIGH PROFIT MARGIN ALERT
// ================================================================
$highMarginQ = $db->prepare("SELECT name, selling_price, cost_price, ROUND(((selling_price-cost_price)/selling_price)*100,1) AS margin_pct FROM products WHERE business_id=? AND is_active=1 AND selling_price>0 ORDER BY margin_pct DESC LIMIT 1");
$highMarginQ->execute([$bizId]);
$hm = $highMarginQ->fetch();
if ($hm) {
    $alerts[] = ['type'=>'info','icon'=>'fa-percent','title'=>'Highest Margin Product','message'=>"<strong>{$hm['name']}</strong> has your highest profit margin at <strong>{$hm['margin_pct']}%</strong>. Consider promoting this product.",'action'=>null];
}

// ================================================================
// 9. CASH FLOW INSIGHT
// ================================================================
$cashInQ = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE business_id=? AND payment_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01')");
$cashInQ->execute([$bizId]);
$cashIn = (float)$cashInQ->fetchColumn();
$cashOutQ = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE business_id=? AND expense_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01')");
$cashOutQ->execute([$bizId]);
$cashOut = (float)$cashOutQ->fetchColumn();
$cashFlow = $cashIn - $cashOut;
$alerts[] = ['type'=>$cashFlow>=0?'success':'danger','icon'=>'fa-money-bill-trend-up','title'=>'Cash Flow This Month','message'=>"Net cash flow this month: <strong>" . formatMoney($cashFlow) . "</strong>. Cash in: " . formatMoney($cashIn) . " | Cash out: " . formatMoney($cashOut) . ".",'action'=>['url'=>'../../modules/reports/financial.php','label'=>'View P&L']];

// Sort by severity
usort($alerts, function($a,$b){
    $order = ['danger'=>0,'warning'=>1,'info'=>2,'success'=>3];
    return ($order[$a['type']]??4) - ($order[$b['type']]??4);
});

$pageTitle = 'Smart Alerts';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-800">Smart Business Alerts</h2>
        <p class="text-sm text-gray-500"><?= count($alerts) ?> alerts generated for <?= date('d F Y') ?></p>
    </div>
    <button onclick="location.reload()" class="border border-gray-300 text-gray-600 px-3 py-2 rounded-lg text-sm hover:bg-gray-50">
        <i class="fa-solid fa-rotate-right mr-1"></i> Refresh
    </button>
</div>

<?php if (empty($alerts)): ?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-16 text-center">
    <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
        <i class="fa-solid fa-circle-check text-green-500 text-4xl"></i>
    </div>
    <h3 class="text-xl font-bold text-gray-800 mb-2">Everything looks great!</h3>
    <p class="text-gray-500">No alerts at this time. Your business is running smoothly.</p>
</div>
<?php else: ?>

<!-- Alert Stats -->
<?php
$counts = array_count_values(array_column($alerts,'type'));
?>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
    <div class="bg-red-50 rounded-xl p-3 text-center"><p class="text-2xl font-black text-red-700"><?= $counts['danger']??0 ?></p><p class="text-xs text-red-600">Critical</p></div>
    <div class="bg-amber-50 rounded-xl p-3 text-center"><p class="text-2xl font-black text-amber-700"><?= $counts['warning']??0 ?></p><p class="text-xs text-amber-600">Warnings</p></div>
    <div class="bg-blue-50 rounded-xl p-3 text-center"><p class="text-2xl font-black text-blue-700"><?= $counts['info']??0 ?></p><p class="text-xs text-blue-600">Insights</p></div>
    <div class="bg-green-50 rounded-xl p-3 text-center"><p class="text-2xl font-black text-green-700"><?= $counts['success']??0 ?></p><p class="text-xs text-green-600">Positive</p></div>
</div>

<div class="space-y-3">
<?php
$typeConfig = [
    'danger'  => ['border'=>'border-red-300',   'bg'=>'bg-red-50',    'icon_bg'=>'bg-red-500',    'title'=>'text-red-800',  'text'=>'text-red-700',  'badge'=>'bg-red-500'],
    'warning' => ['border'=>'border-amber-300',  'bg'=>'bg-amber-50',  'icon_bg'=>'bg-amber-500',  'title'=>'text-amber-800','text'=>'text-amber-700','badge'=>'bg-amber-500'],
    'info'    => ['border'=>'border-blue-300',   'bg'=>'bg-blue-50',   'icon_bg'=>'bg-blue-500',   'title'=>'text-blue-800', 'text'=>'text-blue-700', 'badge'=>'bg-blue-500'],
    'success' => ['border'=>'border-green-300',  'bg'=>'bg-green-50',  'icon_bg'=>'bg-green-500',  'title'=>'text-green-800','text'=>'text-green-700','badge'=>'bg-green-500'],
];
foreach ($alerts as $alert):
    $cfg = $typeConfig[$alert['type']] ?? $typeConfig['info'];
?>
<div class="flex items-start gap-4 p-4 rounded-xl border <?= $cfg['border'] ?> <?= $cfg['bg'] ?>">
    <div class="w-10 h-10 rounded-full <?= $cfg['icon_bg'] ?> flex items-center justify-center flex-shrink-0">
        <i class="fa-solid <?= $alert['icon'] ?> text-white"></i>
    </div>
    <div class="flex-1">
        <div class="flex items-center gap-2 mb-1">
            <h4 class="font-semibold <?= $cfg['title'] ?>"><?= h($alert['title']) ?></h4>
            <span class="<?= $cfg['badge'] ?> text-white text-xs px-1.5 py-0.5 rounded capitalize"><?= $alert['type'] ?></span>
        </div>
        <p class="text-sm <?= $cfg['text'] ?>"><?= $alert['message'] ?></p>
    </div>
    <?php if (!empty($alert['action'])): ?>
    <a href="<?= h($alert['action']['url']) ?>" class="flex-shrink-0 bg-white border border-gray-300 text-gray-700 text-xs px-3 py-1.5 rounded-lg hover:bg-gray-50 whitespace-nowrap">
        <?= h($alert['action']['label']) ?> →
    </a>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="mt-6 bg-blue-50 border border-blue-200 rounded-xl p-4 text-sm text-blue-700">
    <i class="fa-solid fa-info-circle mr-1"></i>
    <strong>About Smart Alerts:</strong> These alerts are generated automatically based on your business data using rule-based analysis. Forecasts are based on average daily sales over the last 30 days.
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
