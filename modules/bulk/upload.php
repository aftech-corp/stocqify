<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requireLogin();

$db     = getDB();
$bizId  = currentBusinessId();
$userId = currentUser()['id'];

if (!$bizId) {
    flash('error', 'No business account is linked to your user. Import is not available.');
    redirect(url('dashboard'));
}

$allowedPayMethods = ['cash','orange_money','afrimoney','qmoney','bank_transfer'];
$allowedUnits      = ['piece','bag','bottle','can','box','pack','tin','kg','litre','carton','dozen','pair'];

// ── Entity definitions ───────────────────────────────────────────────────────
$entities = [
    'categories' => [
        'label'      => 'Product Categories',
        'icon'       => 'fa-tags',
        'color'      => '#f59e0b',
        'permission' => 'inventory',
        'columns'    => [
            'name'        => 'Category Name *',
            'description' => 'Description',
        ],
        'examples' => [
            ['Electronics', 'Electronic devices and accessories'],
            ['Clothing',    'Apparel and fashion items'],
            ['Food & Beverages', 'Food and drink products'],
        ],
    ],
    'products' => [
        'label'      => 'Products',
        'icon'       => 'fa-box',
        'color'      => '#3b82f6',
        'permission' => 'inventory',
        'columns'    => [
            'name'           => 'Product Name *',
            'sku'            => 'SKU / Code',
            'category_name'  => 'Category Name',
            'unit'           => 'Unit (piece/bag/kg/litre...)',
            'cost_price'     => 'Cost Price',
            'selling_price'  => 'Selling Price *',
            'stock_quantity' => 'Opening Stock',
            'reorder_level'  => 'Reorder Level',
            'max_stock'      => 'Max Stock',
            'description'    => 'Description',
        ],
        'examples' => [
            ['Rice 25kg',       'RICE-25KG', 'Food & Beverages', 'bag',    '45.00', '60.00', '100', '10', '',    'Premium quality rice'],
            ['Cooking Oil 5L',  'OIL-5L',   'Food & Beverages', 'bottle', '18.00', '25.00', '50',  '5',  '200', ''],
            ['Sugar 1kg',       'SUG-1KG',  'Food & Beverages', 'piece',  '4.50',  '6.00',  '200', '20', '',    ''],
        ],
    ],
    'customers' => [
        'label'      => 'Customers',
        'icon'       => 'fa-users',
        'color'      => '#22c55e',
        'permission' => 'sales',
        'columns'    => [
            'name'          => 'Name *',
            'phone'         => 'Phone',
            'email'         => 'Email',
            'address'       => 'Address',
            'business_name' => 'Business Name',
            'credit_limit'  => 'Credit Limit',
            'notes'         => 'Notes',
        ],
        'examples' => [
            ['John Smith',   '+23276123456', 'john@example.com', 'Freetown', 'Smith Enterprises', '500.00', ''],
            ['Mary Johnson', '+23277654321', '',                 'Bo',       '',                  '0.00',   'VIP customer'],
        ],
    ],
    'expenses' => [
        'label'      => 'Expenses',
        'icon'       => 'fa-wallet',
        'color'      => '#ec4899',
        'permission' => 'expenses',
        'columns'    => [
            'title'            => 'Title *',
            'category_name'    => 'Category Name',
            'amount'           => 'Amount *',
            'payment_method'   => 'Payment Method (cash / orange_money / afrimoney / qmoney / bank_transfer)',
            'expense_date'     => 'Date * (YYYY-MM-DD)',
            'reference_number' => 'Reference No.',
            'description'      => 'Description',
        ],
        'examples' => [
            ['Office Rent',   'Rent',      '1500.00', 'cash',          '2026-08-01', 'INV-001',  'Monthly office rent'],
            ['Internet Bill', 'Utilities', '200.00',  'bank_transfer', '2026-08-01', 'BILL-002', 'Monthly internet'],
        ],
    ],
    'income' => [
        'label'      => 'Income',
        'icon'       => 'fa-circle-dollar-to-slot',
        'color'      => '#06b6d4',
        'permission' => 'expenses',
        'columns'    => [
            'title'            => 'Title *',
            'amount'           => 'Amount *',
            'payment_method'   => 'Payment Method (cash / orange_money / afrimoney / qmoney / bank_transfer)',
            'income_date'      => 'Date * (YYYY-MM-DD)',
            'reference_number' => 'Reference No.',
            'description'      => 'Description',
        ],
        'examples' => [
            ['Consulting Fee', '5000.00', 'bank_transfer', '2026-08-01', 'INV-100', 'August consulting'],
            ['Commission',     '250.00',  'cash',          '2026-08-02', '',        'Sales commission'],
        ],
    ],
    'suppliers' => [
        'label'      => 'Suppliers',
        'icon'       => 'fa-truck',
        'color'      => '#8b5cf6',
        'permission' => 'expenses',
        'columns'    => [
            'name'            => 'Supplier Name *',
            'phone'           => 'Phone',
            'email'           => 'Email',
            'address'         => 'Address',
            'contact_person'  => 'Contact Person',
            'opening_balance' => 'Opening Balance',
            'notes'           => 'Notes',
        ],
        'examples' => [
            ['ABC Suppliers', '+23276000001', 'abc@supplier.com', 'Freetown', 'John Doe',   '0.00',   'Main supplier'],
            ['XYZ Wholesale', '+23277000002', '',                 'Kenema',   'Jane Smith', '1500.00', ''],
        ],
    ],
];

