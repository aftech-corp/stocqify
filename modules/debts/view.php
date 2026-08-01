<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('debts');

$db    = getDB();
$bizId = currentBusinessId();
$id    = (int)get('id');

$stmt = $db->prepare('SELECT d.*, c.name AS customer_name, c.phone AS customer_phone, s.invoice_number FROM debts d JOIN customers c ON c.id=d.customer_id LEFT JOIN sales s ON s.id=d.sale_id WHERE d.id=? AND d.business_id=?');
$stmt->execute([$id, $bizId]);
$debt = $stmt->fetch();
if (!$debt) { flash('error','Debt not found.'); redirect(url('debts')); }

$histQ = $db->prepare('SELECT dp.*, u.name AS user_name FROM debt_payments dp JOIN users u ON u.id=dp.user_id WHERE dp.debt_id=? ORDER BY dp.payment_date DESC');
$histQ->execute([$id]);
$history = $histQ->fetchAll();

$pageTitle = 'Debt Details';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>
<div class="flex items-center justify-between mb-6">
    <div class="flex items-center gap-3">
        <a href="index.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
        <h2 class="text-xl font-bold text-gray-800">Debt Statement</h2>
    </div>
    <?php if (!in_array($debt['status'],['paid','written_off'])): ?>
    <a href="payment.php?debt=<?= $id ?>" class="bg-green-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-green-700">
        <i class="fa-solid fa-money-bill-wave mr-1"></i> Record Payment
    </a>
    <?php endif; ?>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <h3 class="font-semibold text-gray-800 mb-4">Debt Summary</h3>
        <div class="space-y-3 text-sm">
            <div class="flex justify-between"><span class="text-gray-500">Customer:</span><span class="font-semibold"><?= h($debt['customer_name']) ?></span></div>
            <div class="flex justify-between"><span class="text-gray-500">Phone:</span><span><?= h($debt['customer_phone']??'-') ?></span></div>
            <div class="flex justify-between"><span class="text-gray-500">Invoice:</span><span class="font-mono"><?= h($debt['invoice_number']??'—') ?></span></div>
            <div class="flex justify-between"><span class="text-gray-500">Original Amount:</span><span class="font-bold"><?= formatMoney($debt['original_amount']) ?></span></div>
            <div class="flex justify-between"><span class="text-gray-500">Total Paid:</span><span class="text-green-700 font-bold"><?= formatMoney($debt['amount_paid']) ?></span></div>
            <div class="flex justify-between border-t pt-2"><span class="text-gray-700 font-semibold">Balance Due:</span><span class="text-red-600 font-black text-lg"><?= formatMoney($debt['balance']) ?></span></div>
            <div class="flex justify-between"><span class="text-gray-500">Due Date:</span><span><?= $debt['due_date']?formatDate($debt['due_date']):'-' ?></span></div>
            <div class="flex justify-between"><span class="text-gray-500">Status:</span><?= statusBadge($debt['status']) ?></div>
        </div>
        <?php if (!empty($debt['notes'])): ?>
        <div class="mt-3 pt-3 border-t text-sm text-gray-500"><?= nl2br(h($debt['notes'])) ?></div>
        <?php endif; ?>
    </div>

    <!-- Progress bar -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex flex-col justify-center">
        <h3 class="font-semibold text-gray-800 mb-4">Payment Progress</h3>
        <?php $pct = $debt['original_amount'] > 0 ? round(($debt['amount_paid']/$debt['original_amount'])*100) : 0; ?>
        <div class="text-center mb-4">
            <p class="text-4xl font-black text-blue-600"><?= $pct ?>%</p>
            <p class="text-gray-500 text-sm">Paid</p>
        </div>
        <div class="w-full bg-gray-200 rounded-full h-4">
            <div class="bg-blue-600 h-4 rounded-full transition-all" style="width:<?= $pct ?>%"></div>
        </div>
        <div class="flex justify-between text-xs text-gray-400 mt-1">
            <span>0</span><span><?= formatMoney($debt['original_amount']) ?></span>
        </div>
    </div>

    <!-- History -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <h3 class="font-semibold text-gray-800 mb-4">Payment History (<?= count($history) ?>)</h3>
        <?php if (empty($history)): ?>
        <p class="text-gray-400 text-sm">No payments yet.</p>
        <?php else: ?>
        <div class="space-y-2 overflow-y-auto max-h-72">
            <?php foreach ($history as $p): ?>
            <div class="p-3 bg-green-50 rounded border border-green-100">
                <div class="flex justify-between">
                    <span class="text-green-700 font-bold text-sm"><?= formatMoney($p['amount']) ?></span>
                    <span class="text-xs text-gray-400"><?= formatDate($p['payment_date']) ?></span>
                </div>
                <p class="text-xs text-gray-500 capitalize"><?= str_replace('_',' ',$p['payment_method']) ?> — <?= h($p['user_name']) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
