<?php

declare(strict_types=1);

return [
    'title' => 'Reports & Business Intelligence',
    'subtitle' => 'Comprehensive sales performance, inventory valuation, and accounting analytics.',
    'sales' => [
        'title' => 'Sales Reports',
        'gross_sales' => 'Gross Sales',
        'net_sales' => 'Net Sales',
        'total_discounts' => 'Total Discounts',
        'total_tax' => 'Total Tax Collected',
        'cogs' => 'Cost of Goods Sold (COGS)',
        'gross_margin' => 'Gross Margin',
        'transactions_count' => 'Transaction Count',
        'aov' => 'Average Order Value (AOV)',
    ],
    'inventory' => [
        'title' => 'Inventory Valuation Report',
        'stock_movement' => 'Stock Movement History',
        'dead_stock' => 'Slow Moving / Dead Stock',
        'turnover_rate' => 'Inventory Turnover Rate',
    ],
    'financial' => [
        'income_statement' => 'Income Statement (Profit & Loss)',
        'balance_sheet' => 'Balance Sheet',
        'cash_flow' => 'Cash Flow Statement',
        'trial_balance' => 'Trial Balance',
    ],
    'filters' => [
        'date_range' => 'Date Range',
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'last_7_days' => 'Last 7 Days',
        'last_30_days' => 'Last 30 Days',
        'this_month' => 'This Month',
        'last_month' => 'Last Month',
        'custom_range' => 'Custom Range',
        'branch' => 'Filter by Branch',
        'category' => 'Filter by Category',
    ],
];
