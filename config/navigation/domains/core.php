<?php

declare(strict_types=1);

/**
 * GROUP 1 — CORE (Shopify × Linear Calm Hub Architecture)
 *
 * Essential daily operational hubs:
 *  1. Dashboard
 *  2. POS Terminal (1-click full-bleed cashier launcher)
 *  3. Frontline Desk (Operational hub with dynamic landing for Cook -> kds.index)
 */
return [
    'key' => 'core',
    'label' => 'Core',
    'order' => 10,
    'icon' => 'space_dashboard',
    'hubs' => [
        [
            'id' => 'nav.overview.dashboard',
            'label' => 'Dashboard',
            'route' => 'dashboard',
            'active' => ['dashboard'],
            'roles' => ['Owner', 'Manager'],
            'abilities' => ['dashboard.view'],
            'scope' => 'store',
            'icon' => 'space_dashboard',
            'order' => 10,
            'landing' => true,
            'aliases' => ['dashboard', 'beranda', 'ringkasan', 'home', 'ikhtisar', 'kinerja'],
            'pages' => [],
        ],
        [
            'id' => 'nav.frontline.pos',
            'label' => 'POS Terminal',
            'route' => 'transaction.create',
            'active' => ['transaction.*'],
            'roles' => ['Owner', 'Manager', 'Kasir', 'Waiter'],
            'abilities' => ['orders.create'],
            'scope' => 'store',
            'icon' => 'point_of_sale',
            'order' => 20,
            'landing' => true,
            'aliases' => ['pos', 'kasir', 'checkout', 'transaksi', 'terminal', 'jual', 'bayar', 'struk'],
            'pages' => [],
        ],
        [
            'id' => 'nav.frontline.desk',
            'label' => 'Frontline Desk',
            'route' => 'orders.index',
            'active' => ['orders.*', 'shifts.*', 'tables.*', 'kds.*'],
            'roles' => ['Owner', 'Manager', 'Kasir', 'Waiter', 'Supervisor', 'Cook'],
            'abilities' => ['orders.view'],
            'scope' => 'store',
            'icon' => 'storefront',
            'order' => 30,
            'landing' => true,
            'aliases' => ['frontline', 'operasional', 'meja kasir', 'pesanan', 'shift', 'dapur', 'kds'],
            'pages' => [
                [
                    'id' => 'nav.frontline.desk.orders',
                    'label' => 'Order History',
                    'route' => 'orders.index',
                    'active' => ['orders.*'],
                    'roles' => ['Owner', 'Manager', 'Kasir', 'Waiter', 'Supervisor'],
                    'abilities' => ['orders.view'],
                    'scope' => 'store',
                    'order' => 31,
                    'landing' => true,
                    'aliases' => ['pesanan', 'order', 'riwayat pesanan', 'struk', 'history'],
                ],
                [
                    'id' => 'nav.frontline.desk.shifts',
                    'label' => 'Cashier Shifts',
                    'route' => 'shifts.index',
                    'active' => ['shifts.*'],
                    'roles' => ['Owner', 'Manager', 'Kasir', 'Supervisor'],
                    'abilities' => ['shifts.own'],
                    'scope' => 'store',
                    'order' => 32,
                    'gate' => 'viewAny',
                    'aliases' => ['shift', 'kas', 'buka kasir', 'tutup kasir', 'laci kas'],
                ],
                [
                    'id' => 'nav.frontline.desk.tables',
                    'label' => 'Table & QR',
                    'route' => 'tables.index',
                    'active' => ['tables.*'],
                    'roles' => ['Owner', 'Manager'],
                    'abilities' => ['tables.view'],
                    'scope' => 'store',
                    'order' => 33,
                    'entitlement' => 'table_management',
                    'verticals' => ['fnb'],
                    'aliases' => ['meja', 'table', 'denah', 'qr', 'dine in'],
                ],
                [
                    'id' => 'nav.frontline.desk.kds',
                    'label' => 'Kitchen Display (KDS)',
                    'route' => 'kds.index',
                    'active' => ['kds.*'],
                    'roles' => ['Owner', 'Manager', 'Kasir', 'Barista', 'Waiter', 'Cook'],
                    'abilities' => ['kds.access'],
                    'scope' => 'store',
                    'order' => 34,
                    'verticals' => ['fnb'],
                    'aliases' => ['dapur', 'kds', 'kitchen', 'antrian masak', 'monitor dapur'],
                ],
            ],
        ],
    ],
];
