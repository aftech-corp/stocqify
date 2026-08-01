<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('sales');

// Only business owners and admins can edit sales
if (!isAdmin() && !hasPermission('users')) {
    flash('error', 'You do not have permission to edit sales.');
    redirect(url('sales'));
}

$db    = getDB();
$bizId = currentBusinessId();
$id    = (int)get('id');
$errors = [];

// Load sale — scoped to business
$saleQ = $db->prepare('SELECT s.*, c.name AS customer_name FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE s.id=? AND s.business_id=?');
$saleQ->execute([$id, $bizId]);
$sale = $saleQ->fetch();
if (!$sale) { flash('error', 'Sale not found.'); redirect(url('sales')); }

// Load customers and items for the form
$custsQ = $db->prepare('SELECT id, name FROM customers WHERE business_id=? AND is_active=1 ORDER BY name');
$custsQ->execute([$bizId]);
$customers = $custsQ->fetchAll();

$itemsQ = $db->prepare('SELECT si.*, p.name AS product_name, p.unit FROM sale_items si JOIN products p ON p.id=si.product_id WHERE si.sale_id=?');
$itemsQ->execute([$id]);
$items = $itemsQ->fetchAll();

// ==================== SAVE ====================
if (isPost()) {
    verifyCsrf();
    $customerId  = (int)post('customer_id') ?: null;
    $walkinName  = $customerId ? null : trim(post('walkin_name'));
    $saleDate    = post('sale_date', $sale['sale_date']);
    $saleType    = post('sale_type', $sale['sale_type']);
    $payMethod   = post('payment_method', $sale['payment_method']);
    $dueDate     = post('due_date') ?: null;
    $notes       = post('notes');
    $discount    = (float)post('discount_amount', 0);
    $amountPaid  = $saleType === 'cash' ? $sale['total_amount'] - $discount : (float)post('amount_paid', 0);

    if (empty($saleDate)) $errors[] = 'Sale date is required.';
    if ($saleType === 'credit' && !$customerId) $errors[] = 'A registered customer is required for credit sales.';

    // Recalculate totals
    $subtotal   = $sale['subtotal']; // items are not changed in edit
    $total      = max(0, $subtotal - $discount);
    if ($amountPaid > $total) $amountPaid = $total;
    $balance    = max(0, $total - $amountPaid);
    $payStatus  = $balance <= 0 ? 'paid' : ($amountPaid > 0 ? 'partial' : 'unpaid');

    if (empty($errors)) {
        $db->prepare('UPDATE sales SET customer_id=?,walkin_name=?,sale_date=?,sale_type=?,payment_method=?,due_date=?,notes=?,discount_amount=?,total_amount=?,amount_paid=?,balance_due=?,payment_status=? WHERE id=? AND business_id=?')
           ->execute([$customerId,$walkinName?:null,$saleDate,$saleType,$payMethod,$dueDate,$notes?:null,$discount,$total,$amountPaid,$balance,$payStatus,$id,$bizId]);

        // Update the initial payment record if the amount paid has changed
        if (abs($amountPaid - $sale['amount_paid']) > 0.001 && $amountPaid > 0) {
            // Check if there's a payment record for this sale
            $payChk = $db->prepare('SELECT id FROM payments WHERE sale_id=? ORDER BY created_at ASC LIMIT 1');
            $payChk->execute([$id]);
            $payId = $payChk->fetchColumn();
            if ($payId) {
                $db->prepare('UPDATE payments SET amount=?, payment_method=?, payment_date=? WHERE id=?')
                   ->execute([$amountPaid, $payMethod, $saleDate, $payId]);
            } elseif ($amountPaid > 0) {
                $db->prepare('INSERT INTO payments (business_id,sale_id,customer_id,user_id,amount,payment_method,payment_date) VALUES (?,?,?,?,?,?,?)')
                   ->execute([$bizId,$id,$customerId,currentUser()['id'],$amountPaid,$payMethod,$saleDate]);
            }
        }

        auditLog('update', 'sales', $id, [], ['invoice'=>$sale['invoice_number']]);
        flash('success', 'Sale updated successfully.');
        redirect(url('sales/view') . '?id=' . $id);
    }

    // Preserve inputs on error
    $sale = array_merge($sale, [
        'customer_id'=>$customerId,'walkin_name'=>$walkinName,'sale_date'=>$saleDate,
        'sale_type'=>$saleType,'payment_method'=>$payMethod,'due_date'=>$dueDate,
        'notes'=>$notes,'discount_amount'=>$discount,'amount_paid'=>$amountPaid,
    ]);
}

