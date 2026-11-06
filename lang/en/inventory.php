<?php

declare(strict_types=1);

return [
    'title' => 'Inventory Management',
    'subtitle' => 'Track stock levels, monitor movements, and execute warehouse stock adjustments.',
    'stock_on_hand' => 'Stock on Hand',
    'available_stock' => 'Available Stock',
    'reserved_stock' => 'Reserved Stock',
    'reorder_point' => 'Reorder Point',
    'transfers' => [
        'title' => 'Stock Transfers',
        'create' => 'Create Stock Transfer',
        'source_branch' => 'Source Branch',
        'destination_branch' => 'Destination Branch',
        'transfer_number' => 'Transfer Number',
        'sent' => 'Dispatched',
        'received' => 'Received',
    ],
    'adjustments' => [
        'title' => 'Stock Adjustments',
        'create' => 'Create Stock Adjustment',
        'reason' => 'Adjustment Reason',
        'quantity_diff' => 'Quantity Variance',
    ],
    'stocktake' => [
        'title' => 'Stocktake / Opname',
        'counted_qty' => 'Counted Quantity',
        'system_qty' => 'System Quantity',
        'discrepancy' => 'Discrepancy',
    ],
    'messages' => [
        'transfer_created' => 'Stock transfer created successfully.',
        'transfer_received' => 'Stock transfer marked as received.',
        'adjustment_posted' => 'Stock adjustment posted successfully.',
    ],
];
