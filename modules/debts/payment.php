<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('payments');

$db    = getDB();
$bizId = currentBusinessId();
$debtId = (int)get('debt');
$errors = [];

$stmt = $db->prepare('SELECT d.*, c.name AS customer_name, s.invoice_number FROM debts d JOIN customers c ON c.id=d.customer_id LEFT JOIN sales s ON s.id=d.sale_id WHERE d.id=? AND d.business_id=?');
$stmt->execute([$debtId, $bizId]);
$debt = $stmt->fetch();
if (!$debt || in_array($debt['status'],['paid','written_off'])) { flash('error','Debt not found or already paid.'); redirect(url('debts')); }

// Payment history
$histQ = $db->prepare('SELECT dp.*, u.name AS user_name FROM debt_payments dp JOIN users u ON u.id=dp.user_id WHERE dp.debt_id=? ORDER BY dp.payment_date DESC');
$histQ->execute([$debtId]);
$history = $histQ->fetchAll();

if (isPost()) {
    verifyCsrf();
    $amount  = (float)post('amount', 0);
    $method  = post('payment_method', 'cash');
    $ref     = post('reference_number');
    $notes   = post('notes');
    $payDate = post('payment_date', date('Y-m-d'));
    $validMethods = ['cash','orange_money','afrimoney','qmoney','bank_transfer'];

    if ($amount <= 0) $errors[] = 'Amount must be greater than 0.';
    if ($amount > $debt['balance']) $errors[] = "Amount exceeds balance ({$debt['balance']}).";
    if (!in_array($method, $validMethods)) $errors[] = 'Invalid payment method.';

    if (empty($errors)) {
        $newPaid    = $debt['amount_paid'] + $amount;
        $newBalance = $debt['balance'] - $amount;
        $newStatus  = $newBalance <= 0 ? 'paid' : 'partial';

        $db->beginTransaction();
        try {
            // Record debt payment
            $db->prepare('INSERT INTO debt_payments (debt_id,business_id,user_id,amount,payment_method,reference_number,notes,payment_date) VALUES (?,?,?,?,?,?,?,?)')->execute(
                [$debtId,$bizId,currentUser()['id'],$amount,$method,$ref?:null,$notes?:null,$payDate]
            );
            // Update debt
            $db->prepare('UPDATE debts SET amount_paid=?,balance=?,status=?,updated_at=NOW() WHERE id=?')->execute(
                [$newPaid, $newBalance, $newStatus, $debtId]
            );
            // Update linked sale
            if ($debt['sale_id']) {
                $sale = $db->prepare('SELECT amount_paid, total_amount FROM sales WHERE id=?');
                $sale->execute([$debt['sale_id']]);
                $s = $sale->fetch();
                if ($s) {
                    $newSalePaid = $s['amount_paid'] + $amount;
                    $newSaleBal  = $s['total_amount'] - $newSalePaid;
                    $newSaleSt   = $newSaleBal <= 0 ? 'paid' : 'partial';
                    $db->prepare('UPDATE sales SET amount_paid=?,balance_due=?,payment_status=? WHERE id=?')->execute([$newSalePaid,$newSaleBal,$newSaleSt,$debt['sale_id']]);
                }
            }
            // Record in payments table
            $db->prepare('INSERT INTO payments (business_id,sale_id,customer_id,user_id,amount,payment_method,reference_number,notes,payment_date) VALUES (?,?,?,?,?,?,?,?,?)')->execute(
                [$bizId,$debt['sale_id'],$debt['customer_id'],currentUser()['id'],$amount,$method,$ref?:null,$notes?:null,$payDate]
            );
            $db->commit();
            flash('success', 'Payment of ' . formatMoney($amount) . ' recorded successfully.');
            redirect(url('debts/payment') . '?debt=' . $debtId);
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Failed to record payment.';
        }
    }
}

$pageTitle = 'Record Payment';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center gap-3 mb-6">
    <a href="index.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
    <h2 class="text-xl font-bold text-gray-800">Record Debt Payment</h2>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Debt Info + Form -->
    <div class="space-y-4">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h3 class="font-semibold text-gray-700 mb-4">Debt Summary</h3>
            <div class="grid grid-cols-2 gap-3 text-sm">
                <div><p class="text-xs text-gray-400">Customer</p><p class="font-semibold"><?= h($debt['customer_name']) ?></p></div>
                <div><p class="text-xs text-gray-400">Invoice</p><p class="font-semibold font-mono"><?= h($debt['invoice_number']??'—') ?></p></div>
                <div><p class="text-xs text-gray-400">Original Amount</p><p class="font-semibold"><?= formatMoney($debt['original_amount']) ?></p></div>
                <div><p class="text-xs text-gray-400">Already Paid</p><p class="font-semibold text-green-700"><?= formatMoney($debt['amount_paid']) ?></p></div>
                <div class="col-span-2"><p class="text-xs text-gray-400">Balance Remaining</p><p class="text-2xl font-black text-red-600"><?= formatMoney($debt['balance']) ?></p></div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h3 class="font-semibold text-gray-700 mb-4">Payment Details</h3>
            <?php if ($errors): ?>
            <div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-4">
                <?php foreach ($errors as $e): ?><p class="text-red-700 text-sm"><?= h($e) ?></p><?php endforeach; ?>
            </div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Amount (<?= currencySymbol() ?>) *</label>
                        <input type="number" name="amount" value="<?= h($debt['balance']) ?>" min="0.01" step="0.01" max="<?= $debt['balance'] ?>" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        <p class="text-xs text-gray-400 mt-0.5">Max: <?= formatMoney($debt['balance']) ?></p>
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
                <button type="submit" class="mt-4 w-full bg-green-600 text-white py-3 rounded-lg font-semibold hover:bg-green-700">
                    <i class="fa-solid fa-check mr-1"></i> Confirm Payment
                </button>
            </form>
        </div>
    </div>

    <!-- Payment History -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <h3 class="font-semibold text-gray-700 mb-4">Payment History</h3>
        <?php if (empty($history)): ?>
        <p class="text-gray-400 text-sm">No payments recorded yet.</p>
        <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($history as $p): ?>
            <div class="p-3 bg-green-50 rounded-lg border border-green-100">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="font-bold text-green-700"><?= formatMoney($p['amount']) ?></p>
                        <p class="text-xs text-gray-500 mt-0.5 capitalize"><?= str_replace('_',' ',$p['payment_method']) ?></p>
                        <?php if ($p['reference_number']): ?><p class="text-xs text-gray-400 font-mono">Ref: <?= h($p['reference_number']) ?></p><?php endif; ?>
                        <?php if ($p['notes']): ?><p class="text-xs text-gray-500"><?= h($p['notes']) ?></p><?php endif; ?>
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
