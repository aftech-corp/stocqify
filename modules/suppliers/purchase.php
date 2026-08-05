<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('expenses');

$db     = getDB();
$bizId  = currentBusinessId();
$errors = [];

// Load suppliers for dropdown
$supQ = $db->prepare('SELECT id, name FROM suppliers WHERE business_id=? AND is_active=1 ORDER BY name');
$supQ->execute([$bizId]);
$supplierList = $supQ->fetchAll();

$preSupplier = (int)get('supplier');

if (isPost()) {
    verifyCsrf();
    $supplierId  = (int)post('supplier_id');
    $desc        = post('description');
    $total       = (float)post('total_amount', 0);
    $amountPaid  = (float)post('amount_paid', 0);
    $method      = post('payment_method', 'cash');
    $ref         = post('reference');
    $purDate     = post('purchase_date', date('Y-m-d'));
    $dueDate     = post('due_date') ?: null;
    $notes       = post('notes');
    $validMethods = ['cash','orange_money','afrimoney','qmoney','bank_transfer'];

    if (!$supplierId)            $errors[] = 'Please select a supplier.';
    if (empty($desc))            $errors[] = 'Description is required.';
    if ($total <= 0)             $errors[] = 'Total amount must be greater than 0.';
    if ($amountPaid < 0)         $errors[] = 'Amount paid cannot be negative.';
    if ($amountPaid > $total)    $errors[] = 'Amount paid cannot exceed total amount.';
    if (!in_array($method, $validMethods)) $method = 'cash';

    if (empty($errors)) {
        // Verify supplier belongs to this business
        $supCheck = $db->prepare('SELECT id FROM suppliers WHERE id=? AND business_id=?');
        $supCheck->execute([$supplierId, $bizId]);
        if (!$supCheck->fetch()) { $errors[] = 'Invalid supplier.'; }
    }

    if (empty($errors)) {
        $balance = $total - $amountPaid;
        $status  = $balance <= 0 ? 'paid' : ($amountPaid > 0 ? 'partial' : 'unpaid');

        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO supplier_purchases (business_id,supplier_id,user_id,reference,description,total_amount,amount_paid,balance,payment_status,purchase_date,due_date,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
               ->execute([$bizId,$supplierId,currentUser()['id'],$ref?:null,$desc,$total,$amountPaid,$balance,$status,$purDate,$dueDate,$notes?:null]);
            $purchaseId = (int)$db->lastInsertId();

            // If any payment was made upfront, record in supplier_payments
            if ($amountPaid > 0) {
                $db->prepare('INSERT INTO supplier_payments (business_id,supplier_id,purchase_id,user_id,amount,payment_method,reference_number,payment_date) VALUES (?,?,?,?,?,?,?,?)')
                   ->execute([$bizId,$supplierId,$purchaseId,currentUser()['id'],$amountPaid,$method,$ref?:null,$purDate]);
            }

            $db->commit();
            auditLog('create','supplier_purchases',$purchaseId,[],compact('supplierId','desc','total','amountPaid'));
            flash('success', 'Purchase recorded successfully.');
            redirect(url('suppliers/view').'?id='.$supplierId);
        } catch (\Exception $e) {
            $db->rollBack();
            $errors[] = 'Failed to record purchase. Please try again.';
        }
    }
}

$pageTitle = 'Record Purchase';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div>
    <div class="flex items-center gap-3 mb-6">
        <a href="<?= $preSupplier ? 'view.php?id='.$preSupplier : 'index.php' ?>" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
        <h2 class="text-xl font-bold text-gray-800">Record Purchase from Supplier</h2>
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
                    <label class="block text-sm font-medium text-gray-700 mb-1">Supplier *</label>
                    <select name="supplier_id" required class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
                        <option value="">-- Select Supplier --</option>
                        <?php foreach ($supplierList as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= (int)post('supplier_id',$preSupplier) === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description / What was purchased *</label>
                    <input type="text" name="description" value="<?= h(post('description')) ?>" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-green-500 outline-none"
                        placeholder="e.g. 20 bags of rice, office supplies...">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Total Amount (<?= currencySymbol() ?>) *</label>
                    <input type="number" name="total_amount" value="<?= h(post('total_amount','')) ?>" min="0.01" step="0.01" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-green-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount Paid Now (<?= currencySymbol() ?>)</label>
                    <input type="number" name="amount_paid" value="<?= h(post('amount_paid','0')) ?>" min="0" step="0.01"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-green-500 outline-none">
                    <p class="text-xs text-gray-400 mt-0.5">Leave 0 if paying later.</p>
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
                    <label class="block text-sm font-medium text-gray-700 mb-1">Reference / Invoice No.</label>
                    <input type="text" name="reference" value="<?= h(post('reference')) ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-green-500 outline-none" placeholder="Optional">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Purchase Date</label>
                    <input type="date" name="purchase_date" value="<?= h(post('purchase_date', date('Y-m-d'))) ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-green-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Due Date</label>
                    <input type="date" name="due_date" value="<?= h(post('due_date','')) ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-green-500 outline-none">
                    <p class="text-xs text-gray-400 mt-0.5">Leave blank if no due date.</p>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                    <textarea name="notes" rows="2"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-green-500 outline-none resize-none"
                        placeholder="Additional details (optional)"><?= h(post('notes')) ?></textarea>
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
                    <i class="fa-solid fa-save mr-1"></i> Record Purchase
                </button>
                <a href="index.php" class="bg-gray-100 text-gray-700 px-6 py-2 rounded-lg text-sm font-medium hover:bg-gray-200">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
