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
    'channel_order_system_email' => env('POS_CHANNEL_ORDER_SYSTEM_EMAIL', 'channel-order@system.local'),
    'receipt_width_mm' => env('POS_RECEIPT_WIDTH_MM', 58),
    'printer_name' => env('POS_PRINTER_NAME', 'EPSON_TM_T82'),
    'auto_open_drawer' => env('POS_AUTO_OPEN_DRAWER', false),
    'qz_cert_path' => env('QZ_CERT_PATH', storage_path('app/private/qz/digital-certificate.txt')),
    'qz_private_key_path' => env('QZ_PRIVATE_KEY_PATH', storage_path('app/private/qz/private-key.pem')),

    // PATCH FOR F-11: estimasi menit per item untuk ETA pelanggan self-order.
    'eta_per_item' => env('POS_ETA_PER_ITEM', 3),
    // KDS SLA Configuration
    'kds_sla_warning_minutes' => env('KDS_SLA_WARNING_MINUTES', 10),
    'kds_sla_critical_minutes' => env('KDS_SLA_CRITICAL_MINUTES', 15),

    // Phase 14: Shadow balances authority flag
    'stock_balances_authoritative' => env('POS_STOCK_BALANCES_AUTHORITATIVE', false),

    /*
    |------------------------------------------------------------------
    | Domain 1 — Tenant provisioning
    |------------------------------------------------------------------
    */
    'provisioning' => [
        // Magic link onboarding Owner: masa berlaku token (jam).
        'onboarding_token_ttl_hours' => env('POS_ONBOARDING_TOKEN_TTL_HOURS', 48),
        // Batas retry pengiriman email outbox sebelum ditandai failed.
        'outbox_max_attempts' => env('POS_OUTBOX_MAX_ATTEMPTS', 5),
    ],
];
