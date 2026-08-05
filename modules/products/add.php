<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
require_once __DIR__ . '/../../app/includes/image_uploader.php';
requirePermission('inventory');

$db    = getDB();
$bizId = currentBusinessId();
$errors = [];

// Ensure columns exist (idempotent)
try { $db->exec("ALTER TABLE products ADD COLUMN IF NOT EXISTS image VARCHAR(255) DEFAULT NULL"); } catch (\Exception $e) {}
try { $db->exec("ALTER TABLE inventory_transactions ADD COLUMN IF NOT EXISTS supplier_id INT UNSIGNED DEFAULT NULL"); } catch (\Exception $e) {}
try { $db->exec("ALTER TABLE inventory_transactions ADD COLUMN IF NOT EXISTS unit_cost DECIMAL(15,2) DEFAULT NULL"); } catch (\Exception $e) {}
try { $db->exec("ALTER TABLE inventory_transactions ADD COLUMN IF NOT EXISTS purchase_type ENUM('cash','credit') DEFAULT NULL"); } catch (\Exception $e) {}

function _uploadProductImg(array $file, int $prodId): ?string {
    $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
    if (!in_array($file['type'], $allowed) || $file['size'] > 2 * 1024 * 1024) return null;
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $name = 'prod_' . $prodId . '_' . time() . '.' . $ext;
    $dest = __DIR__ . '/../../uploads/products/' . $name;
    return move_uploaded_file($file['tmp_name'], $dest) ? 'uploads/products/' . $name : null;
}

$catsQ = $db->prepare('SELECT id, name FROM categories WHERE business_id=? ORDER BY name');
$catsQ->execute([$bizId]);
$categories = $catsQ->fetchAll();

// Load suppliers — gracefully empty if table doesn't exist yet
$suppliers = [];
try {
    $suppQ = $db->prepare('SELECT id, name FROM suppliers WHERE business_id=? AND is_active=1 ORDER BY name');
    $suppQ->execute([$bizId]);
    $suppliers = $suppQ->fetchAll();
} catch (\Throwable $e) {}

