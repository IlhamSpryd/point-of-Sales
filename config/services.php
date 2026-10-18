<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'midtrans' => [
        'merchant_id' => env('MIDTRANS_MERCHANT_ID'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'is_production' => env('MIDTRANS_IS_PRODUCTION', false),
        'is_sanitized' => env('MIDTRANS_IS_SANITIZED', true),
        'is_3ds' => env('MIDTRANS_IS_3DS', true),
    ],

    'webhooks' => [
        'grabfood_secret' => env('WEBHOOK_GRABFOOD_SECRET'),
        'gofood_secret' => env('WEBHOOK_GOFOOD_SECRET'),
        'default_tenant_id' => env('WEBHOOK_DEFAULT_TENANT_ID'),
        'default_store_id' => env('WEBHOOK_DEFAULT_STORE_ID'),
    ],

    /*
    |------------------------------------------------------------------
    | Domain 1 — S2S provisioning dari website marketing
    |------------------------------------------------------------------
    | Dua secret aktif untuk rotasi tanpa downtime (D7):
    |  - secret_current  : dipakai marketing untuk menandatangani request baru
    |  - secret_previous : masih diterima selama masa transisi rotasi
    | JANGAN pernah menggunakan APP_KEY sebagai provisioning secret.
    */
    'provisioning' => [
        'secret_current' => env('PROVISIONING_SECRET'),
        'secret_previous' => env('PROVISIONING_SECRET_PREVIOUS'),
        'timestamp_window' => env('PROVISIONING_TIMESTAMP_WINDOW', 300),
        'nonce_retention' => env('PROVISIONING_NONCE_RETENTION', 1800),
    ],

    'marketing' => [
        'url' => env('MARKETING_URL', 'http://localhost:8080'),
        'register_url' => env('MARKETING_REGISTER_URL', 'http://localhost:8080/register'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID', 'test-google-client-id'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET', 'test-google-client-secret'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI', 'http://localhost:8000/auth/oauth/google/callback'),
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID', 'test-apple-client-id'),
        'team_id' => env('APPLE_TEAM_ID', 'test-apple-team-id'),
        'key_id' => env('APPLE_KEY_ID', 'test-apple-key-id'),
        'private_key' => env('APPLE_PRIVATE_KEY'),
        'redirect_uri' => env('APPLE_REDIRECT_URI', 'http://localhost:8000/auth/oauth/apple/callback'),
    ],

];
