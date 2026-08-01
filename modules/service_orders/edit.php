<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('sales');
if (isAdmin()) { redirect(url('admin/businesses')); }

$db    = getDB();
$bizId = currentBusinessId();
$id    = (int)get('id');
$errors = [];

$ordQ = $db->prepare('SELECT * FROM service_orders WHERE id=? AND business_id=?');
$ordQ->execute([$id, $bizId]);
$order = $ordQ->fetch();
if (!$order) { flash('error','Order not found.'); redirect(url('service-orders')); }
if (!in_array($order['status'], ['pending','in_progress'])) {
    flash('error','Only pending or in-progress orders can be edited.');
    redirect(url('service-orders/view') . "?id={$id}");
}

// Ensure services table exists
$db->exec("CREATE TABLE IF NOT EXISTS `services` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `business_id` INT UNSIGNED NOT NULL,
    `category_id` INT UNSIGNED DEFAULT NULL,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `price_type` ENUM('fixed','hourly','custom') NOT NULL DEFAULT 'fixed',
    `duration_minutes` INT UNSIGNED DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`), KEY `idx_svc_biz` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$custsQ = $db->prepare('SELECT id, name, phone FROM customers WHERE business_id=? AND is_active=1 ORDER BY name');
$custsQ->execute([$bizId]);
$customers = $custsQ->fetchAll();

$svcsQ = $db->prepare('SELECT id, name, price, price_type FROM services WHERE business_id=? AND is_active=1 ORDER BY name');
$svcsQ->execute([$bizId]);
$services    = $svcsQ->fetchAll();
$servicesMap = array_column($services, null, 'id');

$itemsQ = $db->prepare('SELECT * FROM service_order_items WHERE order_id=?');
$itemsQ->execute([$id]);
$existingItems = $itemsQ->fetchAll();

if (isPost()) {
    verifyCsrf();
    $customerId  = (int)post('customer_id') ?: null;
    $walkinName  = trim(post('walkin_name'));
    $walkinPhone = trim(post('walkin_phone'));
    $scheduledAt = post('scheduled_at') ?: null;
    $notes       = trim(post('notes'));
    $discountAmt = max(0, (float)post('discount_amount'));

    if (!$customerId && !$walkinName) $errors[] = 'Please select a customer or enter a walk-in name.';

    $svcIds    = $_POST['svc_id']    ?? [];
    $svcNames  = $_POST['svc_name']  ?? [];
    $svcPrices = $_POST['svc_price'] ?? [];
    $svcQtys   = $_POST['svc_qty']   ?? [];
    $svcDiscs  = $_POST['svc_disc']  ?? [];

    $lineItems = [];
    foreach ($svcIds as $idx => $sid) {
        $sid   = (int)$sid;
        $price = (float)($svcPrices[$idx] ?? 0);
        $qty   = max(0.01, (float)($svcQtys[$idx] ?? 1));
        $disc  = max(0, (float)($svcDiscs[$idx] ?? 0));
        $name  = trim($svcNames[$idx] ?? '');
        if (!$name) { $svc = $servicesMap[$sid] ?? null; $name = $svc ? $svc['name'] : 'Service'; }
        $lineItems[] = ['svc_id'=>$sid?:null,'name'=>$name,'price'=>$price,'qty'=>$qty,'disc'=>$disc,'total'=>max(0,($price*$qty)-$disc)];
    }
    if (empty($lineItems)) $errors[] = 'Please add at least one service.';

    if (empty($errors)) {
        $subtotal  = array_sum(array_column($lineItems, 'total'));
        $total     = max(0, $subtotal - $discountAmt);
        $newBalance = max(0, $total - $order['amount_paid']);
        $payStatus  = $newBalance <= 0 ? 'paid' : ($order['amount_paid'] > 0 ? 'partial' : 'unpaid');

        $db->beginTransaction();
        try {
            $db->prepare('UPDATE service_orders SET customer_id=?,walkin_name=?,walkin_phone=?,scheduled_at=?,subtotal=?,discount_amount=?,total_amount=?,balance_due=?,payment_status=?,notes=?,updated_at=NOW() WHERE id=? AND business_id=?')
               ->execute([$customerId,$walkinName?:null,$walkinPhone?:null,$scheduledAt,$subtotal,$discountAmt,$total,$newBalance,$payStatus,$notes?:null,$id,$bizId]);
            $db->prepare('DELETE FROM service_order_items WHERE order_id=?')->execute([$id]);
            $iStmt = $db->prepare('INSERT INTO service_order_items (order_id,service_id,service_name,unit_price,quantity,discount,total) VALUES (?,?,?,?,?,?,?)');
            foreach ($lineItems as $item) {
                $iStmt->execute([$id,$item['svc_id'],$item['name'],$item['price'],$item['qty'],$item['disc'],$item['total']]);
            }
            $db->commit();
            flash('success','Order updated.');
            redirect(url('service-orders/view') . "?id={$id}");
        } catch (\Exception $ex) {
            $db->rollBack();
            $errors[] = 'Database error: ' . $ex->getMessage();
        }
    }
}

$f = isPost() ? $_POST : $order;

$pageTitle = 'Edit Order: ' . $order['order_number'];
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="max-w-4xl mx-auto">
    <div class="flex items-center gap-3 mb-6">
        <a href="view.php?id=<?= $id ?>" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
        <h2 class="text-xl font-bold text-gray-800">Edit Order: <?= h($order['order_number']) ?></h2>
    </div>
    <?php if ($errors): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
        <?php foreach ($errors as $e): ?><p class="text-red-700 text-sm"><?= h($e) ?></p><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" id="order-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <div class="space-y-5">

            <!-- Customer -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                <h3 class="font-semibold text-gray-700 mb-4 flex items-center gap-2">
                    <i class="fa-solid fa-user text-blue-500"></i> Customer
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Registered Customer</label>
                        <select name="customer_id" id="customer_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none" onchange="toggleWalkin(this.value)">
                            <option value="">-- Walk-in --</option>
                            <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= ($f['customer_id']??'')==$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="walkin-fields">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Walk-in Name</label>
                        <input type="text" name="walkin_name" value="<?= h($f['walkin_name'] ?? '') ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none">
                    </div>
                    <div id="walkin-phone-field">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Walk-in Phone</label>
                        <input type="text" name="walkin_phone" value="<?= h($f['walkin_phone'] ?? '') ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none">
                    </div>
                </div>
            </div>

            <!-- Services -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                <h3 class="font-semibold text-gray-700 mb-4 flex items-center gap-2">
                    <i class="fa-solid fa-briefcase text-purple-500"></i> Services
                </h3>
                <div class="flex gap-2 mb-4">
                    <select id="svc-select" class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none">
                        <option value="">-- Select a service --</option>
                        <?php foreach ($services as $svc): ?>
                        <option value="<?= $svc['id'] ?>" data-price="<?= $svc['price'] ?>" data-name="<?= h(addslashes($svc['name'])) ?>">
                            <?= h($svc['name']) ?> — <?= formatMoney($svc['price']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" onclick="addSelectedService()" class="bg-purple-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-purple-700">
                        <i class="fa-solid fa-plus mr-1"></i> Add
                    </button>
                </div>
                <div class="border border-gray-200 rounded-lg overflow-hidden">
                    <table class="w-full text-sm" id="items-table">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-3 py-2 text-left">Service</th>
                                <th class="px-3 py-2 text-right w-32">Unit Price</th>
                                <th class="px-3 py-2 text-right w-24">Qty</th>
                                <th class="px-3 py-2 text-right w-28">Discount</th>
                                <th class="px-3 py-2 text-right w-28">Total</th>
                                <th class="px-3 py-2 w-8"></th>
                            </tr>
                        </thead>
                        <tbody id="items-body" class="divide-y"></tbody>
                        <tfoot class="border-t bg-gray-50 text-sm">
                            <tr>
                                <td colspan="4" class="px-3 py-2 text-right text-gray-500">Subtotal:</td>
                                <td class="px-3 py-2 text-right font-medium" id="subtotal-display">0.00</td>
                                <td></td>
                            </tr>
                            <tr>
                                <td colspan="3" class="px-3 py-2 text-right text-gray-500">Discount:</td>
                                <td class="px-3 py-2">
                                    <input type="number" name="discount_amount" id="order-discount" value="<?= h($f['discount_amount'] ?? '0') ?>" min="0" step="0.01"
                                        class="w-full px-2 py-1 border border-gray-300 rounded text-sm text-right outline-none" oninput="recalculate()">
                                </td>
                                <td class="px-3 py-2 text-right text-red-500 font-medium" id="discount-display">0.00</td>
                                <td></td>
                            </tr>
                            <tr class="font-bold text-base">
                                <td colspan="4" class="px-3 py-3 text-right text-gray-700">Total:</td>
                                <td class="px-3 py-3 text-right" id="total-display">0.00</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Schedule & Notes -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                <h3 class="font-semibold text-gray-700 mb-4 flex items-center gap-2">
                    <i class="fa-solid fa-calendar-check text-green-500"></i> Schedule & Notes
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Scheduled Date/Time</label>
                        <input type="datetime-local" name="scheduled_at"
                            value="<?= $order['scheduled_at'] ? date('Y-m-d\TH:i', strtotime($order['scheduled_at'])) : '' ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none">
                    </div>
                    <div class="md:col-span-2 md:col-start-1">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                        <textarea name="notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none resize-none"><?= h($f['notes'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex gap-3 mt-6">
            <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700">
                <i class="fa-solid fa-save mr-1"></i> Save Changes
            </button>
            <a href="view.php?id=<?= $id ?>" class="bg-gray-100 text-gray-700 px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-gray-200">Cancel</a>
        </div>
    </form>
</div>

<script>
var itemIdx = 0;
// Pre-populate existing items
var existing = <?= json_encode(array_values($existingItems)) ?>;
existing.forEach(function(item) {
    addServiceRow(item.service_id || 0, item.service_name, item.unit_price, item.quantity, item.discount);
});

function fmt(n) { return parseFloat(n||0).toFixed(2); }

function recalculate() {
    var subtotal = 0;
    document.querySelectorAll('.item-row').forEach(function(row) {
        var price = parseFloat(row.querySelector('.svc-price').value)||0;
        var qty   = parseFloat(row.querySelector('.svc-qty').value)||0;
        var disc  = parseFloat(row.querySelector('.svc-disc').value)||0;
        var total = Math.max(0,(price*qty)-disc);
        row.querySelector('.svc-total').textContent = fmt(total);
        subtotal += total;
    });
    var orderDisc = parseFloat(document.getElementById('order-discount').value)||0;
    var total = Math.max(0, subtotal - orderDisc);
    document.getElementById('subtotal-display').textContent = fmt(subtotal);
    document.getElementById('discount-display').textContent = fmt(orderDisc);
    document.getElementById('total-display').textContent    = fmt(total);
}

function addServiceRow(svcId, svcName, price, qty, disc) {
    itemIdx++;
    var tr = document.createElement('tr');
    tr.className = 'item-row'; tr.id = 'row-' + itemIdx;
    qty = qty || 1; disc = disc || 0;
    tr.innerHTML = '<td class="px-3 py-2"><input type="hidden" name="svc_id[]" value="' + svcId + '"><input type="hidden" name="svc_name[]" value="' + escHtml(svcName) + '"><span class="font-medium">' + escHtml(svcName) + '</span></td>' +
        '<td class="px-3 py-2"><input type="number" name="svc_price[]" class="svc-price w-full px-2 py-1 border border-gray-300 rounded text-sm text-right outline-none" value="' + fmt(price) + '" min="0" step="0.01" oninput="recalculate()"></td>' +
        '<td class="px-3 py-2"><input type="number" name="svc_qty[]"   class="svc-qty   w-full px-2 py-1 border border-gray-300 rounded text-sm text-right outline-none" value="' + fmt(qty) + '" min="0.01" step="0.01" oninput="recalculate()"></td>' +
        '<td class="px-3 py-2"><input type="number" name="svc_disc[]"  class="svc-disc  w-full px-2 py-1 border border-gray-300 rounded text-sm text-right outline-none" value="' + fmt(disc) + '" min="0" step="0.01" oninput="recalculate()"></td>' +
        '<td class="px-3 py-2 text-right font-semibold svc-total">' + fmt((price*qty)-disc) + '</td>' +
        '<td class="px-2 py-2 text-center"><button type="button" onclick="removeRow(' + itemIdx + ')" class="text-red-400 hover:text-red-600"><i class="fa-solid fa-times"></i></button></td>';
    document.getElementById('items-body').appendChild(tr);
    recalculate();
}

function removeRow(idx) { var r = document.getElementById('row-' + idx); if (r) r.remove(); recalculate(); }

function addSelectedService() {
    var sel = document.getElementById('svc-select');
    if (!sel.value) return;
    var opt = sel.options[sel.selectedIndex];
    addServiceRow(sel.value, opt.getAttribute('data-name'), parseFloat(opt.getAttribute('data-price'))||0);
    sel.value = '';
}

function toggleWalkin(val) {
    var show = !val;
    document.getElementById('walkin-fields').style.display = show ? '' : 'none';
    document.getElementById('walkin-phone-field').style.display = show ? '' : 'none';
}

function escHtml(str) { var d=document.createElement('div'); d.appendChild(document.createTextNode(str)); return d.innerHTML; }

toggleWalkin(document.getElementById('customer_id').value);
</script>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
