<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requireLogin();
if (isAdmin()) { flash('error','Admin does not belong to a business.'); redirect(url('admin/businesses')); }

$db    = getDB();
$bizId = currentBusinessId();
$readOnly = !hasPermission('inventory');

if (!hasPermission('inventory') && !hasPermission('sales')) {
    http_response_code(403);
    include APP_PATH . '/includes/403.php';
    exit;
}

// Auto-create services table
$db->exec("CREATE TABLE IF NOT EXISTS `services` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `business_id` INT UNSIGNED NOT NULL,
    `category_id` INT UNSIGNED DEFAULT NULL,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `price_type` ENUM('fixed','hourly','custom') NOT NULL DEFAULT 'fixed',
    `duration_minutes` INT UNSIGNED DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_svc_biz` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Handle toggle/delete
if (!$readOnly) {
    if (get('action') === 'toggle' && get('id')) {
        verifyCsrf();
        $cur = $db->prepare('SELECT is_active FROM services WHERE id=? AND business_id=?');
        $cur->execute([(int)get('id'), $bizId]);
        $row = $cur->fetch();
        if ($row) {
            $db->prepare('UPDATE services SET is_active=? WHERE id=? AND business_id=?')
               ->execute([$row['is_active'] ? 0 : 1, (int)get('id'), $bizId]);
            flash('success', 'Service status updated.');
        }
        redirect(url('services'));
    }
    if (get('action') === 'delete' && get('id')) {
        verifyCsrf();
        $db->prepare('DELETE FROM services WHERE id=? AND business_id=?')->execute([(int)get('id'), $bizId]);
        auditLog('delete', 'services', (int)get('id'));
        flash('success', 'Service deleted.');
        redirect(url('services'));
    }
}

$search   = get('search');
$catId    = (int)get('category_id');
$status   = get('status', 'active');
$page     = max(1, (int)get('page', 1));
$perPage  = 20;

$where  = 's.business_id=?';
$params = [$bizId];
if ($search) { $where .= ' AND s.name LIKE ?'; $params[] = "%{$search}%"; }
if ($catId)  { $where .= ' AND s.category_id=?'; $params[] = $catId; }
if ($status === 'active')   { $where .= ' AND s.is_active=1'; }
if ($status === 'inactive') { $where .= ' AND s.is_active=0'; }

$cntQ = $db->prepare("SELECT COUNT(*) FROM services s WHERE $where"); $cntQ->execute($params);
$total = (int)$cntQ->fetchColumn();
$pag   = paginate($total, $page, $perPage);

$svcQ = $db->prepare("SELECT s.*, c.name AS category_name
    FROM services s
    LEFT JOIN categories c ON c.id=s.category_id
    WHERE $where
    ORDER BY s.is_active DESC, s.name ASC
    LIMIT {$pag['per_page']} OFFSET {$pag['offset']}");
$svcQ->execute($params);
$services = $svcQ->fetchAll();

$catsQ = $db->prepare('SELECT id, name FROM categories WHERE business_id=? ORDER BY name');
$catsQ->execute([$bizId]);
$categories = $catsQ->fetchAll();

$statsQ = $db->prepare('SELECT
    COUNT(*) AS total,
    SUM(is_active=1) AS active,
    SUM(is_active=0) AS inactive,
    MIN(price) AS min_price,
    MAX(price) AS max_price
    FROM services WHERE business_id=?');
$statsQ->execute([$bizId]);
$stats = $statsQ->fetch();

$pageTitle = 'Service Catalog';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-800">Service Catalog</h2>
        <p class="text-sm text-gray-500"><?= number_format($stats['total']) ?> service<?= $stats['total']!=1?'s':'' ?> · <?= number_format($stats['active']) ?> active</p>
    </div>
    <?php if (!$readOnly): ?>
    <a href="add.php" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
        <i class="fa-solid fa-plus mr-1"></i> Add Service
    </a>
    <?php endif; ?>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <?php
    $cards = [
        ['label'=>'Total Services', 'value'=>number_format($stats['total']),  'icon'=>'fa-briefcase',           'color'=>'blue'],
        ['label'=>'Active',         'value'=>number_format($stats['active']), 'icon'=>'fa-check-circle',        'color'=>'green'],
        ['label'=>'Inactive',       'value'=>number_format($stats['inactive']),'icon'=>'fa-pause-circle',       'color'=>'gray'],
        ['label'=>'Price Range',    'value'=>formatMoney($stats['min_price']).' - '.formatMoney($stats['max_price']),'icon'=>'fa-tag','color'=>'purple'],
    ];
    foreach ($cards as $c):
    $cls = match($c['color']) { 'blue'=>'bg-blue-50 text-blue-600', 'green'=>'bg-green-50 text-green-600', 'gray'=>'bg-gray-50 text-gray-500', 'purple'=>'bg-purple-50 text-purple-600', default=>'bg-gray-50 text-gray-500' };
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
        <input type="text" name="search" value="<?= h($search) ?>" placeholder="Search services..."
            class="flex-1 min-w-[160px] border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        <select name="category_id" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>" <?= $catId==$cat['id']?'selected':'' ?>><?= h($cat['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
            <option value="active" <?= $status==='active'?'selected':'' ?>>Active</option>
            <option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Inactive</option>
            <option value="all" <?= $status==='all'?'selected':'' ?>>All</option>
        </select>
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
                    <th class="px-4 py-3 font-medium text-left">Service</th>
                    <th class="px-4 py-3 font-medium text-left">Category</th>
                    <th class="px-4 py-3 font-medium text-left">Price Type</th>
                    <th class="px-4 py-3 font-medium text-right">Price</th>
                    <th class="px-4 py-3 font-medium text-center">Duration</th>
                    <th class="px-4 py-3 font-medium text-center">Status</th>
                    <?php if (!$readOnly): ?><th class="px-4 py-3 font-medium text-center">Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php if (empty($services)): ?>
                <tr><td colspan="<?= $readOnly ? 6 : 7 ?>" class="px-4 py-8 text-center text-gray-400">
                    No services found.
                    <?php if (!$readOnly): ?>
                    <a href="add.php" class="text-blue-600 hover:underline ml-1">Add your first service.</a>
                    <?php endif; ?>
                </td></tr>
                <?php else: ?>
                <?php foreach ($services as $s): ?>
                <tr class="hover:bg-gray-50 <?= !$s['is_active'] ? 'opacity-60' : '' ?>">
                    <td class="px-4 py-3">
                        <div>
                            <p class="font-semibold text-gray-800"><?= h($s['name']) ?></p>
                            <?php if ($s['description']): ?>
                            <p class="text-xs text-gray-400 mt-0.5 truncate max-w-xs"><?= h($s['description']) ?></p>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-gray-500"><?= h($s['category_name'] ?? '—') ?></td>
                    <td class="px-4 py-3">
                        <?php $ptCls = match($s['price_type']) { 'hourly'=>'bg-blue-100 text-blue-700', 'custom'=>'bg-purple-100 text-purple-700', default=>'bg-gray-100 text-gray-600' }; ?>
                        <span class="px-2 py-0.5 text-xs font-medium rounded-full <?= $ptCls ?>"><?= ucfirst($s['price_type']) ?></span>
                    </td>
                    <td class="px-4 py-3 text-right font-semibold text-gray-800">
                        <?= formatMoney($s['price']) ?>
                        <?php if ($s['price_type'] === 'hourly'): ?><span class="text-xs text-gray-400 font-normal">/hr</span><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center text-gray-500">
                        <?php if ($s['duration_minutes']): ?>
                            <?php $h = intdiv($s['duration_minutes'], 60); $m = $s['duration_minutes'] % 60; ?>
                            <?= $h > 0 ? "{$h}h " : '' ?><?= $m > 0 ? "{$m}m" : '' ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-0.5 text-xs font-medium rounded-full <?= $s['is_active']?'bg-green-100 text-green-700':'bg-gray-100 text-gray-500' ?>">
                            <?= $s['is_active']?'Active':'Inactive' ?>
                        </span>
                    </td>
                    <?php if (!$readOnly): ?>
                    <td class="px-4 py-3">
                        <div class="flex justify-center gap-1">
                            <a href="edit.php?id=<?= $s['id'] ?>" class="text-blue-600 p-1 hover:text-blue-800" title="Edit">
                                <i class="fa-solid fa-pen"></i>
                            </a>
                            <a href="index.php?action=toggle&id=<?= $s['id'] ?>&csrf_token=<?= csrfToken() ?>"
                               class="<?= $s['is_active']?'text-yellow-500 hover:text-yellow-700':'text-green-500 hover:text-green-700' ?> p-1"
                               title="<?= $s['is_active']?'Deactivate':'Activate' ?>"
                               onclick="return confirm('<?= $s['is_active']?'Deactivate':'Activate' ?> this service?')">
                                <i class="fa-solid fa-<?= $s['is_active']?'toggle-off':'toggle-on' ?>"></i>
                            </a>
                            <a href="index.php?action=delete&id=<?= $s['id'] ?>&csrf_token=<?= csrfToken() ?>"
                               class="text-red-500 p-1 hover:text-red-700" title="Delete"
                               onclick="return confirm('Delete service <?= h(addslashes($s['name'])) ?>? This cannot be undone.')">
                                <i class="fa-solid fa-trash"></i>
                            </a>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t">
        <?= renderPagination($pag, 'index.php?' . http_build_query(array_filter(compact('search','catId','status'))) . '&') ?>
    </div>
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
