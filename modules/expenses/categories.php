<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('expenses');
$db    = getDB();
$bizId = currentBusinessId();
$errors = [];
$editCat = null;

if (isset($_GET['delete']) && isset($_GET['csrf'])) {
    if (!hash_equals(csrfToken(), get('csrf'))) { flash('error','Invalid.'); redirect(url('expenses/categories')); }
    $delId = (int)get('delete');
    $db->prepare('DELETE FROM expense_categories WHERE id=? AND business_id=?')->execute([$delId,$bizId]);
    flash('success','Category deleted.');
    redirect(url('expenses/categories'));
}
if (isset($_GET['edit'])) {
    $es = $db->prepare('SELECT * FROM expense_categories WHERE id=? AND business_id=?');
    $es->execute([(int)get('edit'),$bizId]);
    $editCat = $es->fetch();
}
if (isPost()) {
    verifyCsrf();
    $name  = post('name');
    $catId = (int)post('edit_id') ?: null;
    if (empty($name)) $errors[] = 'Name required.';
    if (empty($errors)) {
        if ($catId) {
            $db->prepare('UPDATE expense_categories SET name=? WHERE id=? AND business_id=?')->execute([$name,$catId,$bizId]);
        } else {
            $db->prepare('INSERT INTO expense_categories (business_id,name) VALUES (?,?)')->execute([$bizId,$name]);
        }
        flash('success','Saved.'); redirect(url('expenses/categories'));
    }
}
$catsQ = $db->prepare('SELECT ec.*, (SELECT COUNT(*) FROM expenses WHERE category_id=ec.id) AS usage_count FROM expense_categories ec WHERE ec.business_id=? ORDER BY ec.name');
$catsQ->execute([$bizId]);
$cats = $catsQ->fetchAll();

$pageTitle = 'Expense Categories';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2">
        <h2 class="text-xl font-bold text-gray-800 mb-4">Expense Categories</h2>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr><th class="px-4 py-3 font-medium text-left">#</th><th class="px-4 py-3 font-medium text-left">Name</th><th class="px-4 py-3 font-medium text-center">Used</th><th class="px-4 py-3 font-medium text-center">Actions</th></tr>
                </thead>
                <tbody class="divide-y">
                    <?php if (empty($cats)): ?>
                    <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">No categories yet.</td></tr>
                    <?php else: ?>
                    <?php foreach ($cats as $i => $c): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-gray-400"><?= $i+1 ?></td>
                        <td class="px-4 py-3 font-medium"><?= h($c['name']) ?></td>
                        <td class="px-4 py-3 text-center"><span class="bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full text-xs"><?= $c['usage_count'] ?></span></td>
                        <td class="px-4 py-3 text-center">
                            <div class="flex justify-center gap-2">
                                <a href="?edit=<?= $c['id'] ?>" class="text-blue-600 p-1"><i class="fa-solid fa-pen"></i></a>
                                <a href="?delete=<?= $c['id'] ?>&csrf=<?= csrfToken() ?>" class="text-red-500 p-1" onclick="return confirm('Delete?')"><i class="fa-solid fa-trash"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h3 class="font-semibold text-gray-800 mb-4"><?= $editCat?'Edit':'Add' ?> Category</h3>
            <?php if ($errors): ?><div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-4"><?php foreach ($errors as $e): ?><p class="text-red-700 text-sm"><?= h($e) ?></p><?php endforeach; ?></div><?php endif; ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <?php if ($editCat): ?><input type="hidden" name="edit_id" value="<?= $editCat['id'] ?>"><?php endif; ?>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Name *</label>
                    <input type="text" name="name" value="<?= h($editCat['name']??'') ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded-lg text-sm font-medium hover:bg-blue-700"><?= $editCat?'Update':'Add' ?> Category</button>
                <?php if ($editCat): ?><a href="categories.php" class="block text-center mt-2 text-gray-500 text-sm hover:underline">Cancel</a><?php endif; ?>
            </form>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
