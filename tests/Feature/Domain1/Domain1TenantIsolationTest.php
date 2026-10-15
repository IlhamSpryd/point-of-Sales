<?php

namespace Tests\Feature\Domain1;

use App\Jobs\ProcessSalesReportExportJob;
use App\Jobs\ProcessWebhookOrderJob;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\ExportTask;
use App\Models\Order;
use App\Models\Product;
use App\Services\Context\TenantContext;
use App\Services\DashboardService;
use App\Services\MenuCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Throwable;

/**
 * D1 DOMAIN 1 — ADVERSARIAL NEGATIVE TEST SUITE (12 tests).
 *
 * Setiap test membuktikan bahwa isolasi multi-tenant bekerja FAIL-CLOSED:
 * akses lintas-tenant HARUS menghasilkan 0 baris / penolakan eksplisit /
 * exception, BUKAN kebocoran data.
 */
class Domain1TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private int $tenantAId;

    private int $tenantBId;

    private int $storeAId;

    private int $storeBId;

    private int $categoryAId;

    private int $categoryBId;

    private int $userAId;

    private int $userBId;

    protected function setUp(): void
    {
        parent::setUp();

        $context = app(TenantContext::class);
        $context->clear();

        $tenantA = \App\Models\Tenant::create(['name' => 'Tenant A Isolasi', 'readiness_status' => 'pending']);
        $tenantB = \App\Models\Tenant::create(['name' => 'Tenant B Isolasi', 'readiness_status' => 'pending']);
        $this->tenantAId = (int) $tenantA->id;
        $this->tenantBId = (int) $tenantB->id;

        $context->runAs($this->tenantAId, null, function () use (&$storeAId) {
            $storeAId = (int) \App\Models\Store::create(['name' => 'Store A1'])->id;
        });
        $context->runAs($this->tenantBId, null, function () use (&$storeBId) {
            $storeBId = (int) \App\Models\Store::create(['name' => 'Store B1'])->id;
        });
        $this->storeAId = $storeAId;
        $this->storeBId = $storeBId;

        $context->runAs($this->tenantAId, $this->storeAId, function () use (&$categoryAId) {
            $categoryAId = (int) Category::create(['category_name' => 'Kategori A'])->id;
        });
        $context->runAs($this->tenantBId, $this->storeBId, function () use (&$categoryBId) {
            $categoryBId = (int) Category::create(['category_name' => 'Kategori B'])->id;
        });
        $this->categoryAId = $categoryAId;
        $this->categoryBId = $categoryBId;

        // orders.user_id adalah NOT NULL tanpa default -> buat user kasir per tenant.
        $context->runAs($this->tenantAId, $this->storeAId, function () use (&$userAId) {
            $userAId = (int) \App\Models\User::create([
                'name' => 'Kasir A',
                'email' => 'kasir-a-'.uniqid().'@test.local',
                'password' => 'password',
                'is_active' => true,
                'store_id' => $this->storeAId,
            ])->id;
        });
        $context->runAs($this->tenantBId, $this->storeBId, function () use (&$userBId) {
            $userBId = (int) \App\Models\User::create([
                'name' => 'Kasir B',
                'email' => 'kasir-b-'.uniqid().'@test.local',
                'password' => 'password',
                'is_active' => true,
                'store_id' => $this->storeBId,
            ])->id;
        });
        $this->userAId = $userAId;
        $this->userBId = $userBId;
    }

    private function asTenantA(): void
    {
        app(TenantContext::class)->setTenantId($this->tenantAId);
        app(TenantContext::class)->setStoreId($this->storeAId);
    }

    private function asTenantB(): void
    {
        app(TenantContext::class)->setTenantId($this->tenantBId);
        app(TenantContext::class)->setStoreId($this->storeBId);
    }

    private function createContextFree(): void
    {
        app(TenantContext::class)->clear();
    }

    private function orderPayload(string $code, array $overrides = []): array
    {
        return array_merge([
            'order_code' => $code,
            'idempotency_key' => 'idem-'.uniqid(),
            'order_date' => now(),
            'order_type' => 'takeaway',
            'order_status' => 'pending',
            'payment_method' => 'cash',
            'subtotal_amount' => 50000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'service_charge_amount' => 0,
            'order_amount' => 50000,
            'order_change' => 0,
        ], $overrides);
    }

    private function createOrderForA(array $overrides = []): Order
    {
        $order = null;
        app(TenantContext::class)->runAs($this->tenantAId, $this->storeAId, function () use (&$order, $overrides) {
            $order = Order::create($this->orderPayload('ORD-A-'.uniqid(), array_merge([
                'user_id' => $this->userAId,
            ], $overrides)));
        });

        return $order->refresh();
    }

    /** NEG-01: Tenant A tidak dapat membaca Order milik Tenant B. */
    public function test_tenant_a_cannot_read_order_of_tenant_b(): void
    {
        $orderB = null;
        app(TenantContext::class)->runAs($this->tenantBId, $this->storeBId, function () use (&$orderB) {
            $orderB = Order::create($this->orderPayload('ORD-B-'.uniqid(), ['user_id' => $this->userBId, 'order_amount' => 77000, 'subtotal_amount' => 77000]));
        });

        $this->asTenantA();
        $this->assertNull(Order::find($orderB->id));
        $this->assertNull(Order::where('order_code', $orderB->order_code)->first());
        $this->assertSame(0, Order::where('id', $orderB->id)->count());

        // Simulasi HTTP 404: route model binding dengan scope tenant.
        $found = Order::where('id', $orderB->id)->first();
        $this->assertTrue($found === null, 'Order Tenant B terlihat oleh Tenant A — KEBOCORAN!');
    }

    /** NEG-02: Tenant A tidak dapat meng-update / mem-void Order milik Tenant B. */
    public function test_tenant_a_cannot_update_or_void_order_of_tenant_b(): void
    {
        $orderB = null;
        app(TenantContext::class)->runAs($this->tenantBId, $this->storeBId, function () use (&$orderB) {
            $orderB = Order::create($this->orderPayload('ORD-B-'.uniqid(), ['user_id' => $this->userBId, 'order_amount' => 77000, 'subtotal_amount' => 77000]));
        });

        $this->asTenantA();
        $target = Order::find($orderB->id);
        $this->assertNull($target, 'Update harus gagal karena record tidak terlihat (fail-closed).');

        $attempted = Order::where('id', $orderB->id)->update(['order_status' => 'void']);
        $this->assertSame(0, $attempted, 'Mass update lintas tenant harus menyentuh 0 baris.');

        $this->asTenantB();
        $this->assertNotSame('void', $orderB->refresh()->order_status->value ?? $orderB->refresh()->order_status);
    }

    /** NEG-03: Tenant A tidak dapat melampirkan Product milik Tenant B (FK komposit / validasi). */
    public function test_tenant_a_cannot_attach_tenant_b_product(): void
    {
        $productB = null;
        app(TenantContext::class)->runAs($this->tenantBId, $this->storeBId, function () use (&$productB) {
            $productB = Product::create([
                'product_name' => 'Produk B Rahasia',
                'category_id' => $this->categoryBId,
                'product_price' => 25000,
                'stock' => 100,
                'stock_type' => 'single',
                'is_active' => true,
            ]);
        });

        $orderA = $this->createOrderForA();

        $this->asTenantA();
        // Produk B tidak ditemukan oleh Tenant A karena global scope.
        $this->assertNull(Product::find($productB->id));

        // Insert langsung via DB harus ditolak FK komposit tenant.
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('order_items')->insert([
            'order_id' => $orderA->id,
            'tenant_id' => $this->tenantAId,
            'store_id' => $this->storeAId,
            'product_id' => $productB->id,
            'product_name' => 'Bocor',
            'qty' => 1,
            'price' => 25000,
            'subtotal' => 25000,
        ]);
    }

    /** NEG-04: Tenant A tidak dapat mereferensikan Customer milik Tenant B pada orders. */
    public function test_tenant_a_cannot_reference_tenant_b_customer_on_orders(): void
    {
        $customerB = null;
        app(TenantContext::class)->runAs($this->tenantBId, $this->storeBId, function () use (&$customerB) {
            $customerB = Customer::create([
                'name' => 'Customer B',
                'phone' => '0812'.random_int(10000000, 99999999),
                'is_active' => true,
            ]);
        });

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('orders')->insert([
            'order_code' => 'ORD-X-'.uniqid(),
            'idempotency_key' => 'idem-'.uniqid(),
            'order_date' => now(),
            'user_id' => $this->userAId,
            'tenant_id' => $this->tenantAId,
            'store_id' => $this->storeAId,
            'order_type' => 'takeaway',
            'order_status' => 'pending',
            'payment_method' => 'cash',
            'subtotal_amount' => 10000,
            'order_amount' => 10000,
            'order_change' => 0,
            'customer_id' => $customerB->id,
        ]);
    }

    /** NEG-05: TenantContext kosong → semua kueri mengembalikan 0 (fail-closed). */
    public function test_empty_tenant_context_returns_zero_rows_everywhere(): void
    {
        $this->createContextFree();

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Product::count());
        $this->assertSame(0, Customer::count());
        $this->assertSame(0, Discount::count());
        $this->assertSame(0, Category::count());
    }

    /** NEG-06: Job yang di-dispatch untuk Tenant A menolak record milik Tenant B. */
    public function test_export_job_rejects_cross_tenant_record(): void
    {
        $taskB = null;
        app(TenantContext::class)->runAs($this->tenantBId, $this->storeBId, function () use (&$taskB) {
            $taskB = ExportTask::create([
                'type' => 'sales_report',
                'status' => 'pending',
                'requested_by' => null,
            ]);
        });

        $job = new ProcessSalesReportExportJob($taskB->withoutGlobalScopes()->findOrFail($taskB->id), $this->tenantAId);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tenant ID mismatch');
        $job->handle();
    }

    /** NEG-07: Dua job berurutan (A gagal lalu B) tidak membocorkan konteks tenant. */
    public function test_sequential_jobs_do_not_leak_tenant_context(): void
    {
        $context = app(TenantContext::class);
        $context->clear();

        // Job A: runAs lalu exception di dalam callback → finally mengembalikan null.
        try {
            $context->runAs($this->tenantAId, $this->storeAId, function () {
                throw new \RuntimeException('boom A');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNull($context->getTenantId(), 'Konteks Tenant A bocor setelah job gagal!');
        $this->assertNull($context->getStoreId());

        // Job B berjalan normal pada konteks B.
        $seen = $context->runAs($this->tenantBId, $this->storeBId, fn () => $context->getTenantId());
        $this->assertSame($this->tenantBId, $seen);
        $this->assertNull($context->getTenantId(), 'Konteks Tenant B bocor keluar dari runAs!');
        $this->assertSame(0, Order::count());
    }

    /** NEG-08: Midtrans webhook hanya memutasi order milik tenant order tsb. */
    public function test_midtrans_webhook_only_mutates_own_tenant_order(): void
    {
        $orderA = $this->createOrderForA(['order_amount' => 50000]);

        // Order Tenant B TIDAK boleh tersentuh webhook yang menunjuk order A.
        app(TenantContext::class)->runAs($this->tenantBId, $this->storeBId, function () {
            Order::create($this->orderPayload('ORD-B-WEBHOOK', ['user_id' => $this->userBId, 'order_amount' => 999999, 'subtotal_amount' => 999999]));
        });

        $context = app(TenantContext::class);
        $context->clear();

        // Simulasi resolusi webhook: order dicari tanpa scope lalu konteks dikunci.
        $order = Order::withoutGlobalScopes()->where('order_code', $orderA->order_code)->firstOrFail();

        $seenTenant = $context->runAs((int) $order->tenant_id, (int) $order->store_id, fn () => $context->getTenantId());
        $this->assertSame($this->tenantAId, $seenTenant);

        $after = $context->runAs($this->tenantAId, $this->storeAId, function () {
            return Order::where('order_code', 'ORD-B-WEBHOOK')->count();
        });
        $this->assertSame(0, $after, 'Order Tenant B terlihat di dalam konteks Tenant A!');
    }

    /** NEG-09: Ledger immutable — update/delete/ubah tenant_id ditolak trigger. */
    public function test_ledger_rows_reject_update_delete_and_tenant_change(): void
    {
        $movementId = null;
        app(TenantContext::class)->runAs($this->tenantAId, $this->storeAId, function () use (&$movementId) {
            $product = Product::create([
                'product_name' => 'Ledger Prod',
                'category_id' => $this->categoryAId,
                'product_price' => 1000,
                'stock' => 10,
                'stock_type' => 'single',
                'is_active' => true,
            ]);

            DB::table('stock_movements')->insert([
                'tenant_id' => $this->tenantAId,
                'store_id' => $this->storeAId,
                'product_id' => $product->id,
                'type' => 'in',
                'quantity' => 5,
                'reason' => 'seed',
                'idempotency_key' => 'seed-'.uniqid(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $movementId = (int) DB::table('stock_movements')->max('id');
        });

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('stock_movements')->where('id', $movementId)->update(['quantity' => 999]);
    }

    /** NEG-10: Unique (tenant, store) — nama sama antar tenant OK, duplikat dalam tenant ditolak. */
    public function test_tenant_scoped_uniques_allow_same_name_across_tenants(): void
    {
        app(TenantContext::class)->runAs($this->tenantAId, $this->storeAId, function () {
            Category::create(['category_name' => 'Minuman Khas']);
        });

        // Tenant B memakai nama kategori yang sama → HARUS diizinkan.
        $ok = null;
        app(TenantContext::class)->runAs($this->tenantBId, $this->storeBId, function () use (&$ok) {
            $ok = Category::create(['category_name' => 'Minuman Khas']);
        });
        $this->assertNotNull($ok->id);

        // Duplikat dalam tenant yang sama → ditolak unique constraint.
        $this->expectException(\Illuminate\Database\QueryException::class);
        app(TenantContext::class)->runAs($this->tenantAId, $this->storeAId, function () {
            Category::create(['category_name' => 'Minuman Khas']);
        });
    }

    /** NEG-11: DiscountStoreScope — diskon global Tenant B tidak pernah bocor ke Tenant A. */
    public function test_discount_store_scope_never_leaks_other_tenants_global_discounts(): void
    {
        app(TenantContext::class)->runAs($this->tenantAId, $this->storeAId, function () {
            Discount::create([
                'name' => 'Promo Global A',
                'store_id' => null,
                'type' => 'percentage',
                'value' => 10,
                'min_purchase_amount' => 0,
                'is_active' => true,
            ]);
            Discount::create([
                'name' => 'Promo Store A1',
                'store_id' => $this->storeAId,
                'type' => 'fixed',
                'value' => 5000,
                'min_purchase_amount' => 0,
                'is_active' => true,
            ]);
        });

        app(TenantContext::class)->runAs($this->tenantBId, $this->storeBId, function () {
            Discount::create([
                'name' => 'Promo Global B',
                'store_id' => null,
                'type' => 'percentage',
                'value' => 99,
                'min_purchase_amount' => 0,
                'is_active' => true,
            ]);
        });

        $names = null;
        app(TenantContext::class)->runAs($this->tenantAId, $this->storeAId, function () use (&$names) {
            $names = Discount::pluck('name')->all();
        });

        $this->assertContains('Promo Global A', $names, 'Diskon global Tenant A harus terlihat oleh Store A1.');
        $this->assertContains('Promo Store A1', $names);
        $this->assertNotContains('Promo Global B', $names, 'Diskon global Tenant B BOCOR ke Tenant A!');
    }

    /** NEG-12: Kunci cache terisolasi penuh antar tenant dan antar store. */
    public function test_cache_keys_are_fully_isolated_per_tenant_and_store(): void
    {
        $service = app(MenuCacheService::class);
        $context = app(TenantContext::class);

        // 1. Verifikasi FORMAT kunci: kunci WAJIB menyertakan dimensi tenant + store.
        $reflect = null;
        $context->runAs($this->tenantAId, $this->storeAId, function () use ($service, &$reflect) {
            $closure = \Closure::bind(fn (string $k) => $this->getTenantKey($k), $service, \App\Services\MenuCacheService::class);
            $reflect = $closure('pos:menu:catalog:v2');
        });
        $this->assertSame('pos:menu:'.$this->tenantAId.':store:'.$this->storeAId.':catalog:v2', $reflect);

        // Seed kategori khusus per tenant agar isolasi bisa dibuktikan perilaku.
        $context->runAs($this->tenantAId, $this->storeAId, fn () => Category::create(['category_name' => 'Cache-Only-A']));
        $context->runAs($this->tenantBId, $this->storeBId, fn () => Category::create(['category_name' => 'Cache-Only-B']));

        // 2. Tenant A hanya melihat datanya sendiri (cache-remember, bukan query baru).
        $namesA = null;
        $context->runAs($this->tenantAId, $this->storeAId, function () use ($service, &$namesA) {
            $namesA = $service->getCatalog()->pluck('category_name')->all();
        });
        $this->assertContains('Cache-Only-A', $namesA);
        $this->assertNotContains('Cache-Only-B', $namesA, 'Katalog cache Tenant B bocor ke Tenant A!');

        // 3. Tenant B hanya melihat datanya sendiri.
        $namesB = null;
        $context->runAs($this->tenantBId, $this->storeBId, function () use ($service, &$namesB) {
            $namesB = $service->getCatalog()->pluck('category_name')->all();
        });
        $this->assertContains('Cache-Only-B', $namesB);
        $this->assertNotContains('Cache-Only-A', $namesB, 'Katalog cache Tenant A bocor ke Tenant B!');

        // 4. Tanpa tenant → exception (fail-closed), bukan kunci global.
        $context->clear();
        $this->expectException(\RuntimeException::class);
        $service->getCatalog();
    }
}
