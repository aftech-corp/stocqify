<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('inventory');

$db    = getDB();
$bizId = currentBusinessId();
$id    = (int)get('id');
$errors = [];

// Ensure new inventory_transactions columns exist (idempotent)
try { $db->exec("ALTER TABLE inventory_transactions ADD COLUMN IF NOT EXISTS supplier_id INT UNSIGNED DEFAULT NULL"); } catch (\Exception $e) {}
try { $db->exec("ALTER TABLE inventory_transactions ADD COLUMN IF NOT EXISTS unit_cost DECIMAL(15,2) DEFAULT NULL"); } catch (\Exception $e) {}
try { $db->exec("ALTER TABLE inventory_transactions ADD COLUMN IF NOT EXISTS purchase_type ENUM('cash','credit') DEFAULT NULL"); } catch (\Exception $e) {}

$stmt = $db->prepare('SELECT * FROM products WHERE id=? AND business_id=?');
$stmt->execute([$id, $bizId]);
$product = $stmt->fetch();
if (!$product) { flash('error', 'Product not found.'); redirect(url('products')); }

// Load history
$histQ = $db->prepare("SELECT it.*, u.name AS user_name FROM inventory_transactions it LEFT JOIN users u ON u.id=it.user_id WHERE it.product_id=? ORDER BY it.created_at DESC LIMIT 20");
$histQ->execute([$id]);
$history = $histQ->fetchAll();

// Load suppliers — gracefully empty if table doesn't exist yet
$suppliers = [];
try {
    $suppQ = $db->prepare('SELECT id, name FROM suppliers WHERE business_id=? AND is_active=1 ORDER BY name');
    $suppQ->execute([$bizId]);
    $suppliers = $suppQ->fetchAll();
} catch (\Throwable $e) {}