// ── Template download ─────────────────────────────────────────────────────────
if (get('action') === 'template') {
    $type = get('type');
    if (!isset($entities[$type])) { http_response_code(404); exit('Not found'); }
    $def = $entities[$type];
    if (!hasPermission($def['permission']) && !isAdmin()) { http_response_code(403); exit('Forbidden'); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="import_template_' . $type . '.csv"');
    header('Cache-Control: no-cache');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
    fputcsv($out, array_values($def['columns']));
    foreach ($def['examples'] as $row) { fputcsv($out, $row); }
    fclose($out);
    exit;
}

// ── Import handler ────────────────────────────────────────────────────────────
$results    = null;
$activeType = get('tab', 'categories');

if (isPost() && post('_action') === 'import') {
    verifyCsrf();
    $type = post('type');
    $activeType = $type;

    if (!isset($entities[$type])) {
        $results = ['fatal' => 'Invalid import type.'];
    } elseif (!hasPermission($entities[$type]['permission']) && !isAdmin()) {
        $results = ['fatal' => 'You do not have permission to import ' . $entities[$type]['label'] . '.'];
    } elseif (empty($_FILES['csv_file']['tmp_name']) || (int)$_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $results = ['fatal' => 'Please select a valid CSV file to upload.'];
    } else {
        $tmpFile = $_FILES['csv_file']['tmp_name'];
        $fh      = fopen($tmpFile, 'r');

        if (!$fh) {
            $results = ['fatal' => 'Could not read the uploaded file.'];
        } else {
            $rawHeader = fgetcsv($fh);
            // Strip BOM from first cell
            if ($rawHeader && isset($rawHeader[0])) {
                $rawHeader[0] = ltrim($rawHeader[0], "\xEF\xBB\xBF");
            }

            // Build col_key → csv_column_index map (match by key or label, case-insensitive)
            $colMap = [];
            $expectedCols   = array_keys($entities[$type]['columns']);
            $expectedLabels = array_values($entities[$type]['columns']);
            foreach ($rawHeader as $j => $h) {
                $hClean = strtolower(trim(str_replace(' *', '', $h)));
                foreach ($expectedCols as $i => $key) {
                    $labelClean = strtolower(str_replace(' *', '', $expectedLabels[$i]));
                    // Match by column key or full label (strip the hint in parentheses for label matching)
                    $labelShort = strtolower(trim(preg_replace('/\s*\(.*\)/', '', $expectedLabels[$i])));
                    $labelShort = str_replace(' *', '', $labelShort);
                    if (($hClean === strtolower($key) || $hClean === $labelClean || $hClean === $labelShort)
                        && !isset($colMap[$key])) {
                        $colMap[$key] = $j;
                        break;
                    }
                }
            }

            $imported = 0;
            $skipped  = 0;
            $rowErrors = [];
            $rowNum   = 1;

            while (($row = fgetcsv($fh)) !== false) {
                $rowNum++;
                if (empty(array_filter(array_map('trim', $row)))) continue;

                $g = function(string $col) use ($row, $colMap): string {
                    return isset($colMap[$col]) ? trim((string)($row[$colMap[$col]] ?? '')) : '';
                };

                try {
                    switch ($type) {

                        // ── Categories ───────────────────────────────────
                        case 'categories':
                            $name = $g('name');
                            if (!$name) { $rowErrors[] = "Row $rowNum: Name is required."; $skipped++; break; }
                            $dup = $db->prepare("SELECT id FROM categories WHERE business_id=? AND LOWER(name)=LOWER(?) LIMIT 1");
                            $dup->execute([$bizId, $name]);
                            if ($dup->fetchColumn()) { $skipped++; break; } // silent skip
                            $db->prepare("INSERT INTO categories (business_id, name, description) VALUES (?,?,?)")
                               ->execute([$bizId, $name, $g('description') ?: null]);
                            $imported++;
                            break;

                        // ── Products ─────────────────────────────────────
                        case 'products':
                            $name = $g('name');
                            $sell = (float)$g('selling_price');
                            if (!$name) { $rowErrors[] = "Row $rowNum: Product name is required."; $skipped++; break; }
                            if ($sell <= 0) { $rowErrors[] = "Row $rowNum: Selling price must be greater than 0."; $skipped++; break; }
                            $dup = $db->prepare("SELECT id FROM products WHERE business_id=? AND LOWER(name)=LOWER(?) LIMIT 1");
                            $dup->execute([$bizId, $name]);
                            if ($dup->fetchColumn()) { $skipped++; break; }
                            // Category lookup
                            $catId = null;
                            if (($cn = $g('category_name')) !== '') {
                                $cq = $db->prepare("SELECT id FROM categories WHERE business_id=? AND LOWER(name)=LOWER(?) LIMIT 1");
                                $cq->execute([$bizId, $cn]);
                                $catId = $cq->fetchColumn() ?: null;
                            }
                            $unit = strtolower($g('unit') ?: 'piece');
                            if (!in_array($unit, $allowedUnits)) $unit = 'piece';
                            $cost     = (float)$g('cost_price');
                            $qty      = (float)($g('stock_quantity') ?: 0);
                            $reorder  = (float)($g('reorder_level') ?: 5);
                            $maxStock = $g('max_stock') !== '' ? (float)$g('max_stock') : null;
                            $db->beginTransaction();
                            $db->prepare("INSERT INTO products (business_id, category_id, name, sku, description, unit, cost_price, selling_price, stock_quantity, reorder_level, max_stock) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                               ->execute([$bizId, $catId, $name, $g('sku') ?: null, $g('description') ?: null, $unit, $cost, $sell, $qty, $reorder, $maxStock]);
                            $prodId = (int)$db->lastInsertId();
                            if ($qty > 0) {
                                $db->prepare("INSERT INTO inventory_transactions (business_id, product_id, user_id, type, quantity, before_quantity, after_quantity, notes) VALUES (?,?,?,?,?,?,?,?)")
                                   ->execute([$bizId, $prodId, $userId, 'purchase', $qty, 0, $qty, 'Bulk import']);
                            }
                            $db->commit();
                            $imported++;
                            break;

                        // ── Customers ────────────────────────────────────
                        case 'customers':
                            $name  = $g('name');
                            $email = $g('email') ?: null;
                            if (!$name) { $rowErrors[] = "Row $rowNum: Name is required."; $skipped++; break; }
                            if ($email) {
                                $dup = $db->prepare("SELECT id FROM customers WHERE business_id=? AND email=? LIMIT 1");
                                $dup->execute([$bizId, $email]);
                                if ($dup->fetchColumn()) { $skipped++; break; }
                            }
                            $db->prepare("INSERT INTO customers (business_id, name, phone, email, address, business_name, credit_limit, notes) VALUES (?,?,?,?,?,?,?,?)")
                               ->execute([$bizId, $name, $g('phone') ?: null, $email, $g('address') ?: null, $g('business_name') ?: null, (float)($g('credit_limit') ?: 0), $g('notes') ?: null]);
                            $imported++;
                            break;

                        // ── Expenses ─────────────────────────────────────
                        case 'expenses':
                            $title  = $g('title');
                            $amount = (float)$g('amount');
                            $date   = $g('expense_date');
                            if (!$title)  { $rowErrors[] = "Row $rowNum: Title is required."; $skipped++; break; }
                            if ($amount <= 0) { $rowErrors[] = "Row $rowNum: Amount must be greater than 0."; $skipped++; break; }
                            if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                                $rowErrors[] = "Row $rowNum: Date must be in YYYY-MM-DD format (e.g. 2026-08-01)."; $skipped++; break;
                            }
                            $catId = null;
                            if (($cn = $g('category_name')) !== '') {
                                $cq = $db->prepare("SELECT id FROM expense_categories WHERE business_id=? AND LOWER(name)=LOWER(?) LIMIT 1");
                                $cq->execute([$bizId, $cn]);
                                $catId = $cq->fetchColumn() ?: null;
                            }
                            $pay = strtolower($g('payment_method') ?: 'cash');
                            if (!in_array($pay, $allowedPayMethods)) $pay = 'cash';
                            $db->prepare("INSERT INTO expenses (business_id, category_id, user_id, title, description, amount, payment_method, reference_number, expense_date) VALUES (?,?,?,?,?,?,?,?,?)")
                               ->execute([$bizId, $catId, $userId, $title, $g('description') ?: null, $amount, $pay, $g('reference_number') ?: null, $date]);
                            $imported++;
                            break;

                        // ── Income ───────────────────────────────────────
                        case 'income':
                            $title  = $g('title');
                            $amount = (float)$g('amount');
                            $date   = $g('income_date');
                            if (!$title)  { $rowErrors[] = "Row $rowNum: Title is required."; $skipped++; break; }
                            if ($amount <= 0) { $rowErrors[] = "Row $rowNum: Amount must be greater than 0."; $skipped++; break; }
                            if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                                $rowErrors[] = "Row $rowNum: Date must be in YYYY-MM-DD format (e.g. 2026-08-01)."; $skipped++; break;
                            }
                            $pay = strtolower($g('payment_method') ?: 'cash');
                            if (!in_array($pay, $allowedPayMethods)) $pay = 'cash';
                            $db->prepare("INSERT INTO income (business_id, user_id, title, description, amount, payment_method, reference_number, income_date) VALUES (?,?,?,?,?,?,?,?)")
                               ->execute([$bizId, $userId, $title, $g('description') ?: null, $amount, $pay, $g('reference_number') ?: null, $date]);
                            $imported++;
                            break;

                        // ── Suppliers ─────────────────────────────────────
                        case 'suppliers':
                            $name = $g('name');
                            if (!$name) { $rowErrors[] = "Row $rowNum: Supplier name is required."; $skipped++; break; }
                            $dup = $db->prepare("SELECT id FROM suppliers WHERE business_id=? AND LOWER(name)=LOWER(?) LIMIT 1");
                            $dup->execute([$bizId, $name]);
                            if ($dup->fetchColumn()) { $skipped++; break; }
                            $db->prepare("INSERT INTO suppliers (business_id, name, phone, email, address, contact_person, opening_balance, notes) VALUES (?,?,?,?,?,?,?,?)")
                               ->execute([$bizId, $name, $g('phone') ?: null, $g('email') ?: null, $g('address') ?: null, $g('contact_person') ?: null, (float)($g('opening_balance') ?: 0), $g('notes') ?: null]);
                            $imported++;
                            break;
                    }
                } catch (\Exception $e) {
                    if (isset($db) && $db->inTransaction()) $db->rollBack();
                    $rowErrors[] = "Row $rowNum: Database error — " . $e->getMessage();
                    $skipped++;
                }
            }
            fclose($fh);

            $results = [
                'type'      => $type,
                'label'     => $entities[$type]['label'],
                'imported'  => $imported,
                'skipped'   => $skipped,
                'rowErrors' => $rowErrors,
            ];
        }
    }
}

