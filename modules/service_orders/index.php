<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requireLogin();
if (isAdmin()) { flash('error','Admin does not belong to a business.'); redirect(url('admin/businesses')); }
requirePermission('sales');

$db    = getDB();
$bizId = currentBusinessId();

// Auto-create service order tables
$db->exec("CREATE TABLE IF NOT EXISTS `service_orders` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `business_id` INT UNSIGNED NOT NULL,
    `customer_id` INT UNSIGNED DEFAULT NULL,
    `walkin_name` VARCHAR(255) DEFAULT NULL,
    `walkin_phone` VARCHAR(50) DEFAULT NULL,
    `order_number` VARCHAR(50) NOT NULL DEFAULT '',
    `status` ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
    `payment_status` ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
    `scheduled_at` DATETIME DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `discount_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `amount_paid` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `balance_due` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `payment_method` VARCHAR(50) DEFAULT 'cash',
    `notes` TEXT DEFAULT NULL,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_so_biz` (`business_id`),
    KEY `idx_so_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS `service_order_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id` INT UNSIGNED NOT NULL,
    `service_id` INT UNSIGNED DEFAULT NULL,
    `service_name` VARCHAR(255) NOT NULL,
    `unit_price` DECIMAL(15,2) NOT NULL,
    `quantity` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    `discount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `total` DECIMAL(15,2) NOT NULL,
    `notes` TEXT DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `fk_soi_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$status  = get('status', '');
$payStatus = get('pay_status', '');
$search  = get('search', '');
$dateFrom = get('date_from');
$dateTo   = get('date_to');
$page    = max(1, (int)get('page', 1));
$perPage = 20;

$where  = 'so.business_id=?';
$params = [$bizId];
if ($status)   { $where .= ' AND so.status=?';         $params[] = $status; }
if ($payStatus){ $where .= ' AND so.payment_status=?'; $params[] = $payStatus; }
if ($dateFrom) { $where .= ' AND DATE(so.created_at)>=?'; $params[] = $dateFrom; }
if ($dateTo)   { $where .= ' AND DATE(so.created_at)<=?'; $params[] = $dateTo; }
if ($search)   { $where .= ' AND (so.order_number LIKE ? OR c.name LIKE ? OR so.walkin_name LIKE ?)';
                 $s="%{$search}%"; $params=array_merge($params,[$s,$s,$s]); }

$cntQ = $db->prepare("SELECT COUNT(*) FROM service_orders so LEFT JOIN customers c ON c.id=so.customer_id WHERE $where");
$cntQ->execute($params);
$total = (int)$cntQ->fetchColumn();
$pag   = paginate($total, $page, $perPage);

$ordQ = $db->prepare("SELECT so.*, c.name AS customer_name, u.name AS staff_name
    FROM service_orders so
    LEFT JOIN customers c ON c.id=so.customer_id
    LEFT JOIN users u ON u.id=so.user_id
    WHERE $where
    ORDER BY so.created_at DESC
    LIMIT {$pag['per_page']} OFFSET {$pag['offset']}");
$ordQ->execute($params);
$orders = $ordQ->fetchAll();

// Stats
$statsQ = $db->prepare('SELECT
    COUNT(*) AS total,
    SUM(status="pending") AS pending,
    SUM(status="in_progress") AS in_progress,
    SUM(status="completed") AS completed,
    SUM(status="cancelled") AS cancelled,
    SUM(payment_status="unpaid") AS unpaid,
    COALESCE(SUM(CASE WHEN status="completed" THEN total_amount ELSE 0 END),0) AS revenue
    FROM service_orders WHERE business_id=?');
$statsQ->execute([$bizId]);
$stats = $statsQ->fetch();

$pageTitle = 'Service Orders';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-800">Service Orders</h2>
        <p class="text-sm text-gray-500"><?= number_format($stats['total']) ?> total orders</p>
    </div>
    <a href="add.php" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
        <i class="fa-solid fa-plus mr-1"></i> New Order
    </a>
</div>

<!-- Stats cards -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <?php
    $cards = [
        ['label'=>'Pending',    'value'=>number_format($stats['pending']),     'icon'=>'fa-clock',            'color'=>'yellow'],
        ['label'=>'In Progress','value'=>number_format($stats['in_progress']), 'icon'=>'fa-person-digging',   'color'=>'blue'],
        ['label'=>'Completed',  'value'=>number_format($stats['completed']),   'icon'=>'fa-circle-check',     'color'=>'green'],
        ['label'=>'Revenue',    'value'=>formatMoney($stats['revenue']),        'icon'=>'fa-circle-dollar-to-slot','color'=>'purple'],
    ];
    foreach ($cards as $c):
    $cls = match($c['color']) { 'yellow'=>'bg-yellow-50 text-yellow-600', 'blue'=>'bg-blue-50 text-blue-600', 'green'=>'bg-green-50 text-green-600', 'purple'=>'bg-purple-50 text-purple-600', default=>'bg-gray-50 text-gray-500' };
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

<!-- Filters -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
    <form method="GET" class="flex flex-wrap gap-3">
        <input type="text" name="search" value="<?= h($search) ?>" placeholder="Search orders, customers..."
            class="flex-1 min-w-[160px] border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        <select name="status" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
            <option value="">All Status</option>
            <option value="pending"     <?= $status==='pending'?'selected':'' ?>>Pending</option>
            <option value="in_progress" <?= $status==='in_progress'?'selected':'' ?>>In Progress</option>
            <option value="completed"   <?= $status==='completed'?'selected':'' ?>>Completed</option>
            <option value="cancelled"   <?= $status==='cancelled'?'selected':'' ?>>Cancelled</option>
        </select>
        <select name="pay_status" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
            <option value="">All Payment</option>
            <option value="unpaid"  <?= $payStatus==='unpaid'?'selected':'' ?>>Unpaid</option>
            <option value="partial" <?= $payStatus==='partial'?'selected':'' ?>>Partial</option>
            <option value="paid"    <?= $payStatus==='paid'?'selected':'' ?>>Paid</option>
        </select>
        <input type="date" name="date_from" value="<?= h($dateFrom) ?>" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        <input type="date" name="date_to"   value="<?= h($dateTo) ?>"   class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        <button class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm">Filter</button>
        <a href="index.php" class="bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm">Clear</a>
    </form>
</div>

<!-- Table -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100">
    <div class="table-responsive">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-medium text-left">Order #</th>
                    <th class="px-4 py-3 font-medium text-left">Customer</th>
                    <th class="px-4 py-3 font-medium text-left">Date</th>
                    <th class="px-4 py-3 font-medium text-center">Status</th>
                    <th class="px-4 py-3 font-medium text-center">Payment</th>
                    <th class="px-4 py-3 font-medium text-right">Total</th>
                    <th class="px-4 py-3 font-medium text-right">Balance</th>
                    <th class="px-4 py-3 font-medium text-center">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php if (empty($orders)): ?>
                <tr><td colspan="8" class="px-4 py-8 text-center text-gray-400">
                    No orders found. <a href="add.php" class="text-blue-600 hover:underline">Create your first order.</a>
                </td></tr>
                <?php else: ?>
                <?php foreach ($orders as $o): ?>
                <?php
                    $statusCls = match($o['status']) {
                        'pending'     => 'bg-yellow-100 text-yellow-700',
                        'in_progress' => 'bg-blue-100 text-blue-700',
                        'completed'   => 'bg-green-100 text-green-700',
                        'cancelled'   => 'bg-red-100 text-red-700',
                        default       => 'bg-gray-100 text-gray-600',
                    };
                    $payCls = match($o['payment_status']) {
                        'paid'    => 'bg-green-100 text-green-700',
                        'partial' => 'bg-orange-100 text-orange-700',
                        default   => 'bg-red-100 text-red-700',
                    };
                    $customerName = $o['customer_name'] ?? ($o['walkin_name'] ? $o['walkin_name'] . ' (Walk-in)' : 'Walk-in');
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-mono font-semibold text-blue-700">
                        <a href="view.php?id=<?= $o['id'] ?>" class="hover:underline"><?= h($o['order_number'] ?: 'SRV-'.$o['id']) ?></a>
                    </td>
                    <td class="px-4 py-3 font-medium"><?= h($customerName) ?></td>
                    <td class="px-4 py-3 text-gray-500"><?= formatDate($o['created_at']) ?></td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-0.5 text-xs font-medium rounded-full <?= $statusCls ?>">
                            <?= ucwords(str_replace('_',' ',$o['status'])) ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-0.5 text-xs font-medium rounded-full <?= $payCls ?>">
                            <?= ucfirst($o['payment_status']) ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-right font-semibold"><?= formatMoney($o['total_amount']) ?></td>
                    <td class="px-4 py-3 text-right <?= $o['balance_due'] > 0 ? 'text-red-600 font-semibold' : 'text-gray-400' ?>">
                        <?= $o['balance_due'] > 0 ? formatMoney($o['balance_due']) : '—' ?>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex justify-center gap-1">
                            <a href="view.php?id=<?= $o['id'] ?>" class="text-blue-600 p-1 hover:text-blue-800" title="View">
                                <i class="fa-solid fa-eye"></i>
                            </a>
                            <?php if (in_array($o['status'], ['pending','in_progress'])): ?>
                            <a href="edit.php?id=<?= $o['id'] ?>" class="text-gray-500 p-1 hover:text-gray-800" title="Edit">
                                <i class="fa-solid fa-pen"></i>
                            </a>
                            <?php endif; ?>
                            <a href="invoice.php?id=<?= $o['id'] ?>" target="_blank" class="text-green-600 p-1 hover:text-green-800" title="Invoice">
                                <i class="fa-solid fa-print"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t">
        <?= renderPagination($pag, 'index.php?' . http_build_query(array_filter(compact('search','status','payStatus','dateFrom','dateTo'))) . '&') ?>
    </div>
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
