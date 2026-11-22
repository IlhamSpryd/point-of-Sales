<?php

declare(strict_types=1);

/**
 * Enterprise Navigation Configuration — Apeiron Systems (ADR-NAV-001 / ADR-NAV-006).
 *
 * Single Source of Truth: app/Platform/Navigation/NavigationRegistry.php
 * Domain Configurations: config/navigation/domains/*.php
 *
 * Legacy colliding taxonomies (`sections`, `sub_nav`) have been eradicated.
 * Navigation visibility is advisory; backend middleware & policies are authoritative.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Navigation Engine Toggle
    |--------------------------------------------------------------------------
    | 'registry' : Canonical 9-domain x 19-hub x 45-page architecture.
    | 'legacy'   : Retained as safety fallback flag if required.
    */
    'engine' => env('NAVIGATION_ENGINE', 'registry'),

    /*
    |--------------------------------------------------------------------------
    | Domains Directory Path
    |--------------------------------------------------------------------------
    */
    'domains_path' => __DIR__.'/navigation/domains',

    /*
    |--------------------------------------------------------------------------
    | Shortcut Allowlist (Governance Law G-1 / ADR-NAV-004)
    |--------------------------------------------------------------------------
    */
    'shortcut_allowlist' => [
        'nav.frontline.pos',
        'nav.frontline.desk',
        'nav.admin.org.channel',
    ],

    /*
    |--------------------------------------------------------------------------
    | Deprecated Legacy Modules (ADR-NAV-011)
    |--------------------------------------------------------------------------
    | Retained solely for backwards compatibility with the deprecated
    | x-erp.app-launcher component test until its scheduled removal.
    */
    'modules' => [
        [
            'id' => 'pos',
            'name' => 'Cashier & Operations',
            'short_name' => 'POS & Frontline',
            'description' => 'Kasir POS, denah meja resto, KDS dapur, struk pesanan & shift.',
            'icon' => 'point_of_sale',
            'badge' => 'Frontline',
            'route' => 'transaction.create',
            'active_pattern' => ['transaction.*'],
            'roles' => ['Owner', 'Manager', 'Kasir', 'Waiter', 'Supervisor', 'Barista', 'Cook'],
            'items' => [],
        ],
        [
            'id' => 'finance',
            'name' => 'Finance & Accounting',
            'short_name' => 'Finance & GL',
            'description' => 'Buku besar akuntansi, rekonsiliasi kas & bank, laporan keuangan.',
            'icon' => 'account_balance',
            'badge' => 'General Ledger',
            'route' => 'finance.index',
            'active_pattern' => ['finance.*'],
            'roles' => ['Owner', 'Manager', 'Finance', 'Admin'],
            'items' => [],
        ],
        [
            'id' => 'hr',
            'name' => 'Human Resources & Payroll',
            'short_name' => 'HR & Payroll',
            'description' => 'Karyawan, presensi shift, lembur PP 35/2021 & payroll PPh 21 TER.',
            'icon' => 'badge',
            'badge' => 'Payroll',
            'route' => 'hr.attendance.index',
            'active_pattern' => ['hr.*'],
            'roles' => ['Owner', 'Manager', 'Admin'],
            'items' => [],
        ],
    ],
];
