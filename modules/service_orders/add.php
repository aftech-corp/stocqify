<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('sales');
if (isAdmin()) { redirect(url('admin/businesses')); }

$db    = getDB();
$bizId = currentBusinessId();
$errors = [];

// Ensure tables exist
$db->exec("CREATE TABLE IF NOT EXISTS `service_orders` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `business_id` INT UNSIGNED NOT NULL,
    `customer_id` INT UNSIGNED DEFAULT NULL,
    `walkin_name` VARCHAR(255) DEFAULT NULL,
    `walkin_phone` VARCHAR(50) DEFAULT NULL,
    `order_number` VARCHAR(50) NOT NULL DEFAULT '',
    `status` ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
    `payment_status` ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
    `scheduled_at` DATETIME DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `discount_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `amount_paid` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `balance_due` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `payment_method` VARCHAR(50) DEFAULT 'cash',
    `notes` TEXT DEFAULT NULL,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`), KEY `idx_so_biz` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$db->exec("CREATE TABLE IF NOT EXISTS `service_order_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id` INT UNSIGNED NOT NULL,
    `service_id` INT UNSIGNED DEFAULT NULL,
    `service_name` VARCHAR(255) NOT NULL,
    `unit_price` DECIMAL(15,2) NOT NULL,
    `quantity` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    `discount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `total` DECIMAL(15,2) NOT NULL,
    `notes` TEXT DEFAULT NULL,
    PRIMARY KEY (`id`), KEY `fk_soi_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Ensure services table exists (may not have been created yet if catalog was never visited)
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
// Extend payments table
try { $db->exec("ALTER TABLE payments ADD COLUMN IF NOT EXISTS service_order_id INT UNSIGNED DEFAULT NULL"); } catch (\Exception $e) {}

// Load customers and services for selects
$custsQ = $db->prepare('SELECT id, name, phone FROM customers WHERE business_id=? AND is_active=1 ORDER BY name');
$custsQ->execute([$bizId]);
$customers = $custsQ->fetchAll();

$svcsQ = $db->prepare('SELECT id, name, price, price_type, duration_minutes FROM services WHERE business_id=? AND is_active=1 ORDER BY name');
$svcsQ->execute([$bizId]);
$services    = $svcsQ->fetchAll();
$servicesMap = array_column($services, null, 'id');

if (isPost()) {
    verifyCsrf();
    $customerId  = (int)post('customer_id') ?: null;
    $walkinName  = trim(post('walkin_name'));
    $walkinPhone = trim(post('walkin_phone'));
    $scheduledAt = post('scheduled_at') ?: null;
    $notes       = trim(post('notes'));
    $payMethod   = post('payment_method', 'cash');
    $amountPaid  = max(0, (float)post('amount_paid'));
    $discountAmt = max(0, (float)post('discount_amount'));
    $initialStatus = in_array(post('initial_status'), ['pending','in_progress']) ? post('initial_status') : 'pending';

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
        $lineItems[] = ['svc_id'=>$sid?:null, 'name'=>$name, 'price'=>$price, 'qty'=>$qty, 'disc'=>$disc, 'total'=>($price*$qty)-$disc];
    }
    if (empty($lineItems)) $errors[] = 'Please add at least one service to the order.';

    if (empty($errors)) {
        $subtotal = array_sum(array_column($lineItems, 'total'));
        $total    = max(0, $subtotal - $discountAmt);
        $amountPaid = min($total, $amountPaid);
        $balance    = $total - $amountPaid;
        $payStatus  = $balance <= 0 ? 'paid' : ($amountPaid > 0 ? 'partial' : 'unpaid');
        $me         = currentUser();

        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO service_orders (business_id,customer_id,walkin_name,walkin_phone,status,payment_status,scheduled_at,subtotal,discount_amount,total_amount,amount_paid,balance_due,payment_method,notes,user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
               ->execute([$bizId,$customerId,$walkinName?:null,$walkinPhone?:null,$initialStatus,$payStatus,$scheduledAt,$subtotal,$discountAmt,$total,$amountPaid,$balance,$payMethod,$notes?:null,$me['id']]);
            $orderId = (int)$db->lastInsertId();

            $orderNum = 'SRV-' . date('Ymd') . '-' . str_pad($orderId, 4, '0', STR_PAD_LEFT);
            $db->prepare('UPDATE service_orders SET order_number=? WHERE id=?')->execute([$orderNum, $orderId]);

            $iStmt = $db->prepare('INSERT INTO service_order_items (order_id,service_id,service_name,unit_price,quantity,discount,total) VALUES (?,?,?,?,?,?,?)');
            foreach ($lineItems as $item) {
                $iStmt->execute([$orderId,$item['svc_id'],$item['name'],$item['price'],$item['qty'],$item['disc'],$item['total']]);
            }

            if ($amountPaid > 0) {
                $db->prepare('INSERT INTO payments (business_id,customer_id,service_order_id,amount,payment_method,payment_date,user_id) VALUES (?,?,?,?,?,NOW(),?)')
                   ->execute([$bizId,$customerId,$orderId,$amountPaid,$payMethod,$me['id']]);
            }

            $db->commit();
            auditLog('create','service_orders',$orderId,[],['order_number'=>$orderNum,'total'=>$total]);
            flash('success',"Order {$orderNum} created successfully.");
            redirect(url('service-orders/view') . "?id={$orderId}");
        } catch (\Exception $ex) {
            $db->rollBack();
            $errors[] = 'Database error: ' . $ex->getMessage();
        }
    }
}

