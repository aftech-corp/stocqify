<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('debts');

$db    = getDB();
$bizId = currentBusinessId();
$search = get('search');
$status = get('status');
$custId = (int)get('customer');
$page   = max(1,(int)get('page',1));
$today  = date('Y-m-d');

$where  = 'd.business_id=?';
$params = [$bizId];
if ($search) { $where.=' AND c.name LIKE ?'; $params[]="%$search%"; }
if ($status) { $where.=' AND d.status=?'; $params[]=$status; }
if ($custId) { $where.=' AND d.customer_id=?'; $params[]=$custId; }

// Stats
$stats = $db->prepare("SELECT SUM(balance) AS total_balance, SUM(CASE WHEN due_date < ? AND status NOT IN ('paid','written_off') THEN balance ELSE 0 END) AS overdue FROM debts WHERE business_id=? AND status NOT IN ('paid','written_off')");
$stats->execute([$today, $bizId]);
$s = $stats->fetch();

$totalQ = $db->prepare("SELECT COUNT(*) FROM debts d JOIN customers c ON c.id=d.customer_id WHERE $where");
$totalQ->execute($params);
$total = (int)$totalQ->fetchColumn();
$pag = paginate($total, $page);

$stmt = $db->prepare("SELECT d.*, c.name AS customer_name, c.phone AS customer_phone, s.invoice_number
    FROM debts d
    JOIN customers c ON c.id=d.customer_id
    LEFT JOIN sales s ON s.id=d.sale_id
    WHERE $where
    ORDER BY CASE d.status WHEN 'overdue' THEN 0 WHEN 'active' THEN 1 WHEN 'partial' THEN 2 ELSE 3 END, d.due_date ASC
    LIMIT {$pag['per_page']} OFFSET {$pag['offset']}");
$stmt->execute($params);
$debts = $stmt->fetchAll();

$pageTitle = 'Debt Management';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-6">
    <h2 class="text-xl font-bold text-gray-800">Debt Management</h2>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-orange-50 rounded-xl p-4 text-center">
        <p class="text-xl font-black text-orange-700"><?= formatMoney($s['total_balance']??0) ?></p>
        <p class="text-sm text-orange-600">Total Outstanding</p>
    </div>
    <div class="bg-red-50 rounded-xl p-4 text-center">
        <p class="text-xl font-black text-red-700"><?= formatMoney($s['overdue']??0) ?></p>
        <p class="text-sm text-red-600">Overdue Amount</p>
    </div>
    <?php
    $cntQ = $db->prepare("SELECT COUNT(*) FROM debts WHERE business_id=? AND status NOT IN ('paid','written_off')"); $cntQ->execute([$bizId]);
    $ovQ  = $db->prepare("SELECT COUNT(*) FROM debts WHERE business_id=? AND due_date < ? AND status NOT IN ('paid','written_off')"); $ovQ->execute([$bizId,$today]);
    ?>
    <div class="bg-blue-50 rounded-xl p-4 text-center">
        <p class="text-2xl font-black text-blue-700"><?= $cntQ->fetchColumn() ?></p>
        <p class="text-sm text-blue-600">Active Debts</p>
    </div>
    <div class="bg-rose-50 rounded-xl p-4 text-center">
        <p class="text-2xl font-black text-rose-700"><?= $ovQ->fetchColumn() ?></p>
        <p class="text-sm text-rose-600">Overdue Debtors</p>
    </div>
</div>

<!-- Filters -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
    <form method="GET" class="flex flex-wrap gap-3">
        <input type="text" name="search" value="<?= h($search) ?>" placeholder="Search customer..."
            class="flex-1 min-w-36 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
        <select name="status" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
            <option value="">All Status</option>
            <option value="active"   <?= $status==='active'  ?'selected':'' ?>>Active</option>
            <option value="partial"  <?= $status==='partial' ?'selected':'' ?>>Partial</option>
            <option value="overdue"  <?= $status==='overdue' ?'selected':'' ?>>Overdue</option>
            <option value="paid"     <?= $status==='paid'    ?'selected':'' ?>>Paid</option>
        </select>
        <button class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm">Filter</button>
        <a href="index.php" class="bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm">Clear</a>
    </form>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-100">
    <div class="table-responsive">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-medium text-left">Customer</th>
                    <th class="px-4 py-3 font-medium text-left">Invoice</th>
                    <th class="px-4 py-3 font-medium text-right">Original</th>
                    <th class="px-4 py-3 font-medium text-right">Paid</th>
                    <th class="px-4 py-3 font-medium text-right">Balance</th>
                    <th class="px-4 py-3 font-medium text-left">Due Date</th>
                    <th class="px-4 py-3 font-medium text-left">Status</th>
                    <th class="px-4 py-3 font-medium text-center">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php if (empty($debts)): ?>
                <tr><td colspan="8" class="px-4 py-8 text-center text-gray-400">No debts found.</td></tr>
                <?php else: ?>
                <?php foreach ($debts as $debt):
                    $isOverdue = $debt['due_date'] && $debt['due_date'] < $today && !in_array($debt['status'],['paid','written_off']);
                ?>
                <tr class="hover:bg-gray-50 <?= $isOverdue?'bg-red-50/40':'' ?>">
                    <td class="px-4 py-3">
                        <a href="../customers/view.php?id=<?= $debt['customer_id'] ?>" class="font-medium text-blue-600 hover:underline"><?= h($debt['customer_name']) ?></a>
                        <p class="text-xs text-gray-400"><?= h($debt['customer_phone']??'') ?></p>
                    </td>
                    <td class="px-4 py-3 font-mono text-xs">
                        <?php if ($debt['invoice_number']): ?>
                        <a href="../sales/view.php?id=<?= $debt['sale_id'] ?>" class="text-blue-600 hover:underline"><?= h($debt['invoice_number']) ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right"><?= formatMoney($debt['original_amount']) ?></td>
                    <td class="px-4 py-3 text-right text-green-700"><?= formatMoney($debt['amount_paid']) ?></td>
                    <td class="px-4 py-3 text-right font-bold text-red-600"><?= formatMoney($debt['balance']) ?></td>
                    <td class="px-4 py-3 <?= $isOverdue?'text-red-600 font-semibold':'' ?>">
                        <?= $debt['due_date'] ? formatDate($debt['due_date']) : '-' ?>
                        <?php if ($isOverdue): ?><span class="text-xs block text-red-500">OVERDUE</span><?php endif; ?>
                    </td>
                    <td class="px-4 py-3"><?= statusBadge($isOverdue?'overdue':$debt['status']) ?></td>
                    <td class="px-4 py-3 text-center">
                        <div class="flex justify-center gap-1">
                            <a href="view.php?id=<?= $debt['id'] ?>" class="text-blue-600 p-1 hover:text-blue-800" title="View"><i class="fa-solid fa-eye"></i></a>
                            <?php if (!in_array($debt['status'],['paid','written_off'])): ?>
                            <a href="payment.php?debt=<?= $debt['id'] ?>" class="text-green-600 p-1 hover:text-green-800" title="Record Payment"><i class="fa-solid fa-money-bill-wave"></i></a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t">
        <?= renderPagination($pag,'index.php?'.http_build_query(array_filter(compact('search','status'))).'&') ?>
    </div>
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
