<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('expenses');

$db    = getDB();
$bizId = currentBusinessId();
$errors = [];

if (isPost()) {
    verifyCsrf();
    $amount  = (float)post('amount', 0);
    $desc    = post('description');
    $method  = post('payment_method', 'cash');
    $date    = post('drawing_date', date('Y-m-d'));
    $notes   = post('notes');
    $validMethods = ['cash','orange_money','afrimoney','qmoney','bank_transfer'];

    if ($amount <= 0)  $errors[] = 'Amount must be greater than 0.';
    if (empty($desc))  $errors[] = 'Description is required.';
    if (!in_array($method, $validMethods)) $method = 'cash';

    if (empty($errors)) {
        $stmt = $db->prepare('INSERT INTO drawings (business_id,user_id,amount,description,payment_method,drawing_date,notes,branch_id) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([$bizId, currentUser()['id'], $amount, $desc, $method, $date, $notes?:null, currentBranchId()]);
        auditLog('create','drawings',(int)$db->lastInsertId(),[],compact('amount','desc'));
        flash('success', 'Drawing of ' . formatMoney($amount) . ' recorded.');
        redirect(url('drawings'));
    }
}

$pageTitle = 'Record Drawing';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div>
    <div class="flex items-center gap-3 mb-6">
        <a href="index.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
        <h2 class="text-xl font-bold text-gray-800">Record Owner Drawing</h2>
    </div>

    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-5 text-sm text-amber-800">
        <i class="fa-solid fa-circle-info mr-2"></i>
        A <strong>drawing</strong> is cash or assets taken out of the business by the owner for personal use. It reduces business capital and is not a business expense.
    </div>

    <?php if ($errors): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
        <?php foreach ($errors as $e): ?><p class="text-red-700 text-sm"><?= h($e) ?></p><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount (<?= currencySymbol() ?>) *</label>
                    <input type="number" name="amount" value="<?= h(post('amount','')) ?>" min="0.01" step="0.01" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description *</label>
                    <input type="text" name="description" value="<?= h(post('description')) ?>" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 outline-none"
                        placeholder="e.g. Personal expenses, School fees, Home utilities...">
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
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                    <input type="date" name="drawing_date" value="<?= h(post('drawing_date', date('Y-m-d'))) ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                    <textarea name="notes" rows="2"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-purple-500 outline-none resize-none"
                        placeholder="Optional details..."><?= h(post('notes')) ?></textarea>
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
                    <i class="fa-solid fa-save mr-1"></i> Save Drawing
                </button>
                <a href="index.php" class="bg-gray-100 text-gray-700 px-6 py-2 rounded-lg text-sm font-medium hover:bg-gray-200">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
