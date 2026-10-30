<?php

declare(strict_types=1);

/**
 * Single source of truth for sidebar navigation menu — Apeiron POS.
 *
 * Every entry MUST have keys: label, icon, route, active, roles, scope.
 * - `roles` MUST be 100% in sync with `role:` middleware in routes/web.php.
 * - `active` contains pattern string or array of patterns for request()->routeIs().
 * - `scope` marks data boundary: 'store' (active branch) or 'tenant' (organization-wide).
 * - Do not add entries with routes not registered in routes/web.php.
 *
 * Used by:
 *   1. layouts/navigation.blade.php — renders sidebar & drawer
 *   2. tests/Feature/Navigation/SidebarIntegrityTest.php — automated validation
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
        'Cashier & Operations' => [
            [
                'label' => 'POS Terminal',
                'icon' => 'point_of_sale',
                'route' => 'transaction.create',
                'active' => 'transaction.*',
                'roles' => ['Owner', 'Manager', 'Kasir'],
                'scope' => 'store',
            ],
            [
                'label' => 'Order History',
                'icon' => 'receipt_long',
                'route' => 'orders.index',
                'active' => 'orders.*',
                'roles' => ['Owner', 'Manager', 'Kasir', 'Waiter'],
                'scope' => 'store',
            ],
            [
                'label' => 'Cashier Shifts',
                'icon' => 'lock_clock',
                'route' => 'shifts.index',
                'active' => 'shifts.*',
                'roles' => ['Owner', 'Manager', 'Kasir', 'Supervisor'],
                'scope' => 'store',
            ],
        ],
        'Catalog & Recipes' => [
            [
                'label' => 'Product Catalog',
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
                'label' => 'Price Lists',
                'icon' => 'price_change',
                'route' => 'price-lists.index',
                'active' => 'price-lists.*',
                'roles' => ['Owner', 'Manager', 'Inventory'],
                'scope' => 'tenant',
            ],
            [
                'label' => 'Branch Assortment',
                'icon' => 'storefront',
                'route' => 'assortments.index',
                'active' => 'assortments.*',
                'roles' => ['Owner', 'Manager', 'Inventory'],
                'scope' => 'store',
            ],
        ],
        'Inventory & Supply' => [
            [
                'label' => 'Raw Materials & Stock',
                'icon' => 'warehouse',
                'route' => 'inventory.ingredients',
                'active' => 'inventory.ingredients*',
                'roles' => ['Owner', 'Manager', 'Inventory'],
                'scope' => 'store',
            ],
            [
                'label' => 'Stock Operations',
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
                'label' => 'Stock Ledger',
                'icon' => 'menu_book',
                'route' => 'inventory.ledger',
                'active' => 'inventory.ledger*',
                'roles' => ['Owner', 'Manager', 'Inventory'],
                'scope' => 'store',
            ],
            [
                'label' => 'Purchasing',
                'icon' => 'local_shipping',
                'route' => 'purchasing.suppliers.index',
                'active' => 'purchasing.*',
                'roles' => ['Owner', 'Manager', 'Inventory'],
                'scope' => 'tenant',
            ],
        ],
        'Analytics & Customers' => [
            [
                'label' => 'Sales Reports',
                'icon' => 'analytics',
                'route' => 'reports.sales',
                'active' => 'reports.*',
                'roles' => ['Owner', 'Manager'],
                'scope' => 'tenant',
            ],
            [
                'label' => 'Customers & CRM',
                'icon' => 'groups',
                'route' => 'customers.index',
                'active' => 'customers.*',
                'roles' => ['Owner', 'Manager'],
                'scope' => 'tenant',
            ],
            [
                'label' => 'Discounts & Promotions',
                'icon' => 'sell',
                'route' => 'discounts.index',
                'active' => [
                    'discounts.*',
                    'pricing.*',
                ],
                'roles' => ['Owner', 'Manager'],
                'scope' => 'store',
            ],
        ],
        'Settings & Governance' => [
            [
                'label' => 'Branches',
                'icon' => 'storefront',
                'route' => 'stores.index',
                'active' => 'stores.*',
                'roles' => ['Owner'],
                'scope' => 'tenant',
            ],
            [
                'label' => 'Users',
                'icon' => 'group',
                'route' => 'users.index',
                'active' => ['users.*', 'users.invitations.*'],
                'roles' => ['Owner'],
                'scope' => 'tenant',
            ],
            [
                'label' => 'Roles & Permissions',
                'icon' => 'admin_panel_settings',
                'route' => 'roles.index',
                'active' => 'roles.*',
                'roles' => ['Owner'],
                'scope' => 'tenant',
            ],
            [
                'label' => 'POS Devices',
                'icon' => 'devices',
                'route' => 'devices.index',
                'active' => 'devices.*',
                'roles' => ['Owner', 'Manager'],
                'scope' => 'store',
            ],
            [
                'label' => 'System Settings',
                'icon' => 'settings',
                'route' => 'settings.index',
                'active' => ['settings.*', 'tax-classes.*'],
                'roles' => ['Owner'],
                'scope' => 'tenant',
            ],
            [
                'label' => 'Audit Logs',
                'icon' => 'manage_search',
                'route' => 'activity-logs.index',
                'active' => 'activity-logs.*',
                'roles' => ['Owner', 'Manager'],
                'scope' => 'tenant',
            ],
        ],
    ],
];
