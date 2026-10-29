<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Livewire\Sales\QuotationManager;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockBalance;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Sales\Enums\QuotationStatusEnum;
use App\Modules\Sales\Enums\SalesOrderStatusEnum;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\QuotationService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class QuotationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected QuotationService $quotationService;

    protected Tenant $tenantA;

    protected Store $storeA;

    protected User $ownerA;

    protected User $cashierA;

    protected Customer $customerA;

    protected Product $productA;

    // Tenant B for isolation testing
    protected Tenant $tenantB;

    protected Store $storeB;

    protected User $ownerB;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->quotationService = app(QuotationService::class);
        $context = app(TenantContext::class);

        // Setup Tenant A
        $this->tenantA = $context->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'Lifecycle Tenant A',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);
        });

        $context->setTenantId($this->tenantA->id);

        $this->storeA = Store::create(['name' => 'Store A']);
        $context->setStoreId($this->storeA->id);

        $roleOwner = Role::firstOrCreate(
            ['name' => 'Owner', 'tenant_id' => $this->tenantA->id],
            ['display_name' => 'Owner', 'is_admin' => true]
        );

        $roleKasir = Role::firstOrCreate(
            ['name' => 'Kasir', 'tenant_id' => $this->tenantA->id],
            ['display_name' => 'Kasir', 'is_admin' => false]
        );

        $this->ownerA = User::create([
            'name' => 'Owner A',
            'email' => 'owner_a@lifecycle.test',
            'password' => bcrypt('password'),
            'role_id' => $roleOwner->id,
            'is_active' => true,
        ]);
        $this->ownerA->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

        $this->cashierA = User::create([
            'name' => 'Kasir A',
            'email' => 'kasir_a@lifecycle.test',
            'password' => bcrypt('password'),
            'role_id' => $roleKasir->id,
            'is_active' => true,
        ]);
        $this->cashierA->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

        $this->customerA = Customer::create([
            'customer_code' => 'CUST-LIFECYCLE-A',
            'name' => 'PT Mandiri Sejahtera',
            'customer_type' => 'COMPANY',
            'phone' => '+628111222333',
            'is_active' => true,
        ]);

        $category = Category::create([
            'category_name' => 'Wholesale Packaging',
            'category_code' => 'WP',
        ]);

        $this->productA = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Karton Box Heavy Duty',
            'product_code' => 'BOX-HD-01',
            'product_price' => 25000.00,
            'is_active' => true,
            'status' => 'active',
        ]);

        // Setup Tenant B
        $this->tenantB = $context->runWithoutTenant(function () {
            return Tenant::create([
                'name' => 'Lifecycle Tenant B',
                'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
                'access_status' => Tenant::ACCESS_ACTIVE,
                'status' => 'active',
            ]);
        });

        $context->setTenantId($this->tenantB->id);
        $this->storeB = Store::create(['name' => 'Store B']);
        $context->setStoreId($this->storeB->id);

        $roleOwnerB = Role::firstOrCreate(
            ['name' => 'Owner', 'tenant_id' => $this->tenantB->id],
            ['display_name' => 'Owner', 'is_admin' => true]
        );

        $this->ownerB = User::create([
            'name' => 'Owner B',
            'email' => 'owner_b@lifecycle.test',
            'password' => bcrypt('password'),
            'role_id' => $roleOwnerB->id,
            'is_active' => true,
        ]);
        $this->ownerB->stores()->attach($this->storeB->id, ['tenant_id' => $this->tenantB->id]);

        // Re-set context back to Tenant A for test execution
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);
    }

    public function test_full_quotation_lifecycle_flow(): void
    {
        $this->actingAs($this->ownerA);

        // 1. Create Draft Quotation
        $quotation = $this->quotationService->createDraft([
            'customer_id' => $this->customerA->id,
            'issue_date' => Carbon::today()->toDateString(),
            'valid_until' => Carbon::today()->addDays(7)->toDateString(),
            'items' => [
                [
                    'product_id' => $this->productA->id,
                    'quantity' => '100.0000',
                    'unit_price' => '22000.00',
                    'discount_amount' => '0.00',
                    'tax_rate' => '11.00',
                ],
            ],
        ], $this->ownerA);

        $this->assertSame(QuotationStatusEnum::DRAFT, $quotation->status);

        // 2. Submit / Send
        $sent = $this->quotationService->submitForApprovalOrSend($quotation, $this->ownerA);
        $this->assertSame(QuotationStatusEnum::SENT, $sent->status);

        // 3. Customer Accepts
        $accepted = $this->quotationService->accept($sent, $this->ownerA);
        $this->assertSame(QuotationStatusEnum::ACCEPTED, $accepted->status);

        // 4. Convert to Sales Order
        $salesOrder = $this->quotationService->convertToSalesOrder($accepted, $this->ownerA);

        $this->assertInstanceOf(SalesOrder::class, $salesOrder);
        $this->assertSame(SalesOrderStatusEnum::DRAFT, $salesOrder->status);
        $this->assertSame($accepted->id, $salesOrder->quotation_id);
        $this->assertSame('2442000.00', (string) $salesOrder->total_amount);

        $accepted->refresh();
        $this->assertSame(QuotationStatusEnum::CONVERTED, $accepted->status);
        $this->assertSame($salesOrder->id, $accepted->converted_sales_order_id);
    }

    public function test_quotations_and_conversion_do_not_touch_stock_balances(): void
    {
        // Guardrail: Quotations MUST NOT reserve or deduct stock!
        $this->actingAs($this->ownerA);

        $initialBalanceCount = StockBalance::where('tenant_id', $this->tenantA->id)
            ->where('product_id', $this->productA->id)
            ->count();

        $quotation = $this->quotationService->createDraft([
            'customer_id' => $this->customerA->id,
            'items' => [
                [
                    'product_id' => $this->productA->id,
                    'quantity' => '500.0000',
                    'unit_price' => '20000.00',
                ],
            ],
        ], $this->ownerA);

        $accepted = $this->quotationService->accept($quotation, $this->ownerA);
        $this->quotationService->convertToSalesOrder($accepted, $this->ownerA);

        $afterBalanceCount = StockBalance::where('tenant_id', $this->tenantA->id)
            ->where('product_id', $this->productA->id)
            ->count();

        $this->assertSame($initialBalanceCount, $afterBalanceCount, 'Saldo stok tidak boleh terpengaruh oleh Quotation.');
    }

    public function test_multi_tenant_isolation_on_quotations(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $quotationA = $this->quotationService->createDraft([
            'customer_id' => $this->customerA->id,
            'items' => [
                [
                    'product_id' => $this->productA->id,
                    'quantity' => '10.0000',
                    'unit_price' => '25000.00',
                ],
            ],
        ], $this->ownerA);

        // Switch to Tenant B
        $context->setTenantId($this->tenantB->id);
        $context->setStoreId($this->storeB->id);
        $this->actingAs($this->ownerB);

        // Owner B must be forbidden to view Tenant A quotation
        $this->assertFalse($this->ownerB->can('view', $quotationA));
        $this->assertFalse($this->ownerB->can('approve', $quotationA));
        $this->assertFalse($this->ownerB->can('convert', $quotationA));
    }

    public function test_livewire_quotation_manager_renders_and_executes_conversion(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);
        $this->actingAs($this->ownerA);

        $quotation = $this->quotationService->createDraft([
            'customer_id' => $this->customerA->id,
            'items' => [
                [
                    'product_id' => $this->productA->id,
                    'quantity' => '20.0000',
                    'unit_price' => '25000.00',
                ],
            ],
        ], $this->ownerA);

        $this->quotationService->accept($quotation, $this->ownerA);

        Livewire::test(QuotationManager::class)
            ->assertStatus(200)
            ->assertSee($quotation->quotation_number)
            ->call('convertToSalesOrder', $quotation->id)
            ->assertHasNoErrors()
            ->assertSee('BERHASIL dikonversi');

        $quotation->refresh();
        $this->assertSame(QuotationStatusEnum::CONVERTED, $quotation->status);
        $this->assertNotNull($quotation->converted_sales_order_id);
    }
}
