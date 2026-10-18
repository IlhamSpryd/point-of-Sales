<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatusEnum;
use App\Models\Category;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\SystemUserResolver;
use App\Services\TransactionService;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

/**
 * WebhookVsReconcileVsVoidRaceTest
 *
 * Menguji kepatuhan terhadap:
 * - AC-2.3: Pengiriman 5 webhook Midtrans settlement identik secara berurutan/paralel
 *           hanya menghasilkan 1 settlement payment dan 1 kali pemotongan stok.
 * - AC-2.4: Percobaan voidOrder oleh kasir terhadap order yang memiliki PaymentAttempt
 *           berstatus unknown_timeout ditolak jika status di Midtrans telah settlement;
 *           order dipromosikan ke Paid dan Payment dicatat.
 * - Concurrency & Idempotency: Transisi monotonik dan pencegahan double-billing / double stock deduction.
 */
class WebhookVsReconcileVsVoidRaceTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected User $cashier;

    protected Device $device;

    protected Shift $shift;

    protected Product $product;

    protected User $systemUser;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        Config::set('services.midtrans.server_key', 'test_server_key_race');
        Config::set('services.midtrans.is_production', false);

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();
            Model::unguard();

            $this->device = Device::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'device_name' => 'POS Race Terminal',
                'device_code' => 'POS-RACE-01',
                'device_type' => Device::TYPE_POS_TERMINAL,
                'device_fingerprint' => 'fp-race-01',
                'status' => Device::STATUS_PAIRED,
                'is_active' => true,
            ]);

            $roleKasir = Role::withoutTenantScope()->firstOrCreate(
                ['name' => 'Kasir', 'tenant_id' => $this->tenantA->id],
                ['is_active' => 1]
            );

            $this->cashier = User::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Kasir Race',
                'email' => 'kasir.race@test.com',
                'password' => bcrypt('password'),
                'pin_hash' => bcrypt('123456'),
                'role_id' => $roleKasir->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $this->cashier->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

            $this->systemUser = SystemUserResolver::selfOrder($this->tenantA->id);

            $this->shift = Shift::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'user_id' => $this->cashier->id,
                'device_id' => $this->device->id,
                'active_cashier_id' => $this->cashier->id,
                'opening_balance' => 150000,
                'status' => 'open',
                'opened_at' => now(),
            ]);

            $category = Category::create([
                'tenant_id' => $this->tenantA->id,
                'category_name' => 'Race Test Category',
            ]);

            $this->product = Product::create([
                'tenant_id' => $this->tenantA->id,
                'category_id' => $category->id,
                'product_name' => 'Manual Brew Single Origin',
                'product_price' => 50000,
                'stock' => 20,
            ]);
        });

        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);
    }

    private function generateSignature(string $orderId, string $statusCode, string $grossAmount): string
    {
        return hash('sha512', $orderId.$statusCode.$grossAmount.'test_server_key_race');
    }

    /**
     * AC-2.3: Pengiriman 5 webhook Midtrans settlement identik secara berurutan
     * hanya menghasilkan 1 settlement pembayaran dan 1 kali pemotongan stok.
     */
    public function test_five_identical_midtrans_webhooks_produce_single_payment_and_single_stock_deduction(): void
    {
        $transactionService = app(TransactionService::class);

        // 1. Buat order dengan item produk ritel berstok
        $initialStock = (int) $this->product->fresh()->stock;
        $this->assertSame(20, $initialStock);

        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashier->id,
            'shift_id' => $this->shift->id,
            'created_by_device_id' => $this->device->id,
            'order_code' => 'ORD-RACE-01',
            'idempotency_key' => 'idemp-race-01',
            'order_date' => now()->toDateString(),
            'order_type' => 'dine_in',
            'order_status' => OrderStatus::Pending,
            'payment_method' => 'qris',
            'subtotal_amount' => 100000,
            'order_amount' => 100000,
        ]);

        $orderItem = OrderItem::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'qty' => 2,
            'order_price' => 50000,
            'order_subtotal' => 100000,
        ]);

        $this->product->decrement('stock', 2);

        StockMovement::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'product_id' => $this->product->id,
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'type' => 'sale_deduction',
            'quantity' => -2,
            'reason' => 'Checkout via POS',
            'idempotency_key' => "sale:{$order->id}:{$orderItem->id}",
        ]);

        $this->assertEquals(OrderStatus::Pending, $order->order_status);
        $this->assertEquals(100000, $order->order_amount);

        // Stok produk terpotong 2 unit saat checkout (sisa 18)
        $this->assertSame(18, (int) $this->product->fresh()->stock);

        $initialMovementCount = StockMovement::where('order_id', $order->id)
            ->where('type', 'sale_deduction')
            ->count();
        $this->assertSame(1, $initialMovementCount, 'Tepat 1 stock movement deduction saat checkout');

        // 2. Inisiasi PaymentAttempt untuk order
        $attempt = $transactionService->initiatePaymentAttempt(
            order: $order,
            method: PaymentMethod::Qris,
            amount: 100000,
            idempotencyKey: 'race-attempt-idemp-01',
            deviceId: $this->device->id,
            shiftId: $this->shift->id,
            userId: $this->cashier->id,
            provider: 'midtrans'
        );

        $providerOrderId = $attempt->provider_order_id;
        $this->assertNotNull($providerOrderId);

        $grossAmountStr = '100000.00';
        $signature = $this->generateSignature($providerOrderId, '200', $grossAmountStr);

        $webhookPayload = [
            'order_id' => $providerOrderId,
            'status_code' => '200',
            'gross_amount' => $grossAmountStr,
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
            'transaction_id' => 'midtrans-trx-race-999',
            'fraud_status' => 'accept',
        ];

        // 3. Kirim webhook yang sama persis 5 KALI ke HTTP controller
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->postJson('/midtrans/notification', $webhookPayload);
            $response->assertStatus(200);
            $response->assertJson(['message' => 'ok']);
        }

        // 4. Verifikasi State Akhir
        $order->refresh();
        $this->assertEquals(OrderStatus::Paid, $order->order_status, 'Order harus lunas');

        // Pembayaran tercatat tepat 1 baris Captured
        $payments = Payment::where('order_id', $order->id)->where('status', PaymentStatusEnum::Captured)->get();
        $this->assertCount(1, $payments, 'HANYA boleh ada tepat 1 pembayaran Captured di database');
        $this->assertEquals(100000, $payments->first()->amount);

        // PaymentAttempt status menjadi Succeeded dan terhubung ke Payment
        $attempt->refresh();
        $this->assertEquals(PaymentAttemptStatus::Succeeded, $attempt->status);
        $this->assertEquals($payments->first()->id, $attempt->payment_id);

        // Stok produk TIDAK BOLEH berkurang lebih dari 1 kali (tetap 18, bukan 10)
        $this->assertSame(18, (int) $this->product->fresh()->stock, 'Stok tidak boleh terpotong berulang kali oleh webhook');

        // Jumlah movement stok tetap 1
        $finalMovementCount = StockMovement::where('order_id', $order->id)
            ->where('type', 'sale_deduction')
            ->count();
        $this->assertSame(1, $finalMovementCount, 'Jumlah mutasi stok deduction harus tetap 1');
    }

    /**
     * AC-2.4: Percobaan voidOrder oleh kasir terhadap order yang memiliki PaymentAttempt
     * berstatus unknown_timeout ditolak jika saat dicek ke Midtrans ternyata statusnya sudah settlement.
     * Order otomatis dipromosikan ke Paid dan Payment dicatat.
     */
    public function test_void_order_rejected_when_timeout_payment_attempt_has_settled_on_gateway(): void
    {
        // 1. Buat pesanan dengan status Pending dan sebuah PaymentAttempt berstatus unknown_timeout
        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashier->id,
            'shift_id' => $this->shift->id,
            'created_by_device_id' => $this->device->id,
            'order_code' => 'ORD-TIMEOUT-VOID-01',
            'idempotency_key' => 'idemp-timeout-void-01',
            'order_date' => now()->toDateString(),
            'order_type' => 'dine_in',
            'order_status' => OrderStatus::Pending,
            'payment_method' => 'qris',
            'subtotal_amount' => 50000,
            'order_amount' => 50000,
        ]);

        $attempt = PaymentAttempt::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'device_id' => $this->device->id,
            'shift_id' => $this->shift->id,
            'idempotency_key' => 'timeout-attempt-key-01',
            'provider' => 'midtrans',
            'payment_method' => 'qris',
            'provider_order_id' => 'ORD-TIMEOUT-VOID-01-ATT-1',
            'amount' => 50000,
            'currency' => 'IDR',
            'status' => PaymentAttemptStatus::UnknownTimeout,
            'initiated_by_user_id' => $this->cashier->id,
        ]);

        // 2. Mock service getMidtransTransactionStatus untuk mengembalikan 'settlement'
        $customService = new class extends TransactionService
        {
            public object $mockStatus;

            public function getMidtransTransactionStatus(string $orderId): object
            {
                return $this->mockStatus;
            }
        };

        $customService->mockStatus = (object) [
            'transaction_status' => 'settlement',
            'transaction_id' => 'midtrans-trx-reconciled-777',
            'gross_amount' => 50000,
        ];

        $this->app->instance(TransactionService::class, $customService);

        // 3. Kasir mencoba melakukan voidOrder
        try {
            $customService->voidOrder($order, 'Pelanggan ingin membatalkan', (int) $this->cashier->id);
            $this->fail('Harus melempar DomainException karena pembayaran telah diselesaikan di Midtrans.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('settlement', $e->getMessage());
        }

        // 4. Verifikasi bahwa order TIDAK di-void, melainkan dipromosikan ke Paid
        $order->refresh();
        $this->assertEquals(OrderStatus::Paid, $order->order_status, 'Order harus dipromosikan ke Paid, BUKAN Void');
        $this->assertNull($order->voided_at, 'voided_at harus tetap null');
        $this->assertNull($order->voided_by, 'voided_by harus tetap null');

        // Attempt berubah ke Succeeded
        $attempt->refresh();
        $this->assertEquals(PaymentAttemptStatus::Succeeded, $attempt->status);
        $this->assertNotNull($attempt->payment_id);
        $this->assertEquals('midtrans-trx-reconciled-777', $attempt->provider_transaction_id);

        // Payment tercatat berstatus Captured
        $payment = Payment::find($attempt->payment_id);
        $this->assertNotNull($payment);
        $this->assertEquals(PaymentStatusEnum::Captured, $payment->status);
        $this->assertEquals(50000, $payment->amount);
        $this->assertEquals($order->id, $payment->order_id);
    }

    /**
     * Uji tambahan: Stale pending order yang sudah memiliki payment settlement
     * ditangani secara idempoten jika command rekonsiliasi dijalankan.
     */
    public function test_reconcile_handles_stale_settled_order_monotonically(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashier->id,
            'shift_id' => $this->shift->id,
            'created_by_device_id' => $this->device->id,
            'order_code' => 'ORD-RECON-RACE-01',
            'idempotency_key' => 'idemp-recon-race-01',
            'order_date' => now()->toDateString(),
            'order_type' => 'dine_in',
            'order_status' => OrderStatus::Pending,
            'payment_method' => 'qris',
            'subtotal_amount' => 50000,
            'order_amount' => 50000,
        ]);

        $attempt = PaymentAttempt::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'device_id' => $this->device->id,
            'shift_id' => $this->shift->id,
            'idempotency_key' => 'recon-attempt-key-01',
            'provider' => 'midtrans',
            'payment_method' => 'qris',
            'provider_order_id' => 'ORD-RECON-RACE-01-ATT-1',
            'amount' => 50000,
            'currency' => 'IDR',
            'status' => PaymentAttemptStatus::PendingProvider,
            'initiated_by_user_id' => $this->cashier->id,
        ]);

        $transactionService = app(TransactionService::class);

        // Simulasi webhook settlement masuk
        $transactionService->handleMidtransWebhook([
            'order_id' => $attempt->provider_order_id,
            'transaction_status' => 'settlement',
            'transaction_id' => 'trx-settled-recon-1',
            'gross_amount' => 50000,
        ]);

        $order->refresh();
        $this->assertEquals(OrderStatus::Paid, $order->order_status);

        // Sekarang simulasi webhook yang terlambat (mis. transaksi_status 'pending' tiba setelah settlement)
        $transactionService->handleMidtransWebhook([
            'order_id' => $attempt->provider_order_id,
            'transaction_status' => 'pending',
            'transaction_id' => 'trx-delayed-pending',
            'gross_amount' => 50000,
        ]);

        // State machine monotonik: Order TIDAK boleh mundur dari Paid ke Pending
        $order->refresh();
        $this->assertEquals(OrderStatus::Paid, $order->order_status, 'Order tidak boleh mundur ke Pending');
        $this->assertEquals(PaymentAttemptStatus::Succeeded, $attempt->fresh()->status);
    }
}
