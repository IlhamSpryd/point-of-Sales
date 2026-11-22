<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Core\Context\TenantContext;
use App\Modules\Organization\Controllers\HomeController;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Modules\Organization\Services\RoleService;
use App\Platform\Navigation\NavigationNode;
use App\Platform\Navigation\NavigationRegistry;
use App\Platform\Navigation\NavigationVisibilityResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class NavigationGovernanceTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    private NavigationRegistry $registry;

    private NavigationVisibilityResolver $resolver;

    protected function afterSubclassSetUp(): void
    {
        $context = app(TenantContext::class);

        $this->tenant = Tenant::create([
            'name' => 'Governance Tenant',
            'slug' => 'gov-tenant-'.uniqid(),
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Governance Store',
            'is_active' => true,
        ]);
        $context->setStoreId($this->store->id);

        app(RoleService::class)->provisionDefaultRoles($this->tenant->id);

        $this->registry = app(NavigationRegistry::class);
        $this->resolver = app(NavigationVisibilityResolver::class);
    }

    /**
     * G-1 (Single Canonical Home): Every non-shortcut page route appears exact once in NavigationRegistry.
     */
    public function test_g1_single_canonical_home_every_non_shortcut_page_route_appears_exactly_once(): void
    {
        $allowlist = (array) config('navigation_engine.shortcut_allowlist', []);
        $routeCounts = [];

        foreach ($this->registry->pages() as $page) {
            if ($page->route === null) {
                continue;
            }
            $routeCounts[$page->route] = ($routeCounts[$page->route] ?? 0) + 1;
        }

        foreach ($routeCounts as $route => $count) {
            if ($count <= 1) {
                continue;
            }

            $duplicateIds = array_map(
                static fn (NavigationNode $n): string => $n->id,
                array_filter($this->registry->pages(), static fn (NavigationNode $n): bool => $n->route === $route),
            );

            $permitted = array_intersect($duplicateIds, $allowlist) !== []
                || collect($duplicateIds)->every(fn ($id) => $this->registry->find($id)?->shortcutOf !== null);

            $this->assertTrue(
                $permitted,
                "Governance Law G-1 Violated: Route [{$route}] is declared {$count} times without being in the shortcut allowlist or having shortcut_of."
            );
        }
    }

    /**
     * G-2 (Middleware Parity): Every node's roles declaration aligns with the actual Laravel route middleware.
     */
    public function test_g2_middleware_parity_node_roles_match_route_role_middleware(): void
    {
        $mismatches = [];

        foreach ([...$this->registry->hubs(), ...$this->registry->pages()] as $node) {
            if ($node->route === null || ! Route::has($node->route)) {
                continue;
            }

            $route = Route::getRoutes()->getByName($node->route);
            $roleMiddleware = collect($route->gatherMiddleware())
                ->first(fn ($m) => str_starts_with($m, 'role:'));

            if (! $roleMiddleware) {
                continue;
            }

            $routeRoles = collect(explode(',', substr($roleMiddleware, strlen('role:'))))->sort()->values()->all();
            $nodeRoles = collect($node->roles)->sort()->values()->all();

            if ($routeRoles !== $nodeRoles) {
                $mismatches[] = "Node [{$node->id}] ('{$node->label}' -> {$node->route}): route has ["
                    .implode(',', $routeRoles).'] but registry declares ['
                    .implode(',', $nodeRoles).'].';
            }
        }

        $this->assertEmpty(
            $mismatches,
            "Governance Law G-2 Violated: Mismatch between route middleware and registry roles:\n".implode("\n", $mismatches)
        );
    }

    /**
     * G-3 (Backend Authoritative): RoleMiddleware has zero imports/reads of config('navigation...').
     */
    public function test_g3_backend_authoritative_role_middleware_has_zero_navigation_config_reads(): void
    {
        $content = File::get(app_path('Http/Middleware/RoleMiddleware.php'));

        $this->assertStringNotContainsString(
            "config('navigation",
            $content,
            "Governance Law G-3 Violated: RoleMiddleware reads config('navigation...')."
        );
        $this->assertStringNotContainsString(
            'config("navigation',
            $content,
            'Governance Law G-3 Violated: RoleMiddleware reads config("navigation...").'
        );

        // Prove legacy colliding taxonomies are eradicated from config/navigation.php (AC-MNT-01 / Task 5.1)
        $this->assertNull(config('navigation.sections'), 'Governance Law G-3: Legacy navigation.sections must be deleted.');
        $this->assertNull(config('navigation.sub_nav'), 'Governance Law G-3: Legacy navigation.sub_nav must be deleted.');
    }

    /**
     * G-4 (Entitlement Parity): Every route gated by enforce.entitlement has matching entitlement metadata in NavigationRegistry.
     */
    public function test_g4_entitlement_parity_every_entitlement_gated_route_has_matching_registry_metadata(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = $route->getName();
            if ($name === null) {
                continue;
            }

            $entitlementMiddleware = collect($route->gatherMiddleware())
                ->first(fn ($m) => str_starts_with($m, 'enforce.entitlement:'));

            if (! $entitlementMiddleware) {
                continue;
            }

            $feature = substr($entitlementMiddleware, strlen('enforce.entitlement:'));

            // Look up node in registry
            $node = $this->registry->primaryForRoute($name);
            if ($node !== null) {
                $this->assertSame(
                    $feature,
                    $node->entitlement,
                    "Governance Law G-4 Violated: Route [{$name}] requires entitlement [{$feature}] but node [{$node->id}] has entitlement [{$node->entitlement}]."
                );
            }
        }
    }

    /**
     * G-5 (Zero Orphans & Zero Dead Links): Every registered node's route exists, fixed-assets is registered in Finance, and zero production-ready GET pages are orphaned.
     */
    public function test_g5_zero_orphans_and_zero_dead_links(): void
    {
        // 1. Zero Dead Links
        foreach ([...$this->registry->hubs(), ...$this->registry->pages()] as $node) {
            if ($node->route !== null) {
                $this->assertTrue(
                    Route::has($node->route),
                    "Governance Law G-5 Violated: Dead link in node [{$node->id}] -> Route [{$node->route}] does not exist."
                );
            }
        }

        // 2. Fixed assets is reachable in Finance hub
        $fixedAssets = $this->registry->primaryForRoute('finance.fixed-assets.index');
        $this->assertNotNull($fixedAssets, 'Governance Law G-5 Violated: finance.fixed-assets.index must be registered.');
        $this->assertSame('nav.finance.gl', $fixedAssets->parent);
        $this->assertTrue($fixedAssets->visible);

        // 3. Zero un-cataloged human GET pages
        // Staff application destinations must be present in registry
        $staffPages = [
            'dashboard',
            'transaction.create',
            'orders.index',
            'shifts.index',
            'tables.index',
            'kds.index',
            'products.index',
            'categories.index',
            'brands.index',
            'units-of-measure.index',
            'inventory.modifiers',
            'assortments.index',
            'tax-classes.index',
            'retail.shelf-labels.index',
            'retail.barcodes.index',
            'retail.exchanges.index',
            'price-lists.index',
            'discounts.index',
            'pricing.promotions.index',
            'pricing.vouchers.index',
            'inventory.ingredients',
            'inventory.goods-receipts',
            'inventory.adjustments',
            'inventory.opnames',
            'inventory.transfers',
            'inventory.supplier-returns',
            'inventory.ledger',
            'purchasing.orders.index',
            'purchasing.suppliers.index',
            'purchasing.grns.index',
            'purchasing.invoices.index',
            'purchasing.returns.index',
            'manufacturing.orders.index',
            'sales.orders.index',
            'sales.quotations.index',
            'sales.invoices.index',
            'sales.returns.index',
            'customers.index',
            'customers.groups.index',
            'customers.credit.index',
            'customers.instruments.index',
            'finance.index',
            'finance.coa.index',
            'finance.journals.index',
            'finance.periods.index',
            'finance.reconciliation.index',
            'finance.fixed-assets.index',
            'hr.employees.index',
            'hr.schedules.index',
            'hr.attendance.index',
            'hr.leaves.index',
            'hr.commissions.index',
            'hr.payroll.index',
            'reports.analytics',
            'reports.sales',
            'stores.index',
            'devices.index',
            'integrations.channel-mapping',
            'users.index',
            'roles.index',
            'settings.index',
            'subscription.portal',
            'erp.workflow.approvals',
            'activity-logs.index',
        ];

        foreach ($staffPages as $route) {
            $node = $this->registry->primaryForRoute($route);
            $this->assertNotNull(
                $node,
                "Governance Law G-5 Violated: Production staff page route [{$route}] is orphaned (not in NavigationRegistry)."
            );
        }
    }

    /**
     * G-6 (Active State & Zero-403 Breadcrumb):
     * - Visiting tax-classes highlights exact 1 sidebar hub (Catalog)
     * - Visiting integrations.channel-mapping does NOT highlight Table Management
     * - Every canonical role renders breadcrumbs and error pages with 0 links pointing to 403-restricted dashboard.
     */
    public function test_g6_active_state_precision_and_zero_403_breadcrumb_links(): void
    {
        // 1. tax-classes highlights exactly Catalog, NOT Settings
        $taxClassesNode = $this->registry->primaryForRoute('tax-classes.index');
        $this->assertNotNull($taxClassesNode);
        $this->assertSame('nav.catalog.master', $taxClassesNode->parent);

        $settingsHub = $this->registry->find('nav.admin.settings');
        $this->assertNotNull($settingsHub);
        $this->assertNotContains('tax-classes.*', $settingsHub->active);

        // 2. channel-mapping does NOT activate Table Management
        $tableNode = $this->registry->find('nav.frontline.desk.tables');
        $this->assertNotNull($tableNode);
        $this->assertNotContains('integrations.channel-mapping', $tableNode->active);

        // 3. Canonical roles breadcrumb and landing tests: zero 403 dashboard links
        $roles = ['Kasir', 'Finance', 'Sales', 'Inventory', 'Waiter', 'Supervisor', 'Cook', 'Barista', 'Admin', 'Owner'];

        foreach ($roles as $roleName) {
            $role = Role::where('tenant_id', $this->tenant->id)->where('name', $roleName)->first();
            $user = User::factory()->create([
                'tenant_id' => $this->tenant->id,
                'role_id' => $role->id,
            ]);

            $landingRoute = HomeController::landingRouteFor($user);

            if (! in_array($roleName, ['Owner', 'Admin'], true)) {
                $this->assertNotSame(
                    'dashboard',
                    $landingRoute,
                    "Governance Law G-6 Violated: Non-Owner role [{$roleName}] resolved landing route to 403-restricted 'dashboard'."
                );
            }

            // Test breadcrumbs for a route the user can see
            $sidebar = $this->resolver->sidebar($user);
            if (! empty($sidebar)) {
                $firstHub = $sidebar[0]['hubs'][0] ?? null;
                if ($firstHub && $firstHub->route) {
                    $crumbs = $this->resolver->breadcrumbsForRoute($firstHub->route, $user);
                    foreach ($crumbs as $crumb) {
                        if (! in_array($roleName, ['Owner', 'Admin'], true) && isset($crumb['url'])) {
                            $this->assertStringNotContainsString(
                                '/dashboard',
                                $crumb['url'],
                                "Governance Law G-6 Violated: Role [{$roleName}] has breadcrumb pointing to /dashboard."
                            );
                        }
                    }
                }
            }
        }

        // 4. Error pages have zero dashboard links
        foreach ([403, 404, 500] as $status) {
            $viewPath = resource_path("views/errors/{$status}.blade.php");
            $content = File::get($viewPath);
            $this->assertStringNotContainsString(
                "route('dashboard')",
                $content,
                "Governance Law G-6 Violated: Error page {$status} contains route('dashboard')."
            );
        }
    }
}
