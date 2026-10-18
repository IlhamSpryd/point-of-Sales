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
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\TransactionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class PaymentAttemptIdempotencyAndSplitTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected User $cashier;

    protected Device $device;

    protected Shift $shift;

    protected Product $product;

    protected TransactionService $transactionService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();
            Model::unguard();

            $this->device = Device::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'device_name' => 'POS Terminal Alpha',
                'device_code' => 'POS-ALPHA',
                'device_type' => Device::TYPE_POS_TERMINAL,
                'device_fingerprint' => 'fp-alpha-01',
                'status' => Device::STATUS_PAIRED,
                'is_active' => true,
            ]);

            $roleKasir = Role::withoutTenantScope()->firstOrCreate(
                ['name' => 'Kasir', 'tenant_id' => $this->tenantA->id],
                ['is_active' => 1]
            );

            $this->cashier = User::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Kasir Alpha',
                'email' => 'kasir.alpha@test.com',
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
                'device_id' => $this->device->id,
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
                'product_name' => 'Specialty Coffee Beans',
                'product_price' => 100000,
                'stock' => 50,
            ]);
        });

        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);
        $this->transactionService = app(TransactionService::class);
    }

    /**
     * AC-2.1: Pengiriman request pembayaran ulang dengan idempotency_key yang sama
     * mengembalikan PaymentAttempt yang sama tanpa membuat tagihan ganda di DB.
     */
    public function test_payment_attempt_idempotency_returns_same_instance(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashier->id,
            'shift_id' => $this->shift->id,
            'created_by_device_id' => $this->device->id,
            'order_code' => 'ORD-IDEMP-01',
            'idempotency_key' => 'idemp-order-01',
            'order_date' => now()->toDateString(),
            'order_type' => 'dine_in',
            'order_status' => OrderStatus::Pending,
            'payment_method' => 'qris',
            'subtotal_amount' => 100000,
            'order_amount' => 100000,
        ]);

        $idempotencyKey = 'attempt-unique-key-12345';

        // Panggilan pertama
        $attempt1 = $this->transactionService->initiatePaymentAttempt(
            order: $order,
            method: PaymentMethod::Qris,
            amount: 100000,
            idempotencyKey: $idempotencyKey,
            deviceId: $this->device->id,
            shiftId: $this->shift->id,
            userId: $this->cashier->id
        );

        $this->assertInstanceOf(PaymentAttempt::class, $attempt1);
        $this->assertEquals(PaymentAttemptStatus::Initiated, $attempt1->status);
        $this->assertEquals(100000, $attempt1->amount);

        // Panggilan kedua dengan idempotency_key yang persis sama
        $attempt2 = $this->transactionService->initiatePaymentAttempt(
            order: $order,
            method: PaymentMethod::Qris,
            amount: 100000,
            idempotencyKey: $idempotencyKey,
            deviceId: $this->device->id,
            shiftId: $this->shift->id,
            userId: $this->cashier->id
        );

        $this->assertEquals($attempt1->id, $attempt2->id, 'Harus mengembalikan instance attempt yang sama');

        // Pastikan hanya tepat 1 baris yang ada di database
        $count = PaymentAttempt::where('tenant_id', $this->tenantA->id)
            ->where('idempotency_key', $idempotencyKey)
            ->count();
        $this->assertSame(1, $count, 'Tidak boleh ada duplikasi attempt di database');
    }

    /**
     * AC-2.2: Ketika attempt pertama Midtrans expired, kasir dapat membuat attempt kedua
     * dengan provider_order_id baru untuk Order yang sama dan berhasil menyelesaikannya.
     */
    public function test_expired_attempt_allows_retry_with_new_provider_order_id_and_settles(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashier->id,
            'shift_id' => $this->shift->id,
            'created_by_device_id' => $this->device->id,
            'order_code' => 'ORD-RETRY-02',
            'idempotency_key' => 'idemp-order-02',
            'order_date' => now()->toDateString(),
            'order_type' => 'dine_in',
            'order_status' => OrderStatus::Pending,
            'payment_method' => 'qris',
            'subtotal_amount' => 50000,
            'order_amount' => 50000,
        ]);

        // 1. Attempt pertama
        $attempt1 = $this->transactionService->initiatePaymentAttempt(
            order: $order,
            method: PaymentMethod::Qris,
            amount: 50000,
            idempotencyKey: 'attempt-key-01',
            deviceId: $this->device->id,
            shiftId: $this->shift->id,
            userId: $this->cashier->id,
            provider: 'midtrans'
        );

        $providerOrderId1 = $attempt1->provider_order_id;
        $this->assertNotNull($providerOrderId1);

        // Simulasi attempt1 expired dari gateway
        $attempt1->status = PaymentAttemptStatus::Expired;
        $attempt1->expired_at = now();
        $attempt1->save();

        // 2. Attempt kedua untuk order yang sama
        $attempt2 = $this->transactionService->initiatePaymentAttempt(
            order: $order,
            method: PaymentMethod::Qris,
            amount: 50000,
            idempotencyKey: 'attempt-key-02',
            deviceId: $this->device->id,
            shiftId: $this->shift->id,
            userId: $this->cashier->id,
            provider: 'midtrans'
        );

        $providerOrderId2 = $attempt2->provider_order_id;
        $this->assertNotNull($providerOrderId2);
        $this->assertNotEquals($providerOrderId1, $providerOrderId2, 'provider_order_id attempt kedua harus unik agar tidak bentrok di Midtrans');

        // 3. Selesaikan attempt kedua
        $payment = $this->transactionService->settlePaymentAttempt($attempt2, [
            'transaction_id' => 'midtrans-trx-998877',
            'transaction_status' => 'settlement',
        ]);

        $this->assertInstanceOf(Payment::class, $payment);
        $this->assertEquals(PaymentStatusEnum::Captured, $payment->status);
        $this->assertEquals(50000, $payment->amount);

        $attempt2->refresh();
        $this->assertEquals(PaymentAttemptStatus::Succeeded, $attempt2->status);
        $this->assertEquals($payment->id, $attempt2->payment_id);

        $order->refresh();
        $this->assertEquals(OrderStatus::Paid, $order->order_status);
    }

    /**
     * Split Tender Workflow: Pembayaran bertahap (Tunai + QRIS) hingga lunas penuh.
     */
    public function test_split_tender_workflow_tracks_remaining_balance_and_completes_order(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashier->id,
            'shift_id' => $this->shift->id,
            'created_by_device_id' => $this->device->id,
            'order_code' => 'ORD-SPLIT-03',
            'idempotency_key' => 'idemp-order-03',
            'order_date' => now()->toDateString(),
            'order_type' => 'dine_in',
            'order_status' => OrderStatus::Pending,
            'payment_method' => 'cash',
            'subtotal_amount' => 100000,
            'order_amount' => 100000,
        ]);

        $this->assertEquals(100000, $order->getRemainingBalance());
        $this->assertEquals(0, $order->getSettledPaidAmount());

        // Leg 1: Bayar Tunai Rp40.000 (Tender Rp50.000, Kembalian Rp10.000)
        $attempt1 = $this->transactionService->initiatePaymentAttempt(
            order: $order,
            method: PaymentMethod::Cash,
            amount: 40000,
            idempotencyKey: 'split-attempt-cash-40k',
            deviceId: $this->device->id,
            shiftId: $this->shift->id,
            userId: $this->cashier->id
        );

        $payment1 = $this->transactionService->settlePaymentAttempt($attempt1, [
            'amount_tendered' => 50000,
            'change_amount' => 10000,
        ]);

        $this->assertEquals(40000, $payment1->amount);
        $this->assertEquals(50000, $payment1->amount_tendered);
        $this->assertEquals(10000, $payment1->change_amount);

        $order->refresh();
        $this->assertEquals(OrderStatus::Pending, $order->order_status, 'Order masih Pending karena baru dibayar sebagian');
        $this->assertEquals(40000, $order->getSettledPaidAmount());
        $this->assertEquals(60000, $order->getRemainingBalance());

        // Coba leg 2 dengan nilai melebihi sisa tagihan (Rp70.000 > Rp60.000) -> HARUS DITOLAK
        $this->expectException(ValidationException::class);
        $this->transactionService->initiatePaymentAttempt(
            order: $order,
            method: PaymentMethod::Qris,
            amount: 70000,
            idempotencyKey: 'split-attempt-overpay',
            deviceId: $this->device->id,
            shiftId: $this->shift->id,
            userId: $this->cashier->id
        );
    }

    /**
     * Split Tender completion: Menyelesaikan leg kedua sehingga total payments persis sama dengan order_amount.
     */
    public function test_split_tender_completes_and_satisfies_payment_totals(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashier->id,
            'shift_id' => $this->shift->id,
            'created_by_device_id' => $this->device->id,
            'order_code' => 'ORD-SPLIT-04',
            'idempotency_key' => 'idemp-order-04',
            'order_date' => now()->toDateString(),
            'order_type' => 'dine_in',
            'order_status' => OrderStatus::Pending,
            'payment_method' => 'cash',
            'subtotal_amount' => 100000,
            'order_amount' => 100000,
        ]);

        // Leg 1: Tunai Rp40.000
        $attempt1 = $this->transactionService->initiatePaymentAttempt(
            order: $order,
            method: PaymentMethod::Cash,
            amount: 40000,
            idempotencyKey: 'split-leg-1',
            deviceId: $this->device->id,
            shiftId: $this->shift->id,
            userId: $this->cashier->id
        );
        $this->transactionService->settlePaymentAttempt($attempt1);

        // Leg 2: QRIS Rp60.000
        $attempt2 = $this->transactionService->initiatePaymentAttempt(
            order: $order,
            method: PaymentMethod::Qris,
            amount: 60000,
            idempotencyKey: 'split-leg-2',
            deviceId: $this->device->id,
            shiftId: $this->shift->id,
            userId: $this->cashier->id
        );
        $this->transactionService->settlePaymentAttempt($attempt2, [
            'transaction_id' => 'qris-trx-112233',
        ]);

        $order->refresh();
        $this->assertEquals(OrderStatus::Paid, $order->order_status, 'Order harus berpindah ke Paid setelah sisa saldo 0');
        $this->assertEquals(100000, $order->getSettledPaidAmount());
        $this->assertEquals(0, $order->getRemainingBalance());

        // Verifikasi tabel payments memiliki 2 baris captured
        $payments = Payment::where('order_id', $order->id)->where('status', PaymentStatusEnum::Captured)->get();
        $this->assertCount(2, $payments);
        $this->assertEquals(100000, $payments->sum('amount'));
    }
}
