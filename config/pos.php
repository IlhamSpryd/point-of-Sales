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
    // [OMEGA-NODE5] Akun sistem atribusi transaksi omnichannel, pola
    // IDENTIK self_order_system_email. WAJIB di-seed (lihat SYNC ALERT
    // NODE 1) sebelum webhook diaktifkan.
    'channel_order_system_email' => env('POS_CHANNEL_ORDER_SYSTEM_EMAIL', 'channel-order@system.local'),
    'receipt_width_mm' => env('POS_RECEIPT_WIDTH_MM', 58),
    'printer_name' => env('POS_PRINTER_NAME', 'EPSON_TM_T82'),
    'auto_open_drawer' => env('POS_AUTO_OPEN_DRAWER', false),
    'qz_cert_path' => env('QZ_CERT_PATH', storage_path('app/private/qz/digital-certificate.txt')),
    'qz_private_key_path' => env('QZ_PRIVATE_KEY_PATH', storage_path('app/private/qz/private-key.pem')),
];
