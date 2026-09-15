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
];
