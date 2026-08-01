<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requireLogin();
if (!hasPermission('inventory') && !hasPermission('sales')) {
    http_response_code(403);
    include APP_PATH . '/includes/403.php';
    exit;
}
$readOnly = !hasPermission('inventory'); // sales-only users get view-only access

$db     = getDB();
$bizId  = currentBusinessId();
$search = get('search');
$filter = get('filter');
$catId  = (int)get('category');
$page   = max(1, (int)get('page', 1));

$where  = 'p.business_id = ? AND p.is_active = 1';
$params = [$bizId];

if ($search) {
    $where .= ' AND (p.name LIKE ? OR p.sku LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($filter === 'low') {
    $where .= ' AND p.stock_quantity <= p.reorder_level';
}
if ($filter === 'out') {
    $where .= ' AND p.stock_quantity <= 0';
}
if ($catId) {
    $where .= ' AND p.category_id = ?';
    $params[] = $catId;
}

$totalQ = $db->prepare("SELECT COUNT(*) FROM products p WHERE $where");
$totalQ->execute($params);
$total = (int)$totalQ->fetchColumn();
$pag   = paginate($total, $page);

$stmt = $db->prepare("SELECT p.*, c.name AS category_name
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE $where
    ORDER BY p.name ASC
    LIMIT {$pag['per_page']} OFFSET {$pag['offset']}");
$stmt->execute($params);
$products = $stmt->fetchAll();

$catsQ = $db->prepare('SELECT id, name FROM categories WHERE business_id=? ORDER BY name');
$catsQ->execute([$bizId]);
$categories = $catsQ->fetchAll();

// Stats
$lowStock  = (int)(getDB()->prepare("SELECT COUNT(*) FROM products WHERE business_id=? AND is_active=1 AND stock_quantity <= reorder_level") && 1);
$s2 = $db->prepare("SELECT COUNT(*) FROM products WHERE business_id=? AND is_active=1 AND stock_quantity <= reorder_level"); $s2->execute([$bizId]); $lowStock = $s2->fetchColumn();
$s3 = $db->prepare("SELECT COUNT(*) FROM products WHERE business_id=? AND is_active=1 AND stock_quantity <= 0"); $s3->execute([$bizId]); $outStock = $s3->fetchColumn();

$pageTitle = 'Products & Inventory';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-800">Products & Inventory</h2>
        <p class="text-sm text-gray-500"><?= number_format($total) ?> products</p>
    </div>
    <div class="flex gap-2">
        <?php if (!$readOnly): ?>
        <a href="categories.php" class="border border-gray-300 text-gray-700 px-3 py-2 rounded-lg text-sm hover:bg-gray-50 flex items-center gap-2">
            <i class="fa-solid fa-tags"></i> Categories
        </a>
        <a href="add.php" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 flex items-center gap-2">
            <i class="fa-solid fa-plus"></i> Add Product
        </a>
        <?php else: ?>
        <span class="text-xs bg-amber-50 border border-amber-200 text-amber-700 px-3 py-2 rounded-lg">
            <i class="fa-solid fa-eye mr-1"></i> View only
        </span>
        <?php endif; ?>
    </div>
</div>

<!-- Alert Badges -->
<div class="flex gap-3 mb-4">
    <a href="?filter=low" class="flex items-center gap-2 bg-amber-50 border border-amber-200 text-amber-700 px-3 py-2 rounded-lg text-sm <?= $filter==='low'?'ring-2 ring-amber-400':'' ?>">
        <i class="fa-solid fa-triangle-exclamation"></i> Low Stock: <strong><?= $lowStock ?></strong>
    </a>
    <a href="?filter=out" class="flex items-center gap-2 bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded-lg text-sm <?= $filter==='out'?'ring-2 ring-red-400':'' ?>">
        <i class="fa-solid fa-box-open"></i> Out of Stock: <strong><?= $outStock ?></strong>
    </a>
    <?php if ($filter): ?><a href="index.php" class="bg-gray-100 text-gray-600 px-3 py-2 rounded-lg text-sm">Clear Filter</a><?php endif; ?>
</div>

<!-- Search / Filter Bar -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
    <form method="GET" class="flex gap-3 flex-wrap">
        <div class="relative flex-1 min-w-48">
            <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
            <input type="text" name="search" value="<?= h($search) ?>" placeholder="Search products..."
                class="w-full pl-9 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
        </div>
        <select name="category" class="border border-gray-300 rounded-lg text-sm px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>" <?= $catId==$cat['id']?'selected':'' ?>><?= h($cat['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm">Search</button>
    </form>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-100">
    <div class="table-responsive">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-medium text-left">#</th>
                    <th class="px-4 py-3 font-medium text-left w-12"></th>
                    <th class="px-4 py-3 font-medium text-left">Product</th>
                    <th class="px-4 py-3 font-medium text-left">Category</th>
                    <?php if (!$readOnly): ?><th class="px-4 py-3 font-medium text-right">Cost Price</th><?php endif; ?>
                    <th class="px-4 py-3 font-medium text-right">Selling Price</th>
                    <th class="px-4 py-3 font-medium text-center">Stock</th>
                    <th class="px-4 py-3 font-medium text-center">Status</th>
                    <?php if (!$readOnly): ?><th class="px-4 py-3 font-medium text-center">Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php if (empty($products)): ?>
                <tr><td colspan="<?= $readOnly ? 7 : 9 ?>" class="px-4 py-8 text-center text-gray-400">No products found.</td></tr>
                <?php else: ?>
                <?php foreach ($products as $i => $p):
                    $stockStatus = $p['stock_quantity'] <= 0 ? 'out' : ($p['stock_quantity'] <= $p['reorder_level'] ? 'low' : 'ok');
                ?>
                <tr class="hover:bg-gray-50 <?= $stockStatus==='out'?'bg-red-50/30':($stockStatus==='low'?'bg-amber-50/30':'') ?>">
                    <td class="px-4 py-3 text-gray-400"><?= $pag['offset'] + $i + 1 ?></td>
                    <td class="px-2 py-2">
                        <?php if (!empty($p['image']) && file_exists(__DIR__ . '/../../' . $p['image'])): ?>
                        <img src="<?= APP_URL . '/' . h($p['image']) ?>" alt=""
                             class="w-10 h-10 rounded-lg object-cover border border-gray-100">
                        <?php else: ?>
                        <div class="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center text-gray-300">
                            <i class="fa-solid fa-image text-sm"></i>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <p class="font-medium text-gray-800"><?= h($p['name']) ?></p>
                        <?php if ($p['sku']): ?><p class="text-xs text-gray-400 font-mono"><?= h($p['sku']) ?></p><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-gray-500"><?= h($p['category_name'] ?? 'Uncategorized') ?></td>
                    <?php if (!$readOnly): ?><td class="px-4 py-3 text-right text-gray-700"><?= formatMoney($p['cost_price']) ?></td><?php endif; ?>
                    <td class="px-4 py-3 text-right font-semibold text-gray-800"><?= formatMoney($p['selling_price']) ?></td>
                    <td class="px-4 py-3 text-center">
                        <span class="font-bold <?= $stockStatus==='out'?'text-red-600':($stockStatus==='low'?'text-amber-600':'text-green-600') ?>">
                            <?= number_format($p['stock_quantity'], 0) ?>
                        </span>
                        <span class="text-xs text-gray-400"> <?= h($p['unit']) ?></span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($stockStatus==='out'): ?>
                        <span class="bg-red-100 text-red-700 text-xs px-2 py-0.5 rounded-full">Out of Stock</span>
                        <?php elseif ($stockStatus==='low'): ?>
                        <span class="bg-amber-100 text-amber-700 text-xs px-2 py-0.5 rounded-full">Low Stock</span>
                        <?php else: ?>
                        <span class="bg-green-100 text-green-700 text-xs px-2 py-0.5 rounded-full">In Stock</span>
                        <?php endif; ?>
                    </td>
                    <?php if (!$readOnly): ?>
                    <td class="px-4 py-3 text-center">
                        <div class="flex justify-center gap-1">
                            <a href="edit.php?id=<?= $p['id'] ?>" class="text-blue-600 hover:text-blue-800 p-1" title="Edit"><i class="fa-solid fa-pen"></i></a>
                            <a href="adjust.php?id=<?= $p['id'] ?>" class="text-green-600 hover:text-green-800 p-1" title="Adjust Stock"><i class="fa-solid fa-sliders"></i></a>
                            <a href="delete.php?id=<?= $p['id'] ?>&csrf=<?= csrfToken() ?>"
                               class="text-red-500 hover:text-red-700 p-1" title="Delete"
                               onclick="return confirm('Delete this product?')"><i class="fa-solid fa-trash"></i></a>
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
        <?= renderPagination($pag, 'index.php?' . http_build_query(array_filter(['search'=>$search,'category'=>$catId,'filter'=>$filter])) . '&') ?>
    </div>
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