if (isPost()) {
    verifyCsrf();
    $name     = post('name');
    $sku      = post('sku');
    $catId    = (int)post('category_id') ?: null;
    $unit     = post('unit', 'piece');
    $cost     = (float)post('cost_price', 0);
    $sell     = (float)post('selling_price', 0);
    $qty      = (float)post('stock_quantity', 0);
    $reorder  = (float)post('reorder_level', 5);
    $maxStock = post('max_stock') !== '' ? (float)post('max_stock') : null;
    $desc     = post('description');

    $suppId    = (int)post('supplier_id') ?: null;
    $purchType = in_array(post('purchase_type'), ['cash','credit']) ? post('purchase_type') : 'cash';
    $reference = post('reference') ?: null;
    $dueDate   = post('due_date') ?: null;

    if (empty($name)) $errors[] = 'Product name is required.';
    if ($sell <= 0)   $errors[] = 'Selling price must be greater than 0.';
    if ($cost < 0)    $errors[] = 'Cost price cannot be negative.';
    if ($suppId && $qty > 0 && $purchType === 'credit' && $cost <= 0) {
        $errors[] = 'Cost price is required to record a credit purchase with a supplier.';
    }

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('INSERT INTO products (business_id, category_id, name, sku, description, unit, cost_price, selling_price, stock_quantity, reorder_level, max_stock, branch_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$bizId, $catId, $name, $sku?:null, $desc?:null, $unit, $cost, $sell, $qty, $reorder, $maxStock, currentBranchId()]);
            $prodId = (int)$db->lastInsertId();

            if (!empty($_FILES['image']['tmp_name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $imgPath = _uploadProductImg($_FILES['image'], $prodId);
                if ($imgPath) {
                    $db->prepare('UPDATE products SET image=? WHERE id=?')->execute([$imgPath, $prodId]);
                }
            }

            if ($qty > 0) {
                $db->prepare('INSERT INTO inventory_transactions (business_id, product_id, user_id, type, quantity, before_quantity, after_quantity, notes, supplier_id, unit_cost, purchase_type) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                   ->execute([$bizId, $prodId, currentUser()['id'], 'purchase', $qty, 0, $qty, 'Initial stock',
                              $suppId, $cost > 0 ? $cost : null, $suppId ? $purchType : null]);
            }

            // Create supplier purchase record when supplier and cost price are both provided
            if ($suppId && $qty > 0 && $cost > 0) {
                $totalAmt  = round($qty * $cost, 2);
                $amtPaid   = $purchType === 'cash' ? $totalAmt : 0.00;
                $balance   = $totalAmt - $amtPaid;
                $payStatus = $purchType === 'cash' ? 'paid' : 'unpaid';
                $purDesc   = $name . ' — initial stock (' . number_format($qty, 2) . ' ' . $unit . ')';

                $db->prepare('INSERT INTO supplier_purchases (business_id, supplier_id, user_id, reference, description, total_amount, amount_paid, balance, payment_status, purchase_date, due_date) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                   ->execute([$bizId, $suppId, currentUser()['id'], $reference, $purDesc, $totalAmt, $amtPaid, $balance, $payStatus, date('Y-m-d'), $purchType === 'credit' ? $dueDate : null]);

                if ($purchType === 'cash') {
                    $purId = (int)$db->lastInsertId();
                    $db->prepare('INSERT INTO supplier_payments (business_id, supplier_id, purchase_id, user_id, amount, payment_method, reference_number, notes, payment_date) VALUES (?,?,?,?,?,?,?,?,?)')
                       ->execute([$bizId, $suppId, $purId, currentUser()['id'], $totalAmt, 'cash', $reference, 'Initial stock purchase', date('Y-m-d')]);
                }
            }

            $db->commit();
            auditLog('create', 'products', $prodId, [], compact('name','sku','qty'));
            flash('success', "Product '{$name}' added successfully.");
            redirect(url('products'));
        } catch (\Exception $e) {
            $db->rollBack();
            $errors[] = 'Failed to save product. Please try again.';
        }
    }
}

$pageTitle = 'Add Product';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center gap-3 mb-6">
    <a href="index.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
    <h2 class="text-xl font-bold text-gray-800">Add New Product</h2>
</div>

<?php if ($errors): ?>
<div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
    <?php foreach ($errors as $e): ?><p class="text-red-700 text-sm"><?= h($e) ?></p><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

<div class="grid grid-cols-1 lg:grid-cols-4 gap-6">

    <!-- Product details -->
    <div class="lg:col-span-3 space-y-4">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <h3 class="font-semibold text-gray-700 mb-4">Product Details</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Product Name *</label>
                    <input type="text" name="name" value="<?= h(post('name')) ?>" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                        placeholder="e.g. Rice 25kg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">SKU / Code</label>
                    <input type="text" name="sku" value="<?= h(post('sku')) ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                        placeholder="Optional">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                    <select name="category_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        <option value="">-- Select Category --</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= post('category_id')==$cat['id']?'selected':'' ?>><?= h($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Unit</label>
                    <select name="unit" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        <?php foreach (['piece','bag','bottle','can','box','pack','tin','kg','litre','carton','dozen','pair'] as $u): ?>
                        <option value="<?= $u ?>" <?= post('unit','piece')===$u?'selected':'' ?>><?= ucfirst($u) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Cost Price (<?= currencySymbol() ?>)</label>
                    <input type="number" name="cost_price" id="cost_price" value="<?= h(post('cost_price','0')) ?>" min="0" step="0.01"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Selling Price (<?= currencySymbol() ?>) *</label>
                    <input type="number" name="selling_price" value="<?= h(post('selling_price','0')) ?>" min="0" step="0.01" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Opening Stock</label>
                    <input type="number" name="stock_quantity" id="stock_quantity" value="<?= h(post('stock_quantity','0')) ?>" min="0" step="0.01"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Reorder Level</label>
                    <input type="number" name="reorder_level" value="<?= h(post('reorder_level','5')) ?>" min="0" step="0.01"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Max Stock <span class="text-gray-400 font-normal">(optional)</span></label>
                    <input type="number" name="max_stock" value="<?= h(post('max_stock','')) ?>" min="0" step="0.01"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea name="description" rows="2"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none resize-none"><?= h(post('description')) ?></textarea>
                </div>
            </div>
        </div>

        <!-- Purchase Details — shown when opening stock > 0 -->
        <div id="purchase-section" class="bg-white rounded-xl shadow-sm border border-indigo-100 p-6 hidden">
            <div class="flex items-center gap-2 mb-4">
                <i class="fa-solid fa-truck text-indigo-500"></i>
                <h3 class="font-semibold text-gray-700">Purchase Details</h3>
                <span class="text-sm font-normal text-gray-400 ml-1">— optional, link this stock to a supplier</span>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
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
                    <label class="block text-sm font-medium text-gray-700 mb-1">Purchase Type</label>
                    <select name="purchase_type" id="purchase_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
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
                <div id="due-date-row" class="hidden">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Due Date</label>
                    <input type="date" name="due_date" value="<?= h(post('due_date')) ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                </div>
                <div class="md:col-span-2">
                    <div class="bg-indigo-50 rounded-lg p-3 text-sm text-indigo-700">
                        <i class="fa-solid fa-circle-info mr-1"></i>
                        Purchase total = <strong>Opening Stock × Cost Price</strong>. A supplier purchase record will be created automatically when both a supplier and a cost price are provided.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Image upload -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <?= renderImageUploader('prod', 'image', 'Product Image <span class="text-gray-400 text-xs font-normal">(optional)</span>', '', '', '220px') ?>
        </div>
    </div>
</div>

<div class="flex gap-3 mt-6">
    <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
        <i class="fa-solid fa-save mr-1"></i> Save Product
    </button>
    <a href="index.php" class="bg-gray-100 text-gray-700 px-6 py-2 rounded-lg text-sm font-medium hover:bg-gray-200">Cancel</a>
</div>

</form>

<script>
(function () {
    const stockInput   = document.getElementById('stock_quantity');
    const purchSection = document.getElementById('purchase-section');
    const purchTypeEl  = document.getElementById('purchase_type');
    const dueDateRow   = document.getElementById('due-date-row');

    function togglePurchaseSection() {
        const qty = parseFloat(stockInput.value) || 0;
        purchSection.classList.toggle('hidden', qty <= 0);
    }
    function toggleDueDate() {
        if (!purchTypeEl) return;
        dueDateRow.classList.toggle('hidden', purchTypeEl.value !== 'credit');
    }

    stockInput.addEventListener('input', togglePurchaseSection);
    if (purchTypeEl) purchTypeEl.addEventListener('change', toggleDueDate);

    togglePurchaseSection();
    toggleDueDate();
})();
</script>

<?php imageUploaderAssets(); ?>
<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
