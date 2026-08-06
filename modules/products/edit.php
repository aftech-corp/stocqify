<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
require_once __DIR__ . '/../../app/includes/image_uploader.php';
requirePermission('inventory');

$db    = getDB();
$bizId = currentBusinessId();
$id    = (int)get('id');
$errors = [];

// Ensure image column exists
try { $db->exec("ALTER TABLE products ADD COLUMN IF NOT EXISTS image VARCHAR(255) DEFAULT NULL"); } catch (\Exception $e) {}

$stmt = $db->prepare('SELECT * FROM products WHERE id=? AND business_id=?');
$stmt->execute([$id, $bizId]);
$product = $stmt->fetch();
if (!$product) { flash('error', 'Product not found.'); redirect(url('products')); }

$catsQ = $db->prepare('SELECT id, name FROM categories WHERE business_id=? ORDER BY name');
$catsQ->execute([$bizId]);
$categories = $catsQ->fetchAll();

function _uploadProductImg(array $file, int $prodId): ?string {
    $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
    if (!in_array($file['type'], $allowed) || $file['size'] > 2 * 1024 * 1024) return null;
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $name = 'prod_' . $prodId . '_' . time() . '.' . $ext;
    $dest = __DIR__ . '/../../uploads/products/' . $name;
    return move_uploaded_file($file['tmp_name'], $dest) ? 'uploads/products/' . $name : null;
}

if (isPost()) {
    verifyCsrf();
    $old     = $product;
    $name    = post('name');
    $sku     = post('sku');
    $catId   = (int)post('category_id') ?: null;
    $unit    = post('unit', 'piece');
    $cost    = (float)post('cost_price', 0);
    $sell    = (float)post('selling_price', 0);
    $reorder = (float)post('reorder_level', 5);
    $maxStock= post('max_stock') !== '' ? (float)post('max_stock') : null;
    $desc    = post('description');

    if (empty($name)) $errors[] = 'Product name is required.';
    if ($sell <= 0)   $errors[] = 'Selling price must be > 0.';

    if (empty($errors)) {
        $curImage = $product['image'] ?? null;

        // New upload
        if (!empty($_FILES['image']['tmp_name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $uploaded = _uploadProductImg($_FILES['image'], $id);
            if ($uploaded) {
                if ($curImage) @unlink(__DIR__ . '/../../' . $curImage);
                $curImage = $uploaded;
            }
        }
        // Remove existing
        if (post('remove_image') === '1' && $curImage) {
            @unlink(__DIR__ . '/../../' . $curImage);
            $curImage = null;
        }

        $db->prepare('UPDATE products SET category_id=?,name=?,sku=?,description=?,unit=?,cost_price=?,selling_price=?,reorder_level=?,max_stock=?,image=?,updated_at=NOW() WHERE id=? AND business_id=?')
           ->execute([$catId,$name,$sku?:null,$desc?:null,$unit,$cost,$sell,$reorder,$maxStock,$curImage,$id,$bizId]);

        auditLog('update','products',$id,$old,compact('name','cost','sell','reorder'));
        flash('success','Product updated successfully.');
        redirect(url('products'));
    }
}

$f = isPost() ? $_POST : $product;
$existingImgUrl = (!empty($product['image']) && file_exists(__DIR__ . '/../../' . $product['image']))
    ? APP_URL . '/' . $product['image'] : '';

$pageTitle = 'Edit Product';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center gap-3 mb-6">
    <a href="index.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
    <h2 class="text-xl font-bold text-gray-800">Edit Product: <?= h($product['name']) ?></h2>
</div>
<?php if ($errors): ?>
<div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
    <?php foreach ($errors as $e): ?><p class="text-red-700 text-sm"><?= h($e) ?></p><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">Product Name *</label>
                <input type="text" name="name" value="<?= h($f['name'] ?? '') ?>" required
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">SKU</label>
                <input type="text" name="sku" value="<?= h($f['sku'] ?? '') ?>"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                <select name="category_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    <option value="">-- None --</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= ($f['category_id']??'')==$cat['id']?'selected':'' ?>><?= h($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Unit</label>
                <select name="unit" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    <?php foreach (['piece','bag','bottle','can','box','pack','tin','kg','litre','carton','dozen','pair'] as $u): ?>
                    <option value="<?= $u ?>" <?= ($f['unit']??'piece')===$u?'selected':'' ?>><?= ucfirst($u) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Cost Price (<?= currencySymbol() ?>)</label>
                <input type="number" name="cost_price" value="<?= h($f['cost_price'] ?? '0') ?>" min="0" step="0.01"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Selling Price (<?= currencySymbol() ?>) *</label>
                <input type="number" name="selling_price" value="<?= h($f['selling_price'] ?? '0') ?>" min="0" step="0.01" required
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Reorder Level</label>
                <input type="number" name="reorder_level" value="<?= h($f['reorder_level'] ?? '5') ?>" min="0" step="0.01"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Max Stock</label>
                <input type="number" name="max_stock" value="<?= h($f['max_stock'] ?? '') ?>" min="0" step="0.01"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea name="description" rows="2"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none resize-none"><?= h($f['description'] ?? '') ?></textarea>
            </div>
        </div>
        <div class="mt-3 p-3 bg-amber-50 rounded-lg text-sm text-amber-700">
            <i class="fa-solid fa-info-circle mr-1"></i>
            Current Stock: <strong><?= number_format($product['stock_quantity'], 2) ?> <?= h($product['unit']) ?></strong>.
            Use <a href="adjust.php?id=<?= $id ?>" class="underline">Stock Adjustment</a> to update quantity.
        </div>
        <div class="flex gap-3 mt-6">
            <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
                <i class="fa-solid fa-save mr-1"></i> Update Product
            </button>
            <a href="index.php" class="bg-gray-100 text-gray-700 px-6 py-2 rounded-lg text-sm font-medium hover:bg-gray-200">Cancel</a>
        </div>
    </form>
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
