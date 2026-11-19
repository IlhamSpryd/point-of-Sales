<?php

declare(strict_types=1);

/**
 * Navigation engine toggle — the single production safety valve.
 *
 * 'registry' : render the sidebar, L2 pills, breadcrumbs and command palette
 *              from app/Platform/Navigation (ADR-NAV-001).
 * 'legacy'   : render from config/navigation.php `sections` / `sub_nav`
 *              (pre-migration behaviour, retained as a one-line rollback).
 *
 * @see docs/architecture/APEIRON_ENTERPRISE_NAVIGATION_ARCHITECTURE_BLUEPRINT.md §18.12
 */
/**
 * Navigation engine toggle — the single production safety valve.
 *
 * NOTE ON FILE NAMING: Laravel resolves the config key `navigation` to
 * `config/navigation.php`, so this engine file intentionally registers under the
 * `navigation_engine` key to avoid colliding with the legacy menu file during the
 * migration window. Once the legacy keys are removed (Phase 5), this file may be
 * renamed to `config/navigation.php`.
 *
 * 'registry' : render the sidebar, L2 pills, breadcrumbs and command palette
 *              from app/Platform/Navigation (ADR-NAV-001).
 * 'legacy'   : render from config/navigation.php `sections` / `sub_nav`
 *              (pre-migration behaviour, retained as a one-line rollback).
 *
 * @see docs/architecture/APEIRON_ENTERPRISE_NAVIGATION_ARCHITECTURE_BLUEPRINT.md §18.12
 */
return [
    'engine' => env('NAVIGATION_ENGINE', 'registry'),

    /*
    |--------------------------------------------------------------------------
    | Domain loader
    |--------------------------------------------------------------------------
    | Every file in config/navigation/domains is a domain. The merge order is
    | determined by each domain's `order` key, not by filesystem ordering.
    */
    'domains_path' => __DIR__.'/navigation/domains',

    /*
    |--------------------------------------------------------------------------
    | Shortcut allow-list (ADR-NAV-004 / Governance Law G-1)
    |--------------------------------------------------------------------------
    | Menu IDs permitted to appear in more than one navigation placement.
    | Every other page must be declared exactly once.
    */
    'shortcut_allowlist' => [
        'nav.frontline.pos',
        'nav.frontline.desk',
        'nav.admin.org.channel',
    ],
];
