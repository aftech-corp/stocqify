<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('expenses');

$db    = getDB();
$bizId = currentBusinessId();
$from  = get('from', date('Y-m-01'));
$to    = get('to', date('Y-m-d'));
$catId = (int)get('category');
$page  = max(1,(int)get('page',1));

$where  = 'e.business_id=?';
$params = [$bizId];
if ($from) { $where.=' AND e.expense_date>=?'; $params[]=$from; }
if ($to)   { $where.=' AND e.expense_date<=?'; $params[]=$to; }
if ($catId){ $where.=' AND e.category_id=?';   $params[]=$catId; }

$totalQ = $db->prepare("SELECT COUNT(*) FROM expenses e WHERE $where");
$totalQ->execute($params);
$total = (int)$totalQ->fetchColumn();
$pag = paginate($total, $page);

$stmt = $db->prepare("SELECT e.*, ec.name AS category_name, u.name AS user_name
    FROM expenses e
    LEFT JOIN expense_categories ec ON ec.id=e.category_id
    LEFT JOIN users u ON u.id=e.user_id
    WHERE $where ORDER BY e.expense_date DESC, e.id DESC
    LIMIT {$pag['per_page']} OFFSET {$pag['offset']}");
$stmt->execute($params);
$expenses = $stmt->fetchAll();

$sumQ = $db->prepare("SELECT SUM(e.amount) FROM expenses e WHERE $where");
$sumQ->execute($params);
$total_amount = (float)$sumQ->fetchColumn();

$catsQ = $db->prepare('SELECT id, name FROM expense_categories WHERE business_id=? ORDER BY name');
$catsQ->execute([$bizId]);
$categories = $catsQ->fetchAll();

// By category breakdown
$byCatQ = $db->prepare("SELECT ec.name, SUM(e.amount) AS total FROM expenses e JOIN expense_categories ec ON ec.id=e.category_id WHERE e.business_id=? AND e.expense_date>=? AND e.expense_date<=? GROUP BY ec.id ORDER BY total DESC LIMIT 5");
$byCatQ->execute([$bizId, $from, $to]);
$byCat = $byCatQ->fetchAll();

$pageTitle = 'Expenses';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-800">Expense Management</h2>
        <p class="text-sm text-gray-500"><?= number_format($total) ?> entries</p>
    </div>
    <div class="flex gap-2">
        <a href="categories.php" class="border border-gray-300 text-gray-700 px-3 py-2 rounded-lg text-sm hover:bg-gray-50">
            <i class="fa-solid fa-tags mr-1"></i> Categories
        </a>
        <a href="add.php" class="bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-red-700">
            <i class="fa-solid fa-plus mr-1"></i> Add Expense
        </a>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-4 gap-4 mb-6">
    <div class="lg:col-span-2 bg-red-50 rounded-xl p-4">
        <p class="text-2xl font-black text-red-700"><?= formatMoney($total_amount) ?></p>
        <p class="text-sm text-red-600">Total Expenses (Period)</p>
    </div>
    <?php foreach (array_slice($byCat,0,2) as $bc): ?>
    <div class="bg-orange-50 rounded-xl p-4">
        <p class="text-lg font-black text-orange-700"><?= formatMoney($bc['total']) ?></p>
        <p class="text-sm text-orange-600"><?= h($bc['name']) ?></p>
    </div>
    <?php endforeach; ?>
</div>

<!-- Filter -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
    <form method="GET" class="flex flex-wrap gap-3">
        <input type="date" name="from" value="<?= h($from) ?>" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        <input type="date" name="to"   value="<?= h($to) ?>"   class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        <select name="category" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
            <option value="">All Categories</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $catId==$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
            <?php endforeach; ?>
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
                    <th class="px-4 py-3 font-medium text-left">Date</th>
                    <th class="px-4 py-3 font-medium text-left">Title</th>
                    <th class="px-4 py-3 font-medium text-left">Category</th>
                    <th class="px-4 py-3 font-medium text-right">Amount</th>
                    <th class="px-4 py-3 font-medium text-left">Method</th>
                    <th class="px-4 py-3 font-medium text-left">Staff</th>
                    <th class="px-4 py-3 font-medium text-center">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php if (empty($expenses)): ?>
                <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">No expenses found.</td></tr>
                <?php else: ?>
                <?php foreach ($expenses as $exp): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-500"><?= formatDate($exp['expense_date']) ?></td>
                    <td class="px-4 py-3 font-medium"><?= h($exp['title']) ?></td>
                    <td class="px-4 py-3 text-gray-500 text-xs"><?= h($exp['category_name']??'Uncategorized') ?></td>
                    <td class="px-4 py-3 text-right font-semibold text-red-600"><?= formatMoney($exp['amount']) ?></td>
                    <td class="px-4 py-3 text-gray-500 text-xs capitalize"><?= str_replace('_',' ',$exp['payment_method']) ?></td>
                    <td class="px-4 py-3 text-gray-400 text-xs"><?= h($exp['user_name']) ?></td>
                    <td class="px-4 py-3 text-center">
                        <div class="flex justify-center gap-1">
                            <a href="edit.php?id=<?= $exp['id'] ?>" class="text-blue-600 p-1 hover:text-blue-800"><i class="fa-solid fa-pen"></i></a>
                            <a href="delete.php?id=<?= $exp['id'] ?>&csrf=<?= csrfToken() ?>" class="text-red-500 p-1 hover:text-red-700" onclick="return confirm('Delete this expense?')"><i class="fa-solid fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t">
        <?= renderPagination($pag,'index.php?'.http_build_query(array_filter(compact('from','to','catId'))).'&') ?>
    </div>
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
