<?php

declare(strict_types=1);

/**
 * D5 — SALES & CUSTOMER
 *
 * Order-to-cash for accounts (B2B) plus the customer master that feeds it.
 * Decision 0.3 (OQ-19): Sales may reach the Customer Directory, but delete /
 * merge / credit-limit mutation stays with Owner, Manager and Finance.
 *
 * @see docs/architecture/APEIRON_ENTERPRISE_NAVIGATION_ARCHITECTURE_BLUEPRINT.md §9.2, OQ-19
 */
return [
    'key' => 'sales_customer',
    'label' => 'Sales & Customer',
    'order' => 50,
    'icon' => 'request_quote',
    'hubs' => [
        [
            'id' => 'nav.sales.orders',
            'label' => 'Sales Orders (B2B)',
            'route' => 'sales.orders.index',
            'active' => ['sales.*'],
            'roles' => ['Owner', 'Manager', 'Sales', 'Admin', 'Finance'],
            'abilities' => ['sales.orders.view'],
            'scope' => 'tenant',
            'icon' => 'request_quote',
            'order' => 100,
            'landing' => true,
            'aliases' => ['penjualan', 'sales order', 'so', 'b2b', 'grosir'],
            'pages' => [
                [
                    'id' => 'nav.sales.orders.list',
                    'label' => 'Sales Orders (SO)',
                    'route' => 'sales.orders.index',
                    'active' => ['sales.orders.*'],
                    'roles' => ['Owner', 'Manager', 'Sales', 'Admin', 'Finance'],
                    'abilities' => ['sales.orders.view'],
                    'scope' => 'tenant',
                    'order' => 101,
                    'landing' => true,
                    'aliases' => ['sales order', 'so', 'pesanan penjualan', 'faktur so'],
                ],
                [
                    'id' => 'nav.sales.orders.quotations',
                    'label' => 'Quotations',
                    'route' => 'sales.quotations.index',
                    'active' => ['sales.quotations.*'],
                    'roles' => ['Owner', 'Manager', 'Sales', 'Admin', 'Finance'],
                    'abilities' => ['sales.quotations.view'],
                    'scope' => 'tenant',
                    'order' => 102,
                    'aliases' => ['penawaran', 'quotation', 'quote', 'penawaran harga'],
                ],
                [
                    'id' => 'nav.sales.orders.invoices',
                    'label' => 'AR Invoices',
                    'route' => 'sales.invoices.index',
                    'active' => ['sales.invoices.*'],
                    'roles' => ['Owner', 'Manager', 'Sales', 'Admin', 'Finance'],
                    'abilities' => ['sales.invoices.view'],
                    'scope' => 'tenant',
                    'order' => 103,
                    'aliases' => ['faktur', 'invoice', 'piutang', 'ar', 'tagihan'],
                ],
                [
                    'id' => 'nav.sales.orders.returns',
                    'label' => 'Returns & Credit Notes',
                    'route' => 'sales.returns.index',
                    'active' => ['sales.returns.*'],
                    'roles' => ['Owner', 'Manager', 'Sales', 'Admin', 'Finance'],
                    'abilities' => ['sales.returns.view'],
                    'scope' => 'tenant',
                    'order' => 104,
                    'aliases' => ['retur penjualan', 'credit note', 'nota kredit', 'return'],
                ],
            ],
        ],
        [
            'id' => 'nav.sales.customers',
            'label' => 'Customers & Loyalty',
            'route' => 'customers.index',
            'active' => ['customers.*'],
            'roles' => ['Owner', 'Manager', 'Sales'],
            'abilities' => ['customers.view'],
            'scope' => 'tenant',
            'icon' => 'groups',
            'order' => 110,
            'landing' => true,
            'aliases' => ['pelanggan', 'customer', 'loyalty', 'member', 'crm'],
            'pages' => [
                [
                    'id' => 'nav.sales.customers.directory',
                    'label' => 'Customer Directory',
                    'route' => 'customers.index',
                    'active' => ['customers.*'],
                    'roles' => ['Owner', 'Manager', 'Sales'],
                    'abilities' => ['customers.view'],
                    'scope' => 'tenant',
                    'order' => 111,
                    'landing' => true,
                    'aliases' => ['pelanggan', 'customer', 'daftar pelanggan', 'directory'],
                ],
                [
                    'id' => 'nav.sales.customers.groups',
                    'label' => 'Customer Groups',
                    'route' => 'customers.groups.index',
                    'active' => ['customers.groups.*'],
                    'roles' => ['Owner', 'Manager'],
                    'abilities' => ['customers.groups.manage'],
                    'scope' => 'tenant',
                    'order' => 112,
                    'aliases' => ['grup pelanggan', 'segmentasi', 'customer group'],
                ],
                [
                    'id' => 'nav.sales.customers.credit',
                    'label' => 'B2B Credit Control',
                    'route' => 'customers.credit.index',
                    'active' => ['customers.credit.*'],
                    'roles' => ['Owner', 'Manager', 'Finance', 'Sales'],
                    'abilities' => ['customers.credit.view'],
                    'scope' => 'tenant',
                    'order' => 113,
                    'aliases' => ['plafon kredit', 'credit limit', 'kredit', 'limit piutang'],
                ],
                [
                    'id' => 'nav.sales.customers.loyalty',
                    'label' => 'Loyalty & Gift Cards',
                    'route' => 'customers.instruments.index',
                    'active' => ['customers.instruments.*'],
                    'roles' => ['Owner', 'Manager'],
                    'abilities' => ['customers.loyalty.manage'],
                    'scope' => 'tenant',
                    'order' => 114,
                    'aliases' => ['loyalitas', 'poin', 'gift card', 'store credit', 'membership'],
                ],
            ],
        ],
    ],
];
