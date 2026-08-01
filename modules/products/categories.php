<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('inventory');

$db    = getDB();
$bizId = currentBusinessId();
$errors = [];
$editCat = null;

// Handle delete
if (isset($_GET['delete']) && isset($_GET['csrf'])) {
    if (!hash_equals(csrfToken(), get('csrf'))) { flash('error','Invalid request.'); redirect(url('products/categories')); }
    $delId = (int)get('delete');
    // Check if category has products
    $check = $db->prepare('SELECT COUNT(*) FROM products WHERE category_id=? AND business_id=?');
    $check->execute([$delId, $bizId]);
    if ($check->fetchColumn() > 0) {
        flash('error', 'Cannot delete: category has products. Reassign them first.');
    } else {
        $db->prepare('DELETE FROM categories WHERE id=? AND business_id=?')->execute([$delId, $bizId]);
        flash('success', 'Category deleted.');
    }
    redirect(url('products/categories'));
}

// Load for edit
if (isset($_GET['edit'])) {
    $editId = (int)get('edit');
    $eStmt  = $db->prepare('SELECT * FROM categories WHERE id=? AND business_id=?');
    $eStmt->execute([$editId, $bizId]);
    $editCat = $eStmt->fetch();
}

// Handle save
if (isPost()) {
    verifyCsrf();
    $catName = post('name');
    $catDesc = post('description');
    $catId   = (int)post('edit_id') ?: null;
    if (empty($catName)) $errors[] = 'Category name is required.';
    if (empty($errors)) {
        if ($catId) {
            $db->prepare('UPDATE categories SET name=?,description=?,created_at=created_at WHERE id=? AND business_id=?')->execute([$catName,$catDesc?:null,$catId,$bizId]);
            flash('success','Category updated.');
        } else {
            $db->prepare('INSERT INTO categories (business_id, name, description) VALUES (?,?,?)')->execute([$bizId,$catName,$catDesc?:null]);
            flash('success','Category added.');
        }
        redirect(url('products/categories'));
    }
}

$catsQ = $db->prepare('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id=c.id AND p.business_id=c.business_id) AS product_count FROM categories c WHERE c.business_id=? ORDER BY c.name');
$catsQ->execute([$bizId]);
$categories = $catsQ->fetchAll();

$pageTitle = 'Product Categories';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-xl font-bold text-gray-800">Product Categories</h2>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3 font-medium text-left">#</th>
                        <th class="px-4 py-3 font-medium text-left">Name</th>
                        <th class="px-4 py-3 font-medium text-left">Description</th>
                        <th class="px-4 py-3 font-medium text-center">Products</th>
                        <th class="px-4 py-3 font-medium text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php if (empty($categories)): ?>
                    <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">No categories yet.</td></tr>
                    <?php else: ?>
                    <?php foreach ($categories as $i => $cat): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-gray-400"><?= $i+1 ?></td>
                        <td class="px-4 py-3 font-medium text-gray-800"><?= h($cat['name']) ?></td>
                        <td class="px-4 py-3 text-gray-500 text-xs"><?= h($cat['description'] ?? '-') ?></td>
                        <td class="px-4 py-3 text-center"><span class="bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full text-xs"><?= $cat['product_count'] ?></span></td>
                        <td class="px-4 py-3 text-center">
                            <div class="flex justify-center gap-2">
                                <a href="?edit=<?= $cat['id'] ?>" class="text-blue-600 p-1 hover:text-blue-800"><i class="fa-solid fa-pen"></i></a>
                                <a href="?delete=<?= $cat['id'] ?>&csrf=<?= csrfToken() ?>" class="text-red-500 p-1 hover:text-red-700" onclick="return confirm('Delete this category?')"><i class="fa-solid fa-trash"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add/Edit Form -->
    <div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h3 class="font-semibold text-gray-800 mb-4"><?= $editCat ? 'Edit Category' : 'Add Category' ?></h3>
            <?php if ($errors): ?>
            <div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-4">
                <?php foreach ($errors as $e): ?><p class="text-red-700 text-sm"><?= h($e) ?></p><?php endforeach; ?>
            </div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <?php if ($editCat): ?>
                <input type="hidden" name="edit_id" value="<?= $editCat['id'] ?>">
                <?php endif; ?>
                <div class="mb-3">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Category Name *</label>
                    <input type="text" name="name" value="<?= h($editCat['name'] ?? post('name')) ?>" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea name="description" rows="2"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none resize-none"><?= h($editCat['description'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
                    <?= $editCat ? 'Update Category' : 'Add Category' ?>
                </button>
                <?php if ($editCat): ?>
                <a href="categories.php" class="block text-center mt-2 text-gray-500 text-sm hover:underline">Cancel</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
