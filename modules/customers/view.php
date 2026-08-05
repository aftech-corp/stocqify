<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('sales');

$db    = getDB();
$bizId = currentBusinessId();
$id    = (int)get('id');

$stmt = $db->prepare('SELECT * FROM customers WHERE id=? AND business_id=? AND is_active=1');
$stmt->execute([$id, $bizId]);
$customer = $stmt->fetch();
if (!$customer) { flash('error', 'Customer not found.'); redirect(url('customers')); }

// Purchase history
$salesQ = $db->prepare('SELECT * FROM sales WHERE customer_id=? ORDER BY sale_date DESC LIMIT 20');
$salesQ->execute([$id]);
$sales = $salesQ->fetchAll();

// Debt history
$debtsQ = $db->prepare('SELECT * FROM debts WHERE customer_id=? ORDER BY created_at DESC LIMIT 20');
$debtsQ->execute([$id]);
$debts = $debtsQ->fetchAll();

// Summary stats
$totalSpent  = array_sum(array_column($sales, 'total_amount'));
$totalDebt   = array_sum(array_column(array_filter($debts, fn($d) => !in_array($d['status'],['paid','written_off'])), 'balance'));

$pageTitle = 'Customer: ' . $customer['name'];
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-6">
    <div class="flex items-center gap-3">
        <a href="index.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
        <h2 class="text-xl font-bold text-gray-800">Customer Profile</h2>
    </div>
    <div class="flex gap-2">
        <a href="edit.php?id=<?= $id ?>" class="bg-blue-600 text-white px-3 py-2 rounded-lg text-sm hover:bg-blue-700">
            <i class="fa-solid fa-pen mr-1"></i> Edit
        </a>
        <a href="<?= url('sales/add') ?>?customer_id=<?= $id ?>" class="bg-green-600 text-white px-3 py-2 rounded-lg text-sm hover:bg-green-700">
            <i class="fa-solid fa-plus mr-1"></i> New Sale
        </a>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <!-- Customer Info -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <div class="flex items-center gap-4 mb-5">
            <div class="w-16 h-16 rounded-full bg-blue-100 flex items-center justify-center text-blue-700 font-black text-2xl">
                <?= strtoupper(substr($customer['name'],0,1)) ?>
            </div>
            <div>
                <h3 class="font-bold text-gray-800 text-lg"><?= h($customer['name']) ?></h3>
                <?php if ($customer['business_name']): ?>
                <p class="text-gray-500 text-sm"><?= h($customer['business_name']) ?></p>
                <?php endif; ?>
            </div>
        </div>
        <div class="space-y-2 text-sm">
            <?php if ($customer['phone']): ?>
            <div class="flex items-center gap-2 text-gray-600"><i class="fa-solid fa-phone w-4 text-gray-400"></i><?= h($customer['phone']) ?></div>
            <?php endif; ?>
            <?php if ($customer['email']): ?>
            <div class="flex items-center gap-2 text-gray-600"><i class="fa-solid fa-envelope w-4 text-gray-400"></i><?= h($customer['email']) ?></div>
            <?php endif; ?>
            <?php if ($customer['address']): ?>
            <div class="flex items-start gap-2 text-gray-600"><i class="fa-solid fa-location-dot w-4 text-gray-400 mt-0.5"></i><?= nl2br(h($customer['address'])) ?></div>
            <?php endif; ?>
        </div>
        <div class="mt-4 pt-4 border-t">
            <p class="text-xs text-gray-400">Credit Limit: <span class="font-semibold text-gray-700"><?= formatMoney($customer['credit_limit']) ?></span></p>
            <p class="text-xs text-gray-400 mt-1">Customer since: <?= formatDate($customer['created_at']) ?></p>
        </div>
    </div>

    <!-- Stats -->
    <div class="lg:col-span-2 grid grid-cols-3 gap-4 content-start">
        <div class="bg-blue-50 rounded-xl p-4 text-center">
            <p class="text-3xl font-black text-blue-700"><?= count($sales) ?></p>
            <p class="text-sm text-blue-600 font-medium">Total Sales</p>
        </div>
        <div class="bg-green-50 rounded-xl p-4 text-center">
            <p class="text-2xl font-black text-green-700"><?= formatMoney($totalSpent) ?></p>
            <p class="text-sm text-green-600 font-medium">Total Spent</p>
        </div>
        <div class="<?= $totalDebt > 0 ? 'bg-red-50' : 'bg-gray-50' ?> rounded-xl p-4 text-center">
            <p class="text-2xl font-black <?= $totalDebt > 0 ? 'text-red-700' : 'text-gray-600' ?>"><?= formatMoney($totalDebt) ?></p>
            <p class="text-sm <?= $totalDebt > 0 ? 'text-red-600' : 'text-gray-500' ?> font-medium">Outstanding Debt</p>
        </div>
    </div>