$pageTitle = 'Import Data';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-800">Import Data</h2>
        <p class="text-sm text-gray-500">Bulk import records from a CSV file. Download a template, fill it in, and upload.</p>
    </div>
</div>

<?php if (isset($results['fatal'])): ?>
<div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6 flex items-start gap-3">
    <i class="fa-solid fa-circle-xmark text-red-500 mt-0.5"></i>
    <p class="text-red-700 text-sm font-medium"><?= h($results['fatal']) ?></p>
</div>
<?php endif; ?>

<?php if (isset($results['imported'])): ?>
<div class="rounded-xl border <?= $results['imported'] > 0 ? 'border-green-200 bg-green-50' : 'border-amber-200 bg-amber-50' ?> p-5 mb-6">
    <div class="flex items-start gap-4">
        <div class="w-10 h-10 rounded-full <?= $results['imported'] > 0 ? 'bg-green-100' : 'bg-amber-100' ?> flex items-center justify-center flex-shrink-0">
            <i class="fa-solid <?= $results['imported'] > 0 ? 'fa-circle-check text-green-600' : 'fa-triangle-exclamation text-amber-600' ?> text-lg"></i>
        </div>
        <div class="flex-1">
            <p class="font-bold text-gray-800 text-sm mb-1"><?= h($results['label']) ?> import complete</p>
            <div class="flex flex-wrap gap-4 text-sm">
                <span class="flex items-center gap-1.5 text-green-700">
                    <i class="fa-solid fa-circle-check text-xs"></i>
                    <strong><?= $results['imported'] ?></strong> imported
                </span>
                <?php if ($results['skipped'] > 0): ?>
                <span class="flex items-center gap-1.5 text-amber-700">
                    <i class="fa-solid fa-forward text-xs"></i>
                    <strong><?= $results['skipped'] ?></strong> skipped (duplicates or invalid)
                </span>
                <?php endif; ?>
            </div>
            <?php if (!empty($results['rowErrors'])): ?>
            <details class="mt-3">
                <summary class="text-xs font-semibold text-red-600 cursor-pointer hover:text-red-800">
                    Show <?= count($results['rowErrors']) ?> row error<?= count($results['rowErrors']) !== 1 ? 's' : '' ?> &darr;
                </summary>
                <ul class="mt-2 space-y-1">
                    <?php foreach ($results['rowErrors'] as $re): ?>
                    <li class="text-xs text-red-600"><i class="fa-solid fa-xmark mr-1"></i><?= h($re) ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Tab navigation -->
