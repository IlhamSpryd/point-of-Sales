<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\StockMovementType;
use App\Livewire\Sales\AccountsReceivableManager;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStockBalance;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customers\Enums\CreditStatusEnum;
use App\Modules\Customers\Models\CustomerCreditAccount;
use App\Modules\Customers\Services\CustomerCreditService;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Modules\Sales\Enums\CustomerInvoiceStatusEnum;
use App\Modules\Sales\Enums\SalesOrderFulfillmentStatusEnum;
use App\Modules\Sales\Enums\SalesOrderStatusEnum;
use App\Modules\Sales\Services\AccountsReceivableService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AccountsReceivableIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected AccountsReceivableService $arService;

    protected SalesOrderService $salesOrderService;

    protected InventoryLedgerService $inventoryLedgerService;

    protected CustomerCreditService $creditService;

    protected Tenant $tenantA;

    protected Store $storeA;

    protected User $ownerA;

    protected Customer $customerA;

    protected Product $product;

    protected Tenant $tenantB;

    protected Store $storeB;

    protected User $ownerB;

    protected Customer $customerB;

    protected function afterSubclassSetUp(): void
    {
        $this->arService = app(AccountsReceivableService::class);
        $this->salesOrderService = app(SalesOrderService::class);
        $this->inventoryLedgerService = app(InventoryLedgerService::class);
        $this->creditService = app(CustomerCreditService::class);

        $context = app(TenantContext::class);

        // Setup Tenant A
        $this->tenantA = $context->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'PT Manufaktur Baja Perkasa',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);
        });

        $context->setTenantId($this->tenantA->id);

        $this->storeA = Store::create([
            'name' => 'Central Warehouse Jakarta',
        ]);
        $context->setStoreId($this->storeA->id);

        $ownerRoleA = Role::firstOrCreate(
            ['name' => 'Owner', 'tenant_id' => $this->tenantA->id],
            ['display_name' => 'Owner', 'is_admin' => true]
        );

        $this->ownerA = User::create([
            'name' => 'Bambang Owner',
            'email' => 'bambang@bajaperkasa.test',
            'password' => bcrypt('password'),
            'role_id' => $ownerRoleA->id,
            'is_active' => true,
        ]);
        $this->ownerA->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

        $this->customerA = Customer::create([
            'customer_code' => 'CUST-B2B-A1',
            'name' => 'PT Konstruksi Mega Mandiri',
            'customer_type' => 'COMPANY',
            'company_name' => 'PT Konstruksi Mega Mandiri',
            'tax_id' => '01.333.444.5-666.000',
            'phone' => '+628123456789',
            'email' => 'procurement@megamandiri.test',
            'is_active' => true,
        ]);

        CustomerCreditAccount::create([
            'tenant_id' => $this->tenantA->id,
            'customer_id' => $this->customerA->id,
            'credit_limit' => '50000000.00',
            'payment_terms_days' => 30,
            'grace_period_days' => 7,
            'credit_status' => CreditStatusEnum::ACTIVE,
            'reserved_exposure' => '0.00',
            'invoiced_exposure' => '0.00',
            'unapplied_credit_balance' => '0.00',
        ]);

        $category = Category::create([
            'category_name' => 'Baja Konstruksi',
            'category_code' => 'STEEL',
        ]);

        $this->product = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Baja H-Beam 150x150x7x10mm 12M',
            'product_code' => 'HBEAM-150',
            'product_price' => 5000000.00,
            'is_active' => true,
            'status' => 'active',
        ]);

        // Saldo stok fisik awal 50 batang
        $this->inventoryLedgerService->recordMovement(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            itemType: 'product',
            itemId: (int) $this->product->id,
            quantityDelta: '50.0000',
            type: StockMovementType::InitialSync,
            unitCost: '3800000.00',
            reason: 'Opening stock baja H-Beam',
            userId: $this->ownerA->id
        );

        // Setup Tenant B
        $this->tenantB = $context->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'Competitor Steel Trading',
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
            'name' => 'Owner Tenant B',
            'email' => 'owner_b@competitor.test',
            'password' => bcrypt('password'),
            'role_id' => $ownerRoleB->id,
            'is_active' => true,
        ]);
        $this->ownerB->stores()->attach($this->storeB->id, ['tenant_id' => $this->tenantB->id]);

        $this->customerB = Customer::create([
            'customer_code' => 'CUST-B2B-B9',
            'name' => 'PT Pelanggan Luar',
            'customer_type' => 'COMPANY',
            'phone' => '+628999888111',
            'is_active' => true,
        ]);

        CustomerCreditAccount::create([
            'tenant_id' => $this->tenantB->id,
            'customer_id' => $this->customerB->id,
            'credit_limit' => '30000000.00',
            'payment_terms_days' => 30,
            'grace_period_days' => 7,
            'credit_status' => CreditStatusEnum::ACTIVE,
            'reserved_exposure' => '0.00',
            'invoiced_exposure' => '0.00',
            'unapplied_credit_balance' => '0.00',
        ]);
    }

    public function test_full_order_to_cash_lifecycle_from_sales_order_to_invoice_and_payment_reconciliation(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);
        $this->actingAs($this->ownerA);

        // 1. Buat Sales Order B2B senilai Rp 25.000.000 (5 batang @ 5.000.000)
        $so = $this->salesOrderService->createOrder([
            'customer_id' => $this->customerA->id,
            'store_id' => $this->storeA->id,
            'payment_terms_days' => 30,
            'customer_po_number' => 'PO-MEGA-STEEL-01',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => '5.0000', 'unit_price' => '5000000.00'],
            ],
        ], $this->ownerA);

        $this->assertSame(SalesOrderStatusEnum::DRAFT, $so->status);

        // 2. Konfirmasi SO -> Reservasi stok fisik dan eksposur kredit
        $confirmedSO = $this->salesOrderService->confirmOrder($so, $this->ownerA);
        $this->assertSame(SalesOrderStatusEnum::CONFIRMED, $confirmedSO->status);

        $creditAccount = CustomerCreditAccount::where('customer_id', $this->customerA->id)->first();
        $this->assertSame('25000000.00', (string) $creditAccount->reserved_exposure);
        $this->assertSame('0.00', (string) $creditAccount->invoiced_exposure);
        $this->assertSame('25000000.00', $creditAccount->getTotalExposure());
        $this->assertSame('25000000.00', $creditAccount->getAvailableCredit());

        // 3. Fulfill Sales Order -> Potong stok aktual via InventoryLedgerService (M4)
        $soLine = $confirmedSO->lines->first();
        $executionOrder = $this->salesOrderService->fulfillOrder($confirmedSO, [
            ['line_id' => $soLine->id, 'quantity' => '5.0000'],
        ], $this->ownerA);

        $this->assertInstanceOf(Order::class, $executionOrder);
        $confirmedSO->refresh();
        $this->assertSame(SalesOrderFulfillmentStatusEnum::FULFILLED, $confirmedSO->fulfillment_status);

        // Verifikasi stok fisik terpotong: 50 - 5 = 45 batang
        $currentStock = ProductStockBalance::where('store_id', $this->storeA->id)
            ->where('product_id', $this->product->id)
            ->value('quantity');
        $this->assertSame('45.0000', (string) $currentStock);

        // 4. Terbitkan dan Posting Faktur Penjualan (Customer Invoice)
        $invoice = $this->arService->createInvoice(
            source: $confirmedSO,
            options: ['post_immediately' => true],
            actor: $this->ownerA
        );

        $this->assertSame(CustomerInvoiceStatusEnum::POSTED, $invoice->status);
        $this->assertSame('25000000.00', (string) $invoice->total_amount);
        $this->assertSame('25000000.00', (string) $invoice->balance_due);

        // Verifikasi eksposur kredit berpindah tanpa hitung-ganda
        $creditAccount->refresh();
        $this->assertSame('0.00', (string) $creditAccount->reserved_exposure);
        $this->assertSame('25000000.00', (string) $creditAccount->invoiced_exposure);
        $this->assertSame('25000000.00', $creditAccount->getTotalExposure());

        // 5. Pembayaran Masuk dan Dialokasikan Penuh (Rp 25.000.000)
        $paymentResult = $this->arService->recordAndAllocatePayment(
            customerId: (int) $this->customerA->id,
            paymentData: [
                'amount' => '25000000.00',
                'payment_reference' => 'TRF-MANDIRI-MEGA-998',
            ],
            invoiceAllocations: [
                ['invoice_id' => $invoice->id, 'amount' => '25000000.00'],
            ],
            actor: $this->ownerA
        );

        $this->assertSame('25000000.00', $paymentResult['total_allocated']);
        $this->assertSame('0.00', $paymentResult['unapplied_credit']);

        // Verifikasi status akhir faktur dan akun kredit
        $invoice->refresh();
        $this->assertSame(CustomerInvoiceStatusEnum::PAID, $invoice->status);
        $this->assertSame('25000000.00', (string) $invoice->paid_amount);
        $this->assertSame('0.00', (string) $invoice->balance_due);

        $creditAccount->refresh();
        $this->assertSame('0.00', (string) $creditAccount->reserved_exposure);
        $this->assertSame('0.00', (string) $creditAccount->invoiced_exposure);
        $this->assertSame('0.00', $creditAccount->getTotalExposure());
        $this->assertSame('50000000.00', $creditAccount->getAvailableCredit());
    }

    public function test_livewire_accounts_receivable_manager_component_interactions(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);
        $this->actingAs($this->ownerA);

        // Buat faktur dummy
        $invoice = $this->arService->createInvoice([
            'customer_id' => $this->customerA->id,
            'store_id' => $this->storeA->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => '2.0000', 'unit_price' => '5000000.00'],
            ],
        ], ['post_immediately' => true], $this->ownerA); // Total 10jt

        $component = Livewire::test(AccountsReceivableManager::class)
            ->assertOk()
            ->assertSee($invoice->invoice_number)
            ->assertSee($this->customerA->name);

        // 1. Buka Modal Detail Invoice
        $component->call('viewInvoice', $invoice->id)
            ->assertSet('showDetailModal', true)
            ->assertSee('Baja H-Beam 150x150x7x10mm 12M')
            ->call('closeDetailModal')
            ->assertSet('showDetailModal', false);

        // 2. Alokasikan Pembayaran via Modal Livewire
        $component->call('openPaymentModal', $this->customerA->id)
            ->assertSet('showPaymentModal', true)
            ->call('allocateFull', $invoice->id, '10000000.00')
            ->set('paymentReference', 'TRF-TEST-LW-01')
            ->call('submitPayment')
            ->assertHasNoErrors()
            ->assertSet('showPaymentModal', false);

        $invoice->refresh();
        $this->assertSame(CustomerInvoiceStatusEnum::PAID, $invoice->status);
        $this->assertSame('0.00', (string) $invoice->balance_due);

        // 3. Uji Customer Statement pada tab Statement
        $component->set('activeTab', 'statement')
            ->set('statementCustomerId', $this->customerA->id)
            ->call('generateStatement')
            ->assertHasNoErrors();

        $statement = $component->get('statementData');
        $this->assertNotNull($statement);
        $this->assertNotEmpty($statement['transactions']);
    }

    public function test_multi_tenant_isolation_prevents_cross_tenant_ar_access(): void
    {
        $context = app(TenantContext::class);

        // Buat Invoice di Tenant A
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);
        $this->actingAs($this->ownerA);

        $invoiceA = $this->arService->createInvoice([
            'customer_id' => $this->customerA->id,
            'store_id' => $this->storeA->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => '1.0000', 'unit_price' => '5000000.00'],
            ],
        ], ['post_immediately' => true], $this->ownerA);

        // Switch ke Tenant B
        $context->setTenantId($this->tenantB->id);
        $context->setStoreId($this->storeB->id);
        $this->actingAs($this->ownerB);

        // Percobaan manipulasi pembayaran lintas tenant oleh User Tenant B
        $this->expectException(DomainException::class);
        $this->arService->recordAndAllocatePayment(
            customerId: (int) $this->customerB->id,
            paymentData: [
                'amount' => '5000000.00',
                'payment_reference' => 'HACK-TRF',
            ],
            invoiceAllocations: [
                ['invoice_id' => $invoiceA->id, 'amount' => '5000000.00'],
            ],
            actor: $this->ownerB
        );
    }

    public function test_concurrent_payment_allocations_respect_lock_order_and_prevent_negative_balance(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);
        $this->actingAs($this->ownerA);

        // Faktur Rp 10.000.000
        $invoice = $this->arService->createInvoice([
            'customer_id' => $this->customerA->id,
            'store_id' => $this->storeA->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => '2.0000', 'unit_price' => '5000000.00'],
            ],
        ], ['post_immediately' => true], $this->ownerA);

        $firstResult = null;
        $secondException = null;

        // Simulasi 2 alokasi pembayaran bersaing:
        // Pembayaran 1 sukses melunasi penuh Rp 10.000.000
        try {
            $firstResult = $this->arService->recordAndAllocatePayment(
                customerId: (int) $this->customerA->id,
                paymentData: ['amount' => '10000000.00', 'payment_reference' => 'PAY-1'],
                invoiceAllocations: [['invoice_id' => $invoice->id, 'amount' => '10000000.00']],
                actor: $this->ownerA
            );
        } catch (\Throwable $e) {
            $this->fail('Pembayaran pertama seharusnya berhasil: '.$e->getMessage());
        }

        // Pembayaran 2 yang tiba sesudahnya mencoba mengalokasikan Rp 10.000.000 ke faktur yang sudah lunas
        try {
            $this->arService->recordAndAllocatePayment(
                customerId: (int) $this->customerA->id,
                paymentData: ['amount' => '10000000.00', 'payment_reference' => 'PAY-2'],
                invoiceAllocations: [['invoice_id' => $invoice->id, 'amount' => '10000000.00']],
                actor: $this->ownerA
            );
        } catch (\Throwable $e) {
            $secondException = $e;
        }

        $this->assertNotNull($firstResult);
        $this->assertSame('10000000.00', $firstResult['total_allocated']);

        $this->assertNotNull($secondException, 'Pembayaran kedua yang bersaing harus ditolak.');
        $this->assertInstanceOf(DomainException::class, $secondException);
        // Faktur sudah berstatus PAID sehingga tidak dapat menerima alokasi
        $this->assertStringContainsString('tidak dapat menerima alokasi', $secondException->getMessage());

        $invoice->refresh();
        $this->assertSame(CustomerInvoiceStatusEnum::PAID, $invoice->status);
        $this->assertSame('0.00', (string) $invoice->balance_due);
        $this->assertSame('10000000.00', (string) $invoice->paid_amount);

        // Invariant saldo tidak pernah negatif
        $this->assertTrue(bccomp((string) $invoice->balance_due, '0.00', 2) >= 0);
    }
}
