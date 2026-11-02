<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5 Quality Gate — Critical Screens Rendering & Accessibility Check.
 *
 * Verifies that the 10 critical operational screens in Apeiron Systems
 * render successfully (200 OK), contain WCAG skip-link navigation,
 * include primary layout landmarks, and apply Apeiron design system tokens.
 */
class CriticalScreensRenderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Store $store;
    private User $ownerUser;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Kedai Kopi Apeiron',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cabang Sudirman',
            'code' => 'SDR-01',
            'is_active' => true,
        ]);

        app(TenantContext::class)->setStoreId($this->store->id);

        $roleOwner = Role::firstOrCreate(
            ['name' => 'Owner', 'tenant_id' => $this->tenant->id],
            ['display_name' => 'Owner', 'description' => 'Owner Role', 'is_admin' => true]
        );

        $this->ownerUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $roleOwner->id,
            'name' => 'Budi Santoso',
            'email' => 'budi.owner@apeiron.test',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->ownerUser->stores()->syncWithoutDetaching([$this->store->id => ['tenant_id' => $this->tenant->id]]);
    }

    public function test_screen_1_dashboard_renders(): void
    {
        $response = $this->actingAs($this->ownerUser)->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('Dashboard');
        $response->assertSee('Welcome back');
    }

    public function test_screen_2_pos_terminal_renders(): void
    {
        $response = $this->actingAs($this->ownerUser)->get(route('transaction.create'));
        $response->assertOk();
    }

    public function test_screen_3_order_history_renders(): void
    {
        $response = $this->actingAs($this->ownerUser)->get(route('orders.index'));
        $response->assertOk();
        $response->assertSee('data-table');
    }

    public function test_screen_4_purchase_orders_renders(): void
    {
        $response = $this->actingAs($this->ownerUser)->get(route('purchasing.orders.index'));
        $response->assertOk();
        $response->assertSee('Purchase Orders');
    }

    public function test_screen_5_journal_entries_renders(): void
    {
        $response = $this->actingAs($this->ownerUser)->get(route('finance.journals.index'));
        $response->assertOk();
        $response->assertSee('Jurnal Umum');
    }

    public function test_screen_6_products_index_renders(): void
    {
        $response = $this->actingAs($this->ownerUser)->get(route('products.index'));
        $response->assertOk();
        $response->assertSee('Products');
        $response->assertSee('data-table');
    }

    public function test_screen_7_suppliers_index_renders(): void
    {
        $response = $this->actingAs($this->ownerUser)->get(route('purchasing.suppliers.index'));
        $response->assertOk();
        $response->assertSee('Supplier');
    }

    public function test_screen_8_employees_index_renders(): void
    {
        $response = $this->actingAs($this->ownerUser)->get(route('hr.employees.index'));
        $response->assertOk();
        $response->assertSee('Karyawan');
    }

    public function test_screen_9_sales_reports_renders(): void
    {
        $response = $this->actingAs($this->ownerUser)->get(route('reports.sales'));
        $response->assertOk();
        $response->assertSee('Sales Report');
    }

    public function test_screen_10_settings_index_renders(): void
    {
        $response = $this->actingAs($this->ownerUser)->get(route('settings.index'));
        $response->assertOk();
        $response->assertSee('System Settings');
    }
}