</div>

<!-- Purchase History -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 mb-6">
    <div class="px-5 py-4 border-b">
        <h3 class="font-semibold text-gray-800">Purchase History</h3>
    </div>
    <div class="table-responsive">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-medium text-left">Invoice</th>
                    <th class="px-4 py-3 font-medium text-left">Date</th>
                    <th class="px-4 py-3 font-medium text-left">Type</th>
                    <th class="px-4 py-3 font-medium text-right">Amount</th>
                    <th class="px-4 py-3 font-medium text-left">Status</th>
                    <th class="px-4 py-3 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php if (empty($sales)): ?>
                <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No sales yet.</td></tr>
                <?php else: ?>
                <?php foreach ($sales as $sale): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-mono text-xs text-blue-600"><?= h($sale['invoice_number']) ?></td>
                    <td class="px-4 py-3 text-gray-600"><?= formatDate($sale['sale_date']) ?></td>
                    <td class="px-4 py-3"><?= statusBadge($sale['sale_type']) ?></td>
                    <td class="px-4 py-3 text-right font-semibold"><?= formatMoney($sale['total_amount']) ?></td>
                    <td class="px-4 py-3"><?= statusBadge($sale['payment_status']) ?></td>
                    <td class="px-4 py-3"><a href="../sales/view.php?id=<?= $sale['id'] ?>" class="text-blue-600 hover:underline text-xs">View</a></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Debt History -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100">
    <div class="px-5 py-4 border-b">
        <h3 class="font-semibold text-gray-800">Debt History</h3>
    </div>
    <div class="table-responsive">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-medium text-left">Date</th>
                    <th class="px-4 py-3 font-medium text-right">Original</th>
                    <th class="px-4 py-3 font-medium text-right">Paid</th>
                    <th class="px-4 py-3 font-medium text-right">Balance</th>
                    <th class="px-4 py-3 font-medium text-left">Due Date</th>
                    <th class="px-4 py-3 font-medium text-left">Status</th>
                    <th class="px-4 py-3 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php if (empty($debts)): ?>
                <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">No debt records.</td></tr>
                <?php else: ?>
                <?php foreach ($debts as $debt): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-600"><?= formatDate($debt['created_at']) ?></td>
                    <td class="px-4 py-3 text-right"><?= formatMoney($debt['original_amount']) ?></td>
                    <td class="px-4 py-3 text-right text-green-600"><?= formatMoney($debt['amount_paid']) ?></td>
                    <td class="px-4 py-3 text-right font-bold <?= $debt['balance'] > 0 ? 'text-red-600' : 'text-green-600' ?>"><?= formatMoney($debt['balance']) ?></td>
                    <td class="px-4 py-3 text-gray-500"><?= $debt['due_date'] ? formatDate($debt['due_date']) : '-' ?></td>
                    <td class="px-4 py-3"><?= statusBadge($debt['status']) ?></td>
                    <td class="px-4 py-3"><a href="../debts/view.php?id=<?= $debt['id'] ?>" class="text-blue-600 hover:underline text-xs">View</a></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
