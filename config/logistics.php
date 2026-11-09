<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Logistics & 3PL Courier Configuration
    |--------------------------------------------------------------------------
    | Configuration for Indonesian 3PL courier integrations (JNE, SiCepat,
    | Pos Indonesia, J&T Express) via direct API or aggregator (Biteship/Shipper).
    */

    'mock_mode' => env('LOGISTICS_MOCK_MODE', true),

    'default_origin_postal_code' => env('LOGISTICS_DEFAULT_ORIGIN_POSTAL_CODE', '10110'),
    'default_origin_city' => env('LOGISTICS_DEFAULT_ORIGIN_CITY', 'Jakarta Pusat'),

    'couriers' => [
        'jne' => [
            'enabled' => env('LOGISTICS_JNE_ENABLED', true),
            'api_key' => env('LOGISTICS_JNE_API_KEY', ''),
            'api_secret' => env('LOGISTICS_JNE_API_SECRET', ''),
            'base_url' => env('LOGISTICS_JNE_BASE_URL', 'https://api.jne.co.id/v1'),
            'timeout' => 15,
        ],
        'sicepat' => [
            'enabled' => env('LOGISTICS_SICEPAT_ENABLED', true),
            'api_key' => env('LOGISTICS_SICEPAT_API_KEY', ''),
            'api_secret' => env('LOGISTICS_SICEPAT_API_SECRET', ''),
            'base_url' => env('LOGISTICS_SICEPAT_BASE_URL', 'https://api.sicepat.com/v1'),
            'timeout' => 15,
        ],
        'pos' => [
            'enabled' => env('LOGISTICS_POS_ENABLED', true),
            'api_key' => env('LOGISTICS_POS_API_KEY', ''),
            'api_secret' => env('LOGISTICS_POS_API_SECRET', ''),
            'base_url' => env('LOGISTICS_POS_BASE_URL', 'https://api.posindonesia.co.id/v1'),
            'timeout' => 15,
        ],
        'jnt' => [
            'enabled' => env('LOGISTICS_JNT_ENABLED', true),
            'api_key' => env('LOGISTICS_JNT_API_KEY', ''),
            'api_secret' => env('LOGISTICS_JNT_API_SECRET', ''),
            'base_url' => env('LOGISTICS_JNT_BASE_URL', 'https://api.jet.co.id/v1'),
            'timeout' => 15,
        ],
    ],
];
