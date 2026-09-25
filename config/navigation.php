<?php

/**
 * Sumber kebenaran tunggal untuk menu navigasi sidebar.
 *
 * Setiap entri WAJIB memiliki key: label, icon, route, active, roles.
 * - `roles` HARUS 100% sinkron dengan middleware `role:` di routes/web.php.
 * - `active` berisi string pola untuk request()->routeIs() (bukan boolean).
 * - Jangan tambahkan entri dengan route yang belum terdaftar di routes/web.php.
 *
 * File ini dipakai oleh:
 *   1. layouts/navigation.blade.php — render sidebar
 *   2. tests/Feature/Navigation/SidebarIntegrityTest.php — validasi otomatis
 *
 * @see routes/web.php
 */

return [
    'sections' => [
        'Operasional' => [
            ['label' => 'Dashboard', 'icon' => 'space_dashboard', 'route' => 'dashboard', 'active' => 'dashboard', 'roles' => ['Owner', 'Manager']],
            ['label' => 'Kasir (POS)', 'icon' => 'point_of_sale', 'route' => 'transaction.create', 'active' => 'transaction.*', 'roles' => ['Owner', 'Manager', 'Kasir']],
            ['label' => 'Shift Kasir', 'icon' => 'lock_clock', 'route' => 'shifts.index', 'active' => 'shifts.*', 'roles' => ['Owner', 'Manager', 'Kasir', 'Supervisor']],
            // 'Tugas Ekspor' DIHAPUS — route 'export-tasks' tidak pernah ada, hanya ada exports.status/download (§3.1).
            ['label' => 'Dapur (KDS)', 'icon' => 'restaurant_menu', 'route' => 'kds.index', 'active' => 'kds.*', 'roles' => ['Owner', 'Manager', 'Kasir', 'Barista', 'Waiter', 'Cook']],
            ['label' => 'Riwayat Pesanan', 'icon' => 'receipt_long', 'route' => 'orders.index', 'active' => 'orders.*', 'roles' => ['Owner', 'Manager', 'Kasir', 'Waiter']],
            ['label' => 'Meja & QR', 'icon' => 'table_restaurant', 'route' => 'tables.index', 'active' => 'tables.*', 'roles' => ['Owner', 'Manager']],
        ],
        'Inventory & Stok' => [
            ['label' => 'Bahan Baku', 'icon' => 'inventory_2', 'route' => 'inventory.ingredients', 'active' => 'inventory.ingredients', 'roles' => ['Owner', 'Manager', 'Inventory']],
            ['label' => 'Riwayat Stok', 'icon' => 'history', 'route' => 'inventory.ledger', 'active' => 'inventory.ledger', 'roles' => ['Owner', 'Manager', 'Inventory']],
        ],
        'Katalog' => [
            ['label' => 'Produk', 'icon' => 'inventory_2', 'route' => 'products.index', 'active' => 'products.*', 'roles' => ['Owner', 'Manager', 'Inventory']],
            ['label' => 'Varian & Modifier', 'icon' => 'tune', 'route' => 'inventory.modifiers', 'active' => 'inventory.modifiers', 'roles' => ['Owner', 'Manager', 'Inventory']],
            ['label' => 'Kategori', 'icon' => 'category', 'route' => 'categories.index', 'active' => 'categories.*', 'roles' => ['Owner', 'Manager', 'Inventory']],
            ['label' => 'Diskon & Promo', 'icon' => 'sell', 'route' => 'discounts.index', 'active' => 'discounts.*', 'roles' => ['Owner', 'Manager']],
        ],
        'Laporan' => [
            ['label' => 'Laporan Penjualan', 'icon' => 'analytics', 'route' => 'reports.sales', 'active' => 'reports.*', 'roles' => ['Owner', 'Manager']],
        ],
        'Sistem' => [
            ['label' => 'Pengguna', 'icon' => 'group', 'route' => 'users.index', 'active' => 'users.*', 'roles' => ['Owner']],
            ['label' => 'Peran', 'icon' => 'admin_panel_settings', 'route' => 'roles.index', 'active' => 'roles.*', 'roles' => ['Owner']],
            ['label' => 'Pengaturan', 'icon' => 'tune', 'route' => 'settings.index', 'active' => 'settings.*', 'roles' => ['Owner']],
            ['label' => 'Audit Log', 'icon' => 'history', 'route' => 'activity-logs.index', 'active' => 'activity-logs.*', 'roles' => ['Owner', 'Manager']],
            ['label' => 'Integrasi Channel', 'icon' => 'hub', 'route' => 'integrations.channel-mapping', 'active' => 'integrations.*', 'roles' => ['Owner', 'Manager']],
        ],
    ],
];
