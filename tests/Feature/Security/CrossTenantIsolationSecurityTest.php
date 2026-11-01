<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Livewire\Kasir\CreateOrder;
use App\Models\Customer;
use App\Models\LoyaltyTier;
use App\Modules\Customers\Enums\StoreCreditStatusEnum;
use App\Modules\Customers\Services\LoyaltyService;
use App\Services\Context\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class CrossTenantIsolationSecurityTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        $this->setupTwoTenants();
    }

    /**
     * Test 1: Kasir di Tenant A tidak dapat mengakses atau membocorkan saldo store credit milik Tenant B.
     */
    public function test_cashier_cannot_access_or_leak_store_credit_balance_from_another_tenant(): void
    {
        $context = app(TenantContext::class);

        // 1. Buat pelanggan di Tenant B dengan saldo store credit aktif Rp 500.000
        $customerB = Customer::withoutTenantScope()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Budi Tenant B',
            'phone' => '+6281200000002',
            'customer_type' => 'INDIVIDUAL',
        ]);

        DB::table('store_credit_accounts')->insert([
            'tenant_id' => $this->tenantB->id,
            'customer_id' => $customerB->id,
            'current_balance' => 500000.00,
            'status' => StoreCreditStatusEnum::ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Login sebagai kasir di Tenant A, Store A
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);
        $this->actingAs($this->userAOwner);

        // 3. Panggil Livewire CreateOrder::selectCustomer dengan ID pelanggan Tenant B
        $component = Livewire::test(CreateOrder::class)
            ->call('selectCustomer', $customerB->id, $customerB->name);

        // Assert: Nilai storeCreditBalance WAJIB bernilai 0.0 dan customerId tidak boleh terpilih
        $this->assertEquals(0.0, (float) $component->get('storeCreditBalance'));
        $component->assertSet('customerId', null);
        $component->assertSet('storeCreditBalance', 0.0);
    }

    /**
     * Test 2: LoyaltyService mengisolasi poin dan tier secara ketat per tenant (fail-closed jika lintas tenant).
     */
    public function test_loyalty_service_strictly_isolates_points_and_tiers_per_tenant(): void
    {
        $context = app(TenantContext::class);
        $loyaltyService = app(LoyaltyService::class);

        // Setup Tier untuk Tenant A (2.0x multiplier)
        $context->setTenantId($this->tenantA->id);
        LoyaltyTier::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Gold A',
            'min_points' => 0,
            'points_multiplier' => 2.0,
            'is_active' => true,
        ]);

        // Setup Tier untuk Tenant B (5.0x multiplier)
        $context->setTenantId($this->tenantB->id);
        LoyaltyTier::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Diamond B',
            'min_points' => 0,
            'points_multiplier' => 5.0,
            'is_active' => true,
        ]);

        // Buat customer milik Tenant B
        $customerB = Customer::withoutTenantScope()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Customer B',
            'phone' => '+6281200000003',
            'customer_type' => 'INDIVIDUAL',
        ]);

        // 1. Sesi aktif Tenant A: kalkulasi poin untuk Customer Tenant B harus fail-closed
        $context->setTenantId($this->tenantA->id);
        $caught = false;
        try {
            $loyaltyService->calculateEarnablePoints($customerB, 100000);
        } catch (\RuntimeException $e) {
            $caught = true;
            $this->assertStringContainsString('Konflik isolasi tenant', $e->getMessage());
        }
        $this->assertTrue($caught, 'Calling LoyaltyService across tenants must fail-closed with RuntimeException.');

        // 2. Sesi aktif Tenant B: kalkulasi poin untuk Customer Tenant B berhasil dan menggunakan tier Tenant B (5.0x)
        $context->setTenantId($this->tenantB->id);
        $pointsB = $loyaltyService->calculateEarnablePoints($customerB, 100000);
        // 100.000 / 10.000 = 10 unit * 5.0 multiplier = 50 poin
        $this->assertEquals(50, $pointsB, 'Loyalty calculation in Tenant B context must use Tenant B tier multiplier.');
    }

    /**
     * Test 3: Database menolak insert pada tabel promosi/kredit dengan tenant_id fiktif (FK Error 1452).
     */
    public function test_database_rejects_inserting_promotion_or_credit_record_with_invalid_tenant_id(): void
    {
        // 1. Insert pada promotion_campaigns dengan tenant_id fiktif (999999)
        try {
            DB::table('promotion_campaigns')->insert([
                'tenant_id' => 999999,
                'campaign_code' => 'TEST_INVALID_TENANT_'.uniqid(),
                'name' => 'Invalid Tenant Campaign',
                'type' => 'AUTOMATIC_DISCOUNT',
                'start_datetime' => now(),
                'end_datetime' => now()->addDays(7),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Database must reject insert with invalid tenant_id into promotion_campaigns');
        } catch (QueryException $e) {
            $this->assertEquals(1452, $e->errorInfo[1] ?? null, 'Database must reject insert with Error Code 1452 (foreign key violation)');
        }

        // 2. Insert pada customer_credit_accounts dengan tenant_id fiktif (999999)
        try {
            DB::table('customer_credit_accounts')->insert([
                'tenant_id' => 999999,
                'customer_id' => 1,
                'credit_limit' => 1000000,
                'credit_status' => 'ACTIVE',
                'risk_rating' => 'LOW',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Database must reject insert with invalid tenant_id into customer_credit_accounts');
        } catch (QueryException $e) {
            $this->assertEquals(1452, $e->errorInfo[1] ?? null, 'Database must reject insert with Error Code 1452 (foreign key violation)');
        }
    }

    /**
     * Test 4: Database menolak hard delete pada tenant yang memiliki data historis pembelian/finansial (FK Restrict Error 1451).
     */
    public function test_database_rejects_hard_deleting_tenant_with_existing_financial_or_payroll_records(): void
    {
        // 1. Buat purchase request yang terhubung ke Tenant A
        DB::table('purchase_requests')->insert([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'pr_number' => 'PR-SEC-'.uniqid(),
            'requested_by_user_id' => $this->userAOwner->id,
            'required_by_date' => now()->addDays(7)->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Coba jalankan hard delete pada Tenant A
        try {
            DB::table('tenants')->where('id', $this->tenantA->id)->delete();
            $this->fail('Database must reject hard delete on tenant with financial/purchase records');
        } catch (QueryException $e) {
            $this->assertEquals(1451, $e->errorInfo[1] ?? null, 'Database must reject tenant delete with Error Code 1451 (foreign key restrict violation)');
        }
    }
}
