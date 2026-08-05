<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('expenses');

$db     = getDB();
$bizId  = currentBusinessId();
$errors = [];

// Accept ?purchase=ID (specific bill) or ?supplier=ID (choose from list)
$purchaseId = (int)get('purchase');
$supplierId = (int)get('supplier');

$purchase = null;
$supplier = null;

if ($purchaseId) {
    $stmt = $db->prepare("SELECT sp.*, s.name AS supplier_name, s.id AS supplier_id
        FROM supplier_purchases sp JOIN suppliers s ON s.id=sp.supplier_id
        WHERE sp.id=? AND sp.business_id=? AND sp.payment_status!='paid'");
    $stmt->execute([$purchaseId, $bizId]);
    $purchase = $stmt->fetch();
    if (!$purchase) { flash('error', 'Purchase not found or already paid.'); redirect(url('suppliers')); }
    $supplierId = (int)$purchase['supplier_id'];
}

if ($supplierId && !$purchase) {
    $stmt = $db->prepare('SELECT * FROM suppliers WHERE id=? AND business_id=?');
    $stmt->execute([$supplierId, $bizId]);
    $supplier = $stmt->fetch();
    if (!$supplier) { flash('error', 'Supplier not found.'); redirect(url('suppliers')); }
}

if (!$purchase && !$supplier) { flash('error', 'Please specify a purchase or supplier.'); redirect(url('suppliers')); }

// If paying by supplier (no specific purchase), load unpaid purchases
$unpaidPurchases = [];
if (!$purchase) {
    $upQ = $db->prepare("SELECT * FROM supplier_purchases WHERE supplier_id=? AND payment_status!='paid' ORDER BY purchase_date DESC");
    $upQ->execute([$supplierId]);
    $unpaidPurchases = $upQ->fetchAll();
}

// Payment history
$histQ = $db->prepare("SELECT sp.*, u.name AS user_name FROM supplier_payments sp JOIN users u ON u.id=sp.user_id WHERE sp.supplier_id=? ORDER BY sp.payment_date DESC LIMIT 20");
$histQ->execute([$supplierId]);
$history = $histQ->fetchAll();

if (isPost()) {
    verifyCsrf();
    $payPurchaseId = (int)post('purchase_id') ?: null;
    $amount        = (float)post('amount', 0);
    $method        = post('payment_method', 'cash');
    $ref           = post('reference_number');
    $notes         = post('notes');
    $payDate       = post('payment_date', date('Y-m-d'));
    $validMethods  = ['cash','orange_money','afrimoney','qmoney','bank_transfer'];

    if ($amount <= 0) $errors[] = 'Amount must be greater than 0.';
    if (!in_array($method, $validMethods)) $method = 'cash';

    // If a specific purchase was targeted, validate amount
    $targetPurchase = null;
    if ($payPurchaseId) {
        $tpStmt = $db->prepare("SELECT * FROM supplier_purchases WHERE id=? AND business_id=? AND payment_status!='paid'");
        $tpStmt->execute([$payPurchaseId, $bizId]);
        $targetPurchase = $tpStmt->fetch();
        if (!$targetPurchase) $errors[] = 'Selected purchase is invalid or already paid.';
        elseif ($amount > $targetPurchase['balance']) $errors[] = 'Amount exceeds balance (' . formatMoney($targetPurchase['balance']) . ').';
    }

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            // Record supplier payment
            $db->prepare('INSERT INTO supplier_payments (business_id,supplier_id,purchase_id,user_id,amount,payment_method,reference_number,notes,payment_date) VALUES (?,?,?,?,?,?,?,?,?)')
               ->execute([$bizId, $supplierId, $payPurchaseId, currentUser()['id'], $amount, $method, $ref?:null, $notes?:null, $payDate]);

            // Update purchase balance if linked to a specific purchase
            if ($targetPurchase) {
                $newPaid    = $targetPurchase['amount_paid'] + $amount;
                $newBalance = $targetPurchase['balance'] - $amount;
                $newStatus  = $newBalance <= 0 ? 'paid' : 'partial';
                $db->prepare('UPDATE supplier_purchases SET amount_paid=?,balance=?,payment_status=?,updated_at=NOW() WHERE id=?')
                   ->execute([$newPaid, $newBalance, $newStatus, $payPurchaseId]);
            }

            $db->commit();
            auditLog('create','supplier_payments',(int)$db->lastInsertId(),[],compact('supplierId','amount'));
            flash('success', 'Payment of ' . formatMoney($amount) . ' recorded successfully.');
            redirect(url('suppliers/view') . '?id=' . $supplierId);
        } catch (\Exception $e) {
            $db->rollBack();
            $errors[] = 'Failed to record payment. Please try again.';
        }
    }
}

$supplierName = $purchase ? $purchase['supplier_name'] : ($supplier['name'] ?? '');
$pageTitle    = 'Pay Supplier';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<?= renderFlash() ?>

