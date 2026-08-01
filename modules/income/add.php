<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('expenses');

$db    = getDB();
$bizId = currentBusinessId();
$errors = [];

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
    if (empty($date))  $errors[] = 'Date is required.';

    if (empty($errors)) {
        $stmt = $db->prepare('INSERT INTO income (business_id,user_id,title,description,amount,payment_method,reference_number,income_date) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([$bizId, currentUser()['id'], $title, $desc?:null, $amount, $method, $ref?:null, $date]);
        auditLog('create', 'income', (int)$db->lastInsertId(), [], compact('title','amount'));
        flash('success', 'Income recorded successfully.');
        redirect(url('income'));
    }
}

$pageTitle = 'Record Income';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>
<div class="max-w-2xl mx-auto">
    <div class="flex items-center gap-3 mb-6">
        <a href="index.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
        <h2 class="text-xl font-bold text-gray-800">Record Income</h2>
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
                    <input type="text" name="title" value="<?= h(post('title')) ?>" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                        placeholder="e.g. Rental Income, Loan Received, Grant...">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount (<?= CURRENCY_SYMBOL ?>) *</label>
                    <input type="number" name="amount" value="<?= h(post('amount','')) ?>" min="0.01" step="0.01" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Income Date *</label>
                    <input type="date" name="income_date" value="<?= h(post('income_date', date('Y-m-d'))) ?>" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label>
                    <select name="payment_method" class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
                        <option value="cash">Cash</option>
                        <option value="orange_money">Orange Money</option>
                        <option value="afrimoney">Afrimoney</option>
                        <option value="qmoney">QMoney</option>
                        <option value="bank_transfer">Bank Transfer</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Reference Number</label>
                    <input type="text" name="reference_number" value="<?= h(post('reference_number')) ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none" placeholder="Optional">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea name="description" rows="2"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none resize-none"
                        placeholder="Optional notes..."><?= h(post('description')) ?></textarea>
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button type="submit" class="bg-green-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-green-700">
                    <i class="fa-solid fa-save mr-1"></i> Save Income
                </button>
                <a href="index.php" class="bg-gray-100 text-gray-700 px-6 py-2 rounded-lg text-sm font-medium hover:bg-gray-200">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
