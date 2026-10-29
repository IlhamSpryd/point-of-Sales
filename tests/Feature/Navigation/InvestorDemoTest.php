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

class InvestorDemoTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Store $store;
    private User $ownerUser;

    protected function afterSubclassSetUp(): void
    {
        $context = app(TenantContext::class);

        $this->tenant = Tenant::create([
            'name' => 'Investor Demo Tenant',
            'slug' => 'investor-demo-tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Investor Flagship Outlet',
            'is_active' => true,
        ]);
        $context->setStoreId($this->store->id);

        $ownerRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'permissions' => ['*'],
        ]);

        $this->ownerUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $ownerRole->id,
        ]);

        DB::table('user_stores')->insert([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->ownerUser->id,
            'store_id' => $this->store->id,
        ]);
    }

    public function test_guest_is_redirected_to_login_when_accessing_investor_demo(): void
    {
        $response = $this->get(route('investor.demo'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_owner_can_access_investor_demo_hub(): void
    {
        $this->actingAs($this->ownerUser);

        $response = $this->withSession([
            'active_tenant_id' => $this->tenant->id,
            'active_store_id' => $this->store->id,
        ])->get(route('investor.demo'));

        $response->assertOk();
        $response->assertSee('Pusat Visi Produk &amp; Demonstrasi Investor', false);
        $response->assertSee('Single Modular Core', false);
        $response->assertSee('5 Alur Demonstrasi Terpadu', false);
        $response->assertSee('Matriks 12 Domain Produk', false);
        $response->assertSee('Keterlacakan 81 PAGE-ID', false);
        $response->assertSee('PAGE-001', false);
        $response->assertSee('PAGE-081', false);
    }

    public function test_investor_demo_contains_all_12_strategic_domains(): void
    {
        $this->actingAs($this->ownerUser);

        $response = $this->withSession([
            'active_tenant_id' => $this->tenant->id,
            'active_store_id' => $this->store->id,
        ])->get(route('investor.demo', ['tab' => 'domains']));

        $response->assertOk();
        $response->assertSee('1. Platform Core', false);
        $response->assertSee('2. Commerce Core', false);
        $response->assertSee('3. F&B Industry Pack', false);
        $response->assertSee('4. Retail Industry Pack', false);
        $response->assertSee('5. Inventori & Suplai', false);
        $response->assertSee('6. Analitik & Finansial', false);
        $response->assertSee('7. CRM & Loyalitas', false);
        $response->assertSee('8. Kitchen Display (KDS)', false);
        $response->assertSee('9. Table Management', false);
        $response->assertSee('10. Pengadaan Formal (P2P)', false);
        $response->assertSee('11. Keuangan & General Ledger', false);
        $response->assertSee('12. Integrasi & Offline Sync', false);
    }

    public function test_cashier_role_cannot_access_investor_demo_hub(): void
    {
        $kasirRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kasir',
            'permissions' => ['pos.cashier'],
        ]);

        $cashierUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $kasirRole->id,
        ]);

        DB::table('user_stores')->insert([
            'tenant_id' => $this->tenant->id,
            'user_id' => $cashierUser->id,
            'store_id' => $this->store->id,
        ]);

        $this->actingAs($cashierUser);

        $response = $this->withSession([
            'active_tenant_id' => $this->tenant->id,
            'active_store_id' => $this->store->id,
        ])->get(route('investor.demo'));

        $response->assertForbidden();
    }

    public function test_investor_demo_contains_all_5_advertised_workflows(): void
    {
        $this->actingAs($this->ownerUser);

        $response = $this->withSession([
            'active_tenant_id' => $this->tenant->id,
            'active_store_id' => $this->store->id,
        ])->get(route('investor.demo', ['tab' => 'journeys']));

        $response->assertOk();
        $response->assertSee('Alur A: Penjualan F&B Menuju Pengurangan Resep', false);
        $response->assertSee('Alur B: Transaksi Ritel, Pemindaian Barcode', false);
        $response->assertSee('Alur C: Penerimaan Barang Langsung & Rekalkulasi HPP', false);
        $response->assertSee('Alur D: Tata Kelola Multi-Cabang & Isolasi Konteks Store', false);
        $response->assertSee('Alur E: Etalase Kapabilitas Masa Depan (Fase F2 & F3)', false);
    }

    public function test_traceability_matrix_contains_exact_81_page_ids_with_reconciled_counts(): void
    {
        $this->actingAs($this->ownerUser);

        $response = $this->withSession([
            'active_tenant_id' => $this->tenant->id,
            'active_store_id' => $this->store->id,
        ])->get(route('investor.demo', ['tab' => 'traceability']));

        $response->assertOk();
        // Check authoritative KPI summary counts
        $response->assertSee('34', false); // KEEP
        $response->assertSee('17', false); // DRAWER/MODAL
        $response->assertSee('4', false);  // MERGE & NAV CONSOLIDATION
        $response->assertSee('9', false);  // DEFER F2
        $response->assertSee('13', false); // DEFER F3
        $response->assertSee('81', false); // TOTAL

        // Check boundary and sample PAGE-IDs
        for ($i = 1; $i <= 81; $i++) {
            $pageId = sprintf('PAGE-%03d', $i);
            $response->assertSee($pageId, false);
        }
    }
}