$pageTitle = 'New Service Order';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="max-w-4xl mx-auto">
    <div class="flex items-center gap-3 mb-6">
        <a href="index.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
        <h2 class="text-xl font-bold text-gray-800">New Service Order</h2>
    </div>
    <?php if ($errors): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
        <?php foreach ($errors as $e): ?><p class="text-red-700 text-sm"><?= h($e) ?></p><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($services)): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-6 text-center">
        <i class="fa-solid fa-hand-holding-heart text-4xl text-amber-400 mb-3"></i>
        <p class="text-amber-800 font-semibold">No active services found.</p>
        <p class="text-amber-600 text-sm mt-1">Add services to your catalog first before creating orders.</p>
        <a href="../services/add.php" class="inline-block mt-3 bg-amber-500 text-white px-4 py-2 rounded-lg text-sm hover:bg-amber-600">
            <i class="fa-solid fa-plus mr-1"></i> Add Service
        </a>
    </div>
    <?php else: ?>
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
                        <select name="customer_id" id="customer_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none" onchange="toggleWalkin(this.value)">
                            <option value="">-- Walk-in / Anonymous --</option>
                            <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= h($c['name']) ?> <?= $c['phone']?'('.$c['phone'].')':'' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="walkin-fields">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Walk-in Name <span class="text-gray-400 text-xs">(if no account)</span></label>
                        <input type="text" name="walkin_name" value="<?= h(post('walkin_name','')) ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none" placeholder="e.g. John Kamara">
                    </div>
                    <div id="walkin-phone-field">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Walk-in Phone</label>
                        <input type="text" name="walkin_phone" value="<?= h(post('walkin_phone','')) ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none" placeholder="+232...">
                    </div>
                </div>
            </div>

            <!-- Services -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                <h3 class="font-semibold text-gray-700 mb-4 flex items-center gap-2">
                    <i class="fa-solid fa-briefcase text-purple-500"></i> Services
                </h3>

                <!-- Service picker -->
                <div class="flex gap-2 mb-4">
                    <select id="svc-select" class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        <option value="">-- Select a service to add --</option>
                        <?php foreach ($services as $svc): ?>
                        <option value="<?= $svc['id'] ?>"
                            data-price="<?= $svc['price'] ?>"
                            data-name="<?= h(addslashes($svc['name'])) ?>"
                            data-type="<?= $svc['price_type'] ?>">
                            <?= h($svc['name']) ?> — <?= formatMoney($svc['price']) ?><?= $svc['price_type']==='hourly'?'/hr':'' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" onclick="addSelectedService()" class="bg-purple-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-purple-700">
                        <i class="fa-solid fa-plus mr-1"></i> Add
                    </button>
                </div>

                <!-- Line items table -->
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
                        <tbody id="items-body" class="divide-y">
                            <tr id="empty-row">
                                <td colspan="6" class="px-4 py-6 text-center text-gray-400 text-sm">
                                    <i class="fa-solid fa-hand-holding-heart mr-2"></i>No services added yet. Select a service above.
                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t bg-gray-50 text-sm">
                            <tr>
                                <td colspan="4" class="px-3 py-2 text-right text-gray-500">Subtotal:</td>
                                <td class="px-3 py-2 text-right font-medium" id="subtotal-display">0.00</td>
                                <td></td>
                            </tr>
                            <tr>
                                <td colspan="3" class="px-3 py-2 text-right text-gray-500">Order Discount:</td>
                                <td class="px-3 py-2">
                                    <input type="number" name="discount_amount" id="order-discount" value="0" min="0" step="0.01"
                                        class="w-full px-2 py-1 border border-gray-300 rounded text-sm text-right outline-none" oninput="recalculate()">
                                </td>
                                <td class="px-3 py-2 text-right text-red-500 font-medium" id="discount-display">0.00</td>
                                <td></td>
                            </tr>
                            <tr class="font-bold text-base">
                                <td colspan="4" class="px-3 py-3 text-right text-gray-700">Total:</td>
                                <td class="px-3 py-3 text-right text-gray-900" id="total-display">0.00</td>
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
                        <label class="block text-sm font-medium text-gray-700 mb-1">Scheduled Date/Time <span class="text-gray-400 text-xs">(optional)</span></label>
                        <input type="datetime-local" name="scheduled_at"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Initial Status</label>
                        <select name="initial_status" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                            <option value="pending">Pending</option>
                            <option value="in_progress">In Progress (start immediately)</option>
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                        <textarea name="notes" rows="2"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none resize-none"
                            placeholder="Any special instructions or notes..."><?= h(post('notes','')) ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Payment -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                <h3 class="font-semibold text-gray-700 mb-4 flex items-center gap-2">
                    <i class="fa-solid fa-money-bill text-green-500"></i> Payment
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label>
                        <select name="payment_method" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                            <?php foreach (['cash'=>'Cash','bank_transfer'=>'Bank Transfer','mobile_money'=>'Mobile Money','cheque'=>'Cheque','pos'=>'POS/Card'] as $val=>$lbl): ?>
                            <option value="<?= $val ?>"><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Amount Paid Now (<?= currencySymbol() ?>)</label>
                        <input type="number" name="amount_paid" id="amount-paid" value="0" min="0" step="0.01"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none" oninput="recalculate()">
                    </div>
                    <div class="flex flex-col justify-center">
                        <p class="text-xs text-gray-400 mb-1">Balance Due</p>
                        <p class="text-2xl font-bold text-red-600" id="balance-display">0.00</p>
                    </div>
                </div>
                <div class="mt-3 p-3 bg-blue-50 rounded-lg text-xs text-blue-700 flex items-center gap-2">
                    <i class="fa-solid fa-info-circle"></i>
                    Set amount paid to 0 to mark as unpaid. You can record additional payments later from the order view.
                </div>
            </div>

        </div><!-- /space-y-5 -->

        <div class="flex gap-3 mt-6">
            <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700">
                <i class="fa-solid fa-check mr-1"></i> Create Order
            </button>
            <a href="index.php" class="bg-gray-100 text-gray-700 px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-gray-200">Cancel</a>
        </div>
    </form>
    <?php endif; ?>
