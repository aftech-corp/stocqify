<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('expenses');

$db    = getDB();
$bizId = currentBusinessId();
$id    = (int)get('id');

$stmt = $db->prepare('SELECT * FROM suppliers WHERE id=? AND business_id=? AND is_active=1');
$stmt->execute([$id, $bizId]);
$supplier = $stmt->fetch();
if (!$supplier) { flash('error', 'Supplier not found.'); redirect(url('suppliers')); }

// Purchase history
$purQ = $db->prepare("SELECT sp.*, u.name AS user_name FROM supplier_purchases sp JOIN users u ON u.id=sp.user_id WHERE sp.supplier_id=? ORDER BY sp.purchase_date DESC LIMIT 50");
$purQ->execute([$id]);
$purchases = $purQ->fetchAll();

// Payment history
$payQ = $db->prepare("SELECT sp.*, u.name AS user_name FROM supplier_payments sp JOIN users u ON u.id=sp.user_id WHERE sp.supplier_id=? ORDER BY sp.payment_date DESC LIMIT 50");
$payQ->execute([$id]);
$payments = $payQ->fetchAll();

// Totals
$totalPurchased  = array_sum(array_column($purchases, 'total_amount'));
$totalPaid       = array_sum(array_column($payments,  'amount'));
$outstanding     = array_sum(array_column(
    array_filter($purchases, fn($p) => $p['payment_status'] !== 'paid'), 'balance'
));

$pageTitle = 'Supplier: ' . $supplier['name'];
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<?= renderFlash() ?>

