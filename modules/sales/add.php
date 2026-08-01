<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('sales');

$db    = getDB();
$bizId = currentBusinessId();
$errors = [];
$preCustomer = (int)get('customer_id');

// Load customers and products for dropdowns
$custsQ = $db->prepare('SELECT id, name, phone FROM customers WHERE business_id=? AND is_active=1 ORDER BY name');
$custsQ->execute([$bizId]);
$customers = $custsQ->fetchAll();

$prodsQ = $db->prepare('SELECT id, name, cost_price, selling_price, stock_quantity, unit FROM products WHERE business_id=? AND is_active=1 ORDER BY name');
$prodsQ->execute([$bizId]);
$products = $prodsQ->fetchAll();
$productsMap = array_column($products, null, 'id');

if (isPost()) {
    verifyCsrf();
    $customerId    = (int)post('customer_id') ?: null;
    $walkinName    = $customerId ? null : trim(post('walkin_name'));
    $saleType      = post('sale_type', 'cash');
    $saleDate      = post('sale_date', date('Y-m-d'));
    $dueDate       = post('due_date') ?: null;
    $payMethod     = post('payment_method', 'cash');
    $amountPaid    = (float)post('amount_paid', 0);
    $discount      = (float)post('discount_amount', 0);
    $notes         = post('notes');
    $itemIds       = $_POST['product_id'] ?? [];
    $itemQtys      = $_POST['quantity'] ?? [];
    $itemPrices    = $_POST['unit_price'] ?? [];
    $itemDiscounts = $_POST['item_discount'] ?? [];

    // Validate items
    $lineItems = [];
    foreach ($itemIds as $idx => $pid) {
        $pid   = (int)$pid;
        $qty   = (float)($itemQtys[$idx] ?? 0);
        $price = (float)($itemPrices[$idx] ?? 0);
        $disc  = (float)($itemDiscounts[$idx] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;
        $prod = $productsMap[$pid] ?? null;
        if (!$prod) { $errors[] = "Invalid product selected."; break; }
        if ($qty > $prod['stock_quantity']) {
            $errors[] = "Insufficient stock for {$prod['name']} (available: {$prod['stock_quantity']} {$prod['unit']}).";
        }
        $lineItems[] = ['product_id'=>$pid,'quantity'=>$qty,'unit_price'=>$price,'discount'=>$disc,'total'=>($qty*$price)-$disc,'cost_price'=>$prod['cost_price']];
    }
    if (empty($lineItems) && empty($errors)) $errors[] = 'Please add at least one product.';
    if (!in_array($saleType, ['cash','credit'])) $errors[] = 'Invalid sale type.';
    if ($saleType === 'credit' && !$customerId) $errors[] = 'A customer must be selected for credit sales. Walk-in customers cannot have credit.';

    if (empty($errors)) {
        $subtotal = array_sum(array_column($lineItems, 'total'));
        $total    = $subtotal - $discount;
        if ($total < 0) $total = 0;
        // Cash sales are always fully paid at point of sale
        if ($saleType === 'cash') $amountPaid = $total;
        if ($amountPaid > $total) $amountPaid = $total;
        $balance = $total - $amountPaid;
        $payStatus = $balance <= 0 ? 'paid' : ($amountPaid > 0 ? 'partial' : 'unpaid');
        $invoiceNo = generateInvoiceNumber($bizId);

        $db->beginTransaction();
        try {
            // Insert sale
            $stmt = $db->prepare('INSERT INTO sales (business_id,customer_id,walkin_name,user_id,invoice_number,sale_type,subtotal,discount_amount,total_amount,amount_paid,balance_due,payment_method,payment_status,notes,sale_date,due_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$bizId,$customerId,$walkinName?:null,currentUser()['id'],$invoiceNo,$saleType,$subtotal,$discount,$total,$amountPaid,$balance,$payMethod,$payStatus,$notes?:null,$saleDate,$dueDate]);
            $saleId = (int)$db->lastInsertId();

            // Insert sale items + deduct stock
            foreach ($lineItems as $item) {
                $db->prepare('INSERT INTO sale_items (sale_id,product_id,quantity,unit_price,cost_price,discount,total) VALUES (?,?,?,?,?,?,?)')->execute([
                    $saleId,$item['product_id'],$item['quantity'],$item['unit_price'],$item['cost_price'],$item['discount'],$item['total']
                ]);
                $prod = $productsMap[$item['product_id']];
                $before = $prod['stock_quantity'];
                $after  = $before - $item['quantity'];
                $db->prepare('UPDATE products SET stock_quantity=? WHERE id=?')->execute([$after, $item['product_id']]);
                $db->prepare('INSERT INTO inventory_transactions (business_id,product_id,user_id,type,quantity,before_quantity,after_quantity,reference) VALUES (?,?,?,?,?,?,?,?)')->execute(
                    [$bizId,$item['product_id'],currentUser()['id'],'sale',$item['quantity'],$before,$after,$invoiceNo]
                );
            }

            // Record payment if paid something
            if ($amountPaid > 0) {
                $db->prepare('INSERT INTO payments (business_id,sale_id,customer_id,user_id,amount,payment_method,payment_date) VALUES (?,?,?,?,?,?,?)')->execute(
                    [$bizId,$saleId,$customerId,currentUser()['id'],$amountPaid,$payMethod,$saleDate]
                );
            }

            // Create debt record for credit sales with balance
            if ($balance > 0) {
                $db->prepare('INSERT INTO debts (business_id,customer_id,sale_id,original_amount,amount_paid,balance,due_date,status) VALUES (?,?,?,?,?,?,?,?)')->execute(
                    [$bizId,$customerId,$saleId,$total,$amountPaid,$balance,$dueDate,
                    ($balance===$total?'active':'partial')]
                );
            }

            $db->commit();
            auditLog('create','sales',$saleId,[],['invoice'=>$invoiceNo,'total'=>$total]);
            flash('success', "Sale recorded: {$invoiceNo} — {$payStatus}.");
            redirect(url('sales/view') . '?id=' . $saleId);
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Failed to record sale: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'New Sale';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center gap-3 mb-6">
    <a href="index.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
    <h2 class="text-xl font-bold text-gray-800">Record New Sale</h2>
</div>

<?php if ($errors): ?>
<div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
    <?php foreach ($errors as $e): ?><p class="text-red-700 text-sm"><i class="fa-solid fa-circle-exclamation mr-1"></i><?= h($e) ?></p><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="POST" id="saleForm">
    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Products Table -->
        <div class="lg:col-span-2 space-y-4">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                <h3 class="font-semibold text-gray-800 mb-4">Sale Items</h3>
                <div class="table-responsive">
                    <table class="w-full text-sm" id="itemsTable">
                        <thead class="bg-gray-50 text-xs">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium text-gray-600">Product</th>
                                <th class="px-3 py-2 text-right font-medium text-gray-600">Price</th>
                                <th class="px-3 py-2 text-right font-medium text-gray-600">Qty</th>
                                <th class="px-3 py-2 text-right font-medium text-gray-600">Discount</th>
                                <th class="px-3 py-2 text-right font-medium text-gray-600">Total</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody">
                            <tr class="item-row" id="row-0">
                                <td class="px-3 py-2">
                                    <select name="product_id[]" class="product-select w-full border border-gray-300 rounded text-sm px-2 py-1.5 outline-none focus:ring-2 focus:ring-blue-500">
                                        <option value="">-- Select Product --</option>
                                        <?php foreach ($products as $p): ?>
                                        <option value="<?= $p['id'] ?>" data-price="<?= $p['selling_price'] ?>" data-stock="<?= $p['stock_quantity'] ?>" data-unit="<?= h($p['unit']) ?>">
                                            <?= h($p['name']) ?> (<?= number_format($p['stock_quantity'],0) ?> <?= h($p['unit']) ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="px-3 py-2">
                                    <input type="number" name="unit_price[]" class="price-input w-24 border border-gray-300 rounded text-sm px-2 py-1.5 text-right outline-none" min="0" step="0.01" value="0">
                                </td>
                                <td class="px-3 py-2">
                                    <input type="number" name="quantity[]" class="qty-input w-20 border border-gray-300 rounded text-sm px-2 py-1.5 text-right outline-none" min="0.01" step="0.01" value="1">
                                </td>
                                <td class="px-3 py-2">
                                    <input type="number" name="item_discount[]" class="disc-input w-24 border border-gray-300 rounded text-sm px-2 py-1.5 text-right outline-none" min="0" step="0.01" value="0">
                                </td>
                                <td class="px-3 py-2 text-right font-semibold row-total text-gray-800">0.00</td>
                                <td class="px-3 py-2">
                                    <button type="button" onclick="removeRow(this)" class="text-red-400 hover:text-red-600"><i class="fa-solid fa-times"></i></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <button type="button" onclick="addRow()" class="mt-3 text-blue-600 text-sm hover:underline flex items-center gap-1">
                    <i class="fa-solid fa-plus"></i> Add another item
                </button>
            </div>
        </div>

        <!-- Sale Summary Sidebar -->
        <div class="space-y-4">
            <!-- Customer & Sale Type -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                <h3 class="font-semibold text-gray-800 mb-4">Sale Details</h3>
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Customer</label>
                        <select name="customer_id" id="customerSelect" class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Walk-in Customer</option>
                            <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $preCustomer==$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="walkinNameWrap">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Walk-in Customer Name <span class="text-gray-400">(optional)</span></label>
                        <input type="text" name="walkin_name" id="walkinNameInput" placeholder="e.g. John Doe"
                            class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Sale Date</label>
                        <input type="date" name="sale_date" value="<?= date('Y-m-d') ?>"
                            class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Sale Type</label>
                        <div class="flex gap-2">
                            <label class="flex-1 flex items-center justify-center gap-2 border rounded-lg py-2 cursor-pointer sale-type-opt" data-type="cash">
                                <input type="radio" name="sale_type" value="cash" checked class="hidden"> <i class="fa-solid fa-money-bill text-green-600"></i> Cash
                            </label>
                            <label class="flex-1 flex items-center justify-center gap-2 border rounded-lg py-2 cursor-pointer sale-type-opt" data-type="credit">
                                <input type="radio" name="sale_type" value="credit" class="hidden"> <i class="fa-solid fa-credit-card text-blue-600"></i> Credit
                            </label>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Payment Method</label>
                        <select name="payment_method" class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
                            <option value="cash">Cash</option>
                            <option value="orange_money">Orange Money</option>
                            <option value="afrimoney">Afrimoney</option>
                            <option value="qmoney">QMoney</option>
                            <option value="bank_transfer">Bank Transfer</option>
                        </select>
                    </div>
                    <div id="dueDateWrap" class="hidden">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Due Date</label>
                        <input type="date" name="due_date"
                            class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Notes</label>
                        <textarea name="notes" rows="2"
                            class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none resize-none"
                            placeholder="Optional..."></textarea>
                    </div>
                </div>
            </div>

            <!-- Totals -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                <h3 class="font-semibold text-gray-800 mb-3">Summary</h3>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between text-gray-600"><span>Subtotal:</span><span id="subtotalDisplay">0.00</span></div>
                    <div class="flex justify-between items-center text-gray-600">
                        <span>Discount:</span>
                        <input type="number" name="discount_amount" id="discountInput" value="0" min="0" step="0.01"
                            class="w-24 border border-gray-300 rounded text-sm px-2 py-1 text-right outline-none">
                    </div>
                    <div class="flex justify-between font-bold text-gray-800 border-t pt-2 text-base">
                        <span>Total:</span><span id="totalDisplay">0.00</span>
                    </div>
                    <div class="flex justify-between items-center text-gray-600">
                        <span>Amount Paid:</span>
                        <input type="number" name="amount_paid" id="paidInput" value="0" min="0" step="0.01"
                            class="w-24 border border-gray-300 rounded text-sm px-2 py-1 text-right outline-none bg-white">
                    </div>
                    <div id="balanceRow" class="flex justify-between font-semibold text-red-600 border-t pt-2">
                        <span>Balance Due:</span><span id="balanceDisplay">0.00</span>
                    </div>
                </div>
                <button type="submit" class="mt-4 w-full bg-blue-600 text-white py-3 rounded-lg font-semibold hover:bg-blue-700 text-sm">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> Complete Sale
                </button>
            </div>
        </div>
    </div>
</form>

<!-- Product data for JS -->
<script>
const PRODUCTS = <?= json_encode(array_values($productsMap)) ?>;
const CURRENCY = '<?= CURRENCY_SYMBOL ?>';
let rowCount = 1;

function getProductsOptions(selectedId = '') {
    let opts = '<option value="">-- Select Product --</option>';
    PRODUCTS.forEach(p => {
        const sel = p.id == selectedId ? 'selected' : '';
        opts += `<option value="${p.id}" data-price="${p.selling_price}" data-stock="${p.stock_quantity}" data-unit="${p.unit}" ${sel}>${p.name} (${parseInt(p.stock_quantity)} ${p.unit})</option>`;
    });
    return opts;
}

function addRow() {
    const tbody = document.getElementById('itemsBody');
    const row = document.createElement('tr');
    row.className = 'item-row';
    row.id = 'row-' + rowCount++;
    row.innerHTML = `
        <td class="px-3 py-2"><select name="product_id[]" class="product-select w-full border border-gray-300 rounded text-sm px-2 py-1.5 outline-none focus:ring-2 focus:ring-blue-500">${getProductsOptions()}</select></td>
        <td class="px-3 py-2"><input type="number" name="unit_price[]" class="price-input w-24 border border-gray-300 rounded text-sm px-2 py-1.5 text-right outline-none" min="0" step="0.01" value="0"></td>
        <td class="px-3 py-2"><input type="number" name="quantity[]" class="qty-input w-20 border border-gray-300 rounded text-sm px-2 py-1.5 text-right outline-none" min="0.01" step="0.01" value="1"></td>
        <td class="px-3 py-2"><input type="number" name="item_discount[]" class="disc-input w-24 border border-gray-300 rounded text-sm px-2 py-1.5 text-right outline-none" min="0" step="0.01" value="0"></td>
        <td class="px-3 py-2 text-right font-semibold row-total text-gray-800">0.00</td>
        <td class="px-3 py-2"><button type="button" onclick="removeRow(this)" class="text-red-400 hover:text-red-600"><i class="fa-solid fa-times"></i></button></td>`;
    tbody.appendChild(row);
    attachRowListeners(row);
}

function removeRow(btn) {
    const rows = document.querySelectorAll('.item-row');
    if (rows.length <= 1) return;
    btn.closest('tr').remove();
    recalcTotal();
}

function attachRowListeners(row) {
    row.querySelector('.product-select').addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        const price = opt.dataset.price || 0;
        row.querySelector('.price-input').value = parseFloat(price).toFixed(2);
        calcRowTotal(row);
    });
    row.querySelectorAll('.price-input,.qty-input,.disc-input').forEach(el => {
        el.addEventListener('input', () => calcRowTotal(row));
    });
}

function calcRowTotal(row) {
    const price = parseFloat(row.querySelector('.price-input').value) || 0;
    const qty   = parseFloat(row.querySelector('.qty-input').value) || 0;
    const disc  = parseFloat(row.querySelector('.disc-input').value) || 0;
    const total = (price * qty) - disc;
    row.querySelector('.row-total').textContent = total.toFixed(2);
    recalcTotal();
}

function recalcTotal() {
    let subtotal = 0;
    document.querySelectorAll('.row-total').forEach(el => subtotal += parseFloat(el.textContent) || 0);
    const discount = parseFloat(document.getElementById('discountInput').value) || 0;
    const total = Math.max(0, subtotal - discount);
    const paid = parseFloat(document.getElementById('paidInput').value) || 0;
    const balance = Math.max(0, total - paid);
    document.getElementById('subtotalDisplay').textContent = subtotal.toFixed(2);
    document.getElementById('totalDisplay').textContent = total.toFixed(2);
    document.getElementById('balanceDisplay').textContent = balance.toFixed(2);
}

// Walk-in name field: show only when no customer selected
const customerSelect  = document.getElementById('customerSelect');
const walkinNameWrap  = document.getElementById('walkinNameWrap');
function toggleWalkinField() {
    walkinNameWrap.classList.toggle('hidden', customerSelect.value !== '');
}
customerSelect.addEventListener('change', toggleWalkinField);
toggleWalkinField();

// Sale type toggle — cash locks amount paid to total
function applySaleType(type) {
    document.querySelectorAll('.sale-type-opt').forEach(l => l.classList.remove('bg-blue-50','border-blue-500','font-semibold'));
    document.querySelector(`.sale-type-opt[data-type="${type}"]`).classList.add('bg-blue-50','border-blue-500','font-semibold');
    document.getElementById('dueDateWrap').classList.toggle('hidden', type === 'cash');
    const paidInput  = document.getElementById('paidInput');
    const balanceRow = document.getElementById('balanceRow');
    if (type === 'cash') {
        const total = parseFloat(document.getElementById('totalDisplay').textContent) || 0;
        paidInput.value = total.toFixed(2);
        paidInput.readOnly = true;
        paidInput.classList.add('bg-gray-100','cursor-not-allowed');
        balanceRow.classList.add('hidden');
    } else {
        paidInput.readOnly = false;
        paidInput.classList.remove('bg-gray-100','cursor-not-allowed');
        balanceRow.classList.remove('hidden');
    }
    recalcTotal();
}

document.querySelectorAll('.sale-type-opt').forEach(label => {
    label.addEventListener('click', function() {
        this.querySelector('input').checked = true;
        applySaleType(this.dataset.type);
    });
});

// Override recalcTotal to also update locked paid field for cash
const _origRecalc = recalcTotal;
function recalcTotal() {
    let subtotal = 0;
    document.querySelectorAll('.row-total').forEach(el => subtotal += parseFloat(el.textContent) || 0);
    const discount = parseFloat(document.getElementById('discountInput').value) || 0;
    const total = Math.max(0, subtotal - discount);
    const paidInput = document.getElementById('paidInput');
    if (paidInput.readOnly) paidInput.value = total.toFixed(2);
    const paid = parseFloat(paidInput.value) || 0;
    const balance = Math.max(0, total - paid);
    document.getElementById('subtotalDisplay').textContent = subtotal.toFixed(2);
    document.getElementById('totalDisplay').textContent    = total.toFixed(2);
    document.getElementById('balanceDisplay').textContent  = balance.toFixed(2);
}

// Re-attach listeners using the new recalcTotal
document.querySelectorAll('.item-row').forEach(attachRowListeners);
document.getElementById('discountInput').addEventListener('input', recalcTotal);
document.getElementById('paidInput').addEventListener('input', recalcTotal);

applySaleType('cash');
</script>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
