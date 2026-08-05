<?php
// Central registry of all system feature keys.
// Add new keys here — subscription plan forms and user access panels pick them up automatically.
return [
    'sales_pos'                  => ['label' => 'Sales & POS',            'section' => 'Sales & Commerce'],
    'sales_customers'            => ['label' => 'Customer Management',    'section' => 'Sales & Commerce'],
    'finance_debts'              => ['label' => 'Debts & Credit Sales',   'section' => 'Sales & Commerce'],
    'finance_payments'           => ['label' => 'Debt Payments',          'section' => 'Sales & Commerce'],
    'finance_expenses'           => ['label' => 'Expenses',               'section' => 'Finance'],
    'finance_income'             => ['label' => 'Income',                 'section' => 'Finance'],
    'finance_drawings'           => ['label' => 'Owner Drawings',         'section' => 'Finance'],
    'inventory_products'         => ['label' => 'Products & Inventory',   'section' => 'Inventory'],
    'inventory_adjustments'      => ['label' => 'Stock Adjustments',      'section' => 'Inventory'],
    'branches_management'        => ['label' => 'Branch Management',      'section' => 'Branches'],
    'finance_suppliers'          => ['label' => 'Suppliers',              'section' => 'Finance'],
    'analytics_sales_report'     => ['label' => 'Sales Report',           'section' => 'Analytics'],
    'analytics_financial_report' => ['label' => 'Financial Report (P&L)', 'section' => 'Analytics'],
    'analytics_inventory_report' => ['label' => 'Inventory Report',       'section' => 'Analytics'],
    'analytics_debt_report'      => ['label' => 'Debt Report',            'section' => 'Analytics'],
    'analytics_payment_report'   => ['label' => 'Payment Report',         'section' => 'Analytics'],
    'analytics_customer_report'  => ['label' => 'Customer Report',        'section' => 'Analytics'],
    'analytics_revenue_report'   => ['label' => 'Revenue Report',         'section' => 'Analytics'],
    'analytics_balance_sheet'    => ['label' => 'Balance Sheet',          'section' => 'Analytics'],
    'analytics_smart_alerts'     => ['label' => 'Smart Alerts',           'section' => 'Analytics'],
    'support_tickets'            => ['label' => 'Support Tickets',        'section' => 'Support'],
];