<div class="flex items-center gap-3 mb-6">
    <a href="view.php?id=<?= $supplierId ?>" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
    <h2 class="text-xl font-bold text-gray-800">Pay Supplier — <?= h($supplierName) ?></h2>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Payment Form -->
    <div class="space-y-4">
        <?php if ($purchase): ?>
        <div class="bg-orange-50 border border-orange-100 rounded-xl p-5">
            <h3 class="font-semibold text-gray-700 mb-3">Bill Being Paid</h3>
            <div class="grid grid-cols-2 gap-3 text-sm">
                <div><p class="text-xs text-gray-400">Description</p><p class="font-medium"><?= h($purchase['description']) ?></p></div>
                <div><p class="text-xs text-gray-400">Purchase Date</p><p class="font-medium"><?= formatDate($purchase['purchase_date']) ?></p></div>
                <div><p class="text-xs text-gray-400">Total Bill</p><p class="font-semibold"><?= formatMoney($purchase['total_amount']) ?></p></div>
                <div><p class="text-xs text-gray-400">Already Paid</p><p class="font-semibold text-green-700"><?= formatMoney($purchase['amount_paid']) ?></p></div>
                <div class="col-span-2"><p class="text-xs text-gray-400">Balance Remaining</p><p class="text-2xl font-black text-red-600"><?= formatMoney($purchase['balance']) ?></p></div>
            </div>
        </div>
        <?php elseif (!empty($unpaidPurchases)): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h3 class="font-semibold text-gray-700 mb-3">Outstanding Bills</h3>
            <div class="space-y-2 max-h-48 overflow-y-auto">
                <?php foreach ($unpaidPurchases as $up): ?>
                <div class="p-3 bg-red-50 rounded border border-red-100 text-sm">
                    <div class="flex justify-between">
                        <span class="font-medium"><?= h($up['description']) ?></span>
                        <span class="font-bold text-red-600"><?= formatMoney($up['balance']) ?></span>
                    </div>
                    <span class="text-xs text-gray-400"><?= formatDate($up['purchase_date']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h3 class="font-semibold text-gray-700 mb-4">Payment Details</h3>
            <?php if ($errors): ?>
            <div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-4">
                <?php foreach ($errors as $e): ?><p class="text-red-700 text-sm"><?= h($e) ?></p><?php endforeach; ?>
            </div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="csrf_token"  value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="purchase_id" value="<?= $purchase ? $purchase['id'] : '' ?>">
                <div class="space-y-3">
                    <?php if (!$purchase && !empty($unpaidPurchases)): ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Apply to Bill (optional)</label>
                        <select name="purchase_id" class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
                            <option value="">— General payment —</option>
                            <?php foreach ($unpaidPurchases as $up): ?>
                            <option value="<?= $up['id'] ?>"><?= h($up['description']) ?> — Balance: <?= formatMoney($up['balance']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Amount (<?= currencySymbol() ?>) *</label>
                        <input type="number" name="amount" value="<?= $purchase ? h($purchase['balance']) : '' ?>"
                            min="0.01" step="0.01" <?= $purchase ? 'max="'.$purchase['balance'].'"' : '' ?> required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-orange-500 outline-none">
                        <?php if ($purchase): ?><p class="text-xs text-gray-400 mt-0.5">Max: <?= formatMoney($purchase['balance']) ?></p><?php endif; ?>
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
                        <label class="block text-sm font-medium text-gray-700 mb-1">Payment Date</label>
                        <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>"
                            class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Reference Number</label>
                        <input type="text" name="reference_number" placeholder="Optional"
                            class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                        <textarea name="notes" rows="2"
                            class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none resize-none" placeholder="Optional..."></textarea>
                    </div>
                </div>
                <button type="submit" class="mt-4 w-full bg-orange-500 text-white py-3 rounded-lg font-semibold hover:bg-orange-600">
                    <i class="fa-solid fa-check mr-1"></i> Confirm Payment
                </button>
            </form>
        </div>
    </div>

    <!-- Payment History -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <h3 class="font-semibold text-gray-700 mb-4">Recent Payments to <?= h($supplierName) ?></h3>
        <?php if (empty($history)): ?>
        <p class="text-gray-400 text-sm">No payments recorded yet.</p>
        <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($history as $p): ?>
            <div class="p-3 bg-green-50 rounded-lg border border-green-100">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="font-bold text-green-700"><?= formatMoney($p['amount']) ?></p>
                        <p class="text-xs text-gray-500 capitalize"><?= str_replace('_',' ',$p['payment_method']) ?></p>
                        <?php if ($p['reference_number']): ?><p class="text-xs text-gray-400 font-mono">Ref: <?= h($p['reference_number']) ?></p><?php endif; ?>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-gray-500"><?= formatDate($p['payment_date']) ?></p>
                        <p class="text-xs text-gray-400"><?= h($p['user_name']) ?></p>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
