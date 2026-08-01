<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('sales');

$db    = getDB();
$bizId = currentBusinessId();
$search = get('search');
$page   = max(1, (int)get('page', 1));

$where = $bizId ? 'c.business_id = ?' : '1=1';
$params = $bizId ? [$bizId] : [];

if ($search) {
    $where .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR c.business_name LIKE ?)";
    $params = array_merge($params, ["%$search%","%$search%","%$search%","%$search%"]);
}

$totalQ = $db->prepare("SELECT COUNT(*) FROM customers c WHERE $where AND c.is_active=1");
$totalQ->execute($params);
$total = (int)$totalQ->fetchColumn();

$pag = paginate($total, $page);

$stmt = $db->prepare("SELECT c.*,
    (SELECT COUNT(*) FROM sales WHERE customer_id=c.id) AS total_sales,
    (SELECT COALESCE(SUM(balance),0) FROM debts WHERE customer_id=c.id AND status NOT IN ('paid','written_off')) AS outstanding_debt
    FROM customers c
    WHERE $where AND c.is_active=1
    ORDER BY c.name ASC
    LIMIT {$pag['per_page']} OFFSET {$pag['offset']}");
$stmt->execute($params);
$customers = $stmt->fetchAll();

$pageTitle = 'Customers';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-800">Customer Management</h2>
        <p class="text-gray-500 text-sm"><?= number_format($total) ?> customers total</p>
    </div>
    <a href="add.php" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 flex items-center gap-2">
        <i class="fa-solid fa-plus"></i> Add Customer
    </a>
</div>

<!-- Search -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
    <form method="GET" class="flex gap-3">
        <div class="relative flex-1">
            <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
            <input type="text" name="search" value="<?= h($search) ?>" placeholder="Search by name, phone, email..."
                class="w-full pl-9 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
        </div>
        <button class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm">Search</button>
        <?php if ($search): ?>
        <a href="index.php" class="bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-100">
    <div class="table-responsive">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr class="text-left text-gray-600 text-xs uppercase tracking-wide">
                    <th class="px-4 py-3 font-medium">#</th>
                    <th class="px-4 py-3 font-medium">Name</th>
                    <th class="px-4 py-3 font-medium">Phone</th>
                    <th class="px-4 py-3 font-medium">Business</th>
                    <th class="px-4 py-3 font-medium">Sales</th>
                    <th class="px-4 py-3 font-medium">Outstanding Debt</th>
                    <th class="px-4 py-3 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php if (empty($customers)): ?>
                <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">No customers found.</td></tr>
                <?php else: ?>
                <?php foreach ($customers as $i => $c): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-400"><?= $pag['offset'] + $i + 1 ?></td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-700 font-bold text-sm">
                                <?= strtoupper(substr($c['name'],0,1)) ?>
                            </div>
                            <div>
                                <a href="view.php?id=<?= $c['id'] ?>" class="font-medium text-gray-800 hover:text-blue-600"><?= h($c['name']) ?></a>
                                <?php if ($c['email']): ?>
                                <p class="text-xs text-gray-400"><?= h($c['email']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-gray-700"><?= h($c['phone'] ?? '-') ?></td>
                    <td class="px-4 py-3 text-gray-600"><?= h($c['business_name'] ?? '-') ?></td>
                    <td class="px-4 py-3 text-center">
                        <span class="bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full text-xs font-medium"><?= $c['total_sales'] ?></span>
                    </td>
                    <td class="px-4 py-3">
                        <?php if ($c['outstanding_debt'] > 0): ?>
                        <span class="text-red-600 font-semibold"><?= formatMoney($c['outstanding_debt']) ?></span>
                        <?php else: ?>
                        <span class="text-green-600 text-xs">Clear</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex gap-2">
                            <a href="view.php?id=<?= $c['id'] ?>" class="text-blue-600 hover:text-blue-800 p-1" title="View"><i class="fa-solid fa-eye"></i></a>
                            <a href="edit.php?id=<?= $c['id'] ?>" class="text-green-600 hover:text-green-800 p-1" title="Edit"><i class="fa-solid fa-pen"></i></a>
                            <a href="delete.php?id=<?= $c['id'] ?>&csrf=<?= csrfToken() ?>"
                               class="text-red-500 hover:text-red-700 p-1" title="Delete"
                               onclick="return confirm('Delete this customer?')"><i class="fa-solid fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t">
        <?= renderPagination($pag, 'index.php?' . ($search ? 'search=' . urlencode($search) . '&' : '')) ?>
    </div>
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