</div>

<style>
.item-row input[type="number"] { -moz-appearance: textfield; }
.item-row input[type="number"]::-webkit-outer-spin-button,
.item-row input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
</style>

<script>
var itemIdx = 0;
var currency = '<?= currencySymbol() ?>';

function fmt(n) {
    return parseFloat(n || 0).toFixed(2);
}

function recalculate() {
    var subtotal = 0;
    document.querySelectorAll('.item-row').forEach(function(row) {
        var price = parseFloat(row.querySelector('.svc-price').value) || 0;
        var qty   = parseFloat(row.querySelector('.svc-qty').value) || 0;
        var disc  = parseFloat(row.querySelector('.svc-disc').value) || 0;
        var total = Math.max(0, (price * qty) - disc);
        row.querySelector('.svc-total').textContent = fmt(total);
        subtotal += total;
    });
    var orderDisc = parseFloat(document.getElementById('order-discount').value) || 0;
    var total     = Math.max(0, subtotal - orderDisc);
    var paid      = parseFloat(document.getElementById('amount-paid').value) || 0;
    var balance   = Math.max(0, total - paid);

    document.getElementById('subtotal-display').textContent = fmt(subtotal);
    document.getElementById('discount-display').textContent = fmt(orderDisc);
    document.getElementById('total-display').textContent    = fmt(total);
    document.getElementById('balance-display').textContent  = fmt(balance);
}