<div class="flex flex-wrap gap-0 mb-6 border-b border-gray-200">
    <?php foreach ($entities as $key => $ent):
        $canSee = hasPermission($ent['permission']) || isAdmin();
        if (!$canSee) continue;
    ?>
    <a href="?tab=<?= $key ?>"
       class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors whitespace-nowrap
              <?= $activeType === $key
                  ? 'border-blue-600 text-blue-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700' ?>">
        <i class="fa-solid <?= $ent['icon'] ?> mr-1.5" style="color:<?= $activeType === $key ? '#1B3263' : $ent['color'] ?>;"></i>
        <?= h($ent['label']) ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Active tab content -->
<?php foreach ($entities as $type => $ent):
    if ($activeType !== $type) continue;
    $canImport = hasPermission($ent['permission']) || isAdmin();
?>
<div class="grid grid-cols-1 lg:grid-cols-5 gap-6">

    <!-- Left: Instructions + template download -->
    <div class="lg:col-span-2 space-y-4">

        <!-- Download template -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0"
                     style="background:<?= $ent['color'] ?>18;">
                    <i class="fa-solid fa-file-csv text-sm" style="color:<?= $ent['color'] ?>;"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-gray-800 text-sm">Step 1 — Download Template</h3>
                    <p class="text-xs text-gray-400">Pre-filled with example rows</p>
                </div>
            </div>
            <a href="?action=template&type=<?= $type ?>"
               class="flex items-center justify-center gap-2 w-full py-2 rounded-lg text-sm font-semibold border-2 transition-colors"
               style="border-color:<?= $ent['color'] ?>;color:<?= $ent['color'] ?>;"
               onmouseover="this.style.background='<?= $ent['color'] ?>18'"
               onmouseout="this.style.background=''">
                <i class="fa-solid fa-download"></i>
                Download <?= h($ent['label']) ?> Template
            </a>
        </div>

        <!-- Column reference -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-4 py-3 bg-gray-50 border-b border-gray-100">
                <p class="text-xs font-bold uppercase tracking-widest text-gray-400">Column Reference</p>
            </div>
            <div class="divide-y divide-gray-50">
                <?php foreach ($ent['columns'] as $key2 => $label): ?>
                <div class="flex items-start gap-3 px-4 py-2.5">
                    <code class="text-[10px] font-mono bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded flex-shrink-0 mt-0.5"><?= h($key2) ?></code>
                    <span class="text-xs text-gray-600"><?= h($label) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Tips -->
        <div class="rounded-xl border border-blue-100 bg-blue-50 p-4 text-xs text-blue-700 space-y-1.5">
            <p class="font-semibold text-blue-800 mb-1"><i class="fa-solid fa-lightbulb mr-1"></i> Tips</p>
            <p><i class="fa-solid fa-circle-dot text-[8px] mr-1"></i> Columns marked <strong>*</strong> are required — rows without them are skipped.</p>
            <p><i class="fa-solid fa-circle-dot text-[8px] mr-1"></i> Duplicate names are silently skipped (no overwrite).</p>
            <?php if ($type === 'products'): ?>
            <p><i class="fa-solid fa-circle-dot text-[8px] mr-1"></i> <strong>Category Name</strong> must match an existing category exactly.</p>
            <p><i class="fa-solid fa-circle-dot text-[8px] mr-1"></i> <strong>Opening Stock</strong> automatically creates an inventory record.</p>
            <?php endif; ?>
            <?php if ($type === 'expenses' || $type === 'income'): ?>
            <p><i class="fa-solid fa-circle-dot text-[8px] mr-1"></i> <strong>Date</strong> must be <code class="bg-blue-100 px-1 rounded">YYYY-MM-DD</code> format.</p>
            <?php endif; ?>
            <p><i class="fa-solid fa-circle-dot text-[8px] mr-1"></i> Save as CSV (UTF-8) from Excel or Google Sheets.</p>
            <p><i class="fa-solid fa-circle-dot text-[8px] mr-1"></i> Maximum file size: 2 MB.</p>
        </div>
    </div>

    <!-- Right: Upload form -->
    <div class="lg:col-span-3">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <div class="flex items-center gap-3 mb-5">
                <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0"
                     style="background:<?= $ent['color'] ?>18;">
                    <i class="fa-solid fa-file-arrow-up text-sm" style="color:<?= $ent['color'] ?>;"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-gray-800 text-sm">Step 2 — Upload Your CSV</h3>
                    <p class="text-xs text-gray-400">Fill the template and upload it here</p>
                </div>
            </div>

            <?php if (!$canImport): ?>
            <div class="p-4 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
                <i class="fa-solid fa-lock mr-1"></i> You don't have permission to import <?= h($ent['label']) ?>.
            </div>
            <?php else: ?>

            <form method="POST" enctype="multipart/form-data" id="importForm">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="_action" value="import">
                <input type="hidden" name="type"    value="<?= h($type) ?>">

                <!-- Drop zone -->
                <div id="dropZone"
                     class="relative border-2 border-dashed border-gray-300 rounded-xl p-10 text-center cursor-pointer hover:border-blue-400 hover:bg-blue-50/30 transition-colors mb-5"
                     onclick="document.getElementById('csv_file_<?= $type ?>').click()">
                    <input type="file" name="csv_file" id="csv_file_<?= $type ?>"
                           accept=".csv,text/csv" class="hidden"
                           onchange="handleFileSelect(this)">
                    <div id="dropIcon_<?= $type ?>">
                        <i class="fa-solid fa-cloud-arrow-up text-4xl text-gray-300 mb-3 block"></i>
                        <p class="font-semibold text-gray-600 text-sm">Click to choose file</p>
                        <p class="text-xs text-gray-400 mt-1">or drag and drop a CSV here</p>
                        <p class="text-[10px] text-gray-300 mt-3">CSV format only · Max 2 MB</p>
                    </div>
                    <div id="fileInfo_<?= $type ?>" class="hidden">
                        <i class="fa-solid fa-file-csv text-3xl text-green-500 mb-2 block"></i>
                        <p id="fileName_<?= $type ?>" class="font-semibold text-gray-700 text-sm"></p>
                        <p id="fileSize_<?= $type ?>" class="text-xs text-gray-400 mt-0.5"></p>
                        <p class="text-xs text-blue-600 mt-2">Click to choose a different file</p>
                    </div>
                </div>

                <!-- Preview note -->
                <div id="previewNote_<?= $type ?>" class="hidden mb-4 p-3 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-700">
                    <i class="fa-solid fa-circle-info mr-1"></i>
                    Duplicates are automatically skipped. Review the column reference on the left before importing.
                </div>

                <div class="flex gap-3">
                    <button type="submit" id="importBtn_<?= $type ?>"
                            class="flex-1 py-2.5 rounded-lg text-sm font-bold text-white transition-all disabled:opacity-40 disabled:cursor-not-allowed"
                            style="background:<?= $ent['color'] ?>;"
                            disabled>
                        <i class="fa-solid fa-file-arrow-up mr-1.5"></i>
                        Import <?= h($ent['label']) ?>
                    </button>
                    <button type="button" id="clearBtn_<?= $type ?>" onclick="clearFile('<?= $type ?>')"
                            class="hidden px-4 py-2.5 rounded-lg text-sm font-medium bg-gray-100 text-gray-600 hover:bg-gray-200">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </form>

            <script>
            (function() {
                const type      = '<?= $type ?>';
                const fileInput = document.getElementById('csv_file_' + type);
                const dropZone  = document.getElementById('dropZone');
                const dropIcon  = document.getElementById('dropIcon_' + type);
                const fileInfo  = document.getElementById('fileInfo_' + type);
                const fileName  = document.getElementById('fileName_' + type);
                const fileSize  = document.getElementById('fileSize_' + type);
                const prevNote  = document.getElementById('previewNote_' + type);
                const importBtn = document.getElementById('importBtn_' + type);
                const clearBtn  = document.getElementById('clearBtn_' + type);

                function handleFileSelect(input) {
                    const file = input?.files?.[0] || fileInput.files[0];
                    if (!file) return;
                    if (file.size > 2 * 1024 * 1024) {
                        alert('File is too large. Maximum size is 2 MB.');
                        fileInput.value = '';
                        return;
                    }
                    const kb = (file.size / 1024).toFixed(1);
                    fileName.textContent = file.name;
                    fileSize.textContent = kb + ' KB';
                    dropIcon.classList.add('hidden');
                    fileInfo.classList.remove('hidden');
                    prevNote.classList.remove('hidden');
                    importBtn.disabled = false;
                    clearBtn.classList.remove('hidden');
                }
                window.handleFileSelect = handleFileSelect;

                // Drag and drop
                dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('border-blue-400','bg-blue-50/30'); });
                dropZone.addEventListener('dragleave',e => { dropZone.classList.remove('border-blue-400','bg-blue-50/30'); });
                dropZone.addEventListener('drop',     e => {
                    e.preventDefault();
                    dropZone.classList.remove('border-blue-400','bg-blue-50/30');
                    const file = e.dataTransfer?.files?.[0];
                    if (file) {
                        const dt = new DataTransfer();
                        dt.items.add(file);
                        fileInput.files = dt.files;
                        handleFileSelect(fileInput);
                    }
                });

                window.clearFile = function(t) {
                    fileInput.value = '';
                    dropIcon.classList.remove('hidden');
                    fileInfo.classList.add('hidden');
                    prevNote.classList.add('hidden');
                    importBtn.disabled = true;
                    clearBtn.classList.add('hidden');
                };

                // Spinner on submit
                document.getElementById('importForm').addEventListener('submit', function() {
                    importBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1.5"></i> Importing…';
                    importBtn.disabled  = true;
                });
            })();
            </script>

            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
