<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Livewire\Customers\CustomerCreditManager;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customers\Enums\CreditStatusEnum;
use App\Modules\Customers\Models\CustomerCreditAccount;
use App\Modules\Customers\Models\CustomerCreditLog;
use App\Modules\Customers\Services\CustomerCreditService;
use App\Modules\Sales\Enums\SalesOrderStatusEnum;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerCreditManagementTest extends TestCase
{
    use RefreshDatabase;

    protected CustomerCreditService $creditService;

    protected SalesOrderService $salesOrderService;

    protected Tenant $tenantA;

    protected Store $storeA;

    protected User $ownerA;

    protected User $cashierA;

    protected Customer $customerA;

    protected Product $productHigh;

    protected Product $productLow;

    protected Tenant $tenantB;

    protected Store $storeB;

    protected User $ownerB;

    protected Customer $customerB;

    protected function afterSubclassSetUp(): void
    {
        $this->creditService = app(CustomerCreditService::class);
        $this->salesOrderService = app(SalesOrderService::class);

        $context = app(TenantContext::class);

        // Setup Tenant A
        $this->tenantA = $context->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'Distribusi Mandiri Perkasa',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);
        });

        $context->setTenantId($this->tenantA->id);

        $this->storeA = Store::create([
            'name' => 'Main Distribution Hub Jakarta',
        ]);
        $context->setStoreId($this->storeA->id);

        $ownerRoleA = Role::firstOrCreate(
            ['name' => 'Owner', 'tenant_id' => $this->tenantA->id],
            ['display_name' => 'Owner', 'is_admin' => true]
        );

        $cashierRoleA = Role::firstOrCreate(
            ['name' => 'Cashier', 'tenant_id' => $this->tenantA->id],
            ['display_name' => 'Cashier', 'is_admin' => false]
        );

        $this->ownerA = User::create([
            'name' => 'Budi Santoso (Owner)',
            'email' => 'budi.owner@mandiri.test',
            'password' => bcrypt('password'),
            'role_id' => $ownerRoleA->id,
            'is_active' => true,
        ]);
        $this->ownerA->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

        $this->cashierA = User::create([
            'name' => 'Siti Kasir',
            'email' => 'siti.kasir@mandiri.test',
            'password' => bcrypt('password'),
            'role_id' => $cashierRoleA->id,
            'is_active' => true,
        ]);
        $this->cashierA->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

        $this->customerA = Customer::create([
            'customer_code' => 'CUST-B2B-001',
            'name' => 'PT Mitra Sejahtera Bersama',
            'customer_type' => 'COMPANY',
            'company_name' => 'PT Mitra Sejahtera Bersama',
            'tax_id' => '01.234.567.8-901.000',
            'phone' => '+6281234567890',
            'email' => 'finance@mitrasejahtera.test',
            'is_active' => true,
        ]);

        $category = Category::create([
            'category_name' => 'Packaging Materials',
            'category_code' => 'PKG',
        ]);

        $this->productHigh = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Heavy Duty Corrugated Carton Box',
            'product_code' => 'BOX-HD-01',
            'product_price' => 12000000.00,
            'is_active' => true,
            'status' => 'active',
        ]);

        $this->productLow = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Adhesive Tape Dispenser Machine',
            'product_code' => 'TAPE-DISP-01',
            'product_price' => 10000000.00,
            'is_active' => true,
            'status' => 'active',
        ]);

        // Setup Tenant B
        $this->tenantB = $context->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'Competitor Global Corp',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);
        });

        $context->setTenantId($this->tenantB->id);

        $this->storeB = Store::create([
            'name' => 'Competitor Warehouse Surabaya',
        ]);
        $context->setStoreId($this->storeB->id);

        $ownerRoleB = Role::firstOrCreate(
            ['name' => 'Owner', 'tenant_id' => $this->tenantB->id],
            ['display_name' => 'Owner', 'is_admin' => true]
        );

        $this->ownerB = User::create([
            'name' => 'Hendro (Competitor)',
            'email' => 'hendro@competitor.test',
            'password' => bcrypt('password'),
            'role_id' => $ownerRoleB->id,
            'is_active' => true,
        ]);
        $this->ownerB->stores()->attach($this->storeB->id, ['tenant_id' => $this->tenantB->id]);

        $this->customerB = Customer::create([
            'customer_code' => 'CUST-B2B-B99',
            'name' => 'PT Pelanggan Tenant B',
            'customer_type' => 'COMPANY',
            'phone' => '+628999111222',
            'is_active' => true,
        ]);
    }

    public function test_credit_exposure_and_reservation_lifecycle_with_limit_enforcement_and_release(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);
        $this->actingAs($this->ownerA);

        // 1. Inisialisasi Plafon Kredit: Limit 20.000.000, termin 30 hari
        $this->creditService->updateCreditLimit($this->customerA->id, '20000000.00', $this->ownerA, 'Plafon kredit awal B2B');
        $this->creditService->updateCreditTerms($this->customerA->id, 30, 7, $this->ownerA, 'Termin Net 30');

        $account = $this->customerA->creditAccount()->first();
        $this->assertSame('20000000.00', (string) $account->credit_limit);
        $this->assertSame('20000000.00', $account->getAvailableCredit());

        // 2. Buat Sales Order 1 (Rp 12.000.000, tempo 30 hari)
        $so1 = $this->salesOrderService->createOrder([
            'customer_id' => $this->customerA->id,
            'store_id' => $this->storeA->id,
            'payment_terms_days' => 30,
            'items' => [
                ['product_id' => $this->productHigh->id, 'quantity' => '1.0000', 'unit_price' => '12000000.00'],
            ],
        ], $this->ownerA);

        // Konfirmasi SO 1 -> eksposur direservasi (Rp 12.000.000)
        $confirmedSO1 = $this->salesOrderService->confirmOrder($so1, $this->ownerA);
        $this->assertSame(SalesOrderStatusEnum::CONFIRMED, $confirmedSO1->status);

        $account->refresh();
        $this->assertSame('12000000.00', (string) $account->reserved_exposure);
        $this->assertSame('8000000.00', $account->getAvailableCredit());

        // 3. Buat Sales Order 2 (Rp 10.000.000, tempo 30 hari) -> total proyeksi = 22.000.000 > limit 20.000.000
        $so2 = $this->salesOrderService->createOrder([
            'customer_id' => $this->customerA->id,
            'store_id' => $this->storeA->id,
            'payment_terms_days' => 30,
            'items' => [
                ['product_id' => $this->productLow->id, 'quantity' => '1.0000', 'unit_price' => '10000000.00'],
            ],
        ], $this->ownerA);

        // Coba konfirmasi SO 2 tanpa override -> WAJIB ditolak oleh limit kredit
        try {
            $this->salesOrderService->confirmOrder($so2, $this->ownerA);
            $this->fail('Konfirmasi SO 2 seharusnya gagal karena melebihi plafon kredit');
        } catch (DomainException $e) {
            $this->assertStringContainsString('melebihi batas kredit', $e->getMessage());
        }

        $so2->refresh();
        $this->assertSame(SalesOrderStatusEnum::DRAFT, $so2->status);

        // 4. Konfirmasi SO 2 dengan Credit Override oleh Owner
        $overrideReason = 'Disetujui direksi atas dasar komitmen pembayaran minggu depan.';
        $confirmedSO2 = $this->salesOrderService->confirmOrder(
            salesOrder: $so2,
            actor: $this->ownerA,
            allowOverride: true,
            overrideReason: $overrideReason
        );

        $this->assertSame(SalesOrderStatusEnum::CONFIRMED, $confirmedSO2->status);
        $this->assertSame($this->ownerA->id, $confirmedSO2->credit_override_approved_by);
        $this->assertSame($overrideReason, $confirmedSO2->credit_override_reason);

        // Eksposur gabungan naik menjadi 22.000.000
        $account->refresh();
        $this->assertSame('22000000.00', (string) $account->reserved_exposure);
        $this->assertSame('0.00', $account->getAvailableCredit());

        // Verifikasi log audit override
        $overrideLog = CustomerCreditLog::where('tenant_id', $this->tenantA->id)
            ->where('customer_id', $this->customerA->id)
            ->where('event_type', 'CREDIT_OVERRIDE')
            ->first();
        $this->assertNotNull($overrideLog);
        $this->assertSame($overrideReason, $overrideLog->reason);
        $this->assertSame(SalesOrder::class, $overrideLog->reference_type);
        $this->assertSame($confirmedSO2->id, $overrideLog->reference_id);

        // 5. Pembatalan SO 1 -> melepaskan reservasi eksposur sebesar 12.000.000
        $cancelledSO1 = $this->salesOrderService->cancelOrder($confirmedSO1, $this->ownerA, 'Pelanggan membatalkan PO item HD');
        $this->assertSame(SalesOrderStatusEnum::CANCELLED, $cancelledSO1->status);

        $account->refresh();
        // Reserved exposure turun: 22.000.000 - 12.000.000 = 10.000.000
        $this->assertSame('10000000.00', (string) $account->reserved_exposure);
        // Sisa kredit kembali: 20.000.000 - 10.000.000 = 10.000.000
        $this->assertSame('10000000.00', $account->getAvailableCredit());
    }

    public function test_credit_blocked_when_account_on_hold_even_if_under_limit(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);
        $this->actingAs($this->ownerA);

        $this->creditService->updateCreditLimit($this->customerA->id, '50000000.00', $this->ownerA, 'Plafon besar');

        // Bekukan akun (ON_HOLD)
        $this->creditService->setCreditStatus($this->customerA->id, CreditStatusEnum::ON_HOLD, $this->ownerA, 'Menunggu audit keuangan');

        $so = $this->salesOrderService->createOrder([
            'customer_id' => $this->customerA->id,
            'store_id' => $this->storeA->id,
            'payment_terms_days' => 14,
            'items' => [
                ['product_id' => $this->productLow->id, 'quantity' => '1.0000', 'unit_price' => '10000000.00'],
            ],
        ], $this->ownerA);

        // Coba konfirmasi -> Ditolak karena ON_HOLD
        try {
            $this->salesOrderService->confirmOrder($so, $this->ownerA);
            $this->fail('Order kredit wajib diblokir jika status akun ON_HOLD');
        } catch (DomainException $e) {
            $this->assertStringContainsString('ON_HOLD', $e->getMessage());
        }

        // Lepaskan pembekuan (ACTIVE)
        $this->creditService->setCreditStatus($this->customerA->id, CreditStatusEnum::ACTIVE, $this->ownerA, 'Audit keuangan selesai dan disetujui');

        $confirmed = $this->salesOrderService->confirmOrder($so, $this->ownerA);
        $this->assertSame(SalesOrderStatusEnum::CONFIRMED, $confirmed->status);
    }

    public function test_livewire_customer_credit_manager_updates_limit_terms_and_status(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);
        $this->actingAs($this->ownerA);

        $component = Livewire::test(CustomerCreditManager::class)
            ->assertOk()
            ->assertSee($this->customerA->name)
            ->assertSee('CUST-B2B-001');

        // 1. Ubah Plafon Kredit & Termin via Modal Edit
        $component->call('openEditModal', $this->customerA->id)
            ->set('creditLimit', '35000000.00')
            ->set('paymentTermsDays', 45)
            ->set('gracePeriodDays', 14)
            ->set('limitChangeReason', 'Penaikan plafon dan termin tahun fiskal baru')
            ->call('saveLimitAndTerms')
            ->assertHasNoErrors();

        $account = CustomerCreditAccount::where('customer_id', $this->customerA->id)->first();
        $this->assertSame('35000000.00', (string) $account->credit_limit);
        $this->assertSame(45, $account->payment_terms_days);
        $this->assertSame(14, $account->grace_period_days);

        // 2. Ubah Status Kredit ke ON_HOLD
        $component->call('openStatusModal', $this->customerA->id)
            ->set('targetStatus', 'ON_HOLD')
            ->set('statusChangeReason', 'Hold administratif sementara')
            ->call('saveStatus')
            ->assertHasNoErrors();

        $account->refresh();
        $this->assertSame(CreditStatusEnum::ON_HOLD, $account->credit_status);

        // 3. Buka Modal Audit Log dan pastikan log tercatat
        $component->call('openLogsModal', $this->customerA->id)
            ->assertSet('showLogsModal', true);

        $selectedCustomer = $component->get('selectedCustomer');
        $this->assertNotNull($selectedCustomer);
        $this->assertGreaterThanOrEqual(2, $selectedCustomer->creditLogs->count());
    }

    public function test_multi_tenant_isolation_prevents_unauthorized_credit_management(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantB->id);
        $context->setStoreId($this->storeB->id);
        $this->actingAs($this->ownerB);

        // User Tenant B mencoba memanipulasi batas kredit Customer Tenant A
        $this->expectException(ModelNotFoundException::class);
        $this->creditService->updateCreditLimit($this->customerA->id, '99999999.00', $this->ownerB, 'Percobaan cross-tenant hack');
    }

    public function test_concurrent_sales_orders_competing_for_credit_limit(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);
        $this->actingAs($this->ownerA);

        // Plafon kredit: 15.000.000
        $this->creditService->updateCreditLimit($this->customerA->id, '15000000.00', $this->ownerA, 'Plafon 15jt');
        $this->creditService->updateCreditTerms($this->customerA->id, 30, 0, $this->ownerA, 'Net 30');

        // Buat 2 order masing-masing 10.000.000 (total = 20.000.000 > limit 15.000.000)
        $soAlpha = $this->salesOrderService->createOrder([
            'customer_id' => $this->customerA->id,
            'store_id' => $this->storeA->id,
            'payment_terms_days' => 30,
            'items' => [
                ['product_id' => $this->productLow->id, 'quantity' => '1.0000', 'unit_price' => '10000000.00'],
            ],
        ], $this->ownerA);

        $soBeta = $this->salesOrderService->createOrder([
            'customer_id' => $this->customerA->id,
            'store_id' => $this->storeA->id,
            'payment_terms_days' => 30,
            'items' => [
                ['product_id' => $this->productLow->id, 'quantity' => '1.0000', 'unit_price' => '10000000.00'],
            ],
        ], $this->ownerA);

        $firstResult = null;
        $secondException = null;

        // Eksekusi kompetisi: Order Alpha dikonfirmasi lebih dulu
        try {
            $firstResult = $this->salesOrderService->confirmOrder($soAlpha, $this->ownerA);
        } catch (\Throwable $e) {
            $this->fail('Order pertama seharusnya berhasil dikonfirmasi: '.$e->getMessage());
        }

        // Order Beta dikonfirmasi saat kredit sudah terpakai 10jt dari 15jt (tersisa 5jt)
        try {
            $this->salesOrderService->confirmOrder($soBeta, $this->ownerA);
        } catch (\Throwable $e) {
            $secondException = $e;
        }

        $this->assertNotNull($firstResult);
        $this->assertSame(SalesOrderStatusEnum::CONFIRMED, $firstResult->status);

        $this->assertNotNull($secondException, 'Order kedua yang bersaing harus ditolak.');
        $this->assertInstanceOf(DomainException::class, $secondException);
        $this->assertStringContainsString('melebihi batas kredit', $secondException->getMessage());

        $soBeta->refresh();
        $this->assertSame(SalesOrderStatusEnum::DRAFT, $soBeta->status);

        $account = CustomerCreditAccount::where('customer_id', $this->customerA->id)->first();
        // Reserved exposure tepat 10.000.000 (tidak ada double exposure atau kebocoran)
        $this->assertSame('10000000.00', (string) $account->reserved_exposure);
        $this->assertSame('5000000.00', $account->getAvailableCredit());
    }
}
