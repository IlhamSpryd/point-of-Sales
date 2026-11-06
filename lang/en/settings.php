<?php

declare(strict_types=1);

return [
    'title' => 'System Settings',
    'subtitle' => 'Configure organization profile, regional defaults, store branches, POS hardware, and access controls.',
    'sections' => [
        'general' => 'Organization Profile',
        'branches' => 'Branches & Stores',
        'devices' => 'POS Terminals & Printers',
        'taxes' => 'Taxes & Service Charges',
        'users' => 'User Management',
        'roles' => 'Roles & Permissions',
        'integrations' => 'API & Integrations',
        'audit' => 'System Audit Logs',
    ],
    'branches' => [
        'title' => 'Branches & Outlets',
        'add_branch' => 'Add Branch',
        'edit_branch' => 'Edit Branch',
        'branch_name' => 'Branch Name',
        'branch_code' => 'Branch Code',
        'address' => 'Physical Address',
        'phone' => 'Phone',
    ],
    'devices' => [
        'title' => 'POS Hardware & Devices',
        'add_device' => 'Register Device',
        'device_name' => 'Device Name',
        'device_identifier' => 'Device Identifier / UUID',
        'printer_name' => 'Receipt Printer',
    ],
    'taxes' => [
        'title' => 'Tax Classes',
        'add_tax' => 'Add Tax Class',
        'rate' => 'Tax Rate (%)',
        'inclusive' => 'Tax Inclusive Pricing',
    ],
];
