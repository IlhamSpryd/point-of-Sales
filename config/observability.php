<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Metrics Scrape Protection Token (OPS-02, ENT-02)
    |--------------------------------------------------------------------------
    | Bearer token used to authenticate Prometheus scrape requests to /metrics.
    | In production, if this token is empty or unset, the endpoint fails closed (401).
    */
    'metrics_token' => env('METRICS_SCRAPE_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Alert-as-Code Configuration (OPS-03, TARGET_SYSTEM_ARCHITECTURE §8.4)
    |--------------------------------------------------------------------------
    | Operational alert webhook URL and cooldown window to prevent notification storms.
    */
    'alert_webhook_url' => env('OPS_ALERT_WEBHOOK_URL'),
    'alert_cooldown_minutes' => (int) env('OPS_ALERT_COOLDOWN_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Operational Alert Thresholds
    |--------------------------------------------------------------------------
    | Thresholds defined per INTEGRATION_AND_EVENT_ARCHITECTURE.md §9 and
    | TARGET_SYSTEM_ARCHITECTURE.md §8.4.
    */
    'thresholds' => [
        'outbox_pending_age_seconds' => 300,
        'outbox_dead_letter_max' => 0,
        'queue_failed_jobs_max' => 10,
        'webhook_failure_ratio_max' => 0.05, // 5% over 15 minutes
        'disk_free_min_percent' => 15.0,
    ],
];
