<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
require_once __DIR__ . '/../../app/includes/image_uploader.php';
requirePermission('inventory');

$db    = getDB();
$bizId = currentBusinessId();
$errors = [];

// Ensure image column exists (idempotent)
try { $db->exec("ALTER TABLE products ADD COLUMN IF NOT EXISTS image VARCHAR(255) DEFAULT NULL"); } catch (\Exception $e) {}

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

if (isPost()) {
    verifyCsrf();
    $name    = post('name');
    $sku     = post('sku');
    $catId   = (int)post('category_id') ?: null;
    $unit    = post('unit', 'piece');
    $cost    = (float)post('cost_price', 0);
    $sell    = (float)post('selling_price', 0);
    $qty     = (float)post('stock_quantity', 0);
    $reorder = (float)post('reorder_level', 5);
    $maxStock= post('max_stock') !== '' ? (float)post('max_stock') : null;
    $desc    = post('description');

    if (empty($name)) $errors[] = 'Product name is required.';
    if ($sell <= 0) $errors[] = 'Selling price must be greater than 0.';
    if ($cost < 0)  $errors[] = 'Cost price cannot be negative.';

    if (empty($errors)) {
        $stmt = $db->prepare('INSERT INTO products (business_id, category_id, name, sku, description, unit, cost_price, selling_price, stock_quantity, reorder_level, max_stock) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$bizId, $catId, $name, $sku?:null, $desc?:null, $unit, $cost, $sell, $qty, $reorder, $maxStock]);
        $prodId = (int)$db->lastInsertId();

        // Handle image upload
        if (!empty($_FILES['image']['tmp_name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $imgPath = _uploadProductImg($_FILES['image'], $prodId);
            if ($imgPath) {
                $db->prepare('UPDATE products SET image=? WHERE id=?')->execute([$imgPath, $prodId]);
            }
        }

        // Record initial stock
        if ($qty > 0) {
            $db->prepare('INSERT INTO inventory_transactions (business_id, product_id, user_id, type, quantity, before_quantity, after_quantity, notes) VALUES (?,?,?,?,?,?,?,?)')
               ->execute([$bizId, $prodId, currentUser()['id'], 'purchase', $qty, 0, $qty, 'Initial stock']);
        }

        auditLog('create', 'products', $prodId, [], compact('name','sku','qty'));
        flash('success', "Product '{$name}' added successfully.");
        redirect(url('products'));
    }
}

$pageTitle = 'Add Product';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="max-w-3xl mx-auto">
    <div class="flex items-center gap-3 mb-6">
        <a href="index.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
        <h2 class="text-xl font-bold text-gray-800">Add New Product</h2>
    </div>
    <?php if ($errors): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
        <?php foreach ($errors as $e): ?><p class="text-red-700 text-sm"><?= h($e) ?></p><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Image upload (left column on wide screens) -->
        <div class="lg:col-span-1 order-first lg:order-last">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                <?= renderImageUploader('prod', 'image', 'Product Image <span class="text-gray-400 text-xs font-normal">(optional)</span>', '', '', '220px') ?>
            </div>
        </div>

        <!-- Product details -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
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
                            <label class="block text-sm font-medium text-gray-700 mb-1">Cost Price (<?= CURRENCY_SYMBOL ?>)</label>
                            <input type="number" name="cost_price" value="<?= h(post('cost_price','0')) ?>" min="0" step="0.01"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Selling Price (<?= CURRENCY_SYMBOL ?>) *</label>
                            <input type="number" name="selling_price" value="<?= h(post('selling_price','0')) ?>" min="0" step="0.01" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Opening Stock</label>
                            <input type="number" name="stock_quantity" value="<?= h(post('stock_quantity','0')) ?>" min="0" step="0.01"
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
                    <div class="flex gap-3 mt-6">
                        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
                            <i class="fa-solid fa-save mr-1"></i> Save Product
                        </button>
                        <a href="index.php" class="bg-gray-100 text-gray-700 px-6 py-2 rounded-lg text-sm font-medium hover:bg-gray-200">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php imageUploaderAssets(); ?>
<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
