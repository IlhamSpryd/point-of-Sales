<?php

declare(strict_types=1);

return [
    'title' => 'Purchasing & Procurement',
    'subtitle' => 'Manage purchase orders, goods receipts, and vendor invoices.',
    'orders' => [
        'title' => 'Purchase Orders',
        'create' => 'Create Purchase Order',
        'po_number' => 'PO Number',
        'supplier' => 'Supplier',
        'order_date' => 'Order Date',
        'expected_date' => 'Expected Delivery Date',
        'total_amount' => 'Total Amount',
        'receiving_status' => 'Receiving Status',
        'invoicing_status' => 'Invoicing Status',
    ],
    'grn' => [
        'title' => 'Goods Received Notes (GRN)',
        'create' => 'Receive Goods',
        'grn_number' => 'GRN Number',
        'received_date' => 'Received Date',
        'received_qty' => 'Received Quantity',
    ],
    'invoices' => [
        'title' => 'Supplier Invoices',
        'invoice_number' => 'Invoice Number',
        'due_date' => 'Due Date',
        'match_status' => '3-Way Match Status',
    ],
    'returns' => [
        'title' => 'Purchase Returns',
        'create' => 'Create Purchase Return',
        'return_number' => 'Return Number',
        'debit_note' => 'Debit Note',
    ],
];
