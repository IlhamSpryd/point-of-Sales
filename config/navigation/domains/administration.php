<?php

declare(strict_types=1);

/**
 * D9 — ADMINISTRATION
 *
 * Non-operational configuration and governance: tenant-scoped, low frequency,
 * high blast radius.
 *
 * ADR-NAV-009 (ACCEPTED): Admin is retained and empowered as the System/IT
 * Administrator. Operational administration routes are opened to
 * `role:Owner,Admin`. Strict Segregation of Duties hard-blocks keep Admin out of
 * Subscription Billing, Finance GL posting / period lock, and Payroll approval,
 * and policies block Admin from touching Owner accounts or assigning Owner.
 *
 * @see docs/architecture/APEIRON_ENTERPRISE_NAVIGATION_ARCHITECTURE_BLUEPRINT.md §9.2, ADR-NAV-009
 */
return [
    'key' => 'administration',
    'label' => 'Administration',
    'order' => 90,
    'icon' => 'settings',
    'hubs' => [
        [
            'id' => 'nav.admin.org',
            'label' => 'Organization',
            'route' => 'stores.index',
            'active' => ['stores.*', 'devices.*', 'integrations.channel-mapping'],
            'roles' => ['Owner', 'Admin'],
            'abilities' => ['stores.manage'],
            'scope' => 'tenant',
            'icon' => 'storefront',
            'order' => 160,
            'landing' => true,
            'aliases' => ['organisasi', 'cabang', 'perangkat', 'device'],
            'pages' => [
                [
                    'id' => 'nav.admin.org.devices',
                    'label' => 'POS Devices',
                    'route' => 'devices.index',
                    'active' => ['devices.*'],
                    'roles' => ['Owner', 'Admin', 'Manager'],
                    'abilities' => ['devices.manage'],
                    'scope' => 'store',
                    'order' => 162,
                    'aliases' => ['perangkat', 'device', 'terminal kasir', 'pairing'],
                ],
                [
                    'id' => 'nav.admin.org.channel',
                    'label' => 'Channel Mapping',
                    'route' => 'integrations.channel-mapping',
                    'active' => ['integrations.channel-mapping'],
                    'roles' => ['Owner', 'Admin', 'Manager'],
                    'abilities' => ['products.manage'],
                    'scope' => 'tenant',
                    'order' => 163,
                    'entitlement' => 'omnichannel',
                    'shortcut_of' => 'nav.frontline.desk.channel',
                    'aliases' => ['omnichannel', 'integrasi channel', 'grabfood', 'gofood'],
                ],
            ],
        ],
        [
            'id' => 'nav.admin.access',
            'label' => 'Users & Access',
            'route' => 'users.index',
            'active' => ['users.*', 'users.invitations.*', 'roles.*'],
            'roles' => ['Owner', 'Admin'],
            'abilities' => ['users.view'],
            'scope' => 'tenant',
            'icon' => 'admin_panel_settings',
            'order' => 170,
            'landing' => true,
            'aliases' => ['pengguna', 'user', 'akses', 'peran', 'role'],
            'pages' => [
                [
                    'id' => 'nav.admin.access.roles',
                    'label' => 'Roles & Permissions',
                    'route' => 'roles.index',
                    'active' => ['roles.*'],
                    'roles' => ['Owner', 'Admin'],
                    'abilities' => ['roles.manage'],
                    'scope' => 'tenant',
                    'order' => 172,
                    'aliases' => ['peran', 'hak akses', 'permission', 'role', 'izin'],
                ],
            ],
        ],
        [
            'id' => 'nav.admin.settings',
            'label' => 'System Settings',
            'route' => 'settings.index',
            'active' => ['settings.*'],
            'roles' => ['Owner', 'Admin'],
            'abilities' => ['settings.manage'],
            'scope' => 'tenant',
            'icon' => 'settings',
            'order' => 180,
            'landing' => true,
            'aliases' => ['pengaturan', 'settings', 'konfigurasi', 'sistem'],
            'pages' => [
            ],
        ],
        [
            'id' => 'nav.admin.subscription',
            'label' => 'Subscription',
            'route' => 'subscription.portal',
            'active' => ['subscription.*'],
            'roles' => ['Owner'],
            'abilities' => ['subscription.manage'],
            'scope' => 'tenant',
            'icon' => 'workspace_premium',
            'order' => 190,
            'landing' => true,
            // SoD: Admin and Manager are excluded from Subscription Billing.
            'aliases' => ['langganan', 'paket', 'subscription', 'billing', 'upgrade'],
            'pages' => [
            ],
        ],
        [
            'id' => 'nav.admin.governance',
            'label' => 'Audit & Approvals',
            'route' => 'erp.workflow.approvals',
            'active' => ['erp.workflow.*', 'activity-logs.*'],
            'roles' => ['Owner', 'Manager', 'Supervisor'],
            'abilities' => ['workflow.approvals.view'],
            'scope' => 'tenant',
            'icon' => 'fact_check',
            'order' => 200,
            'landing' => true,
            'aliases' => ['persetujuan', 'approval', 'audit', 'log aktivitas'],
            'pages' => [
                [
                    'id' => 'nav.admin.governance.audit_logs',
                    'label' => 'Audit Logs',
                    'route' => 'activity-logs.index',
                    'active' => ['activity-logs.*'],
                    'roles' => ['Owner', 'Manager', 'Admin'],
                    'abilities' => ['audit_logs.view'],
                    'scope' => 'tenant',
                    'order' => 202,
                    'aliases' => ['log', 'audit', 'jejak aktivitas', 'riwayat sistem'],
                ],
            ],
        ],
    ],
];
