<?php

declare(strict_types=1);

/**
 * Sumber kebenaran tunggal untuk menu navigasi sidebar — Apeiron POS.
 *
 * Setiap entri WAJIB memiliki key: label, icon, route, active, roles, scope.
 * - `roles` HARUS 100% sinkron dengan middleware `role:` di routes/web.php.
 * - `active` berisi string pola untuk request()->routeIs() (bukan boolean).
 * - `scope` menandai cakupan data: 'store' (cabang aktif) atau 'tenant' (seluruh bisnis).
 * - Jangan tambahkan entri dengan route yang belum terdaftar di routes/web.php.
 *
 * File ini dipakai oleh:
 *   1. layouts/navigation.blade.php — render sidebar & drawer
 *   2. tests/Feature/Navigation/SidebarIntegrityTest.php — validasi otomatis
 *
 * @see routes/web.php
 */

return [
    'sections' => [
        'Overview' => [
            [
                'label' => 'Dashboard',
                'icon' => 'space_dashboard',
                'route' => 'dashboard',
                'active' => 'dashboard',
                'roles' => ['Owner', 'Manager'],
                'scope' => 'tenant',
            ],
        ],
        'Penjualan & Pesanan' => [
            [
                'label' => 'Riwayat Pesanan',
                'icon' => 'receipt_long',
                'route' => 'orders.index',
                'active' => 'orders.*',
                'roles' => ['Owner', 'Manager', 'Kasir', 'Waiter'],
                'scope' => 'store',
            ],
            [
                'label' => 'Laporan Penjualan',
                'icon' => 'analytics',
                'route' => 'reports.sales',
                'active' => 'reports.*',
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
        'Katalog & Inventori' => [
            [
                'label' => 'Produk',
                'icon' => 'inventory_2',
                'route' => 'products.index',
                'active' => 'products.*',
                'roles' => ['Owner', 'Manager', 'Inventory'],
                'scope' => 'tenant',
            ],
            [
                'label' => 'Bahan Baku',
                'icon' => 'egg_alt',
                'route' => 'inventory.ingredients',
                'active' => 'inventory.ingredients',
                'roles' => ['Owner', 'Manager', 'Inventory'],
                'scope' => 'store',
            ],
            [
                'label' => 'Riwayat Stok',
                'icon' => 'history',
                'route' => 'inventory.ledger',
                'active' => 'inventory.ledger',
                'roles' => ['Owner', 'Manager', 'Inventory'],
                'scope' => 'store',
            ],
        ],
        'Operasional Toko' => [
            [
                'label' => 'Shift Kasir',
                'icon' => 'lock_clock',
                'route' => 'shifts.index',
                'active' => 'shifts.*',
                'roles' => ['Owner', 'Manager', 'Kasir', 'Supervisor'],
                'scope' => 'store',
            ],
            [
                'label' => 'Meja & QR',
                'icon' => 'table_restaurant',
                'route' => 'tables.index',
                'active' => 'tables.*',
                'roles' => ['Owner', 'Manager'],
                'scope' => 'store',
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
                'label' => 'Integrasi Channel',
                'icon' => 'hub',
                'route' => 'integrations.channel-mapping',
                'active' => 'integrations.*',
                'roles' => ['Owner', 'Manager'],
                'scope' => 'tenant',
            ],
        ],
        'Organisasi & Pengaturan' => [
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
                'active' => 'users.*',
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
                'label' => 'Pengaturan',
                'icon' => 'settings',
                'route' => 'settings.index',
                'active' => 'settings.*',
                'roles' => ['Owner'],
                'scope' => 'tenant',
            ],
            [
                'label' => 'Audit Log',
                'icon' => 'manage_search',
                'route' => 'activity-logs.index',
                'active' => 'activity-logs.*',
                'roles' => ['Owner', 'Manager'],
                'scope' => 'tenant',
            ],
        ],
        'Terminal Kerja' => [
            [
                'label' => 'Kasir (POS)',
                'icon' => 'point_of_sale',
                'route' => 'transaction.create',
                'active' => 'transaction.*',
                'roles' => ['Owner', 'Manager', 'Kasir'],
                'scope' => 'store',
            ],
            [
                'label' => 'Dapur (KDS)',
                'icon' => 'restaurant_menu',
                'route' => 'kds.index',
                'active' => 'kds.*',
                'roles' => ['Owner', 'Manager', 'Kasir', 'Barista', 'Waiter', 'Cook'],
                'scope' => 'store',
            ],
        ],
    ],
];
