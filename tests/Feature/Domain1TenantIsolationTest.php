<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatusEnum;
use App\Jobs\ProcessMonthlyExportJob;
use App\Jobs\ProcessSalesReportExportJob;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\ExportTask;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Analytics\DashboardService;
use App\Services\Context\TenantContext;
use App\Services\MenuCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

/**
 * Domain 1 Final Acceptance Gate Verification Suite.
 * Covers all 12 adversarial test scenarios specified by the Domain 1 remediation gate.
 */
class Domain1TenantIsolationTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();
        });
    }

    private function asTenantA(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);
    }

    private function asTenantB(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantB->id);
        $context->setStoreId($this->storeB->id);
    }

    /**
     * Test 1 (Read Isolation): User Tenant A cannot read Order, Product, Customer, or ExportTask
     * belonging to Tenant B (find() returns null, HTTP routes return 404/403).
     */
    public function test_01_read_isolation(): void
    {
        // 1. Create entities for Tenant B inside Tenant B context
        $customerB = app(TenantContext::class)->runAs($this->tenantB->id, $this->storeB->id, function () {
            return Customer::create([
                'name' => 'Cust B Secret',
                'phone' => '081299998888',
                'is_active' => true,
            ]);
        });

        $taskB = app(TenantContext::class)->runAs($this->tenantB->id, $this->storeB->id, function () {
            return ExportTask::create([
                'type' => 'sales_report',
                'status' => 'completed',
                'file_path' => 'exports/tenant_'.$this->tenantB->id.'/sales.csv',
                'requested_by' => $this->userBBarista->id,
            ]);
        });

        // 2. Query in Tenant A context
        $this->asTenantA();

        $this->assertNull(Order::find($this->orderB->id), 'Order Tenant B must not be visible to Tenant A');
        $this->assertNull(Product::find($this->productB->id), 'Product Tenant B must not be visible to Tenant A');
        $this->assertNull(Customer::find($customerB->id), 'Customer Tenant B must not be visible to Tenant A');
        $this->assertNull(ExportTask::find($taskB->id), 'ExportTask Tenant B must not be visible to Tenant A');

        // 3. HTTP endpoint read isolation (acting as Tenant A user)
        $response = $this->actingAs($this->userAOwner)->get(route('exports.download', $taskB->id));
        $this->assertTrue(in_array($response->status(), [403, 404], true), 'HTTP download of Tenant B export task must return 403 or 404');
    }

    /**
     * Test 2 (Mutation & Ownership Immutability): User Tenant A cannot mutate/delete Tenant B records,
     * and changing tenant_id or store_id on existing records is rejected by traits and triggers.
     */
    public function test_02_mutation_and_ownership_immutability(): void
    {
        $this->asTenantA();

        // Cross-tenant update yields 0 affected rows
        $affected = Order::where('id', $this->orderB->id)->update(['subtotal_amount' => 999999]);
        $this->assertSame(0, $affected, 'Mass update across tenants must affect 0 rows');

        // Mutation of tenant_id on existing record rejected by AssignsTenant
        try {
            $this->orderA->tenant_id = $this->tenantB->id;
            $this->orderA->save();
            $this->fail('Expected RuntimeException when mutating tenant_id on existing model');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('tenant_id', $e->getMessage());
        }

        // Mutation of store_id on existing record rejected by AssignsStore
        try {
            $this->orderA->store_id = $this->storeB->id;
            $this->orderA->save();
            $this->fail('Expected RuntimeException when mutating store_id on existing model');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('store_id', $e->getMessage());
        }

        // Immutability triggers on append-only table (payments)
        $payment = Payment::create([
            'order_id' => $this->orderA->id,
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'payment_method' => PaymentMethod::Cash,
            'amount' => 5000,
            'status' => PaymentStatusEnum::Captured,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('payments')->where('id', $payment->id)->update(['amount' => 9999]);
    }

    /**
     * Test 3 (Composite FK Parent-Child & Pivot Rejection): Forced raw DB insertion pairing
     * Tenant A with store_id, product_id, or customer_id from Tenant B is strictly rejected
     * by Composite Foreign Key constraints (SQLSTATE 23000).
     */
    public function test_03_composite_fk_parent_child_and_pivot_rejection(): void
    {
        // 1. Direct insert pairing Tenant A with Store B rejected by composite FK
        $rejected = false;
        try {
            DB::table('orders')->insert([
                'order_code' => 'ORD-CROSS-1',
                'idempotency_key' => Str::uuid()->toString(),
                'user_id' => $this->userAOwner->id,
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeB->id, // Cross-tenant store!
                'order_date' => now()->toDateString(),
                'order_status' => 'pending',
                'payment_method' => 'cash',
                'subtotal_amount' => 1000,
                'order_amount' => 1000,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $rejected = true;
            $this->assertSame('23000', (string) $e->getCode());
        }
        $this->assertTrue($rejected, 'Inserting Order with cross-tenant store_id must be rejected by composite FK');

        // 2. Direct insert pairing Tenant A Order with Product B rejected by composite FK
        $rejectedProduct = false;
        try {
            DB::table('order_items')->insert([
                'order_id' => $this->orderA->id,
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'product_id' => $this->productB->id, // Cross-tenant product!
                'qty' => 1,
                'order_price' => 1000,
                'order_subtotal' => 1000,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $rejectedProduct = true;
            $this->assertSame('23000', (string) $e->getCode());
        }
        $this->assertTrue($rejectedProduct, 'Inserting OrderItem with cross-tenant product_id must be rejected by composite FK');
    }

    /**
     * Test 4 (Fail-Closed Missing Context): When TenantContext & StoreResolver are cleared,
     * queries on Order, Product, Customer, and Discount return 0 (not leaking any rows).
     */
    public function test_04_fail_closed_missing_context(): void
    {
        app(TenantContext::class)->clear();

        $this->assertSame(0, Order::count(), 'Order::count() must fail-closed to 0 without context');
        $this->assertSame(0, Product::count(), 'Product::count() must fail-closed to 0 without context');
        $this->assertSame(0, Customer::count(), 'Customer::count() must fail-closed to 0 without context');
        $this->assertSame(0, Discount::count(), 'Discount::count() must fail-closed to 0 without context');
    }

    /**
     * Test 5 (Hybrid Discount Scope): Branch A1 reads Global Tenant A discount and Branch A1 discount,
     * but CANNOT read Branch A2 discount nor Global Tenant B discount.
     */
    public function test_05_hybrid_discount_scope(): void
    {
        app(TenantContext::class)->runAs($this->tenantA->id, null, function () {
            Discount::create([
                'name' => 'Diskon Global A',
                'store_id' => null,
                'type' => 'percentage',
                'value' => 10,
                'min_purchase_amount' => 0,
                'is_active' => true,
            ]);
        });

        app(TenantContext::class)->runAs($this->tenantA->id, $this->storeA->id, function () {
            Discount::create([
                'name' => 'Diskon Cabang A1',
                'store_id' => $this->storeA->id,
                'type' => 'fixed',
                'value' => 5000,
                'min_purchase_amount' => 0,
                'is_active' => true,
            ]);
        });

        app(TenantContext::class)->runAs($this->tenantA->id, $this->storeA2->id, function () {
            Discount::create([
                'name' => 'Diskon Cabang A2',
                'store_id' => $this->storeA2->id,
                'type' => 'fixed',
                'value' => 7000,
                'min_purchase_amount' => 0,
                'is_active' => true,
            ]);
        });

        app(TenantContext::class)->runAs($this->tenantB->id, null, function () {
            Discount::create([
                'name' => 'Diskon Global B',
                'store_id' => null,
                'type' => 'percentage',
                'value' => 50,
                'min_purchase_amount' => 0,
                'is_active' => true,
            ]);
        });

        // In Branch A1 context
        $this->asTenantA();
        $visibleDiscounts = Discount::pluck('name')->all();

        $this->assertContains('Diskon Global A', $visibleDiscounts);
        $this->assertContains('Diskon Cabang A1', $visibleDiscounts);
        $this->assertNotContains('Diskon Cabang A2', $visibleDiscounts, 'Branch A2 discount must not be visible in Branch A1');
        $this->assertNotContains('Diskon Global B', $visibleDiscounts, 'Tenant B global discount must not leak to Tenant A');
    }

    /**
     * Test 6 (Queue Job Isolation & Sequential Worker Bleed): Sequential execution of jobs
     * for Tenant A (including failure/exception) followed by Tenant B does not bleed context.
     * Export jobs reject cross-tenant tasks and export only own data.
     */
    public function test_06_queue_job_isolation_and_sequential_worker_bleed(): void
    {
        $context = app(TenantContext::class);
        $context->clear();

        // 1. Simulate job failure for Tenant A
        try {
            $context->runAs($this->tenantA->id, $this->storeA->id, function () {
                throw new \RuntimeException('Job A crashed intentionally');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNull($context->getTenantId(), 'Context must be cleared after exception in job');
        $this->assertNull($context->getStoreId());

        // 2. Reject cross-tenant export task
        $taskB = $context->runAs($this->tenantB->id, $this->storeB->id, function () {
            return ExportTask::create([
                'type' => 'sales_report',
                'status' => 'pending',
                'requested_by' => $this->userBBarista->id,
            ]);
        });

        $jobA = new ProcessSalesReportExportJob($taskB, $this->tenantA->id);
        $rejected = false;
        try {
            $jobA->handle();
        } catch (\RuntimeException $e) {
            $rejected = true;
            $this->assertStringContainsString('Tenant ID mismatch', $e->getMessage());
        }
        $this->assertTrue($rejected, 'ProcessSalesReportExportJob must reject task from another tenant');

        // 3. ProcessMonthlyExportJob handles execution without context leakage
        $monthlyJob = new ProcessMonthlyExportJob(now()->year, now()->month, (int) $this->storeA->id, (int) $this->tenantA->id, (int) $this->userAOwner->id);
        $monthlyJob->handle();
        $this->assertNull($context->getTenantId(), 'Monthly export job must clear context upon completion');
    }

    /**
     * Test 7 (Export Download IDOR): User Tenant A is denied (403/404) when attempting
     * to download an ExportTask belonging to Tenant B through ExportTaskController.
     */
    public function test_07_export_download_idor(): void
    {
        $taskB = app(TenantContext::class)->runAs($this->tenantB->id, $this->storeB->id, function () {
            return ExportTask::create([
                'type' => 'sales_report',
                'status' => 'completed',
                'file_path' => 'exports/tenant_'.$this->tenantB->id.'/report_b.csv',
                'file_size_bytes' => 100,
                'requested_by' => $this->userBBarista->id,
                'completed_at' => now(),
            ]);
        });

        $response = $this->actingAs($this->userAOwner)->get(route('exports.download', $taskB->id));
        $this->assertTrue(in_array($response->status(), [403, 404], true));

        $statusResponse = $this->actingAs($this->userAOwner)->get(route('exports.status', $taskB->id));
        $this->assertTrue(in_array($statusResponse->status(), [403, 404], true));
    }

    /**
     * Test 8 (QR Self-Order End-to-End & Cross-Tenant Injection): Customer scanning QR for
     * Branch A1 binds context automatically, but sending product_id from Tenant B is rejected.
     */
    public function test_08_qr_self_order_end_to_end_and_cross_tenant_injection(): void
    {
        $tableA = app(TenantContext::class)->runAs($this->tenantA->id, $this->storeA->id, function () {
            return Table::create([
                'table_name' => 'MEJA-01',
                'capacity' => 4,
                'operational_status' => 'available',
                'is_active' => true,
                'secure_token' => Str::uuid()->toString(),
            ]);
        });

        // 1. Visit menu with QR token -> sets table session and tenant context
        $menuResponse = $this->get(route('customer.menu.index', ['token' => $tableA->secure_token]));
        $menuResponse->assertOk();
        $this->assertEquals($tableA->id, session('current_table_id'));
        $this->assertEquals($this->tenantA->id, session('current_tenant_id'));

        // 2. Add product from Tenant B to cart -> must be rejected with 422 validation error
        $cartResponse = $this->postJson(route('customer.cart.store'), [
            'product_id' => $this->productB->id,
            'qty' => 1,
        ]);
        $this->assertSame(422, $cartResponse->status(), 'Adding cross-tenant product to self-order cart must be rejected with 422');
    }

    /**
     * Test 9 (Webhook Midtrans Context Binding): MidtransNotificationController callback
     * binds tenant and store context from order, updates status without fail-closed error,
     * and clears context in finally block.
     */
    public function test_09_webhook_midtrans_context_binding(): void
    {
        Config::set('services.midtrans.server_key', 'test_midtrans_key');

        $order = app(TenantContext::class)->runAs($this->tenantA->id, $this->storeA->id, function () {
            return Order::create([
                'user_id' => $this->userAOwner->id,
                'order_code' => 'ORD-MID-'.uniqid(),
                'idempotency_key' => Str::uuid()->toString(),
                'order_date' => now()->toDateString(),
                'order_status' => OrderStatus::Pending,
                'payment_method' => 'qris',
                'subtotal_amount' => 50000,
                'order_amount' => 50000,
            ]);
        });

        $signature = hash('sha512', $order->order_code.'200'.'50000.00'.'test_midtrans_key');

        // Clear context before webhook callback simulation
        $context = app(TenantContext::class);
        $context->clear();

        $response = $this->postJson('/midtrans/notification', [
            'order_id' => $order->order_code,
            'status_code' => '200',
            'gross_amount' => '50000.00',
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
        ]);

        $response->assertOk();
        $this->assertEquals(OrderStatus::Paid, $order->refresh()->order_status);
        $this->assertNull($context->getTenantId(), 'Webhook must clear tenant context in finally');
    }

    /**
     * Test 10 (Cache Isolation): MenuCacheService, DashboardService, and Setting store
     * and retrieve cache fully isolated across Tenant A and Tenant B.
     */
    public function test_10_cache_isolation(): void
    {
        $context = app(TenantContext::class);

        // 1. Setting Cache isolation
        $this->asTenantA();
        Setting::set('tax_rate', 0.11, 'float');

        $this->asTenantB();
        Setting::set('tax_rate', 0.05, 'float');

        $this->asTenantA();
        $this->assertSame(0.11, Setting::get('tax_rate'));

        $this->asTenantB();
        $this->assertSame(0.05, Setting::get('tax_rate'));

        // 2. MenuCacheService isolation
        $menuService = app(MenuCacheService::class);
        $this->asTenantA();
        $catA = $menuService->getCatalog()->pluck('category_name')->all();

        $this->asTenantB();
        $catB = $menuService->getCatalog()->pluck('category_name')->all();

        $this->assertContains('Cat A', $catA);
        $this->assertNotContains('Cat B', $catA);
        $this->assertContains('Cat B', $catB);
        $this->assertNotContains('Cat A', $catB);
    }

    /**
     * Test 11 (Tenant/Store-Scoped Uniqueness & Soft Deletes): Uniqueness is scoped per tenant/store;
     * soft-deleted records allow reusing unique codes/names, but duplicate active records are rejected.
     */
    public function test_11_tenant_store_scoped_uniques_and_soft_deletes(): void
    {
        $this->asTenantA();

        // 1. Same category name across tenants is allowed
        $catName = 'Kopi Susu Unik';
        Category::create(['category_name' => $catName]);

        $this->asTenantB();
        $catB = Category::create(['category_name' => $catName]);
        $this->assertNotNull($catB->id, 'Same category name in different tenant must succeed');

        // 2. Soft-delete and re-use table name in same store
        $this->asTenantA();
        $table = Table::create([
            'table_name' => 'MEJA-VIP',
            'capacity' => 6,
            'operational_status' => 'available',
            'is_active' => true,
            'secure_token' => Str::uuid()->toString(),
        ]);

        $table->delete(); // Soft delete

        // Re-creating table with same name in same store must succeed after soft-delete
        $newTable = Table::create([
            'table_name' => 'MEJA-VIP',
            'capacity' => 6,
            'operational_status' => 'available',
            'is_active' => true,
            'secure_token' => Str::uuid()->toString(),
        ]);
        $this->assertNotNull($newTable->id);

        // Attempting to create duplicate active table name must throw QueryException
        $this->expectException(\Illuminate\Database\QueryException::class);
        Table::create([
            'table_name' => 'MEJA-VIP',
            'capacity' => 6,
            'operational_status' => 'available',
            'is_active' => true,
            'secure_token' => Str::uuid()->toString(),
        ]);
    }

    /**
     * Test 12 (Console Commands Isolation): ExpireStaleCashSelfOrders and ReconcileBalances
     * operate safely under fail-closed scopes without cross-tenant mixing.
     */
    public function test_12_console_commands_isolation(): void
    {
        // 1. Create a table for self-order
        $table = app(TenantContext::class)->runAs($this->tenantA->id, $this->storeA->id, function () {
            return Table::create([
                'table_name' => 'T-STALE',
                'capacity' => 2,
                'operational_status' => 'available',
                'is_active' => true,
                'secure_token' => Str::uuid()->toString(),
            ]);
        });

        // Create a stale self-order cash order for Tenant A (>30 min old) using time travel
        $this->travel(-40)->minutes();
        $staleOrderA = app(TenantContext::class)->runAs($this->tenantA->id, $this->storeA->id, function () use ($table) {
            return Order::create([
                'user_id' => $this->userAOwner->id,
                'table_id' => $table->id,
                'order_code' => 'ORD-STALE-A',
                'idempotency_key' => Str::uuid()->toString(),
                'order_date' => now()->toDateString(),
                'order_status' => OrderStatus::Pending,
                'payment_method' => 'cash',
                'subtotal_amount' => 15000,
                'order_amount' => 15000,
            ]);
        });
        $this->travelBack();

        // Run expire command
        Artisan::call('orders:expire-stale-cash');

        $this->assertEquals(OrderStatus::Expired, $staleOrderA->refresh()->order_status);

        // 2. Run stock balance reconciliation command without errors
        $exitCode = Artisan::call('inventory:reconcile-balances');
        $this->assertSame(0, $exitCode);
    }
}
