<?php

return [
    'tax_rate' => 0.11,
    'rounding_behavior' => 'ROUND_NEAREST',
    'rounding_value' => 100,
    'active_payment_methods' => [
        'cash' => env('POS_PAYMENT_CASH', true),
        'qris' => env('POS_PAYMENT_QRIS', true),
        'ewallet' => env('POS_PAYMENT_EWALLET', true),
    ],
    'self_order_system_email' => 'selforder@system.local',
    'receipt_width_mm' => env('POS_RECEIPT_WIDTH_MM', 58),
    'printer_name' => env('POS_PRINTER_NAME', 'EPSON_TM_T82'),
    'auto_open_drawer' => env('POS_AUTO_OPEN_DRAWER', false),
    'qz_cert_path' => env('QZ_CERT_PATH', storage_path('app/private/qz/digital-certificate.txt')),
    'qz_private_key_path' => env('QZ_PRIVATE_KEY_PATH', storage_path('app/private/qz/private-key.pem')),
];
