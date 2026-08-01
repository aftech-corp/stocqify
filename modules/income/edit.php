<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('expenses');

$db    = getDB();
$bizId = currentBusinessId();
$id    = (int)get('id', 0);
$errors = [];

$stmt = $db->prepare('SELECT * FROM income WHERE id=? AND business_id=?');
$stmt->execute([$id, $bizId]);
$income = $stmt->fetch();
if (!$income) {
    flash('error', 'Income record not found.');
    redirect(url('income'));
}

if (isPost()) {
    verifyCsrf();
    $title  = post('title');
    $desc   = post('description');
    $amount = (float)post('amount', 0);
    $method = post('payment_method', 'cash');
    $ref    = post('reference_number');
    $date   = post('income_date', date('Y-m-d'));

    if (empty($title)) $errors[] = 'Title is required.';
    if ($amount <= 0)  $errors[] = 'Amount must be greater than zero.';

    if (empty($errors)) {
        $stmt = $db->prepare('UPDATE income SET title=?,description=?,amount=?,payment_method=?,reference_number=?,income_date=? WHERE id=? AND business_id=?');
        $stmt->execute([$title, $desc?:null, $amount, $method, $ref?:null, $date, $id, $bizId]);
        auditLog('update', 'income', $id, [], compact('title','amount'));
        flash('success', 'Income record updated.');
        redirect(url('income'));
    }
    $income = array_merge($income, compact('title','desc','amount','method','ref','date'));
}

$pageTitle = 'Edit Income';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>
<div class="max-w-2xl mx-auto">
    <div class="flex items-center gap-3 mb-6">
        <a href="index.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
        <h2 class="text-xl font-bold text-gray-800">Edit Income Record</h2>
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
                    <label class="block text-sm font-medium text-gray-700 mb-1">Income Title *</label>
                    <input type="text" name="title" value="<?= h($income['title']) ?>" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount (<?= CURRENCY_SYMBOL ?>) *</label>
                    <input type="number" name="amount" value="<?= h($income['amount']) ?>" min="0.01" step="0.01" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Income Date *</label>
                    <input type="date" name="income_date" value="<?= h($income['income_date']) ?>" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label>
                    <select name="payment_method" class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
                        <?php foreach (['cash'=>'Cash','orange_money'=>'Orange Money','afrimoney'=>'Afrimoney','qmoney'=>'QMoney','bank_transfer'=>'Bank Transfer'] as $val=>$label): ?>
                        <option value="<?= $val ?>" <?= $income['payment_method']===$val?'selected':'' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Reference Number</label>
                    <input type="text" name="reference_number" value="<?= h($income['reference_number']??'') ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea name="description" rows="2"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none resize-none"><?= h($income['description']??'') ?></textarea>
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button type="submit" class="bg-green-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-green-700">
                    <i class="fa-solid fa-save mr-1"></i> Save Changes
                </button>
                <a href="index.php" class="bg-gray-100 text-gray-700 px-6 py-2 rounded-lg text-sm font-medium hover:bg-gray-200">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