<div class="flex items-center justify-between mb-6">
    <div class="flex items-center gap-3">
        <a href="index.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
        <h2 class="text-xl font-bold text-gray-800">Supplier Profile</h2>
    </div>
    <div class="flex gap-2">
        <a href="purchase.php?supplier=<?= $id ?>" class="bg-green-600 text-white px-3 py-2 rounded-lg text-sm hover:bg-green-700">
            <i class="fa-solid fa-cart-plus mr-1"></i> Record Purchase
        </a>
        <a href="edit.php?id=<?= $id ?>" class="bg-blue-600 text-white px-3 py-2 rounded-lg text-sm hover:bg-blue-700">
            <i class="fa-solid fa-pen mr-1"></i> Edit
        </a>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <!-- Supplier Info -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <div class="flex items-center gap-4 mb-5">
            <div class="w-16 h-16 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-700 font-black text-2xl">
                <?= strtoupper(substr($supplier['name'],0,1)) ?>
            </div>
            <div>
                <h3 class="font-bold text-gray-800 text-lg"><?= h($supplier['name']) ?></h3>
                <?php if ($supplier['contact_person']): ?>
                <p class="text-gray-500 text-sm"><?= h($supplier['contact_person']) ?></p>
                <?php endif; ?>
            </div>
        </div>
        <div class="space-y-2 text-sm">
            <?php if ($supplier['phone']): ?>
            <div class="flex gap-3"><i class="fa-solid fa-phone text-gray-400 w-4 mt-0.5"></i><span><?= h($supplier['phone']) ?></span></div>
            <?php endif; ?>
            <?php if ($supplier['email']): ?>
            <div class="flex gap-3"><i class="fa-solid fa-envelope text-gray-400 w-4 mt-0.5"></i><span><?= h($supplier['email']) ?></span></div>
            <?php endif; ?>
            <?php if ($supplier['address']): ?>
            <div class="flex gap-3"><i class="fa-solid fa-location-dot text-gray-400 w-4 mt-0.5"></i><span class="text-gray-600"><?= nl2br(h($supplier['address'])) ?></span></div>
            <?php endif; ?>
            <?php if ($supplier['opening_balance'] > 0): ?>
            <div class="mt-3 pt-3 border-t flex justify-between">
                <span class="text-gray-500">Opening Balance</span>
                <span class="font-semibold text-orange-600"><?= formatMoney($supplier['opening_balance']) ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($supplier['notes']): ?>
        <div class="mt-4 pt-4 border-t text-sm text-gray-500"><?= nl2br(h($supplier['notes'])) ?></div>
        <?php endif; ?>
    </div>

    <!-- Summary Stats -->
    <div class="space-y-4">
        <div class="bg-blue-50 rounded-xl p-5">
            <p class="text-2xl font-black text-blue-700"><?= formatMoney($totalPurchased) ?></p>
            <p class="text-sm text-blue-600">Total Purchased</p>
        </div>
        <div class="bg-green-50 rounded-xl p-5">
            <p class="text-2xl font-black text-green-700"><?= formatMoney($totalPaid) ?></p>
            <p class="text-sm text-green-600">Total Payments Made</p>
        </div>
    </div>

    <!-- Outstanding -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 flex flex-col justify-center text-center">
        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Outstanding Balance</p>
        <p class="text-4xl font-black <?= $outstanding > 0 ? 'text-red-600' : 'text-green-600' ?> mb-3">
            <?= formatMoney($outstanding) ?>
        </p>
        <?php if ($outstanding > 0): ?>
        <p class="text-xs text-gray-400 mb-4">Amount still owed to this supplier</p>
        <a href="pay.php?supplier=<?= $id ?>" class="bg-orange-500 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-orange-600">
            <i class="fa-solid fa-money-bill-wave mr-1"></i> Make Payment
        </a>
        <?php else: ?>
        <p class="text-xs text-green-500">All bills settled</p>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Purchase History -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100">
        <div class="px-5 py-4 border-b flex items-center justify-between">
            <h3 class="font-semibold text-gray-800">Purchase History</h3>
            <a href="purchase.php?supplier=<?= $id ?>" class="text-xs text-green-600 hover:underline">+ New Purchase</a>
        </div>
        <?php if (empty($purchases)): ?>
        <p class="px-5 py-6 text-gray-400 text-sm">No purchases recorded yet.</p>
        <?php else: ?>
        <div class="divide-y max-h-96 overflow-y-auto">
            <?php foreach ($purchases as $p): ?>
            <div class="px-5 py-4">
                <div class="flex items-start justify-between">
                    <div class="flex-1 min-w-0">
                        <p class="font-medium text-gray-800 text-sm truncate"><?= h($p['description']) ?></p>
                        <p class="text-xs text-gray-400 mt-0.5"><?= formatDate($p['purchase_date']) ?>
                            <?php if ($p['reference']): ?> · Ref: <?= h($p['reference']) ?><?php endif; ?></p>
                    </div>
                    <div class="text-right ml-4 flex-shrink-0">
                        <p class="font-bold text-gray-800"><?= formatMoney($p['total_amount']) ?></p>
                        <?= statusBadge($p['payment_status']) ?>
                    </div>
                </div>
                <?php if ($p['payment_status'] !== 'paid'): ?>
                <div class="flex items-center justify-between mt-2">
                    <span class="text-xs text-red-500 font-medium">Balance: <?= formatMoney($p['balance']) ?></span>
                    <a href="pay.php?purchase=<?= $p['id'] ?>" class="text-xs text-orange-600 hover:underline">Pay Now</a>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Payment History -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100">
        <div class="px-5 py-4 border-b">
            <h3 class="font-semibold text-gray-800">Payment History</h3>
        </div>
        <?php if (empty($payments)): ?>
        <p class="px-5 py-6 text-gray-400 text-sm">No payments recorded yet.</p>
        <?php else: ?>
        <div class="divide-y max-h-96 overflow-y-auto">
            <?php foreach ($payments as $p): ?>
            <div class="px-5 py-4">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="font-bold text-green-700"><?= formatMoney($p['amount']) ?></p>
                        <p class="text-xs text-gray-400 capitalize mt-0.5"><?= str_replace('_',' ',$p['payment_method']) ?></p>
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
