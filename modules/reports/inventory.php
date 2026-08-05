<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('reports');
requireFeature('analytics_inventory_report');

$db    = getDB();
$bizId = currentBusinessId();
$export = get('export');

// Total inventory value
$q = $db->prepare("SELECT COALESCE(SUM(cost_price*stock_quantity),0) FROM products WHERE business_id=? AND is_active=1"); $q->execute([$bizId]); $costValue = (float)$q->fetchColumn();
$q2 = $db->prepare("SELECT COALESCE(SUM(selling_price*stock_quantity),0) FROM products WHERE business_id=? AND is_active=1"); $q2->execute([$bizId]); $sellValue = (float)$q2->fetchColumn();
$q3 = $db->prepare("SELECT COUNT(*) FROM products WHERE business_id=? AND is_active=1"); $q3->execute([$bizId]); $totalProds = (int)$q3->fetchColumn();
$q4 = $db->prepare("SELECT COUNT(*) FROM products WHERE business_id=? AND is_active=1 AND stock_quantity<=reorder_level"); $q4->execute([$bizId]); $lowCount = (int)$q4->fetchColumn();

// All products with stock
$prodsQ = $db->prepare("SELECT p.*, c.name AS category_name, (p.selling_price-p.cost_price) AS margin, (p.selling_price*p.stock_quantity) AS sell_value, (p.cost_price*p.stock_quantity) AS cost_value FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.business_id=? AND p.is_active=1 ORDER BY p.stock_quantity ASC");
$prodsQ->execute([$bizId]);
$products = $prodsQ->fetchAll();

// CSV Export
if ($export === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="inventory_report.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['Product','SKU','Category','Unit','Cost Price','Selling Price','Stock Qty','Reorder Level','Status','Cost Value','Sell Value']);
    foreach ($products as $p) {
        $status = $p['stock_quantity'] <= 0 ? 'Out of Stock' : ($p['stock_quantity'] <= $p['reorder_level'] ? 'Low Stock' : 'In Stock');
        fputcsv($out, [$p['name'],$p['sku']??'',$p['category_name']??'',$p['unit'],$p['cost_price'],$p['selling_price'],$p['stock_quantity'],$p['reorder_level'],$status,$p['cost_value'],$p['sell_value']]);
    }
    fclose($out); exit;
}

$pageTitle = 'Inventory Report';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-6">
    <h2 class="text-xl font-bold text-gray-800">Inventory Report</h2>
    <a href="?export=csv" class="bg-green-600 text-white px-3 py-2 rounded-lg text-sm hover:bg-green-700">
        <i class="fa-solid fa-download mr-1"></i> Export CSV
    </a>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-blue-50 rounded-xl p-4 text-center"><p class="text-2xl font-black text-blue-700"><?= number_format($totalProds) ?></p><p class="text-sm text-blue-600">Total Products</p></div>
    <div class="bg-green-50 rounded-xl p-4 text-center"><p class="text-xl font-black text-green-700"><?= formatMoney($costValue) ?></p><p class="text-sm text-green-600">Stock Cost Value</p></div>
    <div class="bg-teal-50 rounded-xl p-4 text-center"><p class="text-xl font-black text-teal-700"><?= formatMoney($sellValue) ?></p><p class="text-sm text-teal-600">Stock Sell Value</p></div>
    <div class="bg-amber-50 rounded-xl p-4 text-center"><p class="text-2xl font-black text-amber-700"><?= $lowCount ?></p><p class="text-sm text-amber-600">Low Stock Items</p></div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-100">
    <div class="table-responsive">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-medium text-left">Product</th>
                    <th class="px-4 py-3 font-medium text-left">Category</th>
                    <th class="px-4 py-3 font-medium text-right">Cost</th>
                    <th class="px-4 py-3 font-medium text-right">Price</th>
                    <th class="px-4 py-3 font-medium text-right">Margin</th>
                    <th class="px-4 py-3 font-medium text-center">Stock</th>
                    <th class="px-4 py-3 font-medium text-right">Sell Value</th>
                    <th class="px-4 py-3 font-medium text-left">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($products as $p):
                    $st = $p['stock_quantity']<=0?'out':($p['stock_quantity']<=$p['reorder_level']?'low':'ok');
                    $stClasses = ['out'=>'text-red-600','low'=>'text-amber-600','ok'=>'text-green-600'];
                    $margin_pct = $p['selling_price']>0 ? round(($p['margin']/$p['selling_price'])*100,1) : 0;
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3"><p class="font-medium text-gray-800"><?= h($p['name']) ?></p><p class="text-xs text-gray-400"><?= h($p['sku']??'') ?></p></td>
                    <td class="px-4 py-3 text-gray-500 text-xs"><?= h($p['category_name']??'-') ?></td>
                    <td class="px-4 py-3 text-right text-gray-600"><?= formatMoney($p['cost_price']) ?></td>
                    <td class="px-4 py-3 text-right font-medium"><?= formatMoney($p['selling_price']) ?></td>
                    <td class="px-4 py-3 text-right text-green-700 text-xs"><?= $margin_pct ?>%</td>
                    <td class="px-4 py-3 text-center font-bold <?= $stClasses[$st] ?>"><?= number_format($p['stock_quantity'],0) ?> <span class="text-xs font-normal text-gray-400"><?= h($p['unit']) ?></span></td>
                    <td class="px-4 py-3 text-right text-gray-700"><?= formatMoney($p['sell_value']) ?></td>
                    <td class="px-4 py-3">
                        <?php if ($st==='out'): ?><span class="text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded-full">Out</span>
                        <?php elseif ($st==='low'): ?><span class="text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full">Low</span>
                        <?php else: ?><span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full">OK</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
