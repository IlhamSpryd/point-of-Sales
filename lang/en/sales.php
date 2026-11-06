<?php

declare(strict_types=1);

return [
    'title' => 'Sales & Orders',
    'subtitle' => 'Track completed sales, manage quotations, invoices, and process customer returns.',
    'orders' => [
        'title' => 'Sales Orders',
        'order_number' => 'Order Number',
        'order_date' => 'Order Date',
        'channel' => 'Sales Channel',
        'customer' => 'Customer',
        'total' => 'Total Amount',
        'payment_status' => 'Payment Status',
        'fulfillment_status' => 'Fulfillment Status',
    ],
    'quotations' => [
        'title' => 'Sales Quotations',
        'create' => 'Create Quotation',
        'expiry_date' => 'Expiration Date',
        'convert_to_order' => 'Convert to Sales Order',
    ],
    'returns' => [
        'title' => 'Returns & Refunds',
        'create' => 'Process Return',
        'credit_note' => 'Credit Note',
        'return_reason' => 'Return Reason',
        'refund_amount' => 'Refund Amount',
    ],
    'receivables' => [
        'title' => 'Accounts Receivable',
        'outstanding' => 'Outstanding Balance',
        'due_date' => 'Due Date',
        'record_payment' => 'Record Customer Payment',
    ],
];
