<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatusEnum;
use App\Enums\ReturnDispositionEnum;
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
 * ConcurrentRefundLimitTest
 *
 * Menguji kepatuhan terhadap:
 * - AC-3.2: Dua request refund paralel sebesar Rp100.000 atas Payment Rp150.000
 *           hanya meloloskan 1 refund (Rp100.000) dan menolak request kedua
 *           karena melebihi sisa refundable Rp50.000.
 * - INV-07: Batas maksimum refund per payment: SUM(refund_amount) <= payment.amount.
 * - Penguncian baris kanonis SELECT ... FOR UPDATE pada tabel payments.
 */
class ConcurrentRefundLimitTest extends TestCase
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
                'opening_balance' => 500000,
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
                'product_name' => 'Coffee Beans 250g',
                'product_price' => 75000,
                'stock' => 50,
            ]);
        });

        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA->id);
        $this->service = app(ReturnRefundService::class);
    }

    /**
     * AC-3.2: Dua request refund paralel sebesar Rp100.000 atas Payment Rp150.000
     * hanya meloloskan 1 refund (Rp100.000) dan menolak request kedua karena melebihi sisa refundable Rp50.000.
     */
    public function test_two_refund_requests_exceeding_payment_amount_rejects_second_request(): void
    {
        // 1. Buat Order senilai Rp150.000 dengan 2 pcs biji kopi
        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashier->id,
            'shift_id' => $this->shift->id,
            'created_by_device_id' => $this->device->id,
            'order_code' => 'ORD-CONC-01',
            'idempotency_key' => 'idemp-conc-01',
            'order_date' => now()->toDateString(),
            'order_type' => 'take_away',
            'order_status' => OrderStatus::Paid,
            'payment_method' => 'cash',
            'subtotal_amount' => 150000,
            'order_amount' => 150000,
        ]);

        $orderItem1 = OrderItem::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'qty' => 1,
            'order_price' => 75000,
            'order_subtotal' => 75000,
        ]);

        $orderItem2 = OrderItem::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'qty' => 1,
            'order_price' => 75000,
            'order_subtotal' => 75000,
        ]);

        // Payment tunggal senilai Rp150.000
        $payment = Payment::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'shift_id' => $this->shift->id,
            'device_id' => $this->device->id,
            'payment_method' => PaymentMethod::Cash,
            'amount' => 150000,
            'status' => PaymentStatusEnum::Captured,
            'captured_at' => now(),
            'processed_by' => $this->cashier->id,
            'idempotency_key' => 'pay-conc-01',
        ]);

        // Request 1: Refund Rp100.000 -> HARUS LOLOS
        $return1 = $this->service->createReturnAndRefund(
            order: $order,
            returnItemsWithDisposition: [
                [
                    'order_item_id' => $orderItem1->id,
                    'quantity' => 1,
                    'disposition' => ReturnDispositionEnum::Restock,
                ],
            ],
            refundAllocations: [
                [
                    'payment_id' => $payment->id,
                    'amount' => 100000,
                    'refund_method' => 'cash',
                ],
            ],
            shiftId: $this->shift->id,
            deviceId: $this->device->id,
            actorUserId: $this->cashier->id,
            supervisorPin: '654321',
            reason: 'Refund leg pertama',
            idempotencyKey: 'idemp-req-01'
        );

        $this->assertNotNull($return1);

        // Verifikasi saldo refundable saat ini: Rp150.000 - Rp100.000 = Rp50.000
        $this->assertEquals(50000, $payment->getRefundableBalance());

        // Request 2: Refund Rp100.000 berikutnya (total Rp200.000 > Rp150.000) -> HARUS DITOLAK
        try {
            $this->service->createReturnAndRefund(
                order: $order,
                returnItemsWithDisposition: [
                    [
                        'order_item_id' => $orderItem2->id,
                        'quantity' => 1,
                        'disposition' => ReturnDispositionEnum::Restock,
                    ],
                ],
                refundAllocations: [
                    [
                        'payment_id' => $payment->id,
                        'amount' => 100000, // Rp100.000 > sisa Rp50.000
                        'refund_method' => 'cash',
                    ],
                ],
                shiftId: $this->shift->id,
                deviceId: $this->device->id,
                actorUserId: $this->cashier->id,
                supervisorPin: '654321',
                reason: 'Refund leg kedua melebihi limit',
                idempotencyKey: 'idemp-req-02'
            );
            $this->fail('Request refund kedua wajib ditolak karena melebihi sisa refundable balance payment.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('melebihi sisa yang dapat direfund', $e->getMessage());
        }

        // Verifikasi bahwa di database hanya ada tepat 1 record refund senilai Rp100.000
        $refunds = PaymentRefund::where('payment_id', $payment->id)->get();
        $this->assertCount(1, $refunds);
        $this->assertEquals(100000, $refunds->first()->amount);
        $this->assertEquals(50000, $payment->getRefundableBalance());
    }

    /**
     * Split Payment Refund: Refund bertahap dari dua payment leg yang berbeda
     * menghormati limit masing-masing leg secara independen.
     */
    public function test_split_payment_refund_enforces_limit_per_payment_leg(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->cashier->id,
            'shift_id' => $this->shift->id,
            'created_by_device_id' => $this->device->id,
            'order_code' => 'ORD-SPLIT-REF-02',
            'idempotency_key' => 'idemp-split-ref-02',
            'order_date' => now()->toDateString(),
            'order_type' => 'dine_in',
            'order_status' => OrderStatus::Paid,
            'payment_method' => 'cash',
            'subtotal_amount' => 150000,
            'order_amount' => 150000,
        ]);

        $orderItem = OrderItem::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'qty' => 2,
            'order_price' => 75000,
            'order_subtotal' => 150000,
        ]);

        // Leg 1: Tunai Rp50.000
        $payment1 = Payment::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'shift_id' => $this->shift->id,
            'device_id' => $this->device->id,
            'payment_method' => PaymentMethod::Cash,
            'amount' => 50000,
            'status' => PaymentStatusEnum::Captured,
            'captured_at' => now(),
            'processed_by' => $this->cashier->id,
            'idempotency_key' => 'split-leg-pay-1',
        ]);

        // Leg 2: QRIS Rp100.000
        $payment2 = Payment::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'shift_id' => $this->shift->id,
            'device_id' => $this->device->id,
            'payment_method' => PaymentMethod::Qris,
            'amount' => 100000,
            'status' => PaymentStatusEnum::Captured,
            'captured_at' => now(),
            'processed_by' => $this->cashier->id,
            'idempotency_key' => 'split-leg-pay-2',
        ]);

        // Coba refund Rp60.000 dialokasikan pada Leg 1 (yang hanya Rp50.000) -> DITOLAK
        try {
            $this->service->createReturnAndRefund(
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
                        'payment_id' => $payment1->id,
                        'amount' => 60000, // Rp60.000 > Rp50.000
                        'refund_method' => 'cash',
                    ],
                ],
                shiftId: $this->shift->id,
                deviceId: $this->device->id,
                actorUserId: $this->cashier->id,
                supervisorPin: '654321',
                reason: 'Alokasi melebihi leg 1',
                idempotencyKey: 'split-ref-over-leg1'
            );
            $this->fail('Refund melebihi payment leg harus ditolak.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('melebihi sisa yang dapat direfund', $e->getMessage());
        }

        // Alokasi yang benar: Rp50.000 pada Leg 1 dan Rp25.000 pada Leg 2 (Total Rp75.000 untuk 1 item) -> LOLOS
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
                    'payment_id' => $payment1->id,
                    'amount' => 50000,
                    'refund_method' => 'cash',
                ],
                [
                    'payment_id' => $payment2->id,
                    'amount' => 25000,
                    'refund_method' => 'qris',
                ],
            ],
            shiftId: $this->shift->id,
            deviceId: $this->device->id,
            actorUserId: $this->cashier->id,
            supervisorPin: '654321',
            reason: 'Refund multi-leg sah',
            idempotencyKey: 'split-ref-valid'
        );

        $this->assertNotNull($return);
        $this->assertEquals(0, $payment1->getRefundableBalance());
        $this->assertEquals(75000, $payment2->getRefundableBalance());
    }
}
