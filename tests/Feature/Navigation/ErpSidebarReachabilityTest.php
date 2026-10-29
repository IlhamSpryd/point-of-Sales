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
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErpSidebarReachabilityTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    private Store $store;

    private User $ownerUser;

    private User $managerUser;

    private User $kasirUser;

    protected function afterSubclassSetUp(): void
    {
        $context = app(TenantContext::class);

        $this->tenant = Tenant::create([
            'name' => 'ERP Reachability Tenant',
            'slug' => 'erp-reach-tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'ERP Reachability Store',
            'is_active' => true,
        ]);
        $context->setStoreId($this->store->id);

        $ownerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'permissions' => ['*']]);
        $managerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Manager', 'permissions' => ['*']]);
        $kasirRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Kasir', 'permissions' => ['*']]);

        $this->ownerUser = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $ownerRole->id]);
        $this->managerUser = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $managerRole->id]);
        $this->kasirUser = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $kasirRole->id]);

        foreach ([$this->ownerUser, $this->managerUser, $this->kasirUser] as $u) {
            DB::table('user_stores')->insert([
                'tenant_id' => $this->tenant->id,
                'user_id' => $u->id,
                'store_id' => $this->store->id,
            ]);
        }
    }

    public function test_all_sidebar_menu_routes_exist_in_laravel_routing_table(): void
    {
        $sections = config('navigation.sections');
        $this->assertNotEmpty($sections);

        foreach (collect($sections)->flatten(1) as $item) {
            $this->assertTrue(
                Route::has($item['route']),
                "Route '{$item['route']}' for sidebar item '{$item['label']}' is not registered."
            );
        }
    }

    public function test_owner_dashboard_sidebar_renders_all_navigation_pillars_and_module_entry_points(): void
    {
        $response = $this->actingAs($this->ownerUser)->get(route('dashboard'));

        $response->assertOk();

        // Check key ERP module entry points in sidebar
        $response->assertSee('Pengadaan (Purchasing)');
        $response->assertSee('Pelanggan & CRM');
        $response->assertSee('Penjualan B2B & AR');
        $response->assertSee('Keuangan & Akuntansi');
        $response->assertSee('Katalog Produk');
        $response->assertSee('Inventori & Stok');
    }

    public function test_purchasing_hub_is_reachable_and_renders_sub_nav(): void
    {
        $response = $this->actingAs($this->ownerUser)->get(route('purchasing.suppliers.index'));

        $response->assertOk();
        $response->assertSee('Sub-navigasi Pengadaan (Purchasing)', false);
        $response->assertSee('Pemasok');
        $response->assertSee('Pesanan Pembelian (PO)');
        $response->assertSee('Penerimaan GRN');
        $response->assertSee('Faktur Pemasok (3-Way)');
        $response->assertSee('Retur Pembelian');
    }

    public function test_customer_crm_hub_is_reachable_and_renders_sub_nav(): void
    {
        $response = $this->actingAs($this->ownerUser)->get(route('customers.index'));

        $response->assertOk();
        $response->assertSee('Sub-navigasi Pelanggan & Loyalti (CRM)', false);
        $response->assertSee('Direktori Pelanggan');
        $response->assertSee('Grup Pelanggan');
        $response->assertSee('Kontrol Kredit B2B');
        $response->assertSee('Loyalti, Voucher & Gift Card');
    }

    public function test_sales_b2b_hub_is_reachable_and_renders_sub_nav(): void
    {
        $response = $this->actingAs($this->ownerUser)->get(route('sales.orders.index'));

        $response->assertOk();
        $response->assertSee('Sub-navigasi Penjualan B2B & Piutang (AR)', false);
        $response->assertSee('Pesanan Penjualan (SO)');
        $response->assertSee('Penawaran Harga (Quotation)');
        $response->assertSee('Faktur & Piutang (AR)');
        $response->assertSee('Retur & Nota Kredit');
    }

    public function test_finance_hub_is_reachable_by_owner(): void
    {
        $response = $this->actingAs($this->ownerUser)->get(route('finance.index'));

        $response->assertOk();
        $response->assertSee('Keuangan & Akuntansi');
        $response->assertSee('Modul 7');
    }

    public function test_unauthorized_role_cannot_reach_restricted_modules(): void
    {
        // Kasir cannot reach Purchasing
        $response = $this->actingAs($this->kasirUser)->get(route('purchasing.suppliers.index'));
        $this->assertEquals(403, $response->status());

        // Kasir cannot reach Finance
        $response = $this->actingAs($this->kasirUser)->get(route('finance.index'));
        $this->assertEquals(403, $response->status());

        // Kasir cannot reach Stores management
        $response = $this->actingAs($this->kasirUser)->get(route('stores.index'));
        $this->assertEquals(403, $response->status());
    }
}
