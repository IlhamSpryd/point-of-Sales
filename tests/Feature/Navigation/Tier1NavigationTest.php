<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Tier1NavigationTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    private User $ownerUser;

    private User $kasirUser;

    protected function afterSubclassSetUp(): void
    {
        $context = app(TenantContext::class);

        $this->tenant = Tenant::create([
            'name' => 'Tier 1 Navigation Tenant',
            'slug' => 'tier1-nav-tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Tier 1 Navigation Store',
            'is_active' => true,
        ]);
        $context->setStoreId($this->store->id);

        $ownerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'permissions' => ['*']]);
        $kasirRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Kasir', 'permissions' => ['*']]);

        $this->ownerUser = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $ownerRole->id]);
        $this->kasirUser = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $kasirRole->id]);

        foreach ([$this->ownerUser, $this->kasirUser] as $u) {
            DB::table('user_stores')->insert([
                'tenant_id' => $this->tenant->id,
                'user_id' => $u->id,
                'store_id' => $this->store->id,
            ]);
        }
    }

    public function test_top_navbar_is_streamlined_without_app_launcher(): void
    {
        $response = $this->actingAs($this->ownerUser)->get(route('dashboard'));

        $response->assertOk();

        // App launcher is removed from top navbar per user instruction
        $response->assertDontSee('Open ERP App Launcher', false);
        $response->assertDontSee('Alt+A', false);

        // Branch switcher and breadcrumbs remain active on navbar left
        $response->assertSee('Tier 1 Navigation Store', false);
    }

    public function test_standalone_app_launcher_component_filters_restricted_modules_by_role(): void
    {
        $this->actingAs($this->ownerUser);
        $ownerHtml = \Illuminate\Support\Facades\Blade::render('<x-erp.app-launcher />');

        $this->assertStringContainsString('Cashier &amp; Operations', $ownerHtml);
        $this->assertStringContainsString('Finance &amp; Accounting', $ownerHtml);
        $this->assertStringContainsString('Human Resources &amp; Payroll', $ownerHtml);

        $this->actingAs($this->kasirUser);
        $kasirHtml = \Illuminate\Support\Facades\Blade::render('<x-erp.app-launcher />');

        $this->assertStringContainsString('Cashier &amp; Operations', $kasirHtml);
        $this->assertStringNotContainsString('Finance &amp; Accounting', $kasirHtml);
        $this->assertStringNotContainsString('Human Resources &amp; Payroll', $kasirHtml);
    }

    public function test_smart_accordion_sidebar_renders_section_toggle_buttons_and_badges(): void
    {
        $response = $this->actingAs($this->ownerUser)->get(route('dashboard'));

        $response->assertOk();

        // Accordion triggers with aria-expanded
        $response->assertSee('aria-expanded="openSections[\'overview\']"', false);
        $response->assertSee('toggleSection(', false);

        // Quick Access is removed from sidebar for a distraction-free modular experience
        $response->assertDontSee('Quick Access', false);
        $response->assertSee('POS Terminal', false);
    }

    public function test_contextual_breadcrumbs_render_module_and_page_trail(): void
    {
        $response = $this->actingAs($this->ownerUser)->get(route('inventory.ingredients'));

        $response->assertOk();

        // Breadcrumbs container
        $response->assertSee('aria-label="Context Breadcrumb"', false);
        $response->assertSee('Inventori &amp; Stok', false);
        $response->assertSee('Raw Materials &amp; Stock', false);
    }
}

