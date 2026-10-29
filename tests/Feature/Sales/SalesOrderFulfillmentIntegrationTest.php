<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\StockMovementType;
use App\Livewire\Sales\SalesOrderManager;
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
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Modules\Sales\Enums\SalesOrderFulfillmentStatusEnum;
use App\Modules\Sales\Enums\SalesOrderStatusEnum;
use App\Modules\Sales\Services\SalesOrderService;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SalesOrderFulfillmentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;

    protected InventoryLedgerService $inventoryLedgerService;

    protected Tenant $tenantA;

    protected Store $storeA;

    protected User $ownerA;

    protected User $salesA;

    protected Customer $customerA;

    protected Product $productA;

    protected Product $productB;

    // Tenant B for isolation testing
    protected Tenant $tenantB;

    protected Store $storeB;

    protected User $ownerB;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->salesOrderService = app(SalesOrderService::class);
        $this->inventoryLedgerService = app(InventoryLedgerService::class);
        $context = app(TenantContext::class);

        // Setup Tenant A
        $this->tenantA = $context->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'Logistics Central A',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);
        });

        $context->setTenantId($this->tenantA->id);

        $this->storeA = Store::create([
            'name' => 'Warehouse Alpha Jakarta',
        ]);
        $context->setStoreId($this->storeA->id);

        $ownerRoleA = Role::firstOrCreate(
            ['name' => 'Owner', 'tenant_id' => $this->tenantA->id],
            ['display_name' => 'Owner', 'is_admin' => true]
        );

        $salesRoleA = Role::firstOrCreate(
            ['name' => 'Sales', 'tenant_id' => $this->tenantA->id],
            ['display_name' => 'Sales Specialist', 'is_admin' => false]
        );

        $this->ownerA = User::create([
            'name' => 'Alpha Owner',
            'email' => 'owner_alpha@example.com',
            'password' => bcrypt('password'),
            'role_id' => $ownerRoleA->id,
            'is_active' => true,
        ]);
        $this->ownerA->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

        $this->salesA = User::create([
            'name' => 'Alpha Sales Lead',
            'email' => 'sales_alpha@example.com',
            'password' => bcrypt('password'),
            'role_id' => $salesRoleA->id,
            'is_active' => true,
        ]);
        $this->salesA->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

        $this->customerA = Customer::create([
            'customer_code' => 'CUST-ALPHA-01',
            'name' => 'CV Sumber Rezeki Logistik',
            'customer_type' => 'COMPANY',
            'company_name' => 'CV Sumber Rezeki Logistik',
            'tax_id' => '02.345.678.9-123.000',
            'phone' => '+6281311223344',
            'email' => 'order@sumberrezeki.co.id',
            'is_active' => true,
        ]);

        CustomerCreditAccount::create([
            'tenant_id' => $this->tenantA->id,
            'customer_id' => $this->customerA->id,
            'credit_limit' => 50000000.00,
            'payment_terms_days' => 45,
            'grace_period_days' => 7,
            'credit_status' => CreditStatusEnum::ACTIVE,
            'reserved_exposure' => 0.00,
            'invoiced_exposure' => 0.00,
            'unapplied_credit_balance' => 0.00,
        ]);

        $category = Category::create([
            'category_name' => 'Heavy Logistics Supplies',
            'category_code' => 'HEAVY-LOG',
        ]);

        $this->productA = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Wooden Pallet Euro Grade',
            'product_code' => 'PLT-EURO-01',
            'product_price' => 150000.00,
            'is_active' => true,
            'status' => 'active',
        ]);

        $this->productB = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Industrial Stretch Film 500mm',
            'product_code' => 'STR-FILM-500',
            'product_price' => 85000.00,
            'is_active' => true,
            'status' => 'active',
        ]);

        // Inisialisasi Stok Fisik Awal via InventoryLedgerService
        $this->inventoryLedgerService->recordMovement(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            itemType: 'product',
            itemId: (int) $this->productA->id,
            quantityDelta: '100.0000',
            type: StockMovementType::InitialSync,
            unitCost: '110000.00',
            reason: 'Opening balance pallet',
            userId: $this->ownerA->id
        );

        $this->inventoryLedgerService->recordMovement(
            tenantId: (int) $this->tenantA->id,
            storeId: (int) $this->storeA->id,
            itemType: 'product',
            itemId: (int) $this->productB->id,
            quantityDelta: '50.0000',
            type: StockMovementType::InitialSync,
            unitCost: '60000.00',
            reason: 'Opening balance stretch film',
            userId: $this->ownerA->id
        );

        // Setup Tenant B
        $this->tenantB = $context->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'Logistics Central B',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);
        });

        $context->setTenantId($this->tenantB->id);

        $this->storeB = Store::create([
            'name' => 'Warehouse Beta Surabaya',
        ]);
        $context->setStoreId($this->storeB->id);

        $ownerRoleB = Role::firstOrCreate(
            ['name' => 'Owner', 'tenant_id' => $this->tenantB->id],
            ['display_name' => 'Owner', 'is_admin' => true]
        );

        $this->ownerB = User::create([
            'name' => 'Beta Owner',
            'email' => 'owner_beta@example.com',
            'password' => bcrypt('password'),
            'role_id' => $ownerRoleB->id,
            'is_active' => true,
        ]);
        $this->ownerB->stores()->attach($this->storeB->id, ['tenant_id' => $this->tenantB->id]);
    }

    public function test_full_sales_order_lifecycle_with_inventory_reservation_and_partial_fulfillments(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);
        $this->actingAs($this->ownerA);

        // 1. Buat Sales Order (DRAFT) untuk Product A (qty 30) dan Product B (qty 20)
        $so = $this->salesOrderService->createOrder([
            'customer_id' => $this->customerA->id,
            'store_id' => $this->storeA->id,
            'customer_po_number' => 'PO-SRL-2027-ALPHA',
            'payment_terms_days' => 45,
            'items' => [
                ['product_id' => $this->productA->id, 'quantity' => '30.0000', 'unit_price' => '145000.00'],
                ['product_id' => $this->productB->id, 'quantity' => '20.0000', 'unit_price' => '82000.00'],
            ],
        ], $this->ownerA);

        $this->assertSame(SalesOrderStatusEnum::DRAFT, $so->status);
        $this->assertSame(SalesOrderFulfillmentStatusEnum::UNFULFILLED, $so->fulfillment_status);

        // Stok fisik awal tetap 100 dan 50 (Draft belum mereservasi stok)
        $stockA0 = ProductStockBalance::where('store_id', $this->storeA->id)->where('product_id', $this->productA->id)->value('quantity');
        $stockB0 = ProductStockBalance::where('store_id', $this->storeA->id)->where('product_id', $this->productB->id)->value('quantity');
        $this->assertSame('100.0000', (string) $stockA0);
        $this->assertSame('50.0000', (string) $stockB0);

        // 2. Konfirmasi Sales Order (CONFIRMED) -> Reservasi stok dicatat pada baris SO
        $confirmedSO = $this->salesOrderService->confirmOrder($so, $this->ownerA);
        $this->assertSame(SalesOrderStatusEnum::CONFIRMED, $confirmedSO->status);

        $confirmedSO->load('lines');
        $lineA = $confirmedSO->lines->firstWhere('product_id', $this->productA->id);
        $lineB = $confirmedSO->lines->firstWhere('product_id', $this->productB->id);

        $this->assertSame('30.0000', (string) $lineA->reserved_qty);
        $this->assertSame('20.0000', (string) $lineB->reserved_qty);

        // 3. Partial Fulfillment Tahap 1: Fulfill 10 unit Product A
        $order1 = $this->salesOrderService->fulfillOrder($confirmedSO, [
            ['line_id' => $lineA->id, 'quantity' => '10.0000'],
        ], $this->ownerA);

        $this->assertInstanceOf(Order::class, $order1);
        $this->assertSame($confirmedSO->id, $order1->sales_order_id);

        // Verifikasi pemotongan stok aktual fisik M4: Product A menjadi 90 (100 - 10)
        $stockA1 = ProductStockBalance::where('store_id', $this->storeA->id)->where('product_id', $this->productA->id)->value('quantity');
        $this->assertSame('90.0000', (string) $stockA1);

        // Verifikasi mutasi dicatat pada ledger M4
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'product_id' => $this->productA->id,
            'type' => 'sale_deduction',
            'order_id' => $order1->id,
        ]);

        $confirmedSO->refresh();
        $this->assertSame(SalesOrderStatusEnum::PARTIALLY_FULFILLED, $confirmedSO->status);
        $this->assertSame(SalesOrderFulfillmentStatusEnum::PARTIALLY_FULFILLED, $confirmedSO->fulfillment_status);

        // 4. Partial Fulfillment Tahap 2: Fulfill sisa Product A (20) dan 15 unit Product B
        $order2 = $this->salesOrderService->fulfillOrder($confirmedSO, [
            ['line_id' => $lineA->id, 'quantity' => '20.0000'],
            ['line_id' => $lineB->id, 'quantity' => '15.0000'],
        ], $this->ownerA);

        // Verifikasi stok fisik M4: Product A menjadi 70 (90 - 20), Product B menjadi 35 (50 - 15)
        $stockA2 = ProductStockBalance::where('store_id', $this->storeA->id)->where('product_id', $this->productA->id)->value('quantity');
        $stockB2 = ProductStockBalance::where('store_id', $this->storeA->id)->where('product_id', $this->productB->id)->value('quantity');
        $this->assertSame('70.0000', (string) $stockA2);
        $this->assertSame('35.0000', (string) $stockB2);

        $lineA->refresh();
        $lineB->refresh();
        $this->assertSame('30.0000', (string) $lineA->fulfilled_qty);
        $this->assertSame('0.0000', (string) $lineA->reserved_qty);

        $this->assertSame('15.0000', (string) $lineB->fulfilled_qty);
        $this->assertSame('5.0000', (string) $lineB->reserved_qty);
        $this->assertSame('5.0000', (string) $lineB->getRemainingQty());

        // 5. Batalkan sisa 5 unit Product B
        $closedSO = $this->salesOrderService->cancelOrder($confirmedSO, $this->ownerA, 'Pelanggan meminta penutupan sisa pesanan');
        $this->assertSame(SalesOrderStatusEnum::CLOSED, $closedSO->status);

        $lineB->refresh();
        $this->assertSame('5.0000', (string) $lineB->cancelled_qty);
        $this->assertSame('0.0000', (string) $lineB->reserved_qty);
        $this->assertSame('0.0000', (string) $lineB->getRemainingQty());

        // Stok fisik Product B tidak berubah saat pembatalan sisa (tetap 35)
        $stockBFinal = ProductStockBalance::where('store_id', $this->storeA->id)->where('product_id', $this->productB->id)->value('quantity');
        $this->assertSame('35.0000', (string) $stockBFinal);
    }

    public function test_multi_tenant_isolation_on_sales_orders(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $soA = $this->salesOrderService->createOrder([
            'customer_id' => $this->customerA->id,
            'store_id' => $this->storeA->id,
            'items' => [
                ['product_id' => $this->productA->id, 'quantity' => '10.0000'],
            ],
        ], $this->ownerA);

        // Pindah ke sesi Tenant B
        $context->setTenantId($this->tenantB->id);
        $context->setStoreId($this->storeB->id);
        $this->actingAs($this->ownerB);

        // User Tenant B tidak boleh memiliki izin mengonfirmasi, memenuhi, membatalkan, atau melihat SO Tenant A
        $this->assertFalse($this->ownerB->can('view', $soA));
        $this->assertFalse($this->ownerB->can('confirm', $soA));
        $this->assertFalse($this->ownerB->can('fulfill', $soA));
        $this->assertFalse($this->ownerB->can('cancel', $soA));
    }

    public function test_livewire_sales_order_manager_component_renders_and_executes_actions(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);
        $this->actingAs($this->ownerA);

        $so = $this->salesOrderService->createOrder([
            'customer_id' => $this->customerA->id,
            'store_id' => $this->storeA->id,
            'customer_po_number' => 'PO-TEST-LW-999',
            'items' => [
                ['product_id' => $this->productA->id, 'quantity' => '10.0000', 'unit_price' => '150000.00'],
            ],
        ], $this->ownerA);

        Livewire::test(SalesOrderManager::class)
            ->assertStatus(200)
            ->assertSee($so->so_number)
            ->assertSee('PO-TEST-LW-999')
            // Buka modal detail
            ->call('viewOrder', $so->id)
            ->assertSet('showDetailModal', true)
            ->assertSet('selectedSOId', $so->id)
            // Konfirmasi order
            ->call('confirmOrder', $so->id)
            ->assertHasNoErrors()
            ->assertSee('BERHASIL dikonfirmasi')
            // Buka modal fulfill
            ->call('openFulfillModal', $so->id)
            ->assertSet('showFulfillModal', true)
            // Eksekusi fulfillment sebagian (4 unit)
            ->set("fulfillmentQuantities.{$so->lines->first()->id}", '4.0000')
            ->call('executeFulfillment')
            ->assertHasNoErrors()
            ->assertSee('Fulfillment berhasil diproses');

        $so->refresh();
        $this->assertSame(SalesOrderStatusEnum::PARTIALLY_FULFILLED, $so->status);

        $line = $so->lines->first();
        $this->assertSame('4.0000', (string) $line->fulfilled_qty);
        $this->assertSame('6.0000', (string) $line->reserved_qty);
    }
}