function addServiceRow(svcId, svcName, price) {
    itemIdx++;
    var emptyRow = document.getElementById('empty-row');
    if (emptyRow) emptyRow.remove();

    var tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.id = 'row-' + itemIdx;
    tr.innerHTML = '<td class="px-3 py-2">' +
        '<input type="hidden" name="svc_id[]" value="' + svcId + '">' +
        '<input type="hidden" name="svc_name[]" value="' + escHtml(svcName) + '">' +
        '<span class="font-medium text-gray-800">' + escHtml(svcName) + '</span>' +
        '</td>' +
        '<td class="px-3 py-2">' +
        '<input type="number" name="svc_price[]" class="svc-price w-full px-2 py-1 border border-gray-300 rounded text-sm text-right outline-none" value="' + fmt(price) + '" min="0" step="0.01" oninput="recalculate()">' +
        '</td>' +
        '<td class="px-3 py-2">' +
        '<input type="number" name="svc_qty[]" class="svc-qty w-full px-2 py-1 border border-gray-300 rounded text-sm text-right outline-none" value="1" min="0.01" step="0.01" oninput="recalculate()">' +
        '</td>' +
        '<td class="px-3 py-2">' +
        '<input type="number" name="svc_disc[]" class="svc-disc w-full px-2 py-1 border border-gray-300 rounded text-sm text-right outline-none" value="0" min="0" step="0.01" oninput="recalculate()">' +
        '</td>' +
        '<td class="px-3 py-2 text-right font-semibold svc-total">' + fmt(price) + '</td>' +
        '<td class="px-2 py-2 text-center">' +
        '<button type="button" onclick="removeRow(' + itemIdx + ')" class="text-red-400 hover:text-red-600 text-sm">' +
        '<i class="fa-solid fa-times"></i></button>' +
        '</td>';
    document.getElementById('items-body').appendChild(tr);
    recalculate();
}

function removeRow(idx) {
    var row = document.getElementById('row-' + idx);
    if (row) row.remove();
    if (!document.querySelector('.item-row')) {
        var tbody = document.getElementById('items-body');
        var tr = document.createElement('tr');
        tr.id = 'empty-row';
        tr.innerHTML = '<td colspan="6" class="px-4 py-6 text-center text-gray-400 text-sm"><i class="fa-solid fa-hand-holding-heart mr-2"></i>No services added yet.</td>';
        tbody.appendChild(tr);
    }
    recalculate();
}

function addSelectedService() {
    var sel = document.getElementById('svc-select');
    if (!sel.value) return;
    var opt  = sel.options[sel.selectedIndex];
    var name  = opt.getAttribute('data-name');
    var price = parseFloat(opt.getAttribute('data-price')) || 0;
    addServiceRow(sel.value, name, price);
    sel.value = '';
}

function toggleWalkin(val) {
    var show = !val;
    document.getElementById('walkin-fields').style.display = show ? '' : 'none';
    document.getElementById('walkin-phone-field').style.display = show ? '' : 'none';
}

function escHtml(str) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(str));
    return d.innerHTML;
}

toggleWalkin(document.getElementById('customer_id').value);
</script>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
