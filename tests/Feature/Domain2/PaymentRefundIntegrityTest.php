<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Enums\CashDrawerMovementTypeEnum;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatusEnum;
use App\Enums\ReturnDispositionEnum;
use App\Models\CashDrawerMovement;
use App\Models\Category;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\ReturnRefundService;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

/**
 * PaymentRefundIntegrityTest
 *
 * Menguji kepatuhan terhadap:
 * - AC-3.3: Refund tunai (baik dari pembayaran asal tunai maupun authorized cash refund exception)
 *           mengurangi kas pada shift aktif melalui CashDrawerMovement keluar (type='cash_out', category='refund').
 * - AC-3.4: Baris orders dan payments asli TIDAK mengalami perubahan nilai nominal total_amount / amount historis.
 * - Penolakan refund tunai jika fisik uang di laci kas shift aktif tidak mencukupi.
 * - Otorisasi override supervisor untuk non-cash payment yang di-refund tunai.
 */
class PaymentRefundIntegrityTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected User $cashier;

    protected User $supervisor;

    protected Device $device;

    protected Shift $shift;

    protected Product $product;

    protected ReturnRefundService $service;

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

            $roleSupervisor = Role::withoutTenantScope()->firstOrCreate(
                ['name' => 'Supervisor', 'tenant_id' => $this->tenantA->id],
                ['is_active' => 1, 'permissions' => ['can_refund_order', 'can_return_order']]
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

            $this->supervisor = User::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Supervisor Alpha',
                'email' => 'spv.alpha@test.com',
                'password' => bcrypt('password'),
                'pin_hash' => bcrypt('654321'),
                'role_id' => $roleSupervisor->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $this->supervisor->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

            $this->shift = Shift::create([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'user_id' => $this->cashier->id,
                'device_id' => $this->device->id,
                'active_cashier_id' => $this->cashier->id,
                'opening_balance' => 200000, // Saldo modal laci: Rp200.000
                'status' => 'open',
                'opened_at' => now(),
            ]);

            $cat = Category::create([
                'tenant_id' => $this->tenantA->id,
                'category_name' => 'Retail Merch',
            ]);

            $this->product = Product::create([
                'tenant_id' => $this->tenantA->id,
                'category_id' => $cat->id,
                'product_name' => 'Signature Mug',
                'product_price' => 75000,
                'stock' => 20,
            ]);
        });

        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);
        $this->service = app(ReturnRefundService::class);
    }

    /**
     * AC-3.3: Refund tunai dari pembayaran asal tunai membuat CashDrawerMovement keluar.
     * AC-3.4: Baris orders dan payments asli tidak berubah nilai nominalnya.
     */
    public function test_cash_refund_creates_cash_drawer_out_movement_and_preserves_historical_amounts(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashier->id,
            'shift_id' => $this->shift->id,
            'created_by_device_id' => $this->device->id,
            'order_code' => 'ORD-REF-01',
            'idempotency_key' => 'idemp-ref-01',
            'order_date' => now()->toDateString(),
            'order_type' => 'dine_in',
            'order_status' => OrderStatus::Paid,
            'payment_method' => 'cash',
            'subtotal_amount' => 75000,
            'order_amount' => 75000,
        ]);

        $orderItem = OrderItem::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'qty' => 1,
            'order_price' => 75000,
            'order_subtotal' => 75000,
        ]);

        $payment = Payment::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'shift_id' => $this->shift->id,
            'device_id' => $this->device->id,
            'payment_method' => PaymentMethod::Cash,
            'amount' => 75000,
            'amount_tendered' => 100000,
            'change_amount' => 25000,
            'status' => PaymentStatusEnum::Captured,
            'captured_at' => now(),
            'processed_by' => $this->cashier->id,
            'idempotency_key' => 'pay-ref-01',
        ]);

        $initialDrawerOutCount = CashDrawerMovement::where('shift_id', $this->shift->id)->where('type', 'cash_out')->count();

        // Eksekusi Refund Tunai Penuh Rp75.000
        $return = $this->service->createReturnAndRefund(
            order: $order,
            returnItemsWithDisposition: [
                [
                    'order_item_id' => $orderItem->id,
                    'quantity' => 1,
                    'disposition' => ReturnDispositionEnum::Restock,
                ],
            ],
            refundAllocations: [
                [
                    'payment_id' => $payment->id,
                    'amount' => 75000,
                    'refund_method' => 'cash',
                ],
            ],
            shiftId: $this->shift->id,
            deviceId: $this->device->id,
            actorUserId: $this->cashier->id,
            supervisorPin: '654321',
            reason: 'Pelanggan membatalkan pesanan',
            idempotencyKey: 'idemp-refund-cash-01'
        );

        $this->assertNotNull($return);

        // AC-3.3: Verifikasi terbentuk 1 CashDrawerMovement keluar
        $cdmList = CashDrawerMovement::where('shift_id', $this->shift->id)->where('type', 'cash_out')->get();
        $this->assertCount($initialDrawerOutCount + 1, $cdmList);

        $lastCdm = $cdmList->last();
        $this->assertEquals(75000, $lastCdm->amount);
        $this->assertEquals(CashDrawerMovementTypeEnum::CashOut, $lastCdm->type);
        $this->assertEquals('refund', $lastCdm->category instanceof \BackedEnum ? $lastCdm->category->value : $lastCdm->category);
        $this->assertEquals($this->cashier->id, $lastCdm->created_by);
        $this->assertEquals($this->supervisor->id, $lastCdm->approved_by);

        // Verifikasi baris payment_refunds terhubung ke cash_drawer_movement
        $refundRecord = PaymentRefund::where('order_id', $order->id)->first();
        $this->assertNotNull($refundRecord);
        $this->assertEquals(75000, $refundRecord->amount);
        $this->assertEquals('cash', $refundRecord->refund_method);
        $this->assertEquals($lastCdm->id, $refundRecord->cash_drawer_movement_id);

        // AC-3.4: Baris orders dan payments ASLI TIDAK berubah nilai historisnya
        $order->refresh();
        $payment->refresh();
        $this->assertSame(75000, (int) $order->order_amount, 'Nilai order_amount historis harus tetap 75000');
        $this->assertSame(75000, (int) $payment->amount, 'Nilai payment.amount historis harus tetap 75000');
    }

    /**
     * AC-3.3: Authorized Cash Refund Exception (Pembayaran QRIS direfund Tunai atas izin Supervisor)
     * membuat CashDrawerMovement keluar dan mencatat is_method_override = true.
     */
    public function test_authorized_cash_refund_exception_for_non_cash_payment(): void
    {
        // Order dibayar dengan QRIS
        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashier->id,
            'shift_id' => $this->shift->id,
            'created_by_device_id' => $this->device->id,
            'order_code' => 'ORD-REF-QRIS-02',
            'idempotency_key' => 'idemp-ref-qris-02',
            'order_date' => now()->toDateString(),
            'order_type' => 'dine_in',
            'order_status' => OrderStatus::Paid,
            'payment_method' => 'qris',
            'subtotal_amount' => 75000,
            'order_amount' => 75000,
        ]);

        $orderItem = OrderItem::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'qty' => 1,
            'order_price' => 75000,
            'order_subtotal' => 75000,
        ]);

        $payment = Payment::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'shift_id' => $this->shift->id,
            'device_id' => $this->device->id,
            'payment_method' => PaymentMethod::Qris,
            'amount' => 75000,
            'status' => PaymentStatusEnum::Captured,
            'captured_at' => now(),
            'processed_by' => $this->cashier->id,
            'idempotency_key' => 'pay-ref-qris-02',
        ]);

        // Eksekusi refund tunai dengan otorisasi override
        $return = $this->service->createReturnAndRefund(
            order: $order,
            returnItemsWithDisposition: [
                [
                    'order_item_id' => $orderItem->id,
                    'quantity' => 1,
                    'disposition' => ReturnDispositionEnum::Restock,
                ],
            ],
            refundAllocations: [
                [
                    'payment_id' => $payment->id,
                    'amount' => 75000,
                    'refund_method' => 'cash', // Override ke tunai
                    'is_method_override' => true,
                    'method_override_reason' => 'Pelanggan tidak memiliki e-wallet aktif lagi',
                ],
            ],
            shiftId: $this->shift->id,
            deviceId: $this->device->id,
            actorUserId: $this->cashier->id,
            supervisorPin: '654321',
            reason: 'Refund tunai darurat',
            idempotencyKey: 'idemp-refund-override-02'
        );

        $this->assertNotNull($return);

        // Verifikasi record PaymentRefund
        $refund = PaymentRefund::where('order_id', $order->id)->first();
        $this->assertNotNull($refund);
        $this->assertEquals('qris', $refund->original_payment_method);
        $this->assertEquals('cash', $refund->refund_method);
        $this->assertTrue((bool) $refund->is_method_override);
        $this->assertEquals('Pelanggan tidak memiliki e-wallet aktif lagi', $refund->method_override_reason);

        // Kas laci shift aktif terpotong
        $this->assertNotNull($refund->cash_drawer_movement_id);
        $cdm = CashDrawerMovement::find($refund->cash_drawer_movement_id);
        $this->assertEquals(75000, $cdm->amount);
        $this->assertEquals(CashDrawerMovementTypeEnum::CashOut, $cdm->type);
    }

    /**
     * Uji Penolakan: Refund tunai melebihi saldo kas yang ada di laci shift ditolak.
     */
    public function test_cash_refund_is_rejected_when_drawer_cash_is_insufficient(): void
    {
        // Transaksi terjadi pada shift sebelumnya (kemarin)
        $previousShift = Shift::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashier->id,
            'device_id' => $this->device->id,
            'active_cashier_id' => $this->cashier->id,
            'opening_balance' => 300000,
            'status' => 'closed',
            'opened_at' => now()->subDay(),
            'closed_at' => now()->subDay()->addHours(8),
        ]);

        // Order bernilai Rp300.000 dibayar tunai pada shift kemarin
        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashier->id,
            'shift_id' => $previousShift->id,
            'created_by_device_id' => $this->device->id,
            'order_code' => 'ORD-REF-BIG-03',
            'idempotency_key' => 'idemp-ref-big-03',
            'order_date' => now()->subDay()->toDateString(),
            'order_type' => 'dine_in',
            'order_status' => OrderStatus::Paid,
            'payment_method' => 'cash',
            'subtotal_amount' => 300000,
            'order_amount' => 300000,
        ]);

        $orderItem = OrderItem::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'qty' => 4,
            'order_price' => 75000,
            'order_subtotal' => 300000,
        ]);

        $payment = Payment::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'shift_id' => $previousShift->id,
            'device_id' => $this->device->id,
            'payment_method' => PaymentMethod::Cash,
            'amount' => 300000,
            'status' => PaymentStatusEnum::Captured,
            'captured_at' => now()->subDay(),
            'processed_by' => $this->cashier->id,
            'idempotency_key' => 'pay-ref-big-03',
        ]);

        // Shift aktif saat ini ($this->shift) HANYA memiliki saldo modal Rp200.000.
        // Coba refund tunai Rp300.000 dari shift aktif saat ini -> HARUS DITOLAK
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Kas fisik di laci shift');

        $this->service->createReturnAndRefund(
            order: $order,
            returnItemsWithDisposition: [
                [
                    'order_item_id' => $orderItem->id,
                    'quantity' => 4,
                    'disposition' => ReturnDispositionEnum::Restock,
                ],
            ],
            refundAllocations: [
                [
                    'payment_id' => $payment->id,
                    'amount' => 300000, // Rp300.000 > Rp200.000 modal laci
                    'refund_method' => 'cash',
                ],
            ],
            shiftId: $this->shift->id,
            deviceId: $this->device->id,
            actorUserId: $this->cashier->id,
            supervisorPin: '654321',
            reason: 'Refund melebihi saldo laci kas',
            idempotencyKey: 'idemp-refund-overdrawer'
        );
    }
}
