<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('users');
requireFeature('branches_management');

$db    = getDB();
$bizId = currentBusinessId();

// Auto-create branches table and add branch_id to transaction tables (idempotent)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `branches` (
        `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `business_id`  INT UNSIGNED NOT NULL,
        `name`         VARCHAR(200) NOT NULL,
        `code`         VARCHAR(20)  DEFAULT NULL,
        `address`      TEXT         DEFAULT NULL,
        `phone`        VARCHAR(30)  DEFAULT NULL,
        `email`        VARCHAR(100) DEFAULT NULL,
        `manager_name` VARCHAR(150) DEFAULT NULL,
        `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
        `notes`        TEXT         DEFAULT NULL,
        `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_branch_biz` (`business_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("ALTER TABLE sales      ADD COLUMN IF NOT EXISTS branch_id INT UNSIGNED DEFAULT NULL");
    $db->exec("ALTER TABLE expenses   ADD COLUMN IF NOT EXISTS branch_id INT UNSIGNED DEFAULT NULL");
    $db->exec("ALTER TABLE income     ADD COLUMN IF NOT EXISTS branch_id INT UNSIGNED DEFAULT NULL");
    $db->exec("ALTER TABLE drawings   ADD COLUMN IF NOT EXISTS branch_id INT UNSIGNED DEFAULT NULL");
    $db->exec("ALTER TABLE users      ADD COLUMN IF NOT EXISTS branch_id INT UNSIGNED DEFAULT NULL");
    $db->exec("ALTER TABLE customers  ADD COLUMN IF NOT EXISTS branch_id INT UNSIGNED DEFAULT NULL");
    $db->exec("ALTER TABLE products   ADD COLUMN IF NOT EXISTS branch_id INT UNSIGNED DEFAULT NULL");
    $db->exec("ALTER TABLE branches   ADD COLUMN IF NOT EXISTS manager_user_id INT UNSIGNED DEFAULT NULL");
} catch (\Throwable $e) {}

// Auto-create Main Branch and migrate all existing records if no branches exist yet
$checkQ = $db->prepare("SELECT COUNT(*) FROM branches WHERE business_id = ?");
$checkQ->execute([$bizId]);
if ((int)$checkQ->fetchColumn() === 0) {
    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO branches (business_id, name, is_active) VALUES (?, 'Main Branch', 1)")->execute([$bizId]);
        $mainBranchId = (int)$db->lastInsertId();
        foreach (['sales', 'expenses', 'income', 'drawings', 'customers', 'products'] as $tbl) {
            $db->prepare("UPDATE $tbl SET branch_id = ? WHERE business_id = ? AND branch_id IS NULL")->execute([$mainBranchId, $bizId]);
        }
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
    }
}

$stmt = $db->prepare("
    SELECT b.*,
        (SELECT COUNT(*) FROM sales    s WHERE s.branch_id = b.id) AS sales_count,
        (SELECT COUNT(*) FROM expenses e WHERE e.branch_id = b.id) AS expense_count,
        (SELECT COUNT(*) FROM income   i WHERE i.branch_id = b.id) AS income_count
    FROM branches b
    WHERE b.business_id = ?
    ORDER BY b.is_active DESC, b.name ASC
");
$stmt->execute([$bizId]);
$branches = $stmt->fetchAll();

$pageTitle = 'Branches';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-800">Branch Management</h2>
        <p class="text-sm text-gray-500"><?= count($branches) ?> branch<?= count($branches) !== 1 ? 'es' : '' ?> &mdash; each branch keeps separate sales, expenses, customers &amp; inventory</p>
    </div>
    <a href="add.php" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
        <i class="fa-solid fa-plus mr-1"></i> Add Branch
    </a>
</div>

<?php if (empty($branches)): ?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-12 text-center">
    <div class="w-16 h-16 bg-blue-50 rounded-full flex items-center justify-center mx-auto mb-4">
        <i class="fa-solid fa-code-branch text-2xl text-blue-400"></i>
    </div>
    <h3 class="text-gray-700 font-semibold mb-1">No branches yet</h3>
    <p class="text-gray-400 text-sm mb-4">Create branches to organise operations across multiple locations. Each branch shares the same products, customers and suppliers — only transactions are separated.</p>
    <a href="add.php" class="bg-blue-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 inline-block">
        <i class="fa-solid fa-plus mr-1"></i> Add First Branch
    </a>
</div>
<?php else: ?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100">
    <div class="table-responsive">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-medium text-left">Branch</th>
                    <th class="px-4 py-3 font-medium text-left">Code</th>
                    <th class="px-4 py-3 font-medium text-left">Manager</th>
                    <th class="px-4 py-3 font-medium text-left">Contact</th>
                    <th class="px-4 py-3 font-medium text-center">Sales</th>
                    <th class="px-4 py-3 font-medium text-center">Expenses</th>
                    <th class="px-4 py-3 font-medium text-center">Income</th>
                    <th class="px-4 py-3 font-medium text-center">Status</th>
                    <th class="px-4 py-3 font-medium text-center">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($branches as $b): ?>
                <tr class="hover:bg-gray-50 <?= !$b['is_active'] ? 'opacity-60' : '' ?>">
                    <td class="px-4 py-3">
                        <div class="font-medium text-gray-800"><?= h($b['name']) ?></div>
                        <?php if ($b['address']): ?><div class="text-xs text-gray-400 truncate max-w-xs"><?= h($b['address']) ?></div><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-gray-500"><?= h($b['code'] ?: '—') ?></td>
                    <td class="px-4 py-3 text-gray-500"><?= h($b['manager_name'] ?: '—') ?></td>
                    <td class="px-4 py-3 text-gray-400 text-xs">
                        <?php if ($b['phone']): ?><div><?= h($b['phone']) ?></div><?php endif; ?>
                        <?php if ($b['email']): ?><div><?= h($b['email']) ?></div><?php endif; ?>
                        <?php if (!$b['phone'] && !$b['email']): ?>—<?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center text-gray-600"><?= number_format($b['sales_count']) ?></td>
                    <td class="px-4 py-3 text-center text-gray-600"><?= number_format($b['expense_count']) ?></td>
                    <td class="px-4 py-3 text-center text-gray-600"><?= number_format($b['income_count']) ?></td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-0.5 rounded-full text-xs font-semibold <?= $b['is_active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
                            <?= $b['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <a href="edit.php?id=<?= $b['id'] ?>" class="text-blue-600 p-1 hover:text-blue-800" title="Edit">
                            <i class="fa-solid fa-pen"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
