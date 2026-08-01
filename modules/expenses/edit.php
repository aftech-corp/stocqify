<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('expenses');
$db    = getDB();
$bizId = currentBusinessId();
$id    = (int)get('id');
$errors = [];
$stmt  = $db->prepare('SELECT * FROM expenses WHERE id=? AND business_id=?');
$stmt->execute([$id,$bizId]);
$exp = $stmt->fetch();
if (!$exp) { flash('error','Expense not found.'); redirect(url('expenses')); }
$catsQ = $db->prepare('SELECT id, name FROM expense_categories WHERE business_id=? ORDER BY name');
$catsQ->execute([$bizId]);
$categories = $catsQ->fetchAll();
if (isPost()) {
    verifyCsrf();
    $title  = post('title');
    $catId  = (int)post('category_id') ?: null;
    $amount = (float)post('amount', 0);
    $method = post('payment_method','cash');
    $ref    = post('reference_number');
    $desc   = post('description');
    $date   = post('expense_date', date('Y-m-d'));
    if (empty($title)) $errors[] = 'Title is required.';
    if ($amount <= 0)  $errors[] = 'Amount must be > 0.';
    if (empty($errors)) {
        $db->prepare('UPDATE expenses SET category_id=?,title=?,description=?,amount=?,payment_method=?,reference_number=?,expense_date=?,updated_at=NOW() WHERE id=? AND business_id=?')->execute([$catId,$title,$desc?:null,$amount,$method,$ref?:null,$date,$id,$bizId]);
        flash('success','Expense updated.');
        redirect(url('expenses'));
    }
}
$f = isPost() ? $_POST : $exp;
$pageTitle = 'Edit Expense';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>
<div class="max-w-2xl mx-auto">
    <div class="flex items-center gap-3 mb-6">
        <a href="index.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
        <h2 class="text-xl font-bold text-gray-800">Edit Expense</h2>
    </div>
    <?php if ($errors): ?><div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4"><?php foreach ($errors as $e): ?><p class="text-red-700 text-sm"><?= h($e) ?></p><?php endforeach; ?></div><?php endif; ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Title *</label>
                    <input type="text" name="title" value="<?= h($f['title']??'') ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                    <select name="category_id" class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
                        <option value="">-- None --</option>
                        <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>" <?= ($f['category_id']??'')==$c['id']?'selected':'' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount *</label>
                    <input type="number" name="amount" value="<?= h($f['amount']??'') ?>" min="0.01" step="0.01" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label>
                    <select name="payment_method" class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
                        <?php foreach (['cash','orange_money','afrimoney','qmoney','bank_transfer'] as $m): ?><option value="<?= $m ?>" <?= ($f['payment_method']??'cash')===$m?'selected':'' ?>><?= ucwords(str_replace('_',' ',$m)) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                    <input type="date" name="expense_date" value="<?= h($f['expense_date']??date('Y-m-d')) ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea name="description" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none resize-none"><?= h($f['description']??'') ?></textarea>
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-blue-700"><i class="fa-solid fa-save mr-1"></i> Update</button>
                <a href="index.php" class="bg-gray-100 text-gray-700 px-6 py-2 rounded-lg text-sm font-medium hover:bg-gray-200">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
