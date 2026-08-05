<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('expenses');
requireFeature('finance_drawings');

$db    = getDB();
$bizId = currentBusinessId();

// Auto-create tables for existing installations
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `drawings` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `business_id` INT UNSIGNED NOT NULL,
        `user_id` INT UNSIGNED NOT NULL, `amount` DECIMAL(15,2) NOT NULL,
        `description` VARCHAR(300) NOT NULL,
        `payment_method` ENUM('cash','orange_money','afrimoney','qmoney','bank_transfer') NOT NULL DEFAULT 'cash',
        `drawing_date` DATE NOT NULL, `notes` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), KEY `idx_draw_biz` (`business_id`), KEY `idx_draw_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("ALTER TABLE drawings ADD COLUMN IF NOT EXISTS branch_id INT UNSIGNED DEFAULT NULL");
    $db->exec("CREATE TABLE IF NOT EXISTS `capital_accounts` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `business_id` INT UNSIGNED NOT NULL,
        `user_id` INT UNSIGNED NOT NULL,
        `type` ENUM('opening','injection') NOT NULL DEFAULT 'opening',
        `amount` DECIMAL(15,2) NOT NULL, `description` VARCHAR(300) DEFAULT NULL,
        `entry_date` DATE NOT NULL, `notes` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), KEY `idx_cap_biz` (`business_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {}

$from = get('from', date('Y-m-01'));
$to   = get('to',   date('Y-m-d'));
$page = max(1,(int)get('page',1));

$where  = 'd.business_id=?';
$params = [$bizId];
if ($from) { $where .= ' AND d.drawing_date>=?'; $params[] = $from; }
if ($to)   { $where .= ' AND d.drawing_date<=?'; $params[] = $to; }
$__activeBranch = currentBranchId();
if ($__activeBranch !== null) { $where .= ' AND d.branch_id=?'; $params[] = $__activeBranch; }

$totalQ = $db->prepare("SELECT COUNT(*) FROM drawings d WHERE $where");
$totalQ->execute($params);
$total = (int)$totalQ->fetchColumn();
$pag   = paginate($total, $page);

$stmt = $db->prepare("SELECT d.*, u.name AS user_name FROM drawings d JOIN users u ON u.id=d.user_id WHERE $where ORDER BY d.drawing_date DESC, d.id DESC LIMIT {$pag['per_page']} OFFSET {$pag['offset']}");
$stmt->execute($params);
$drawings = $stmt->fetchAll();

$sumQ = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM drawings d WHERE $where");
$sumQ->execute($params);
$periodTotal = (float)$sumQ->fetchColumn();

$allTimeQ = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM drawings WHERE business_id=?");
$allTimeQ->execute([$bizId]);
$allTimeTotal = (float)$allTimeQ->fetchColumn();

$pageTitle = 'Owner Drawings';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<?= renderFlash() ?>
<?= renderBranchBanner() ?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-800">Owner Drawings</h2>
        <p class="text-sm text-gray-500">Cash withdrawn by business owner(s)</p>
    </div>
    <a href="add.php" class="bg-purple-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-purple-700">
        <i class="fa-solid fa-plus mr-1"></i> Record Drawing
    </a>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
    <div class="bg-purple-50 rounded-xl p-4">
        <p class="text-2xl font-black text-purple-700"><?= formatMoney($periodTotal) ?></p>
        <p class="text-sm text-purple-600">Drawings (Selected Period)</p>
    </div>
    <div class="bg-gray-50 rounded-xl p-4">
        <p class="text-2xl font-black text-gray-700"><?= formatMoney($allTimeTotal) ?></p>
        <p class="text-sm text-gray-500">Total Drawings (All Time)</p>
    </div>
</div>

<!-- Filter -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
    <form method="GET" class="flex flex-wrap gap-3">
        <input type="date" name="from" value="<?= h($from) ?>" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        <input type="date" name="to"   value="<?= h($to) ?>"   class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        <button class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm">Filter</button>
        <a href="index.php" class="bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm">Clear</a>
    </form>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-100">
    <div class="table-responsive">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-medium text-left">Date</th>
                    <th class="px-4 py-3 font-medium text-left">Description</th>
                    <th class="px-4 py-3 font-medium text-right">Amount</th>
                    <th class="px-4 py-3 font-medium text-left">Method</th>
                    <th class="px-4 py-3 font-medium text-left">Recorded By</th>
                    <th class="px-4 py-3 font-medium text-center">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php if (empty($drawings)): ?>
                <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">No drawings recorded for this period.</td></tr>
                <?php else: ?>
                <?php foreach ($drawings as $d): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-500"><?= formatDate($d['drawing_date']) ?></td>
                    <td class="px-4 py-3 font-medium text-gray-800"><?= h($d['description']) ?></td>
                    <td class="px-4 py-3 text-right font-bold text-purple-700"><?= formatMoney($d['amount']) ?></td>
                    <td class="px-4 py-3 text-gray-500 capitalize text-xs"><?= str_replace('_',' ',$d['payment_method']) ?></td>
                    <td class="px-4 py-3 text-gray-400 text-xs"><?= h($d['user_name']) ?></td>
                    <td class="px-4 py-3 text-center">
                        <div class="flex justify-center gap-1">
                            <a href="edit.php?id=<?= $d['id'] ?>"   class="text-blue-600 p-1 hover:text-blue-800"><i class="fa-solid fa-pen"></i></a>
                            <a href="delete.php?id=<?= $d['id'] ?>&csrf=<?= csrfToken() ?>" class="text-red-500 p-1 hover:text-red-700"
                               onclick="return confirm('Delete this drawing record?')"><i class="fa-solid fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t">
        <?= renderPagination($pag, 'index.php?'.http_build_query(array_filter(compact('from','to'))).'&') ?>
    </div>
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
