<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requireLogin();

$db    = getDB();
$bizId = currentBusinessId();
$id    = (int)get('id');

$ordQ = $db->prepare('SELECT so.*, c.name AS customer_name, c.phone AS customer_phone, c.address AS customer_address,
    u.name AS staff_name,
    b.name AS biz_name, b.address AS biz_address, b.phone AS biz_phone, b.email AS biz_email,
    b.logo AS biz_logo, b.currency AS biz_currency
    FROM service_orders so
    LEFT JOIN customers c ON c.id=so.customer_id
    LEFT JOIN users u ON u.id=so.user_id
    LEFT JOIN businesses b ON b.id=so.business_id
    WHERE so.id=? AND so.business_id=?');
$ordQ->execute([$id, $bizId]);
$order = $ordQ->fetch();
if (!$order) die('Order not found.');

$itemsQ = $db->prepare('SELECT * FROM service_order_items WHERE order_id=?');
$itemsQ->execute([$id]);
$items = $itemsQ->fetchAll();

$bizLogoUrl = '';
if (!empty($order['biz_logo'])) {
    $absPath = __DIR__ . '/../../' . $order['biz_logo'];
    if (file_exists($absPath)) $bizLogoUrl = APP_URL . '/' . $order['biz_logo'];
}

$statusLabel = match($order['status']) {
    'pending'     => ['label' => 'PENDING',     'color' => '#f59e0b'],
    'in_progress' => ['label' => 'IN PROGRESS', 'color' => '#3b82f6'],
    'completed'   => ['label' => 'COMPLETED',   'color' => '#16a34a'],
    'cancelled'   => ['label' => 'CANCELLED',   'color' => '#dc2626'],
    default       => ['label' => strtoupper($order['status']), 'color' => '#6b7280'],
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice <?= h($order['order_number']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Arial', sans-serif; }
        @media print {
            .no-print { display: none !important; }
            body { padding: 0; margin: 0; }
            img { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body class="bg-white p-8 max-w-2xl mx-auto">

<div class="no-print mb-4 flex gap-2">
    <button onclick="window.print()" class="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700">
        <i class="fa-solid fa-print mr-1"></i>Print
    </button>
    <a href="view.php?id=<?= $id ?>" class="bg-gray-200 text-gray-700 px-4 py-2 rounded text-sm hover:bg-gray-300">Back</a>
</div>

<div class="border-2 border-gray-200 rounded-lg p-8">
    <!-- Header -->
    <div class="flex justify-between items-start mb-8">
        <div class="flex items-start gap-4">
            <?php if ($bizLogoUrl): ?>
            <img src="<?= h($bizLogoUrl) ?>" alt="<?= h($order['biz_name']) ?> logo"
                 style="height:64px;width:auto;max-width:140px;object-fit:contain;border-radius:8px;">
            <?php endif; ?>
            <div>
                <h1 class="text-2xl font-black text-blue-700 leading-tight"><?= h($order['biz_name']) ?></h1>
                <?php if ($order['biz_address']): ?><p class="text-gray-500 text-sm mt-0.5"><?= nl2br(h($order['biz_address'])) ?></p><?php endif; ?>
                <?php if ($order['biz_phone']): ?><p class="text-gray-500 text-sm"><?= h($order['biz_phone']) ?></p><?php endif; ?>
                <?php if ($order['biz_email']): ?><p class="text-gray-500 text-sm"><?= h($order['biz_email']) ?></p><?php endif; ?>
            </div>
        </div>
        <div class="text-right flex-shrink-0">
            <div class="text-3xl font-black text-gray-200 tracking-widest">SERVICE</div>
            <div class="text-xl font-black text-gray-200 tracking-widest -mt-1">INVOICE</div>
            <p class="font-bold text-gray-800 font-mono text-lg"><?= h($order['order_number']) ?></p>
            <p class="text-gray-500 text-sm mt-1">Date: <?= formatDate($order['created_at']) ?></p>
            <div style="background:<?= $statusLabel['color'] ?>;color:#fff;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;margin-top:6px;display:inline-block;">
                <?= $statusLabel['label'] ?>
            </div>
        </div>
    </div>

    <!-- Bill To -->
    <div class="bg-gray-50 rounded-lg p-4 mb-6">
        <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Bill To:</p>
        <?php if ($order['customer_name']): ?>
        <p class="font-bold text-gray-800"><?= h($order['customer_name']) ?></p>
        <?php if ($order['customer_phone']): ?><p class="text-gray-600 text-sm"><?= h($order['customer_phone']) ?></p><?php endif; ?>
        <?php if ($order['customer_address']): ?><p class="text-gray-600 text-sm"><?= nl2br(h($order['customer_address'])) ?></p><?php endif; ?>
        <?php else: ?>
        <p class="font-bold text-gray-800"><?= $order['walkin_name'] ? h($order['walkin_name']) : 'Walk-in Customer' ?></p>
        <?php if ($order['walkin_phone']): ?><p class="text-gray-600 text-sm"><?= h($order['walkin_phone']) ?></p><?php endif; ?>
        <?php if ($order['walkin_name']): ?><p class="text-gray-400 text-xs">Walk-in Customer</p><?php endif; ?>
        <?php endif; ?>
        <?php if ($order['scheduled_at']): ?>
        <p class="text-gray-500 text-sm mt-1">Scheduled: <?= date('d M Y H:i', strtotime($order['scheduled_at'])) ?></p>
        <?php endif; ?>
    </div>

    <!-- Items Table -->
    <table class="w-full text-sm mb-6">
        <thead>
            <tr class="bg-gray-800 text-white">
                <th class="px-3 py-2 text-left font-medium" style="width:28px">#</th>
                <th class="px-3 py-2 text-left font-medium">Service Description</th>
                <th class="px-3 py-2 text-right font-medium">Unit Price</th>
                <th class="px-3 py-2 text-right font-medium">Qty</th>
                <th class="px-3 py-2 text-right font-medium">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $i => $item): ?>
            <tr class="border-b <?= $i%2==0?'bg-white':'bg-gray-50' ?>">
                <td class="px-3 py-2 text-gray-400"><?= $i+1 ?></td>
                <td class="px-3 py-2 font-medium">
                    <?= h($item['service_name']) ?>
                    <?php if ($item['discount'] > 0): ?>
                    <span class="text-xs text-red-500 ml-1">(disc: <?= formatMoney($item['discount']) ?>)</span>
                    <?php endif; ?>
                </td>
                <td class="px-3 py-2 text-right"><?= formatMoney($item['unit_price']) ?></td>
                <td class="px-3 py-2 text-right"><?= number_format($item['quantity'], 2) ?></td>
                <td class="px-3 py-2 text-right font-semibold"><?= formatMoney($item['total']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Totals -->
    <div class="flex justify-end">
        <table class="text-sm">
            <tr><td class="px-4 py-1 text-gray-500 text-right">Subtotal:</td><td class="px-4 py-1 font-medium text-right"><?= formatMoney($order['subtotal']) ?></td></tr>
            <?php if ($order['discount_amount'] > 0): ?>
            <tr><td class="px-4 py-1 text-gray-500 text-right">Discount:</td><td class="px-4 py-1 text-red-500 text-right">-<?= formatMoney($order['discount_amount']) ?></td></tr>
            <?php endif; ?>
            <tr class="border-t-2 border-gray-300">
                <td class="px-4 py-2 font-bold text-gray-800 text-right text-base">TOTAL:</td>
                <td class="px-4 py-2 font-black text-blue-700 text-right text-base"><?= formatMoney($order['total_amount']) ?></td>
            </tr>
            <tr><td class="px-4 py-1 text-green-600 text-right">Paid:</td><td class="px-4 py-1 text-green-700 font-semibold text-right"><?= formatMoney($order['amount_paid']) ?></td></tr>
            <?php if ($order['balance_due'] > 0): ?>
            <tr class="bg-red-50"><td class="px-4 py-2 text-red-600 font-bold text-right">BALANCE DUE:</td><td class="px-4 py-2 text-red-700 font-black text-right"><?= formatMoney($order['balance_due']) ?></td></tr>
            <?php else: ?>
            <tr class="bg-green-50"><td class="px-4 py-2 text-green-700 font-bold text-center" colspan="2">✓ FULLY PAID</td></tr>
            <?php endif; ?>
        </table>
    </div>

    <!-- Notes -->
    <?php if ($order['notes']): ?>
    <div class="mt-6 p-4 bg-gray-50 rounded-lg">
        <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Notes:</p>
        <p class="text-sm text-gray-600"><?= nl2br(h($order['notes'])) ?></p>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <div class="mt-8 pt-4 border-t text-center text-gray-400 text-xs">
        <p>Thank you for choosing our services! | Handled by: <?= h($order['staff_name'] ?? 'N/A') ?></p>
        <p>Payment Method: <?= ucwords(str_replace('_',' ',$order['payment_method'])) ?> | Generated: <?= date('d M Y H:i') ?></p>
    </div>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script>window.onload = function() { window.print(); }</script>
</body>
</html>
