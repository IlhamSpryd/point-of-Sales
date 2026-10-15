<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrderLifecycleTransitionTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected User $cashier;
    protected Shift $shift;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::create([
            'name' => 'Lifecycle Tenant',
            'readiness_status' => 'active',
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Lifecycle Store',
        ]);

        $context->setStoreId($this->store->id);

        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cashier',
            'permissions' => ['can_void_order'],
        ]);

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
        ]);

        $this->shift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'opening_balance' => 100000,
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }

    public function test_pay_pending_order_rejects_already_paid_order(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'order_code' => 'ORD-LIFE-001',
            'order_date' => now()->toDateString(),
            'order_status' => OrderStatus::Paid,
            'payment_method' => 'cash',
            'subtotal_amount' => 50000,
            'tax_amount' => 5500,
            'order_amount' => 55500,
            'idempotency_key' => 'life-key-001',
        ]);

        $service = app(TransactionService::class);

        $this->expectException(ValidationException::class);
        $service->payPendingOrder(
            $order,
            [['method' => PaymentMethod::Cash->value, 'amount' => 55500]],
            $this->cashier->id,
            $this->shift->id
        );
    }

    public function test_pay_pending_order_rejects_void_order(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'order_code' => 'ORD-LIFE-002',
            'order_date' => now()->toDateString(),
            'order_status' => OrderStatus::Void,
            'payment_method' => 'cash',
            'subtotal_amount' => 30000,
            'tax_amount' => 3300,
            'order_amount' => 33300,
            'idempotency_key' => 'life-key-002',
        ]);

        $service = app(TransactionService::class);

        $this->expectException(ValidationException::class);
        $service->payPendingOrder(
            $order,
            [['method' => PaymentMethod::Cash->value, 'amount' => 33300]],
            $this->cashier->id,
            $this->shift->id
        );
    }

    public function test_pay_pending_order_rejects_cancelled_order(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'order_code' => 'ORD-LIFE-003',
            'order_date' => now()->toDateString(),
            'order_status' => OrderStatus::Cancelled,
            'payment_method' => 'qris',
            'subtotal_amount' => 20000,
            'tax_amount' => 2200,
            'order_amount' => 22200,
            'idempotency_key' => 'life-key-003',
        ]);

        $service = app(TransactionService::class);

        $this->expectException(ValidationException::class);
        $service->payPendingOrder(
            $order,
            [['method' => PaymentMethod::Qris->value, 'amount' => 22200]],
            $this->cashier->id,
            $this->shift->id
        );
    }

    public function test_midtrans_notification_does_not_modify_final_order_status(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'order_code' => 'ORD-LIFE-004',
            'order_date' => now()->toDateString(),
            'order_status' => OrderStatus::Paid,
            'payment_method' => 'qris',
            'subtotal_amount' => 40000,
            'tax_amount' => 4400,
            'order_amount' => 44400,
            'idempotency_key' => 'life-key-004',
        ]);

        $service = app(TransactionService::class);

        // Attempt to pass 'cancel' notification on already Paid order
        $result = $service->updateStatusFromMidtransNotification(
            orderCode: 'ORD-LIFE-004',
            transactionStatus: 'cancel',
            fraudStatus: null,
            grossAmount: 44400
        );

        $fresh = $order->fresh();
        $this->assertEquals(OrderStatus::Paid, $fresh->order_status, 'Final order must not be mutated by late webhook');
        $this->assertEquals(OrderStatus::Paid, $result->order_status);
    }

    public function test_void_order_rejects_pending_order(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'order_code' => 'ORD-LIFE-005',
            'order_date' => now()->toDateString(),
            'order_status' => OrderStatus::Pending,
            'payment_method' => 'cash',
            'subtotal_amount' => 50000,
            'tax_amount' => 5500,
            'order_amount' => 55500,
            'idempotency_key' => 'life-key-005',
        ]);

        $service = app(TransactionService::class);

        $this->expectException(\Exception::class);
        $service->voidOrder($order, 'Membatalkan pesanan pending', $this->cashier->id);
    }
}
