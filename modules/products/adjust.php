<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('inventory');

$db    = getDB();
$bizId = currentBusinessId();
$id    = (int)get('id');
$errors = [];

$stmt = $db->prepare('SELECT * FROM products WHERE id=? AND business_id=?');
$stmt->execute([$id, $bizId]);
$product = $stmt->fetch();
if (!$product) { flash('error', 'Product not found.'); redirect(url('products')); }

// Load history
$histQ = $db->prepare("SELECT it.*, u.name AS user_name FROM inventory_transactions it LEFT JOIN users u ON u.id=it.user_id WHERE it.product_id=? ORDER BY it.created_at DESC LIMIT 20");
$histQ->execute([$id]);
$history = $histQ->fetchAll();

if (isPost()) {
    verifyCsrf();
    $type  = post('type');
    $qty   = (float)post('quantity', 0);
    $notes = post('notes');
    $validTypes = ['purchase','adjustment','return','damage'];

    if (!in_array($type, $validTypes)) $errors[] = 'Invalid transaction type.';
    if ($qty <= 0) $errors[] = 'Quantity must be greater than 0.';

    // For damage/sale type, ensure stock is sufficient
    if (in_array($type, ['damage']) && $qty > $product['stock_quantity']) {
        $errors[] = 'Quantity exceeds current stock (' . $product['stock_quantity'] . ' ' . $product['unit'] . ').';
    }

    if (empty($errors)) {
        $before = $product['stock_quantity'];
        if (in_array($type, ['damage'])) {
            $after = $before - $qty;
        } else {
            $after = $before + $qty;
        }
        if ($after < 0) $after = 0;

        $db->beginTransaction();
        try {
            $db->prepare('UPDATE products SET stock_quantity=?, updated_at=NOW() WHERE id=?')->execute([$after, $id]);
            $db->prepare('INSERT INTO inventory_transactions (business_id,product_id,user_id,type,quantity,before_quantity,after_quantity,notes) VALUES (?,?,?,?,?,?,?,?)')->execute(
                [$bizId, $id, currentUser()['id'], $type, $qty, $before, $after, $notes ?: null]
            );
            $db->commit();
            flash('success', 'Stock adjusted. New quantity: ' . number_format($after, 2) . ' ' . $product['unit']);
            redirect(url('products/adjust') . '?id=' . $id);
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Failed to adjust stock.';
        }
    }
}

$pageTitle = 'Stock Adjustment';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>
<div class="max-w-4xl mx-auto">
    <div class="flex items-center gap-3 mb-6">
        <a href="index.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
        <div>
            <h2 class="text-xl font-bold text-gray-800">Stock Adjustment</h2>
            <p class="text-sm text-gray-500"><?= h($product['name']) ?></p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div>
            <!-- Current Stock Card -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mb-4">
                <h3 class="font-semibold text-gray-700 mb-3">Current Stock</h3>
                <div class="text-center py-4">
                    <p class="text-5xl font-black text-blue-600"><?= number_format($product['stock_quantity'], 0) ?></p>
                    <p class="text-gray-500 mt-1"><?= h($product['unit']) ?></p>
                    <p class="text-xs text-gray-400 mt-2">Reorder Level: <?= $product['reorder_level'] ?></p>
                </div>
            </div>

            <!-- Adjustment Form -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                <h3 class="font-semibold text-gray-700 mb-4">Adjust Stock</h3>
                <?php if ($errors): ?>
                <div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-4">
                    <?php foreach ($errors as $e): ?><p class="text-red-700 text-sm"><?= h($e) ?></p><?php endforeach; ?>
                </div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Transaction Type</label>
                        <select name="type" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                            <option value="purchase">Purchase / Restock (+)</option>
                            <option value="adjustment">Manual Adjustment (+)</option>
                            <option value="return">Customer Return (+)</option>
                            <option value="damage">Damage / Loss (-)</option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Quantity</label>
                        <input type="number" name="quantity" min="0.01" step="0.01" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                        <textarea name="notes" rows="2"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none resize-none"
                            placeholder="Optional reason..."></textarea>
                    </div>
                    <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
                        Apply Adjustment
                    </button>
                </form>
            </div>
        </div>

        <!-- History -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h3 class="font-semibold text-gray-700 mb-4">Transaction History</h3>
            <div class="space-y-2 max-h-96 overflow-y-auto">
                <?php if (empty($history)): ?>
                <p class="text-gray-400 text-sm text-center py-6">No transactions yet.</p>
                <?php else: ?>
                <?php foreach ($history as $h): ?>
                <?php $isDecrease = in_array($h['type'],['sale','damage']); ?>
                <div class="flex items-start gap-3 p-3 rounded-lg border border-gray-100">
                    <div class="w-8 h-8 rounded-full <?= $isDecrease?'bg-red-100':'bg-green-100' ?> flex items-center justify-center flex-shrink-0">
                        <i class="fa-solid <?= $isDecrease?'fa-arrow-down text-red-600':'fa-arrow-up text-green-600' ?> text-xs"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex justify-between items-start">
                            <p class="text-sm font-medium text-gray-700 capitalize"><?= str_replace('_',' ',$h['type']) ?></p>
                            <span class="text-sm font-bold <?= $isDecrease?'text-red-600':'text-green-600' ?>">
                                <?= $isDecrease?'-':'+' ?><?= number_format($h['quantity'],2) ?>
                            </span>
                        </div>
                        <p class="text-xs text-gray-400"><?= $h['before_quantity'] ?> → <?= $h['after_quantity'] ?> <?= h($product['unit']) ?></p>
                        <?php if ($h['notes']): ?><p class="text-xs text-gray-500 mt-0.5"><?= h($h['notes']) ?></p><?php endif; ?>
                        <p class="text-xs text-gray-400"><?= formatDateTime($h['created_at']) ?> by <?= h($h['user_name']??'System') ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
