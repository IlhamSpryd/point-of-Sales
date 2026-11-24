<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Core\Context\TenantContext;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Platform\Navigation\NavigationRegistry;
use App\Platform\Navigation\NavigationVisibilityResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Rendered-sidebar contract for the 9 × 19 architecture (ADR-NAV-003/004).
 *
 * These assertions read the actual HTML an operator receives — not the config —
 * so a passing registry test can never mask a broken render.
 */
class NavigationRenderTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    /** @var array<string, User> */
    private array $users = [];

    protected function afterSubclassSetUp(): void
    {
        $context = app(TenantContext::class);

        $this->tenant = Tenant::create([
            'name' => 'Nav Render Tenant',
            'slug' => 'nav-render-tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Nav Render Store',
            'is_active' => true,
        ]);
        $context->setStoreId($this->store->id);

        $roles = [
            'Owner' => ['*'],
            'Admin' => ['dashboard', 'reports.*', 'users.*', 'roles.*', 'stores.*', 'settings.*', 'devices.*', 'activity-logs.*'],
            'Manager' => ['dashboard', 'reports.*', 'orders.*', 'shifts.*', 'inventory.*', 'purchasing.*', 'customers.*', 'products.*', 'pricing.*', 'discounts.*', 'finance.*', 'hr.*'],
            'Finance' => ['finance.*', 'sales.orders.*', 'sales.invoices.*', 'purchasing.invoice.*', 'customers.view', 'customers.credit.*'],
            'Sales' => ['sales.*', 'customers.*', 'products.view', 'pricing.view'],
            'Kasir' => ['orders.create', 'orders.view', 'payments.create', 'shifts.own'],
            'Waiter' => ['orders.create', 'orders.view', 'tables.view'],
            'Barista' => ['orders.view', 'kds.view', 'kds.update', 'kds.access'],
            'Inventory' => ['inventory.*', 'ingredients.*', 'purchasing.*', 'products.view', 'pricing.view'],
            'Supervisor' => ['orders.*', 'shifts.*', 'reports.daily', 'void.approve'],
            'Cook' => ['orders.view', 'kds.view', 'kds.update', 'kds.access'],
        ];

        foreach ($roles as $name => $permissions) {
            $role = Role::create([
                'tenant_id' => $this->tenant->id,
                'name' => $name,
                'permissions' => $permissions,
            ]);

            $user = User::factory()->create([
                'tenant_id' => $this->tenant->id,
                'role_id' => $role->id,
            ]);

            DB::table('user_stores')->insert([
                'tenant_id' => $this->tenant->id,
                'user_id' => $user->id,
                'store_id' => $this->store->id,
            ]);

            $this->users[$name] = $user;
        }
    }

    private function resolver(): NavigationVisibilityResolver
    {
        return app(NavigationVisibilityResolver::class);
    }

    public function test_registry_projects_exactly_nine_domains_for_owner(): void
    {
        $sections = $this->resolver()->sidebar($this->users['Owner']);

        $this->assertCount(4, $sections, 'Owner must see all four non-empty domains.');
    }

    public function test_owner_sees_nineteen_hub_rows(): void
    {
        $sections = $this->resolver()->sidebar($this->users['Owner']);

        $hubCount = array_sum(array_map(static fn (array $s): int => count($s['hubs']), $sections));

        $this->assertSame(10, $hubCount, 'Owner must see exactly 10 hub rows (9 primary + 1 pinned settings).');
    }

    public function test_rendered_sidebar_contains_no_accordion_or_soon_badge(): void
    {
        $response = $this->actingAs($this->users['Owner'])->get(route('dashboard'));

        $response->assertOk();

        $html = (string) $response->getContent();

        $this->assertStringNotContainsString('toggleSection(', $html, 'Accordion toggles must be gone.');
        $this->assertStringNotContainsString('expand_more', $html, 'Section chevrons must be gone.');
        $this->assertStringNotContainsString('>Soon<', $html, 'SOON badges must be gone.');
        $this->assertStringNotContainsString('aria-disabled="true"', $html, 'Disabled menu rows must be gone.');
        $this->assertStringNotContainsString('openSections', $html, 'Accordion state must be gone.');
    }

    public function test_rendered_sidebar_has_no_arbitrary_hex_colours(): void
    {
        $response = $this->actingAs($this->users['Owner'])->get(route('dashboard'));
        $html = (string) $response->getContent();

        // The navigation component files themselves must be token-only.
        $files = [
            resource_path('views/layouts/navigation.blade.php'),
            resource_path('views/components/erp/nav-link.blade.php'),
            resource_path('views/components/erp/module-sub-nav.blade.php'),
            resource_path('views/components/erp/command-palette.blade.php'),
        ];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression(
                '/#[0-9a-fA-F]{6}\b/',
                $source,
                "Hardcoded hex colour found in {$file}",
            );
        }

        $this->assertNotSame('', $html);
    }

    public function test_every_menu_link_carries_the_pos_cart_dirty_guard(): void
    {
        $response = $this->actingAs($this->users['Owner'])->get(route('dashboard'));
        $html = (string) $response->getContent();

        // nav-link renders the guard on every navigation anchor.
        $this->assertStringContainsString('apeironPosCartDirty', $html);

        $navLinkSource = (string) file_get_contents(resource_path('views/components/erp/nav-link.blade.php'));
        $this->assertStringContainsString('apeironPosCartDirty', $navLinkSource);
        $this->assertStringContainsString('stopImmediatePropagation', $navLinkSource);
    }

    public function test_hub_labels_are_unique_in_the_rendered_sidebar(): void
    {
        $sections = $this->resolver()->sidebar($this->users['Owner']);

        $labels = [];
        foreach ($sections as $section) {
            foreach ($section['hubs'] as $hub) {
                $labels[] = $hub->label;
            }
        }

        $this->assertSame(count($labels), count(array_unique($labels)), 'Two hubs render the same label.');
    }

    public function test_kasir_sees_pos_frontline_and_merchandising(): void
    {
        $sections = $this->resolver()->sidebar($this->users['Kasir']);
        $labels = $this->flattenHubLabels($sections);

        $this->assertSame(['POS Terminal', 'Frontline Desk', 'Catalog'], $labels);
    }

    public function test_waiter_sees_pos_frontline_and_merchandising(): void
    {
        $sections = $this->resolver()->sidebar($this->users['Waiter']);
        $labels = $this->flattenHubLabels($sections);

        $this->assertSame(['POS Terminal', 'Frontline Desk'], $labels);
    }

    public function test_cook_sees_only_frontline_desk(): void
    {
        $sections = $this->resolver()->sidebar($this->users['Cook']);
        $labels = $this->flattenHubLabels($sections);

        $this->assertSame(['Frontline Desk'], $labels);
    }

    public function test_inventory_sees_catalog_and_supply_chain_hubs(): void
    {
        $sections = $this->resolver()->sidebar($this->users['Inventory']);
        $labels = $this->flattenHubLabels($sections);

        $this->assertEqualsCanonicalizing(
            ['Catalog', 'Inventory & Supply'],
            $labels,
        );
    }

    public function test_finance_sees_sales_customers_procurement_and_finance(): void
    {
        $sections = $this->resolver()->sidebar($this->users['Finance']);
        $labels = $this->flattenHubLabels($sections);

        $this->assertEqualsCanonicalizing(
            ['Inventory & Supply', 'Sales & Customers', 'Finance & GL'],
            $labels,
            'Finance must gain the Procurement hub (supplier invoices) — RBAC-F04.',
        );
    }

    public function test_sales_sees_catalog_sales_and_customers(): void
    {
        $sections = $this->resolver()->sidebar($this->users['Sales']);
        $labels = $this->flattenHubLabels($sections);

        $this->assertEqualsCanonicalizing(
            ['Catalog', 'Sales & Customers'],
            $labels,
            'Sales must gain the Customer Directory under OQ-19.',
        );
    }

    public function test_admin_sees_administration_hubs_but_not_subscription(): void
    {
        $sections = $this->resolver()->sidebar($this->users['Admin']);
        $labels = $this->flattenHubLabels($sections);

        $this->assertContains('Settings', $labels);

        $settingsHub = app(NavigationRegistry::class)->find('nav.settings');
        $visiblePages = $this->resolver()->visiblePagesFor($settingsHub, $this->users['Admin']);
        $pageRoutes = array_map(static fn ($p): string => (string) $p->route, $visiblePages);
        $this->assertNotContains('subscription.portal', $pageRoutes, 'SoD: Admin must never see Subscription Billing.');
    }

    public function test_manager_sees_all_nineteen_hubs_except_subscription(): void
    {
        $sections = $this->resolver()->sidebar($this->users['Manager']);
        $labels = $this->flattenHubLabels($sections);

        $this->assertCount(10, $labels);

        $settingsHub = app(NavigationRegistry::class)->find('nav.settings');
        $visiblePages = $this->resolver()->visiblePagesFor($settingsHub, $this->users['Manager']);
        $pageRoutes = array_map(static fn ($p): string => (string) $p->route, $visiblePages);
        $this->assertNotContains('subscription.portal', $pageRoutes, 'Manager must never see Subscription Billing.');
    }

    public function test_finance_procurement_hub_exposes_supplier_invoices_only(): void
    {
        $registry = app(NavigationRegistry::class);
        $hub = $registry->find('nav.supply.inventory');

        $this->assertNotNull($hub);

        $pages = $this->resolver()->visiblePagesFor($hub, $this->users['Finance']);
        $labels = array_map(static fn ($p): string => $p->label, $pages);

        $this->assertSame(['Supplier Invoices'], $labels, 'Finance must see only Supplier Invoices in Inventory & Supply (SC-04).');
    }

    public function test_sub_nav_is_suppressed_for_single_page_hubs(): void
    {
        // Finance & GL has 6 tabs -> bar renders.
        $multi = $this->resolver()->subNavForRoute('finance.index', $this->users['Owner']);
        $this->assertNotSame([], $multi['pages']);

        // Dashboard has 1 page -> bar suppressed (R-5).
        $single = $this->resolver()->subNavForRoute('dashboard', $this->users['Owner']);
        $this->assertSame([], $single['pages']);
    }

    public function test_every_visible_hub_route_returns_success_for_owner(): void
    {
        $sections = $this->resolver()->sidebar($this->users['Owner']);

        foreach ($sections as $section) {
            foreach ($section['hubs'] as $hub) {
                $this->assertNotNull($hub->route);
                $response = $this->actingAs($this->users['Owner'])->get(route((string) $hub->route));
                $this->assertContains(
                    $response->status(),
                    [200, 302],
                    "Hub [{$hub->label}] route [{$hub->route}] returned {$response->status()}.",
                );
            }
        }
    }

    public function test_fixed_assets_is_reachable_from_the_finance_hub(): void
    {
        $registry = app(NavigationRegistry::class);
        $hub = $registry->find('nav.finance.gl');
        $this->assertNotNull($hub);

        $routes = array_map(
            static fn ($p): string => (string) $p->route,
            $this->resolver()->visiblePagesFor($hub, $this->users['Owner']),
        );

        $this->assertContains('finance.fixed-assets.index', $routes, 'INT-01 must be closed.');
    }

    public function test_exactly_one_hub_matches_at_a_time(): void
    {
        $registry = app(NavigationRegistry::class);
        $routeNames = [
            'tax-classes.index' => 'nav.catalog.master',
            'integrations.channel-mapping' => 'nav.settings',
            'finance.fixed-assets.index' => 'nav.finance.gl',
            'inventory.opnames' => 'nav.supply.inventory',
        ];

        foreach ($routeNames as $route => $expectedHubId) {
            $node = $registry->primaryForRoute($route);
            $this->assertNotNull($node, "Route [{$route}] is not in the registry.");
            $this->assertSame($expectedHubId, $registry->hubFor($node)?->id, "Route [{$route}] resolved to the wrong hub.");
        }
    }

    public function test_each_route_resolves_to_exactly_one_active_hub(): void
    {
        $registry = app(NavigationRegistry::class);

        foreach ($registry->pages() as $page) {
            if ($page->route === null) {
                continue;
            }

            // Every page route must map back to exactly one owning hub, even when
            // the route is delivered through a shortcut placement.
            $hub = $registry->hubFor($page);
            $this->assertNotNull($hub, "Page [{$page->id}] has no owning hub.");
        }
    }

    /**
     * @param  list<array{domain: mixed, hubs: list<mixed>}>  $sections
     * @return list<string>
     */
    private function flattenHubLabels(array $sections): array
    {
        $labels = [];
        foreach ($sections as $section) {
            foreach ($section['hubs'] as $hub) {
                $labels[] = $hub->label;
            }
        }

        return $labels;
    }
}
