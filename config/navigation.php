<?php

declare(strict_types=1);

/**
 * Sumber kebenaran tunggal untuk menu navigasi sidebar — Apeiron POS.
 *
 * Setiap entri WAJIB memiliki key: label, icon, route, active, roles, scope.
 * - `roles` HARUS 100% sinkron dengan middleware `role:` di routes/web.php.
 * - `active` berisi string pola atau array pola untuk request()->routeIs().
 * - `scope` menandai cakupan data: 'store' (cabang aktif) atau 'tenant' (seluruh bisnis).
 * - Jangan tambahkan entri dengan route yang belum terdaftar di routes/web.php.
 *
 * File ini dipakai oleh:
 *   1. layouts/navigation.blade.php — render sidebar & drawer
 *   2. tests/Feature/Navigation/SidebarIntegrityTest.php — validasi otomatis
 *
 * @see routes/web.php
 * @see docs/implementationPlanSidebarNavigation.md
 */

return [
    'sections' => [
        'Ikhtisar' => [
            [
                'label' => 'Dashboard',
                'icon' => 'space_dashboard',
                'route' => 'dashboard',
                'active' => 'dashboard',
                'roles' => ['Owner', 'Manager'],
                'scope' => 'tenant',
            ],
        ],
        'Kasir & Operasional' => [
            [
                'label' => 'Kasir (POS)',
                'icon' => 'point_of_sale',
                'route' => 'transaction.create',
                'active' => 'transaction.*',
                'roles' => ['Owner', 'Manager', 'Kasir'],
                'scope' => 'store',
            ],
            [
                'label' => 'Riwayat Pesanan',
                'icon' => 'receipt_long',
                'route' => 'orders.index',
                'active' => 'orders.*',
                'roles' => ['Owner', 'Manager', 'Kasir', 'Waiter'],
                'scope' => 'store',
            ],
            [
                'label' => 'Shift Kasir',
                'icon' => 'lock_clock',
                'route' => 'shifts.index',
                'active' => 'shifts.*',
                'roles' => ['Owner', 'Manager', 'Kasir', 'Supervisor'],
                'scope' => 'store',
            ],
        ],
        'Katalog & Resep' => [
            [
                'label' => 'Katalog Produk',
                'icon' => 'inventory_2',
                'route' => 'products.index',
                'active' => [
                    'products.*',
                    'categories.*',
                    'brands.*',
                    'units-of-measure.*',
                    'inventory.modifiers',
                ],
                'roles' => ['Owner', 'Manager', 'Inventory'],
                'scope' => 'tenant',
            ],
            [
                'label' => 'Daftar Harga',
                'icon' => 'price_change',
                'route' => 'price-lists.index',
                'active' => 'price-lists.*',
                'roles' => ['Owner', 'Manager', 'Inventory'],
                'scope' => 'tenant',
            ],
            [
                'label' => 'Asortimen Cabang',
                'icon' => 'storefront',
                'route' => 'assortments.index',
                'active' => 'assortments.*',
                'roles' => ['Owner', 'Manager', 'Inventory'],
                'scope' => 'store',
            ],
        ],
        'Inventori & Suplai' => [
            [
                'label' => 'Bahan Baku & Stok',
                'icon' => 'warehouse',
                'route' => 'inventory.ingredients',
                'active' => 'inventory.ingredients*',
                'roles' => ['Owner', 'Manager', 'Inventory'],
                'scope' => 'store',
            ],
            [
                'label' => 'Operasional Stok',
                'icon' => 'sync_alt',
                'route' => 'inventory.goods-receipts',
                'active' => [
                    'inventory.goods-receipts*',
                    'inventory.adjustments*',
                    'inventory.opnames*',
                    'inventory.supplier-returns*',
                    'inventory.transfers*',
                ],
                'roles' => ['Owner', 'Manager', 'Inventory'],
                'scope' => 'store',
            ],
            [
                'label' => 'Buku Besar Stok',
                'icon' => 'menu_book',
                'route' => 'inventory.ledger',
                'active' => 'inventory.ledger*',
                'roles' => ['Owner', 'Manager', 'Inventory'],
                'scope' => 'store',
            ],
            [
                'label' => 'Pengadaan (Purchasing)',
                'icon' => 'local_shipping',
                'route' => 'purchasing.suppliers.index',
                'active' => 'purchasing.*',
                'roles' => ['Owner', 'Manager', 'Inventory'],
                'scope' => 'tenant',
            ],
        ],
        'Analitik & Pelanggan' => [
            [
                'label' => 'Laporan Penjualan',
                'icon' => 'analytics',
                'route' => 'reports.sales',
                'active' => 'reports.*',
                'roles' => ['Owner', 'Manager'],
                'scope' => 'tenant',
            ],
            [
                'label' => 'Pelanggan & CRM',
                'icon' => 'groups',
                'route' => 'customers.index',
                'active' => 'customers.*',
                'roles' => ['Owner', 'Manager'],
                'scope' => 'tenant',
            ],
            [
                'label' => 'Diskon & Promo',
                'icon' => 'sell',
                'route' => 'discounts.index',
                'active' => 'discounts.*',
                'roles' => ['Owner', 'Manager'],
                'scope' => 'store',
            ],
        ],
        'Pengaturan & Tata Kelola' => [
            [
                'label' => 'Cabang',
                'icon' => 'storefront',
                'route' => 'stores.index',
                'active' => 'stores.*',
                'roles' => ['Owner'],
                'scope' => 'tenant',
            ],
            [
                'label' => 'Pengguna',
                'icon' => 'group',
                'route' => 'users.index',
                'active' => ['users.*', 'users.invitations.*'],
                'roles' => ['Owner'],
                'scope' => 'tenant',
            ],
            [
                'label' => 'Peran',
                'icon' => 'admin_panel_settings',
                'route' => 'roles.index',
                'active' => 'roles.*',
                'roles' => ['Owner'],
                'scope' => 'tenant',
            ],
            [
                'label' => 'Perangkat POS',
                'icon' => 'devices',
                'route' => 'devices.index',
                'active' => 'devices.*',
                'roles' => ['Owner', 'Manager'],
                'scope' => 'store',
            ],
            [
                'label' => 'Pengaturan Sistem',
                'icon' => 'settings',
                'route' => 'settings.index',
                'active' => ['settings.*', 'tax-classes.*'],
                'roles' => ['Owner'],
                'scope' => 'tenant',
            ],
            [
                'label' => 'Log Audit',
                'icon' => 'manage_search',
                'route' => 'activity-logs.index',
                'active' => 'activity-logs.*',
                'roles' => ['Owner', 'Manager'],
                'scope' => 'tenant',
            ],
        ],
    ],
];
