<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requireLogin();

$db    = getDB();
$bizId = currentBusinessId();
$id    = (int)get('id');

$saleQ = $db->prepare('SELECT s.*, c.name AS customer_name, c.phone AS customer_phone, c.address AS customer_address,
    u.name AS staff_name,
    b.name AS biz_name, b.address AS biz_address, b.phone AS biz_phone, b.email AS biz_email,
    b.logo AS biz_logo, b.currency AS biz_currency
    FROM sales s
    LEFT JOIN customers c ON c.id=s.customer_id
    LEFT JOIN users u ON u.id=s.user_id
    LEFT JOIN businesses b ON b.id=s.business_id
    WHERE s.id=? AND s.business_id=?');
$saleQ->execute([$id, $bizId]);
$sale = $saleQ->fetch();
if (!$sale) die('Sale not found.');

$itemsQ = $db->prepare('SELECT si.*, p.name AS product_name, p.unit, p.image AS product_image
    FROM sale_items si
    JOIN products p ON p.id=si.product_id
    WHERE si.sale_id=?');
$itemsQ->execute([$id]);
$items = $itemsQ->fetchAll();

// Resolve absolute URLs for logo and product images (needed for print rendering)
$bizLogoUrl = '';
if (!empty($sale['biz_logo'])) {
    $absPath = __DIR__ . '/../../' . $sale['biz_logo'];
    if (file_exists($absPath)) $bizLogoUrl = APP_URL . '/' . $sale['biz_logo'];
}
foreach ($items as &$itm) {
    $itm['_img_url'] = '';
    if (!empty($itm['product_image'])) {
        $ap = __DIR__ . '/../../' . $itm['product_image'];
        if (file_exists($ap)) $itm['_img_url'] = APP_URL . '/' . $itm['product_image'];
    }
}
unset($itm);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice <?= h($sale['invoice_number']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Arial', sans-serif; }
        @media print {
            .no-print { display: none !important; }
            body { padding: 0; margin: 0; }
            img { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .page-break { page-break-before: always; }
        }
        /* Product image thumbnail in items table */
        .item-img {
            width: 36px; height: 36px;
            object-fit: cover;
            border-radius: 5px;
            border: 1px solid #e5e7eb;
            display: block;
        }
        .item-img-placeholder {
            width: 36px; height: 36px;
            background: #f3f4f6;
            border-radius: 5px;
            border: 1px solid #e5e7eb;
            display: flex; align-items: center; justify-content: center;
            color: #d1d5db; font-size: 14px;
        }
        @media print {
            .item-img-placeholder { display: none; }
        }
    </style>
</head>
<body class="bg-white p-8 max-w-2xl mx-auto">

<!-- Print Button -->
<div class="no-print mb-4 flex gap-2">
    <button onclick="window.print()" class="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700"><i class="fa-solid fa-print mr-1"></i>Print</button>
    <a href="view.php?id=<?= $id ?>" class="bg-gray-200 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-300">Back</a>
</div>

<!-- Invoice Content -->
<div class="border-2 border-gray-200 rounded-lg p-8">
    <!-- Header -->
    <div class="flex justify-between items-start mb-8">
        <!-- Business identity: logo + name + contact -->
        <div class="flex items-start gap-4">
            <?php if ($bizLogoUrl): ?>
            <img src="<?= h($bizLogoUrl) ?>" alt="<?= h($sale['biz_name']) ?> logo"
                 style="height:64px;width:auto;max-width:140px;object-fit:contain;border-radius:8px;">
            <?php endif; ?>
            <div>
                <h1 class="text-2xl font-black text-blue-700 leading-tight"><?= h($sale['biz_name']) ?></h1>
                <?php if ($sale['biz_address']): ?><p class="text-gray-500 text-sm mt-0.5"><?= nl2br(h($sale['biz_address'])) ?></p><?php endif; ?>
                <?php if ($sale['biz_phone']): ?><p class="text-gray-500 text-sm"><?= h($sale['biz_phone']) ?></p><?php endif; ?>
                <?php if ($sale['biz_email']): ?><p class="text-gray-500 text-sm"><?= h($sale['biz_email']) ?></p><?php endif; ?>
            </div>
        </div>
        <!-- Invoice meta -->
        <div class="text-right flex-shrink-0">
            <div class="text-3xl font-black text-gray-200 tracking-widest">INVOICE</div>
            <p class="font-bold text-gray-800 font-mono text-lg"><?= h($sale['invoice_number']) ?></p>
            <p class="text-gray-500 text-sm mt-1">Date: <?= formatDate($sale['sale_date']) ?></p>
            <?php if ($sale['due_date']): ?>
            <p class="text-red-500 text-sm font-semibold">Due: <?= formatDate($sale['due_date']) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bill To -->
    <div class="bg-gray-50 rounded-lg p-4 mb-6">
        <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Bill To:</p>
        <?php if ($sale['customer_name']): ?>
        <p class="font-bold text-gray-800"><?= h($sale['customer_name']) ?></p>
        <?php if ($sale['customer_phone']): ?><p class="text-gray-600 text-sm"><?= h($sale['customer_phone']) ?></p><?php endif; ?>
        <?php if ($sale['customer_address']): ?><p class="text-gray-600 text-sm"><?= nl2br(h($sale['customer_address'])) ?></p><?php endif; ?>
        <?php else: ?>
        <p class="font-bold text-gray-800"><?= $sale['walkin_name'] ? h($sale['walkin_name']) : 'Walk-in Customer' ?></p>
        <?php if ($sale['walkin_name']): ?><p class="text-gray-400 text-xs">Walk-in</p><?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Items Table -->
    <table class="w-full text-sm mb-6">
        <thead>
            <tr class="bg-gray-800 text-white">
                <th class="px-3 py-2 text-left font-medium" style="width:28px">#</th>
                <th class="px-2 py-2" style="width:44px"></th>
                <th class="px-3 py-2 text-left font-medium">Description</th>
                <th class="px-3 py-2 text-right font-medium">Unit Price</th>
                <th class="px-3 py-2 text-right font-medium">Qty</th>
                <th class="px-3 py-2 text-right font-medium">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $i => $item): ?>
            <tr class="border-b <?= $i%2==0?'bg-white':'bg-gray-50' ?>">
                <td class="px-3 py-2 text-gray-400"><?= $i+1 ?></td>
                <td class="px-2 py-2">
                    <?php if ($item['_img_url']): ?>
                    <img src="<?= h($item['_img_url']) ?>" alt="" class="item-img">
                    <?php else: ?>
                    <div class="item-img-placeholder"><i class="fa-solid fa-box"></i></div>
                    <?php endif; ?>
                </td>
                <td class="px-3 py-2 font-medium"><?= h($item['product_name']) ?></td>
                <td class="px-3 py-2 text-right"><?= formatMoney($item['unit_price']) ?></td>
                <td class="px-3 py-2 text-right"><?= number_format($item['quantity'],2) ?> <?= h($item['unit']) ?></td>
                <td class="px-3 py-2 text-right font-semibold"><?= formatMoney($item['total']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Totals -->
    <div class="flex justify-end">
        <table class="text-sm">
            <tr><td class="px-4 py-1 text-gray-500 text-right">Subtotal:</td><td class="px-4 py-1 font-medium text-right"><?= formatMoney($sale['subtotal']) ?></td></tr>
            <?php if ($sale['discount_amount']>0): ?>
            <tr><td class="px-4 py-1 text-gray-500 text-right">Discount:</td><td class="px-4 py-1 text-red-500 text-right">-<?= formatMoney($sale['discount_amount']) ?></td></tr>
            <?php endif; ?>
            <tr class="border-t-2 border-gray-300">
                <td class="px-4 py-2 font-bold text-gray-800 text-right text-base">TOTAL:</td>
                <td class="px-4 py-2 font-black text-blue-700 text-right text-base"><?= formatMoney($sale['total_amount']) ?></td>
            </tr>
            <tr><td class="px-4 py-1 text-green-600 text-right">Paid:</td><td class="px-4 py-1 text-green-700 font-semibold text-right"><?= formatMoney($sale['amount_paid']) ?></td></tr>
            <?php if ($sale['balance_due']>0): ?>
            <tr class="bg-red-50"><td class="px-4 py-2 text-red-600 font-bold text-right">BALANCE DUE:</td><td class="px-4 py-2 text-red-700 font-black text-right"><?= formatMoney($sale['balance_due']) ?></td></tr>
            <?php else: ?>
            <tr class="bg-green-50"><td class="px-4 py-2 text-green-700 font-bold text-right text-center" colspan="2">✓ FULLY PAID</td></tr>
            <?php endif; ?>
        </table>
    </div>

    <!-- Footer -->
    <div class="mt-8 pt-4 border-t text-center text-gray-400 text-xs">
        <p>Thank you for your business! | Served by: <?= h($sale['staff_name']) ?></p>
        <p>Payment Method: <?= ucwords(str_replace('_',' ',$sale['payment_method'])) ?></p>
    </div>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script>window.onload = function() { window.print(); }</script>
</body>
</html>
