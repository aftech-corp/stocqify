<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('expenses');

$db    = getDB();
$bizId = currentBusinessId();

// Auto-create tables for existing installations that haven't re-run schema.sql
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `suppliers` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `business_id` INT UNSIGNED NOT NULL,
        `name` VARCHAR(200) NOT NULL, `phone` VARCHAR(30) DEFAULT NULL,
        `email` VARCHAR(100) DEFAULT NULL, `address` TEXT DEFAULT NULL,
        `contact_person` VARCHAR(150) DEFAULT NULL, `opening_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `notes` TEXT DEFAULT NULL, `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), KEY `idx_sup_biz` (`business_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS `supplier_purchases` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `business_id` INT UNSIGNED NOT NULL,
        `supplier_id` INT UNSIGNED NOT NULL, `user_id` INT UNSIGNED NOT NULL,
        `reference` VARCHAR(100) DEFAULT NULL, `description` VARCHAR(500) NOT NULL,
        `total_amount` DECIMAL(15,2) NOT NULL, `amount_paid` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `payment_status` ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
        `purchase_date` DATE NOT NULL, `due_date` DATE DEFAULT NULL, `notes` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), KEY `idx_suppur_biz` (`business_id`), KEY `idx_suppur_sup` (`supplier_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS `supplier_payments` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `business_id` INT UNSIGNED NOT NULL,
        `supplier_id` INT UNSIGNED NOT NULL, `purchase_id` INT UNSIGNED DEFAULT NULL,
        `user_id` INT UNSIGNED NOT NULL, `amount` DECIMAL(15,2) NOT NULL,
        `payment_method` ENUM('cash','orange_money','afrimoney','qmoney','bank_transfer') NOT NULL DEFAULT 'cash',
        `reference_number` VARCHAR(100) DEFAULT NULL, `notes` TEXT DEFAULT NULL,
        `payment_date` DATE NOT NULL, `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), KEY `idx_suppay_biz` (`business_id`), KEY `idx_suppay_sup` (`supplier_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {}

$search = get('search');
$where  = 's.business_id=? AND s.is_active=1';
$params = [$bizId];
if ($search) {
    $where .= ' AND (s.name LIKE ? OR s.phone LIKE ? OR s.email LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}

$stmt = $db->prepare("
    SELECT s.*,
        COALESCE((SELECT SUM(sp.total_amount) FROM supplier_purchases sp WHERE sp.supplier_id=s.id AND sp.business_id=s.business_id), 0) AS total_purchased,
        COALESCE((SELECT SUM(sp.balance)       FROM supplier_purchases sp WHERE sp.supplier_id=s.id AND sp.payment_status!='paid'), 0)   AS outstanding
    FROM suppliers s WHERE $where ORDER BY s.name ASC
");
$stmt->execute($params);
$suppliers  = $stmt->fetchAll();
$totalOwed  = array_sum(array_column($suppliers, 'outstanding'));
$totalCount = count($suppliers);

$pageTitle = 'Suppliers';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<?= renderFlash() ?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-800">Supplier Management</h2>
        <p class="text-sm text-gray-500"><?= $totalCount ?> supplier<?= $totalCount !== 1 ? 's' : '' ?></p>
    </div>
    <a href="add.php" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-indigo-700">
        <i class="fa-solid fa-plus mr-1"></i> Add Supplier
    </a>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
    <div class="bg-indigo-50 rounded-xl p-4">
        <p class="text-2xl font-black text-indigo-700"><?= $totalCount ?></p>
        <p class="text-sm text-indigo-600">Active Suppliers</p>
    </div>
    <div class="bg-orange-50 rounded-xl p-4">
        <p class="text-2xl font-black text-orange-700"><?= formatMoney($totalOwed) ?></p>
        <p class="text-sm text-orange-600">Total Outstanding Payables</p>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
    <form method="GET" class="flex gap-3">
        <input type="text" name="search" value="<?= h($search) ?>" placeholder="Search name, phone, email..."
            class="flex-1 border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        <button class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm">Search</button>
        <?php if ($search): ?>
        <a href="index.php" class="bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-100">
    <div class="table-responsive">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-medium text-left">Supplier</th>
                    <th class="px-4 py-3 font-medium text-left">Contact</th>
                    <th class="px-4 py-3 font-medium text-right">Total Purchased</th>
                    <th class="px-4 py-3 font-medium text-right">Outstanding</th>
                    <th class="px-4 py-3 font-medium text-center">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php if (empty($suppliers)): ?>
                <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">
                    No suppliers yet. <a href="add.php" class="text-indigo-600 hover:underline">Add your first supplier.</a>
                </td></tr>
                <?php else: ?>
                <?php foreach ($suppliers as $s): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-700 font-bold text-sm flex-shrink-0">
                                <?= strtoupper(substr($s['name'],0,1)) ?>
                            </div>
                            <div>
                                <a href="view.php?id=<?= $s['id'] ?>" class="font-semibold text-gray-800 hover:text-indigo-600"><?= h($s['name']) ?></a>
                                <?php if ($s['contact_person']): ?><p class="text-xs text-gray-400"><?= h($s['contact_person']) ?></p><?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-gray-500 text-xs">
                        <?= $s['phone'] ? h($s['phone']) : '—' ?>
                        <?php if ($s['email']): ?><br><span class="text-gray-400"><?= h($s['email']) ?></span><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right font-semibold text-gray-700"><?= formatMoney($s['total_purchased']) ?></td>
                    <td class="px-4 py-3 text-right">
                        <?php if ($s['outstanding'] > 0): ?>
                            <span class="font-bold text-red-600"><?= formatMoney($s['outstanding']) ?></span>
                        <?php else: ?>
                            <span class="text-green-600 font-semibold text-xs">Settled</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <div class="flex justify-center gap-1">
                            <a href="view.php?id=<?= $s['id'] ?>"         title="View"             class="text-gray-500 p-1 hover:text-indigo-600"><i class="fa-solid fa-eye"></i></a>
                            <a href="purchase.php?supplier=<?= $s['id'] ?>" title="Record Purchase" class="text-green-600 p-1 hover:text-green-800"><i class="fa-solid fa-cart-plus"></i></a>
                            <a href="edit.php?id=<?= $s['id'] ?>"          title="Edit"             class="text-blue-600 p-1 hover:text-blue-800"><i class="fa-solid fa-pen"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
