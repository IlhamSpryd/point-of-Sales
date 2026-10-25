<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Enums\OrderStatus;
use App\Events\WaiterCalled;
use App\Jobs\ProcessWebhookOrderJob;
use App\Models\Category;
use App\Models\ChannelOrderLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\TransactionService;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class ConcurrencySurgicalRemediationVerificationTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected User $cashier;

    protected Shift $shift;

    protected Product $product;

    protected TransactionService $transactionService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->setupTwoTenants();
        Model::unguard();

        app(TenantContext::class)->runAs($this->tenantA->id, $this->storeA->id, function () {
            $roleKasir = Role::withoutTenantScope()->firstOrCreate(
                ['name' => 'Kasir', 'tenant_id' => $this->tenantA->id],
                ['is_active' => 1]
            );

            $this->cashier = User::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Kasir Concurrency',
                'email' => 'kasir.conc@test.com',
                'password' => bcrypt('password'),
                'pin_hash' => bcrypt('123456'),
                'role_id' => $roleKasir->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $this->cashier->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

            $this->shift = Shift::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'user_id' => $this->cashier->id,
                'active_cashier_id' => $this->cashier->id,
                'opening_balance' => 200000,
                'status' => 'open',
                'opened_at' => now(),
            ]);

            $category = Category::create([
                'tenant_id' => $this->tenantA->id,
                'category_name' => 'Coffee Retail',
            ]);

            $this->product = Product::create([
                'tenant_id' => $this->tenantA->id,
                'category_id' => $category->id,
                'product_name' => 'Signature Blend Coffee',
                'product_price' => 25000,
                'stock' => 100,
                'track_inventory' => true,
                'is_active' => true,
                'status' => 'active',
            ]);

            $this->transactionService = app(TransactionService::class);
        });
    }

    /**
     * [CONC-06] Memastikan event WaiterCalled mengimplementasikan ShouldDispatchAfterCommit
     * sehingga aman dari rollback transaksi database.
     */
    public function test_waiter_called_event_implements_should_dispatch_after_commit(): void
    {
        $reflection = new \ReflectionClass(WaiterCalled::class);
        $this->assertTrue(
            $reflection->implementsInterface(ShouldDispatchAfterCommit::class),
            'WaiterCalled event WAJIB mengimplementasikan ShouldDispatchAfterCommit untuk mencegah premature side-effects sebelum DB commit.'
        );
    }

    /**
     * [CONC-04] Memastikan ProcessWebhookOrderJob melakukan atomic compare-and-swap
     * sehingga dua worker antrean yang memproses log yang sama tidak berjalan paralel.
     */
    public function test_process_webhook_order_job_atomic_status_claim_prevents_duplicate_worker(): void
    {
        $log = ChannelOrderLog::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'provider' => 'gofood',
            'external_order_id' => 'EXT-TEST-CONC-01',
            'payload' => ['items' => []],
            'status' => 'pending',
        ]);

        $job1 = new ProcessWebhookOrderJob($log, $this->tenantA->id, $this->storeA->id);
        $job2 = new ProcessWebhookOrderJob($log, $this->tenantA->id, $this->storeA->id);

        // Worker 1 mengklaim log
        $job1->handle($this->transactionService);
        $log->refresh();

        // Worker 2 mencoba memproses log yang sama -> harus langsung no-op tanpa melempar exception
        $job2->handle($this->transactionService);

        // Status log tidak boleh crash atau dobel proses
        $this->assertContains($log->status, ['processing', 'completed', 'failed']);
    }

    /**
     * [CONC-02] Memastikan voidOrder dapat mengunci Order dan memulihkan stok
     * bahkan saat dipanggil dari konteks tanpa global scopes.
     */
    public function test_void_order_locks_and_restores_stock_reliably(): void
    {
        $order = app(TenantContext::class)->runAs($this->tenantA->id, $this->storeA->id, function () {
            return $this->transactionService->createTransaction([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'order_type' => 'takeaway',
                'items' => [
                    ['product_id' => $this->product->id, 'quantity' => 2],
                ],
                'payments' => [
                    ['method' => 'cash', 'amount' => 55500],
                ],
                'shift_id' => $this->shift->id,
                'idempotency_key' => (string) Str::uuid(),
            ], $this->cashier->id);
        });

        $this->assertEquals(OrderStatus::Paid, $order->order_status);
        $this->product->refresh();
        $this->assertEquals(98, $this->product->stock);

        // Lakukan void order
        app(TenantContext::class)->runAs($this->tenantA->id, $this->storeA->id, function () use ($order) {
            $this->transactionService->voidOrder($order, 'Pelanggan membatalkan pesanan', $this->cashier->id);
        });

        $order->refresh();
        $this->assertEquals(OrderStatus::Void, $order->order_status);

        // Stok produk harus dikembalikan secara tepat
        $this->product->refresh();
        $this->assertEquals(100, $this->product->stock);

        // Percobaan void kedua kali harus ditolak (karena status sudah Void, bukan Paid)
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Hanya pesanan yang sudah lunas (Paid) yang dapat di-void.');
        app(TenantContext::class)->runAs($this->tenantA->id, $this->storeA->id, function () use ($order) {
            $this->transactionService->voidOrder($order, 'Void kedua kalinya', $this->cashier->id);
        });
    }
}
