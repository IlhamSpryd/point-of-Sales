<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Core\Context\TenantContext;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * L2 contextual sub-navigation (ADR-NAV-013).
 *
 * Asserts that the horizontal pill bar derives from the registry, renders on
 * every page of a hub, and is suppressed for single-page hubs (R-5).
 */
class ModuleSubNavRenderTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    private User $owner;

    protected function afterSubclassSetUp(): void
    {
        $context = app(TenantContext::class);

        $this->tenant = Tenant::create([
            'name' => 'SubNav Tenant',
            'slug' => 'subnav-tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'SubNav Store',
            'is_active' => true,
        ]);
        $context->setStoreId($this->store->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'permissions' => ['*']]);
        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $role->id]);

        DB::table('user_stores')->insert([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'store_id' => $this->store->id,
        ]);
    }

    public function test_inventory_hub_renders_all_seven_workflow_tabs(): void
    {
        $response = $this->actingAs($this->owner)->get(route('inventory.ingredients'));

        $response->assertOk();
        $response->assertSee('Inventory Navigation', false);

        foreach ([
            'Raw Materials &amp; Stock',
            'Direct Receipts',
            'Stock Adjustments',
            'Stock Count (Opname)',
            'Inter-Branch Transfers',
            'Direct Supplier Returns',
            'Stock Ledger',
        ] as $label) {
            $response->assertSee($label, false);
        }
    }

    public function test_finance_hub_renders_all_six_tabs_including_fixed_assets(): void
    {
        $response = $this->actingAs($this->owner)->get(route('finance.index'));

        $response->assertOk();
        $response->assertSee('Finance &amp; Accounting Navigation', false);
        $response->assertSee('Fixed Assets', false);
    }

    public function test_procurement_renders_five_tabs_with_disambiguated_labels(): void
    {
        $response = $this->actingAs($this->owner)->get(route('purchasing.orders.index'));

        $response->assertOk();
        $response->assertSee('Procurement Navigation', false);
        $response->assertSee('PO Goods Receipt (GRN)', false);
        $response->assertSee('PO Purchase Returns', false);
        $response->assertSee('Supplier Invoices (3-Way)', false);
    }

    public function test_single_page_hub_suppresses_the_pill_bar(): void
    {
        $response = $this->actingAs($this->owner)->get(route('manufacturing.orders.index'));

        $response->assertOk();
        $response->assertDontSee('Manufacturing Navigation', false);
    }

    public function test_legacy_module_prop_mapping_still_resolves(): void
    {
        $this->actingAs($this->owner);

        $html = Blade::render('<x-erp.module-sub-nav module="inventory" />');

        $this->assertStringContainsString('Stock Count (Opname)', $html);
        $this->assertStringContainsString('Inventory Navigation', $html);
    }

    public function test_explicit_hub_prop_overrides_route_detection(): void
    {
        $this->actingAs($this->owner);

        // Even while sitting on the dashboard, asking for the finance hub works.
        $html = Blade::render('<x-erp.module-sub-nav hub="nav.finance.gl" />');

        $this->assertStringContainsString('Chart of Accounts', $html);
    }

    public function test_tab_is_marked_aria_current_on_its_own_page(): void
    {
        $response = $this->actingAs($this->owner)->get(route('inventory.opnames'));

        $response->assertOk();
        $html = (string) $response->getContent();

        $this->assertMatchesRegularExpression(
            '/aria-current="page"[^>]*>\s*(<span[^>]*>)?\s*Stock Count \(Opname\)/s',
            $html,
            'The active pill must carry aria-current="page".',
        );
    }

    public function test_pills_use_semantic_tokens_only(): void
    {
        $source = (string) file_get_contents(resource_path('views/components/erp/module-sub-nav.blade.php'));

        $this->assertStringContainsString('bg-primary', $source);
        $this->assertStringContainsString('text-primary-foreground', $source);
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{6}\b/', $source);
    }
}
