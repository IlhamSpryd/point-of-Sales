<?php

declare(strict_types=1);

return [
    'title' => 'Customers',
    'subtitle' => 'Manage customer relationships, loyalty profiles, and transaction histories.',
    'add_customer' => 'Add Customer',
    'edit_customer' => 'Edit Customer',
    'fields' => [
        'name' => 'Customer Name',
        'code' => 'Customer Code',
        'email' => 'Email Address',
        'phone' => 'Phone Number',
        'address' => 'Billing / Delivery Address',
        'loyalty_points' => 'Loyalty Points',
        'group' => 'Customer Group',
        'receivables' => 'Accounts Receivable Balance',
    ],
    'empty' => [
        'title' => 'No Customers Found',
        'description' => 'Add customers or register them directly during POS transactions.',
    ],
];
