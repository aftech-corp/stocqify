<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('sales');

$db    = getDB();
$bizId = currentBusinessId();
$id    = (int)get('id');

$saleQ = $db->prepare('SELECT s.*, c.name AS customer_name, c.phone AS customer_phone, c.address AS customer_address,
    u.name AS staff_name,
    b.name AS biz_name, b.address AS biz_address, b.phone AS biz_phone, b.logo AS biz_logo
    FROM sales s
    LEFT JOIN customers c ON c.id=s.customer_id
    LEFT JOIN users u ON u.id=s.user_id
    LEFT JOIN businesses b ON b.id=s.business_id
    WHERE s.id=? AND s.business_id=?');
$saleQ->execute([$id, $bizId]);
$sale = $saleQ->fetch();
if (!$sale) { flash('error','Sale not found.'); redirect(url('sales')); }

$itemsQ = $db->prepare('SELECT si.*, p.name AS product_name, p.unit, p.image AS product_image
    FROM sale_items si
    JOIN products p ON p.id=si.product_id
    WHERE si.sale_id=?');
$itemsQ->execute([$id]);
$items = $itemsQ->fetchAll();

// Resolve logo URL
$bizLogoUrl = '';
if (!empty($sale['biz_logo']) && file_exists(__DIR__ . '/../../' . $sale['biz_logo'])) {
    $bizLogoUrl = APP_URL . '/' . $sale['biz_logo'];
}
// Resolve product image URLs
foreach ($items as &$itm) {
    $itm['_img_url'] = '';
    if (!empty($itm['product_image']) && file_exists(__DIR__ . '/../../' . $itm['product_image'])) {
        $itm['_img_url'] = APP_URL . '/' . $itm['product_image'];
    }
}
unset($itm);

$paymentsQ = $db->prepare('SELECT * FROM payments WHERE sale_id=? ORDER BY payment_date');
$paymentsQ->execute([$id]);
$payments = $paymentsQ->fetchAll();

$pageTitle = 'Sale: ' . $sale['invoice_number'];
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-6">
    <div class="flex items-center gap-3">
        <a href="index.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
        <div>
            <h2 class="text-xl font-bold text-gray-800">Sale Details</h2>
            <p class="text-sm text-gray-500 font-mono"><?= h($sale['invoice_number']) ?></p>
        </div>
    </div>
    <div class="flex gap-2">
        <?php if (isAdmin() || hasPermission('users')): ?>
        <a href="edit.php?id=<?= $id ?>" class="bg-gray-600 text-white px-3 py-2 rounded-lg text-sm hover:bg-gray-700 flex items-center gap-2">
            <i class="fa-solid fa-pen"></i> Edit
        </a>
        <?php endif; ?>
        <a href="invoice.php?id=<?= $id ?>" target="_blank" class="bg-green-600 text-white px-3 py-2 rounded-lg text-sm hover:bg-green-700 flex items-center gap-2">
            <i class="fa-solid fa-print"></i> Print Invoice
        </a>
        <?php if ($sale['balance_due'] > 0 && $sale['customer_id']): ?>
        <a href="../debts/index.php?customer=<?= $sale['customer_id'] ?>" class="bg-orange-500 text-white px-3 py-2 rounded-lg text-sm hover:bg-orange-600 flex items-center gap-2">
            <i class="fa-solid fa-money-bill-wave"></i> Record Payment
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-4">
        <!-- Business Header (logo + name) -->
        <?php if ($bizLogoUrl): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex items-center gap-4">
            <img src="<?= h($bizLogoUrl) ?>" alt="<?= h($sale['biz_name']) ?>"
                 class="h-12 w-auto max-w-[120px] object-contain rounded-lg border border-gray-100">
            <div>
                <p class="font-bold text-gray-800"><?= h($sale['biz_name']) ?></p>
                <?php if ($sale['biz_phone']): ?><p class="text-xs text-gray-400"><?= h($sale['biz_phone']) ?></p><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Sale Info -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                <div><p class="text-xs text-gray-400">Invoice</p><p class="font-bold font-mono"><?= h($sale['invoice_number']) ?></p></div>
                <div><p class="text-xs text-gray-400">Date</p><p class="font-semibold"><?= formatDate($sale['sale_date']) ?></p></div>
                <div><p class="text-xs text-gray-400">Type</p><?= statusBadge($sale['sale_type']) ?></div>
                <div><p class="text-xs text-gray-400">Status</p><?= statusBadge($sale['payment_status']) ?></div>
                <div><p class="text-xs text-gray-400">Customer</p><p class="font-medium"><?= h($sale['customer_name'] ?? ($sale['walkin_name'] ? $sale['walkin_name'] . ' (Walk-in)' : 'Walk-in Customer')) ?></p></div>
                <div><p class="text-xs text-gray-400">Staff</p><p class="font-medium"><?= h($sale['staff_name']) ?></p></div>
                <div><p class="text-xs text-gray-400">Payment Method</p><p class="font-medium capitalize"><?= str_replace('_',' ',$sale['payment_method']) ?></p></div>
                <?php if ($sale['due_date']): ?>
                <div><p class="text-xs text-gray-400">Due Date</p><p class="font-medium <?= strtotime($sale['due_date'])<time()?'text-red-600':'' ?>"><?= formatDate($sale['due_date']) ?></p></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Items Table -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100">
            <div class="px-5 py-4 border-b"><h3 class="font-semibold text-gray-800">Items Sold</h3></div>
            <div class="table-responsive">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                        <tr>
                            <th class="px-2 py-3 w-12"></th>
                            <th class="px-4 py-3 font-medium text-left">Product</th>
                            <th class="px-4 py-3 font-medium text-right">Price</th>
                            <th class="px-4 py-3 font-medium text-right">Qty</th>
                            <th class="px-4 py-3 font-medium text-right">Discount</th>
                            <th class="px-4 py-3 font-medium text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td class="px-2 py-2">
                                <?php if ($item['_img_url']): ?>
                                <img src="<?= h($item['_img_url']) ?>" alt=""
                                     class="w-10 h-10 rounded-lg object-cover border border-gray-100">
                                <?php else: ?>
                                <div class="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center text-gray-300">
                                    <i class="fa-solid fa-box text-sm"></i>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 font-medium"><?= h($item['product_name']) ?></td>
                            <td class="px-4 py-3 text-right"><?= formatMoney($item['unit_price']) ?></td>
                            <td class="px-4 py-3 text-right"><?= number_format($item['quantity'],2) ?> <?= h($item['unit']) ?></td>
                            <td class="px-4 py-3 text-right text-red-500"><?= $item['discount']>0?formatMoney($item['discount']):'-' ?></td>
                            <td class="px-4 py-3 text-right font-semibold"><?= formatMoney($item['total']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="border-t bg-gray-50">
                        <tr>
                            <td colspan="4" class="px-4 py-2 text-right text-sm text-gray-500">Subtotal:</td>
                            <td class="px-4 py-2 text-right font-semibold"><?= formatMoney($sale['subtotal']) ?></td>
                        </tr>
                        <?php if ($sale['discount_amount'] > 0): ?>
                        <tr>
                            <td colspan="4" class="px-4 py-2 text-right text-sm text-gray-500">Discount:</td>
                            <td class="px-4 py-2 text-right text-red-500">-<?= formatMoney($sale['discount_amount']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="font-bold text-base">
                            <td colspan="4" class="px-4 py-3 text-right text-gray-700">Total:</td>
                            <td class="px-4 py-3 text-right text-gray-900"><?= formatMoney($sale['total_amount']) ?></td>
                        </tr>
                        <tr class="text-green-700">
                            <td colspan="4" class="px-4 py-2 text-right text-sm">Amount Paid:</td>
                            <td class="px-4 py-2 text-right font-semibold"><?= formatMoney($sale['amount_paid']) ?></td>
                        </tr>
                        <?php if ($sale['balance_due'] > 0): ?>
                        <tr class="text-red-600 font-bold">
                            <td colspan="4" class="px-4 py-2 text-right">Balance Due:</td>
                            <td class="px-4 py-2 text-right"><?= formatMoney($sale['balance_due']) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Payments Sidebar -->
    <div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h3 class="font-semibold text-gray-800 mb-4">Payment History</h3>
            <?php if (empty($payments)): ?>
            <p class="text-gray-400 text-sm">No payments yet.</p>
            <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($payments as $pay): ?>
                <div class="p-3 bg-green-50 rounded-lg">
                    <div class="flex justify-between">
                        <span class="text-sm font-semibold text-green-700"><?= formatMoney($pay['amount']) ?></span>
                        <span class="text-xs text-gray-500"><?= formatDate($pay['payment_date']) ?></span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1 capitalize"><?= str_replace('_',' ',$pay['payment_method']) ?></p>
                    <?php if ($pay['reference_number']): ?><p class="text-xs text-gray-400 font-mono">Ref: <?= h($pay['reference_number']) ?></p><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($sale['notes']): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mt-4">
            <h3 class="font-semibold text-gray-800 mb-2">Notes</h3>
            <p class="text-sm text-gray-600"><?= nl2br(h($sale['notes'])) ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