if (isPost()) {
    verifyCsrf();
    $type  = post('type');
    $qty   = (float)post('quantity', 0);
    $notes = post('notes');
    $validTypes = ['purchase','adjustment','return','damage'];

    $suppId    = (int)post('supplier_id') ?: null;
    $purchType = in_array(post('purchase_type'), ['cash','credit']) ? post('purchase_type') : 'cash';
    $unitCost  = (float)post('unit_cost', 0);
    $reference = post('reference') ?: null;
    $dueDate   = post('due_date') ?: null;

    if (!in_array($type, $validTypes)) $errors[] = 'Invalid transaction type.';
    if ($qty <= 0) $errors[] = 'Quantity must be greater than 0.';

    if (in_array($type, ['damage']) && $qty > $product['stock_quantity']) {
        $errors[] = 'Quantity exceeds current stock (' . $product['stock_quantity'] . ' ' . $product['unit'] . ').';
    }
    if ($type === 'purchase' && $suppId && $purchType === 'credit' && $unitCost <= 0) {
        $errors[] = 'Unit cost is required to record a credit purchase with a supplier.';
    }

    if (empty($errors)) {
        $before = $product['stock_quantity'];
        $after  = in_array($type, ['damage']) ? max(0, $before - $qty) : $before + $qty;

        $db->beginTransaction();
        try {
            $db->prepare('UPDATE products SET stock_quantity=?, updated_at=NOW() WHERE id=?')->execute([$after, $id]);

            $db->prepare('INSERT INTO inventory_transactions (business_id,product_id,user_id,type,quantity,before_quantity,after_quantity,notes,supplier_id,unit_cost,purchase_type) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute(
                [$bizId, $id, currentUser()['id'], $type, $qty, $before, $after, $notes ?: null,
                 $type === 'purchase' ? $suppId : null,
                 $type === 'purchase' && $unitCost > 0 ? $unitCost : null,
                 $type === 'purchase' && $suppId ? $purchType : null]
            );

            // Create supplier purchase record for restock purchases
            if ($type === 'purchase' && $suppId && $unitCost > 0) {
                $totalAmt  = round($qty * $unitCost, 2);
                $amtPaid   = $purchType === 'cash' ? $totalAmt : 0.00;
                $balance   = $totalAmt - $amtPaid;
                $payStatus = $purchType === 'cash' ? 'paid' : 'unpaid';
                $purDesc   = $product['name'] . ' — restock (' . number_format($qty, 2) . ' ' . $product['unit'] . ')';

                $db->prepare('INSERT INTO supplier_purchases (business_id, supplier_id, user_id, reference, description, total_amount, amount_paid, balance, payment_status, purchase_date, due_date) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                   ->execute([$bizId, $suppId, currentUser()['id'], $reference, $purDesc, $totalAmt, $amtPaid, $balance, $payStatus, date('Y-m-d'), $purchType === 'credit' ? $dueDate : null]);

                if ($purchType === 'cash') {
                    $purId = (int)$db->lastInsertId();
                    $db->prepare('INSERT INTO supplier_payments (business_id, supplier_id, purchase_id, user_id, amount, payment_method, reference_number, notes, payment_date) VALUES (?,?,?,?,?,?,?,?,?)')
                       ->execute([$bizId, $suppId, $purId, currentUser()['id'], $totalAmt, 'cash', $reference, 'Restock purchase', date('Y-m-d')]);
                }
            }

            $db->commit();
            flash('success', 'Stock adjusted. New quantity: ' . number_format($after, 2) . ' ' . $product['unit']);
            redirect(url('products/adjust') . '?id=' . $id);
        } catch (\Exception $e) {
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
                        <select name="type" id="adj_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                            <option value="purchase" <?= post('type')==='purchase'?'selected':'' ?>>Purchase / Restock (+)</option>
                            <option value="adjustment" <?= post('type')==='adjustment'?'selected':'' ?>>Manual Adjustment (+)</option>
                            <option value="return" <?= post('type')==='return'?'selected':'' ?>>Customer Return (+)</option>
                            <option value="damage" <?= post('type')==='damage'?'selected':'' ?>>Damage / Loss (-)</option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Quantity</label>
                        <input type="number" name="quantity" value="<?= h(post('quantity')) ?>" min="0.01" step="0.01" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                        <textarea name="notes" rows="2"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none resize-none"
                            placeholder="Optional reason..."><?= h(post('notes')) ?></textarea>
                    </div>

                    <!-- Supplier section — shown only when type = purchase -->
                    <div id="supplier-section" class="hidden border-t border-gray-100 pt-4 mb-4 space-y-3">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Supplier &amp; Purchase Details <span class="font-normal normal-case text-gray-400">(optional)</span></p>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Supplier</label>
                            <select name="supplier_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                                <option value="">-- No Supplier --</option>
                                <?php foreach ($suppliers as $sup): ?>
                                <option value="<?= $sup['id'] ?>" <?= post('supplier_id')==$sup['id']?'selected':'' ?>><?= h($sup['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($suppliers)): ?>
                            <p class="text-xs text-gray-400 mt-1"><a href="<?= url('suppliers/add') ?>" class="underline text-indigo-600">Add a supplier</a> to track purchases against them.</p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Unit Cost (<?= currencySymbol() ?>)</label>
                            <input type="number" name="unit_cost" value="<?= h(post('unit_cost', $product['cost_price'])) ?>" min="0" step="0.01"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none"
                                placeholder="Cost per unit for this purchase">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Purchase Type</label>
                            <select name="purchase_type" id="adj_purchase_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                                <option value="cash" <?= post('purchase_type','cash')==='cash'?'selected':'' ?>>Cash — Paid Now</option>
                                <option value="credit" <?= post('purchase_type')==='credit'?'selected':'' ?>>Credit — Pay Later</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Reference / Invoice No.</label>
                            <input type="text" name="reference" value="<?= h(post('reference')) ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none"
                                placeholder="Optional">
                        </div>
                        <div id="adj-due-date-row" class="hidden">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Payment Due Date</label>
                            <input type="date" name="due_date" value="<?= h(post('due_date')) ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                        </div>
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
<script>
(function () {
    const typeEl      = document.getElementById('adj_type');
    const suppSection = document.getElementById('supplier-section');
    const purchTypeEl = document.getElementById('adj_purchase_type');
    const dueDateRow  = document.getElementById('adj-due-date-row');

    function toggleSupplierSection() {
        suppSection.classList.toggle('hidden', typeEl.value !== 'purchase');
    }
    function toggleDueDate() {
        if (!purchTypeEl) return;
        dueDateRow.classList.toggle('hidden', purchTypeEl.value !== 'credit');
    }

    typeEl.addEventListener('change', toggleSupplierSection);
    if (purchTypeEl) purchTypeEl.addEventListener('change', toggleDueDate);

    toggleSupplierSection();
    toggleDueDate();
})();
</script>
<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
