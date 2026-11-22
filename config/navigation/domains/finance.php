<?php

declare(strict_types=1);

/**
 * D6 — FINANCE
 *
 * Double-entry integrity, period close, statutory reporting.
 *
 * Closes INT-01: `finance.fixed-assets.index` was a fully implemented ledger
 * reachable from no surface at all. It is now a first-class Finance tab.
 *
 * SC-04: `nav.supply.procurement.invoices` declares Finance in its roles. A
 * Finance user therefore sees the Procurement hub with exactly one visible page
 * and the L2 bar is suppressed. This is a persona-dependent projection, not a
 * duplicated menu entry.
 *
 * @see docs/architecture/APEIRON_ENTERPRISE_NAVIGATION_ARCHITECTURE_BLUEPRINT.md §9.2, INT-01
 */
return [
    'key' => 'finance',
    'label' => 'Finance',
    'order' => 60,
    'icon' => 'account_balance',
    'hubs' => [
        [
            'id' => 'nav.finance.gl',
            'label' => 'Finance & Accounting',
            'route' => 'finance.index',
            'active' => ['finance.*'],
            'roles' => ['Owner', 'Manager', 'Finance', 'Admin'],
            'abilities' => ['finance.view'],
            'scope' => 'tenant',
            'icon' => 'account_balance',
            'order' => 120,
            'landing' => true,
            'aliases' => ['keuangan', 'akuntansi', 'finance', 'gl', 'buku besar'],
            'pages' => [
                [
                    'id' => 'nav.finance.gl.overview',
                    'label' => 'Financial Overview',
                    'route' => 'finance.index',
                    'active' => ['finance.*'],
                    'roles' => ['Owner', 'Manager', 'Finance', 'Admin'],
                    'abilities' => ['finance.view'],
                    'scope' => 'tenant',
                    'order' => 121,
                    'landing' => true,
                    'aliases' => ['ikhtisar keuangan', 'financial overview', 'ringkasan'],
                ],
                [
                    'id' => 'nav.finance.gl.coa',
                    'label' => 'Chart of Accounts',
                    'route' => 'finance.coa.index',
                    'active' => ['finance.coa.*'],
                    'roles' => ['Owner', 'Manager', 'Finance', 'Admin'],
                    'abilities' => ['finance.coa.manage'],
                    'scope' => 'tenant',
                    'order' => 122,
                    'aliases' => ['coa', 'bagan akun', 'chart of accounts', 'akun'],
                ],
                [
                    'id' => 'nav.finance.gl.journals',
                    'label' => 'General Journal',
                    'route' => 'finance.journals.index',
                    'active' => ['finance.journals.*'],
                    'roles' => ['Owner', 'Manager', 'Finance', 'Admin'],
                    'abilities' => ['finance.journals.manage'],
                    'scope' => 'tenant',
                    'order' => 123,
                    'aliases' => ['jurnal', 'journal', 'jurnal umum', 'posting'],
                ],
                [
                    'id' => 'nav.finance.gl.periods',
                    'label' => 'Accounting Periods',
                    'route' => 'finance.periods.index',
                    'active' => ['finance.periods.*'],
                    'roles' => ['Owner', 'Manager', 'Finance', 'Admin'],
                    'abilities' => ['finance.periods.manage'],
                    'scope' => 'tenant',
                    'order' => 124,
                    'aliases' => ['periode', 'tutup buku', 'period', 'fiscal', 'tutup periode'],
                ],
                [
                    'id' => 'nav.finance.gl.reconciliation',
                    'label' => 'Bank Reconciliation',
                    'route' => 'finance.reconciliation.index',
                    'active' => ['finance.reconciliation.*'],
                    'roles' => ['Owner', 'Manager', 'Finance', 'Admin'],
                    'abilities' => ['finance.reconciliation.manage'],
                    'scope' => 'tenant',
                    'order' => 125,
                    'aliases' => ['rekonsiliasi', 'rekonsiliasi bank', 'bank reconciliation', 'mutasi bank'],
                ],
                [
                    'id' => 'nav.finance.gl.fixed_assets',
                    'label' => 'Fixed Assets',
                    'route' => 'finance.fixed-assets.index',
                    'active' => ['finance.fixed-assets.*'],
                    'roles' => ['Owner', 'Manager', 'Finance', 'Admin'],
                    'abilities' => ['finance.assets.manage'],
                    'scope' => 'tenant',
                    'order' => 126,
                    'aliases' => ['aset tetap', 'fixed asset', 'depresiasi', 'penyusutan'],
                ],
            ],
        ],
    ],
];
