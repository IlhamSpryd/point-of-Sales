<?php

declare(strict_types=1);

return [
    'title' => 'Suppliers',
    'subtitle' => 'Manage vendor contacts, payment terms, and procurement partnerships.',
    'add_supplier' => 'Add Supplier',
    'edit_supplier' => 'Edit Supplier',
    'fields' => [
        'name' => 'Supplier Name',
        'code' => 'Supplier Code',
        'email' => 'Email Address',
        'phone' => 'Phone Number',
        'address' => 'Business Address',
        'tax_number' => 'Tax Identification Number (NPWP/TIN)',
        'payment_terms' => 'Payment Terms',
        'status' => 'Status',
    ],
    'empty' => [
        'title' => 'No Suppliers Found',
        'description' => 'Add your first supplier to begin creating purchase orders.',
    ],
];
