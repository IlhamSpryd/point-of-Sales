<?php

declare(strict_types=1);

/**
 * D8 — ANALYTICS
 *
 * Read-only, cross-domain aggregation. Separate cadence (weekly / monthly) and
 * a different consumer than the operational domains.
 *
 * @see docs/architecture/APEIRON_ENTERPRISE_NAVIGATION_ARCHITECTURE_BLUEPRINT.md §9.2
 */
return [
    'key' => 'analytics',
    'label' => 'Analytics',
    'order' => 80,
    'icon' => 'query_stats',
    'hubs' => [
        [
            'id' => 'nav.analytics.bi',
            'label' => 'Business Intelligence',
            'route' => 'reports.analytics',
            'active' => ['reports.analytics*'],
            'roles' => ['Owner', 'Manager'],
            'abilities' => ['reports.analytics.view'],
            'scope' => 'tenant',
            'icon' => 'query_stats',
            'order' => 140,
            'landing' => true,
            'aliases' => ['bi', 'analitik', 'analytics', 'dashboard analitik'],
            'pages' => [
            ],
        ],
        [
            'id' => 'nav.analytics.sales_reports',
            'label' => 'Sales Reports & EOD',
            'route' => 'reports.sales',
            'active' => ['reports.sales*', 'reports.eod-close'],
            'roles' => ['Owner', 'Manager'],
            'abilities' => ['reports.sales.view'],
            'scope' => 'store',
            'icon' => 'analytics',
            'order' => 150,
            'landing' => true,
            'aliases' => ['laporan penjualan', 'sales report', 'eod', 'tutup harian'],
            'pages' => [
            ],
        ],
    ],
];
