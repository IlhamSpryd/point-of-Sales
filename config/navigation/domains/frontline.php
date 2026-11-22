<?php

declare(strict_types=1);

/**
 * D2 — FRONTLINE
 *
 * High-frequency, shift-bound, touch-first, store-scoped operations.
 *
 * SC-01: `nav.frontline.pos` is a hub AND the fastest path to the checkout
 *        terminal (ADR-NAV-004 documented shortcut).
 * SC-02: `nav.frontline.desk` is a hub whose landing page is also its first tab.
 * SC-05: channel mapping is deliberately placed here (operator context) AND in
 *        Administration (setup context).
 *
 * @see docs/architecture/APEIRON_ENTERPRISE_NAVIGATION_ARCHITECTURE_BLUEPRINT.md §9.2, §9.4
 */
return [
    'key' => 'frontline',
    'label' => 'Frontline',
    'order' => 20,
    'icon' => 'point_of_sale',
    'hubs' => [
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
            'shortcut_of' => 'nav.frontline.desk.orders',
            'aliases' => ['pos', 'kasir', 'checkout', 'transaksi', 'terminal', 'jual'],
            'pages' => [],
        ],
        [
            'id' => 'nav.frontline.desk',
            'label' => 'Frontline Desk',
            'route' => 'orders.index',
            'active' => ['orders.*', 'shifts.*', 'kds.*', 'tables.*', 'integrations.channel-mapping'],
            'roles' => ['Owner', 'Manager', 'Kasir', 'Waiter', 'Supervisor'],
            'abilities' => ['orders.view'],
            'scope' => 'store',
            'icon' => 'receipt_long',
            'order' => 30,
            'landing' => true,
            'aliases' => ['frontline', 'operasional', 'meja kasir'],
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
                    'aliases' => ['meja', 'denah', 'qr', 'dine in', 'table'],
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
                    'aliases' => ['dapur', 'kds', 'kitchen', 'antrian masak', 'monitor dapur'],
                ],
                [
                    'id' => 'nav.frontline.desk.channel',
                    'label' => 'Omnichannel Mapping',
                    'route' => 'integrations.channel-mapping',
                    'active' => ['integrations.channel-mapping'],
                    'roles' => ['Owner', 'Admin', 'Manager'],
                    'abilities' => ['products.manage'],
                    'scope' => 'store',
                    'order' => 35,
                    'entitlement' => 'omnichannel',
                    'shortcut_of' => 'nav.admin.org.channel',
                    'aliases' => ['omnichannel', 'grabfood', 'gofood', 'marketplace', 'mapping'],
                ],
            ],
        ],
    ],
];
