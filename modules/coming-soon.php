<?php
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/functions.php';
requireLogin();

$featureLabels = [
    'sales_pos'                  => ['label' => 'Sales & POS',              'icon' => 'fa-receipt',           'desc' => 'Point-of-sale and invoice management'],
    'sales_customers'            => ['label' => 'Customer Management',       'icon' => 'fa-users',             'desc' => 'Customer directory and profiles'],
    'finance_debts'              => ['label' => 'Debts & Credit Sales',      'icon' => 'fa-file-invoice-dollar','desc' => 'Credit sales and outstanding debt tracking'],
    'finance_payments'           => ['label' => 'Debt Payments',             'icon' => 'fa-money-bill-transfer','desc' => 'Payment collection against outstanding debts'],
    'finance_expenses'           => ['label' => 'Expenses',                  'icon' => 'fa-wallet',            'desc' => 'Business expense recording and categorisation'],
    'finance_income'             => ['label' => 'Income',                    'icon' => 'fa-circle-dollar-to-slot','desc' => 'Other income streams beyond direct sales'],
    'finance_drawings'           => ['label' => 'Owner Drawings',            'icon' => 'fa-money-bill-wave',   'desc' => 'Owner withdrawal and drawing records'],
    'inventory_products'         => ['label' => 'Products & Inventory',      'icon' => 'fa-boxes-stacked',     'desc' => 'Product catalogue and stock management'],
    'inventory_adjustments'      => ['label' => 'Stock Adjustments',         'icon' => 'fa-warehouse',         'desc' => 'Manual stock-level corrections and write-offs'],
    'branches_management'        => ['label' => 'Branch Management',         'icon' => 'fa-code-branch',       'desc' => 'Multiple branch setup and per-branch records'],
    'analytics_sales_report'     => ['label' => 'Sales Report',              'icon' => 'fa-chart-bar',         'desc' => 'Detailed sales analytics and daily breakdowns'],
    'analytics_financial_report' => ['label' => 'Financial Report (P&L)',    'icon' => 'fa-chart-pie',         'desc' => 'Profit & Loss statement and expense analysis'],
    'analytics_inventory_report' => ['label' => 'Inventory Report',          'icon' => 'fa-warehouse',         'desc' => 'Stock movement, value, and reorder analysis'],
    'analytics_debt_report'      => ['label' => 'Debt Report',               'icon' => 'fa-file-invoice-dollar','desc' => 'Outstanding and settled debt summary'],
    'analytics_payment_report'   => ['label' => 'Payment Report',            'icon' => 'fa-money-bill-trend-up','desc' => 'Payment method breakdown and collection trends'],
    'analytics_customer_report'  => ['label' => 'Customer Report',           'icon' => 'fa-users-line',        'desc' => 'Top customers and purchase behaviour insights'],
    'analytics_revenue_report'   => ['label' => 'Revenue Report',            'icon' => 'fa-chart-bar',         'desc' => 'Service revenue and collection summary'],
    'analytics_balance_sheet'    => ['label' => 'Balance Sheet',             'icon' => 'fa-scale-balanced',    'desc' => 'Assets, liabilities and equity overview'],
    'analytics_smart_alerts'     => ['label' => 'Smart Alerts',              'icon' => 'fa-bell',              'desc' => 'Automated low-stock and overdue-debt notifications'],
    'support_tickets'            => ['label' => 'Support Tickets',           'icon' => 'fa-headset',           'desc' => 'Submit and track support requests'],
];

$key  = trim(get('feature', ''));
$feat = $featureLabels[$key] ?? ['label' => 'This Feature', 'icon' => 'fa-hourglass-half', 'desc' => 'This feature is not yet available.'];

$pageTitle = 'Coming Soon — ' . $feat['label'];
include __DIR__ . '/../app/includes/header.php';
include __DIR__ . '/../app/includes/sidebar.php';
?>

<div class="flex flex-col items-center justify-center min-h-[60vh] text-center px-4">

    <!-- Animated clock ring -->
    <div class="relative mb-8">
        <div class="w-28 h-28 rounded-full bg-gradient-to-br from-blue-50 to-indigo-100 flex items-center justify-center shadow-lg border border-blue-100">
            <i class="fa-solid <?= h($feat['icon']) ?> text-4xl text-blue-600 opacity-80"></i>
        </div>
        <!-- Orbit dot -->
        <div class="absolute inset-0 rounded-full" style="animation: spin 3s linear infinite;">
            <div class="absolute top-1 left-1/2 -translate-x-1/2 w-3 h-3 bg-amber-400 rounded-full shadow-sm border border-amber-300"></div>
        </div>
    </div>

    <h2 class="text-2xl font-black text-gray-800 mb-2"><?= h($feat['label']) ?></h2>
    <p class="text-base text-gray-500 mb-1">Coming Soon</p>
    <p class="text-sm text-gray-400 max-w-md mb-8"><?= h($feat['desc']) ?> — this feature is being prepared for your account and will be enabled shortly.</p>

    <!-- Info card -->
    <div class="bg-amber-50 border border-amber-200 rounded-xl px-6 py-4 max-w-sm mb-8 text-left">
        <div class="flex items-start gap-3">
            <i class="fa-solid fa-triangle-exclamation text-amber-500 mt-0.5 flex-shrink-0"></i>
            <div>
                <p class="text-sm font-semibold text-amber-800 mb-0.5">Feature not yet available</p>
                <p class="text-xs text-amber-600 leading-relaxed">
                    Your administrator has marked this feature as <strong>Coming Soon</strong>.
                    Contact your system administrator for an estimated activation date.
                </p>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="flex gap-3">
        <a href="<?= url('dashboard') ?>"
           class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition-colors flex items-center gap-2">
            <i class="fa-solid fa-gauge-high"></i> Go to Dashboard
        </a>
        <a href="javascript:history.back()"
           class="bg-gray-100 text-gray-600 px-5 py-2.5 rounded-lg text-sm font-medium hover:bg-gray-200 transition-colors">
            <i class="fa-solid fa-arrow-left mr-1"></i> Go Back
        </a>
    </div>
</div>

<style>
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>

<?php include __DIR__ . '/../app/includes/footer.php'; ?>
