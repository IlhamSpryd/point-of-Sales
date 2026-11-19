<?php

declare(strict_types=1);

/**
 * D1 — OVERVIEW
 *
 * The single "where am I / how are we doing" surface. Landing target for
 * Owner/Admin after login (HomeController::landingRouteFor).
 *
 * @see docs/architecture/APEIRON_ENTERPRISE_NAVIGATION_ARCHITECTURE_BLUEPRINT.md §9.2
 */
return [
    'key' => 'overview',
    'label' => 'Overview',
    'order' => 10,
    'icon' => 'space_dashboard',
    'hubs' => [
        [
            'id' => 'nav.overview.dashboard',
            'label' => 'Dashboard',
            'route' => 'dashboard',
            'active' => ['dashboard'],
            'roles' => ['Owner', 'Manager'],
            'abilities' => ['dashboard'],
            'scope' => 'tenant',
            'icon' => 'space_dashboard',
            'order' => 10,
            'landing' => true,
            'aliases' => ['dashboard', 'ringkasan', 'beranda', 'kpi', 'overview'],
        ],
    ],
];
