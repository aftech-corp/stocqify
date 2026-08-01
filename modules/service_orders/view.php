<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('sales');
if (isAdmin()) { redirect(url('admin/businesses')); }

$db    = getDB();
$bizId = currentBusinessId();
$id    = (int)get('id');

// Ensure service_order_id column exists on payments
try { $db->exec("ALTER TABLE payments ADD COLUMN IF NOT EXISTS service_order_id INT UNSIGNED DEFAULT NULL"); } catch (\Exception $e) {}

// Load order
$ordQ = $db->prepare('SELECT so.*, c.name AS customer_name, c.phone AS customer_phone, c.address AS customer_address,
    u.name AS staff_name,
    b.name AS biz_name, b.address AS biz_address, b.phone AS biz_phone, b.logo AS biz_logo
    FROM service_orders so
    LEFT JOIN customers c ON c.id=so.customer_id
    LEFT JOIN users u ON u.id=so.user_id
    LEFT JOIN businesses b ON b.id=so.business_id
    WHERE so.id=? AND so.business_id=?');
$ordQ->execute([$id, $bizId]);
$order = $ordQ->fetch();
if (!$order) { flash('error','Order not found.'); redirect(url('service-orders')); }

$itemsQ = $db->prepare('SELECT * FROM service_order_items WHERE order_id=?');
$itemsQ->execute([$id]);
$items = $itemsQ->fetchAll();

$paymentsQ = $db->prepare('SELECT p.*, u.name AS staff_name FROM payments p LEFT JOIN users u ON u.id=p.user_id WHERE p.service_order_id=? ORDER BY p.payment_date');
$paymentsQ->execute([$id]);
$payments = $paymentsQ->fetchAll();

$bizLogoUrl = '';
if (!empty($order['biz_logo']) && file_exists(__DIR__ . '/../../' . $order['biz_logo'])) {
    $bizLogoUrl = APP_URL . '/' . $order['biz_logo'];
}

$errors = [];

// Handle status change
if (isPost() && post('_action') === 'status') {
    verifyCsrf();
    $newStatus = post('new_status');
    $allowed = ['pending','in_progress','completed','cancelled'];
    if (in_array($newStatus, $allowed)) {
        $completedAt = $newStatus === 'completed' ? date('Y-m-d H:i:s') : null;
        $db->prepare('UPDATE service_orders SET status=?, completed_at=?, updated_at=NOW() WHERE id=? AND business_id=?')
           ->execute([$newStatus, $completedAt, $id, $bizId]);
        auditLog('update','service_orders',$id,['status'=>$order['status']],['status'=>$newStatus]);
        flash('success','Order status updated to ' . ucwords(str_replace('_',' ',$newStatus)) . '.');
        redirect(url('service-orders/view') . "?id={$id}");
    }
}

// Handle payment recording
if (isPost() && post('_action') === 'payment') {
    verifyCsrf();
    $payAmt    = (float)post('pay_amount');
    $payMethod = post('pay_method', 'cash');
    $payRef    = trim(post('pay_reference'));
    $me        = currentUser();

    if ($payAmt <= 0)                        $errors[] = 'Payment amount must be greater than 0.';
    if ($payAmt > $order['balance_due'])     $errors[] = 'Payment cannot exceed balance due.';

    if (empty($errors)) {
        $db->prepare('INSERT INTO payments (business_id,customer_id,service_order_id,amount,payment_method,reference_number,payment_date,user_id) VALUES (?,?,?,?,?,?,NOW(),?)')
           ->execute([$bizId,$order['customer_id'],$id,$payAmt,$payMethod,$payRef?:null,$me['id']]);
        $newPaid    = $order['amount_paid'] + $payAmt;
        $newBalance = $order['total_amount'] - $newPaid;
        $newPayStat = $newBalance <= 0 ? 'paid' : 'partial';
        $db->prepare('UPDATE service_orders SET amount_paid=?, balance_due=?, payment_status=?, updated_at=NOW() WHERE id=?')
           ->execute([$newPaid, $newBalance, $newPayStat, $id]);
        flash('success','Payment of ' . formatMoney($payAmt) . ' recorded.');
        redirect(url('service-orders/view') . "?id={$id}");
    }
    $order = $ordQ->execute([$id, $bizId]) ? $ordQ->fetch() : $order; // reload
}

$pageTitle = 'Order: ' . ($order['order_number'] ?: 'SRV-'.$id);
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-6">
    <div class="flex items-center gap-3">
        <a href="index.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
        <div>
            <h2 class="text-xl font-bold text-gray-800">Order Details</h2>
            <p class="text-sm text-gray-500 font-mono"><?= h($order['order_number'] ?: 'SRV-'.$id) ?></p>
        </div>
    </div>
    <div class="flex gap-2">
        <?php if (in_array($order['status'], ['pending','in_progress'])): ?>
        <a href="edit.php?id=<?= $id ?>" class="bg-gray-600 text-white px-3 py-2 rounded-lg text-sm hover:bg-gray-700 flex items-center gap-2">
            <i class="fa-solid fa-pen"></i> Edit
        </a>
        <?php endif; ?>
        <a href="invoice.php?id=<?= $id ?>" target="_blank" class="bg-green-600 text-white px-3 py-2 rounded-lg text-sm hover:bg-green-700 flex items-center gap-2">
            <i class="fa-solid fa-print"></i> Invoice
        </a>
    </div>
</div>

<?php if ($errors): ?>
<div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
    <?php foreach ($errors as $e): ?><p class="text-red-700 text-sm"><?= h($e) ?></p><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-4">

        <!-- Business header -->
        <?php if ($bizLogoUrl): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex items-center gap-4">
            <img src="<?= h($bizLogoUrl) ?>" alt="<?= h($order['biz_name']) ?>"
                 class="h-12 w-auto max-w-[120px] object-contain rounded-lg border border-gray-100">
            <div>
                <p class="font-bold text-gray-800"><?= h($order['biz_name']) ?></p>
                <?php if ($order['biz_phone']): ?><p class="text-xs text-gray-400"><?= h($order['biz_phone']) ?></p><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Order info -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                <div><p class="text-xs text-gray-400">Order #</p><p class="font-bold font-mono"><?= h($order['order_number'] ?: 'SRV-'.$id) ?></p></div>
                <div><p class="text-xs text-gray-400">Date</p><p class="font-semibold"><?= formatDate($order['created_at']) ?></p></div>
                <div>
                    <p class="text-xs text-gray-400">Status</p>
                    <?php $sc = match($order['status']) { 'pending'=>'bg-yellow-100 text-yellow-700','in_progress'=>'bg-blue-100 text-blue-700','completed'=>'bg-green-100 text-green-700','cancelled'=>'bg-red-100 text-red-700', default=>'bg-gray-100 text-gray-600' }; ?>
                    <span class="px-2 py-0.5 text-xs font-medium rounded-full <?= $sc ?>"><?= ucwords(str_replace('_',' ',$order['status'])) ?></span>
                </div>
                <div>
                    <p class="text-xs text-gray-400">Payment</p>
                    <?php $pc = match($order['payment_status']) { 'paid'=>'bg-green-100 text-green-700','partial'=>'bg-orange-100 text-orange-700', default=>'bg-red-100 text-red-700' }; ?>
                    <span class="px-2 py-0.5 text-xs font-medium rounded-full <?= $pc ?>"><?= ucfirst($order['payment_status']) ?></span>
                </div>
                <div><p class="text-xs text-gray-400">Customer</p><p class="font-medium"><?= h($order['customer_name'] ?? ($order['walkin_name'] ? $order['walkin_name'].' (Walk-in)' : 'Walk-in')) ?></p></div>
                <?php if ($order['walkin_phone']): ?>
                <div><p class="text-xs text-gray-400">Phone</p><p class="font-medium"><?= h($order['walkin_phone']) ?></p></div>
                <?php endif; ?>
                <div><p class="text-xs text-gray-400">Handled By</p><p class="font-medium"><?= h($order['staff_name'] ?? '—') ?></p></div>
                <?php if ($order['scheduled_at']): ?>
                <div><p class="text-xs text-gray-400">Scheduled</p><p class="font-medium"><?= date('d M Y H:i', strtotime($order['scheduled_at'])) ?></p></div>
                <?php endif; ?>
                <?php if ($order['completed_at']): ?>
                <div><p class="text-xs text-gray-400">Completed</p><p class="font-medium"><?= date('d M Y H:i', strtotime($order['completed_at'])) ?></p></div>
                <?php endif; ?>
            </div>
            <?php if ($order['notes']): ?>
            <div class="mt-4 pt-4 border-t">
                <p class="text-xs text-gray-400 mb-1">Notes</p>
                <p class="text-sm text-gray-700"><?= nl2br(h($order['notes'])) ?></p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Status actions -->
        <?php if ($order['status'] !== 'cancelled' && $order['status'] !== 'completed'): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h3 class="font-semibold text-gray-700 mb-3">Update Status</h3>
            <div class="flex flex-wrap gap-2">
                <?php
                $transitions = [
                    'pending'     => [['in_progress','Start Work','fa-person-digging','blue'],['cancelled','Cancel Order','fa-times','red']],
                    'in_progress' => [['completed','Mark Completed','fa-check','green'],['pending','Revert to Pending','fa-rotate-left','gray'],['cancelled','Cancel','fa-times','red']],
                ];
                $avail = $transitions[$order['status']] ?? [];
                foreach ($avail as [$ns, $lbl, $ico, $col]):
                    $btnCls = match($col) { 'blue'=>'bg-blue-600 hover:bg-blue-700','green'=>'bg-green-600 hover:bg-green-700','red'=>'bg-red-600 hover:bg-red-700', default=>'bg-gray-500 hover:bg-gray-600' };
                ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('<?= addslashes($lbl) ?>?')">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="_action" value="status">
                    <input type="hidden" name="new_status" value="<?= $ns ?>">
                    <button type="submit" class="<?= $btnCls ?> text-white px-4 py-2 rounded-lg text-sm flex items-center gap-1.5">
                        <i class="fa-solid <?= $ico ?>"></i> <?= $lbl ?>
                    </button>
                </form>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Items table -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100">
            <div class="px-5 py-4 border-b"><h3 class="font-semibold text-gray-800">Services</h3></div>
            <div class="table-responsive">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                        <tr>
                            <th class="px-4 py-3 font-medium text-left">Service</th>
                            <th class="px-4 py-3 font-medium text-right">Unit Price</th>
                            <th class="px-4 py-3 font-medium text-right">Qty</th>
                            <th class="px-4 py-3 font-medium text-right">Discount</th>
                            <th class="px-4 py-3 font-medium text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td class="px-4 py-3 font-medium"><?= h($item['service_name']) ?></td>
                            <td class="px-4 py-3 text-right"><?= formatMoney($item['unit_price']) ?></td>
                            <td class="px-4 py-3 text-right"><?= number_format($item['quantity'], 2) ?></td>
                            <td class="px-4 py-3 text-right text-red-500"><?= $item['discount']>0 ? formatMoney($item['discount']) : '—' ?></td>
                            <td class="px-4 py-3 text-right font-semibold"><?= formatMoney($item['total']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="border-t bg-gray-50">
                        <tr><td colspan="4" class="px-4 py-2 text-right text-sm text-gray-500">Subtotal:</td><td class="px-4 py-2 text-right font-semibold"><?= formatMoney($order['subtotal']) ?></td></tr>
                        <?php if ($order['discount_amount'] > 0): ?>
                        <tr><td colspan="4" class="px-4 py-2 text-right text-sm text-gray-500">Discount:</td><td class="px-4 py-2 text-right text-red-500">-<?= formatMoney($order['discount_amount']) ?></td></tr>
                        <?php endif; ?>
                        <tr class="font-bold text-base">
                            <td colspan="4" class="px-4 py-3 text-right text-gray-700">Total:</td>
                            <td class="px-4 py-3 text-right text-gray-900"><?= formatMoney($order['total_amount']) ?></td>
                        </tr>
                        <tr class="text-green-700">
                            <td colspan="4" class="px-4 py-2 text-right text-sm">Paid:</td>
                            <td class="px-4 py-2 text-right font-semibold"><?= formatMoney($order['amount_paid']) ?></td>
                        </tr>
                        <?php if ($order['balance_due'] > 0): ?>
                        <tr class="text-red-600 font-bold">
                            <td colspan="4" class="px-4 py-2 text-right">Balance Due:</td>
                            <td class="px-4 py-2 text-right"><?= formatMoney($order['balance_due']) ?></td>
                        </tr>
                        <?php else: ?>
                        <tr class="bg-green-50 text-green-700 font-bold">
                            <td colspan="5" class="px-4 py-2 text-center">✓ Fully Paid</td>
                        </tr>
                        <?php endif; ?>
                    </tfoot>
                </table>
            </div>
        </div>

    </div><!-- /main col -->

    <!-- Sidebar -->
    <div class="space-y-4">

        <!-- Payment history -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h3 class="font-semibold text-gray-800 mb-4">Payment History</h3>
            <?php if (empty($payments)): ?>
            <p class="text-gray-400 text-sm">No payments recorded.</p>
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
                    <?php if ($pay['staff_name']): ?><p class="text-xs text-gray-400">By: <?= h($pay['staff_name']) ?></p><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Record payment -->
        <?php if ($order['balance_due'] > 0 && $order['status'] !== 'cancelled'): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h3 class="font-semibold text-gray-800 mb-4">Record Payment</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="_action" value="payment">
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Amount (max <?= formatMoney($order['balance_due']) ?>)</label>
                        <input type="number" name="pay_amount" max="<?= $order['balance_due'] ?>" step="0.01" min="0.01"
                            value="<?= number_format($order['balance_due'], 2) ?>" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-green-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Method</label>
                        <select name="pay_method" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none">
                            <?php foreach (['cash'=>'Cash','bank_transfer'=>'Bank Transfer','mobile_money'=>'Mobile Money','cheque'=>'Cheque','pos'=>'POS/Card'] as $v=>$l): ?>
                            <option value="<?= $v ?>"><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Reference # <span class="text-gray-400">(optional)</span></label>
                        <input type="text" name="pay_reference"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none" placeholder="Receipt / transaction ref">
                    </div>
                    <button type="submit" class="w-full bg-green-600 text-white py-2 rounded-lg text-sm font-medium hover:bg-green-700">
                        <i class="fa-solid fa-money-bill-wave mr-1"></i> Record Payment
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>

    </div><!-- /sidebar col -->
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