$pageTitle = 'Edit Sale: ' . $sale['invoice_number'];
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center gap-3 mb-6">
    <a href="view.php?id=<?= $id ?>" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
    <div>
        <h2 class="text-xl font-bold text-gray-800">Edit Sale</h2>
        <p class="text-sm text-gray-500 font-mono"><?= h($sale['invoice_number']) ?></p>
    </div>
</div>

<?php if ($errors): ?>
<div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
    <?php foreach ($errors as $e): ?><p class="text-red-700 text-sm"><i class="fa-solid fa-circle-exclamation mr-1"></i><?= h($e) ?></p><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- Items (read-only) -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100">
            <div class="px-5 py-4 border-b flex items-center justify-between">
                <h3 class="font-semibold text-gray-800">Items Sold</h3>
                <span class="text-xs text-gray-400 bg-gray-100 px-2 py-1 rounded">Items cannot be changed after recording</span>
            </div>
            <div class="table-responsive">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium">Product</th>
                            <th class="px-4 py-3 text-right font-medium">Price</th>
                            <th class="px-4 py-3 text-right font-medium">Qty</th>
                            <th class="px-4 py-3 text-right font-medium">Discount</th>
                            <th class="px-4 py-3 text-right font-medium">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td class="px-4 py-3 font-medium"><?= h($item['product_name']) ?></td>
                            <td class="px-4 py-3 text-right"><?= formatMoney($item['unit_price']) ?></td>
                            <td class="px-4 py-3 text-right"><?= number_format($item['quantity'],2) ?> <?= h($item['unit']) ?></td>
                            <td class="px-4 py-3 text-right text-red-500"><?= $item['discount']>0?formatMoney($item['discount']):'-' ?></td>
                            <td class="px-4 py-3 text-right font-semibold"><?= formatMoney($item['total']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="border-t bg-gray-50">
                        <tr class="font-bold">
                            <td colspan="4" class="px-4 py-3 text-right text-gray-700">Subtotal:</td>
                            <td class="px-4 py-3 text-right"><?= formatMoney($sale['subtotal']) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Edit Form -->
    <div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 space-y-4">
                <h3 class="font-semibold text-gray-800">Edit Details</h3>

                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Customer</label>
                    <select name="customer_id" id="custSelect" class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">Walk-in Customer</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= ($sale['customer_id']==$c['id'])?'selected':'' ?>><?= h($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="walkinWrap" <?= $sale['customer_id'] ? 'class="hidden"' : '' ?>>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Walk-in Name <span class="text-gray-400">(optional)</span></label>
                    <input type="text" name="walkin_name" value="<?= h($sale['walkin_name'] ?? '') ?>" placeholder="e.g. John Doe"
                        class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Sale Date</label>
                    <input type="date" name="sale_date" value="<?= h($sale['sale_date']) ?>" required
                        class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Sale Type</label>
                    <div class="flex gap-2">
                        <label class="flex-1 flex items-center justify-center gap-2 border rounded-lg py-2 cursor-pointer type-opt <?= $sale['sale_type']==='cash'?'bg-blue-50 border-blue-500 font-semibold':'' ?>" data-type="cash">
                            <input type="radio" name="sale_type" value="cash" <?= $sale['sale_type']==='cash'?'checked':'' ?> class="hidden">
                            <i class="fa-solid fa-money-bill text-green-600"></i> Cash
                        </label>
                        <label class="flex-1 flex items-center justify-center gap-2 border rounded-lg py-2 cursor-pointer type-opt <?= $sale['sale_type']==='credit'?'bg-blue-50 border-blue-500 font-semibold':'' ?>" data-type="credit">
                            <input type="radio" name="sale_type" value="credit" <?= $sale['sale_type']==='credit'?'checked':'' ?> class="hidden">
                            <i class="fa-solid fa-credit-card text-blue-600"></i> Credit
                        </label>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Payment Method</label>
                    <select name="payment_method" class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
                        <?php foreach (['cash','orange_money','afrimoney','qmoney','bank_transfer'] as $pm): ?>
                        <option value="<?= $pm ?>" <?= $sale['payment_method']===$pm?'selected':'' ?>><?= ucwords(str_replace('_',' ',$pm)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="dueDateEdit" <?= $sale['sale_type']==='cash'?'class="hidden"':'' ?>>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Due Date</label>
                    <input type="date" name="due_date" value="<?= h($sale['due_date'] ?? '') ?>"
                        class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Discount</label>
                    <input type="number" name="discount_amount" id="editDiscount" value="<?= $sale['discount_amount'] ?>" min="0" step="0.01"
                        class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none text-right">
                </div>

                <div id="paidWrapEdit" <?= $sale['sale_type']==='cash'?'class="hidden"':'' ?>>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Amount Paid</label>
                    <input type="number" name="amount_paid" id="editPaid" value="<?= $sale['amount_paid'] ?>" min="0" step="0.01"
                        class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none text-right">
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Notes</label>
                    <textarea name="notes" rows="2" class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none resize-none"><?= h($sale['notes'] ?? '') ?></textarea>
                </div>

                <div class="pt-2 border-t text-sm space-y-1">
                    <div class="flex justify-between text-gray-600"><span>Subtotal:</span><span><?= formatMoney($sale['subtotal']) ?></span></div>
                    <div class="flex justify-between text-gray-600"><span>Discount:</span><span id="discDisplay">–<?= formatMoney($sale['discount_amount']) ?></span></div>
                    <div class="flex justify-between font-bold text-gray-800"><span>Total:</span><span id="totalEditDisplay"><?= formatMoney($sale['total_amount']) ?></span></div>
                    <div id="balanceEditRow" class="flex justify-between font-semibold text-red-600 <?= $sale['sale_type']==='cash'?'hidden':'' ?>">
                        <span>Balance:</span><span id="balanceEditDisplay"><?= formatMoney($sale['balance_due']) ?></span>
                    </div>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="submit" class="flex-1 bg-blue-600 text-white py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
                        <i class="fa-solid fa-save mr-1"></i> Save Changes
                    </button>
                    <a href="view.php?id=<?= $id ?>" class="flex-1 text-center bg-gray-100 text-gray-700 py-2 rounded-lg text-sm hover:bg-gray-200">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
const SUBTOTAL = <?= (float)$sale['subtotal'] ?>;

// Walk-in name toggle
const custSel = document.getElementById('custSelect');
const walkinW = document.getElementById('walkinWrap');
custSel.addEventListener('change', () => walkinW.classList.toggle('hidden', custSel.value !== ''));

// Sale type toggle
document.querySelectorAll('.type-opt').forEach(lbl => {
    lbl.addEventListener('click', function() {
        document.querySelectorAll('.type-opt').forEach(l => l.classList.remove('bg-blue-50','border-blue-500','font-semibold'));
        this.classList.add('bg-blue-50','border-blue-500','font-semibold');
        this.querySelector('input').checked = true;
        const isCash = this.dataset.type === 'cash';
        document.getElementById('dueDateEdit').classList.toggle('hidden', isCash);
        document.getElementById('paidWrapEdit').classList.toggle('hidden', isCash);
        document.getElementById('balanceEditRow').classList.toggle('hidden', isCash);
        recalcEdit();
    });
});

function recalcEdit() {
    const disc    = parseFloat(document.getElementById('editDiscount').value) || 0;
    const total   = Math.max(0, SUBTOTAL - disc);
    const isCash  = document.querySelector('input[name="sale_type"]:checked')?.value === 'cash';
    const paid    = isCash ? total : (parseFloat(document.getElementById('editPaid').value) || 0);
    const balance = Math.max(0, total - paid);
    document.getElementById('discDisplay').textContent      = '–' + disc.toFixed(2);
    document.getElementById('totalEditDisplay').textContent = '<?= CURRENCY_SYMBOL ?> ' + total.toFixed(2);
    document.getElementById('balanceEditDisplay').textContent = '<?= CURRENCY_SYMBOL ?> ' + balance.toFixed(2);
}

document.getElementById('editDiscount').addEventListener('input', recalcEdit);
document.getElementById('editPaid').addEventListener('input', recalcEdit);
</script>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
