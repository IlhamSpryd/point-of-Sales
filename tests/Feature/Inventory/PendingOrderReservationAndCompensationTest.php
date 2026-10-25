<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\OrderStatus;
use App\Enums\ReturnDispositionEnum;
use App\Enums\StockMovementType;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductStockBalance;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\Table;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Modules\Sales\Services\ReturnRefundService;
use App\Modules\Sales\Services\TransactionService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class PendingOrderReservationAndCompensationTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected TransactionService $transactionService;

    protected InventoryLedgerService $ledgerService;

    protected ReturnRefundService $returnService;

    protected Table $tableA;

    protected Shift $shiftA;

    protected Device $deviceA;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();

            $this->tableA = Table::forceCreate([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'table_name' => 'Meja 1',
                'table_code' => 'T-01',
                'capacity' => 4,
                'operational_status' => 'available',
                'secure_token' => (string) Str::uuid(),
                'is_active' => true,
            ]);

            $this->deviceA = Device::forceCreate([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'device_code' => 'DEV-MAIN-001',
                'device_name' => 'Main POS Terminal',
                'device_type' => Device::TYPE_POS_TERMINAL,
                'is_active' => true,
                'status' => Device::STATUS_PAIRED,
            ]);

            $this->shiftA = Shift::forceCreate([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA->id,
                'user_id' => $this->userAOwner->id,
                'active_cashier_id' => $this->userAOwner->id,
                'device_id' => $this->deviceA->id,
                'opening_balance' => 1000000,
                'opened_at' => now()->subHours(2),
                'status' => 'open',
            ]);
        });

        $this->transactionService = app(TransactionService::class);
        $this->ledgerService = app(InventoryLedgerService::class);
        $this->returnService = app(ReturnRefundService::class);
    }

    public function test_expire_stale_cash_self_orders_restores_inventory_and_is_idempotent(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Ensure stock balances are authoritative
        config(['pos.stock_balances_authoritative' => true]);

        // Seed initial balance: 50 units
        $this->productA->track_inventory = true;
        $this->productA->stock = 50;
        $this->productA->save();

        ProductStockBalance::updateOrCreate(
            ['tenant_id' => $this->tenantA->id, 'store_id' => $this->storeA->id, 'product_id' => $this->productA->id],
            ['quantity' => '50.0000', 'baseline_quantity' => '50.0000', 'baseline_movement_id' => 0]
        );

        // Simulate creating a stale pending cash self-order (3 units)
        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'table_id' => $this->tableA->id,
            'order_code' => 'ORD-STALE-CASH-001',
            'idempotency_key' => 'idemp-stale-01',
            'order_date' => now()->toDateString(),
            'order_status' => OrderStatus::Pending,
            'payment_method' => 'cash',
            'subtotal_amount' => 60000,
            'order_amount' => 60000,
            'created_at' => now()->subMinutes(40),
            'updated_at' => now()->subMinutes(40),
        ]);

        $orderItem = OrderItem::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'product_id' => $this->productA->id,
            'qty' => 3,
            'order_price' => 20000,
            'order_subtotal' => 60000,
        ]);

        // Deduct stock for the pending order (simulating order creation)
        $this->productA->stock = 47;
        $this->productA->save();

        ProductStockBalance::where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('product_id', $this->productA->id)
            ->update(['quantity' => '47.0000']);

        // Run the expire command
        Artisan::call('orders:expire-stale-cash');

        $order->refresh();
        $this->assertSame(OrderStatus::Expired, $order->order_status);

        // Check stock restored to 50
        $balance = ProductStockBalance::where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('product_id', $this->productA->id)
            ->first();
        $this->assertSame(0, bccomp((string) $balance->quantity, '50.0000', 4));

        $this->productA->refresh();
        $this->assertSame(50, (int) $this->productA->stock);

        // Check StockMovement recorded
        $movement = StockMovement::where('tenant_id', $this->tenantA->id)
            ->where('order_id', $order->id)
            ->where('type', StockMovementType::RestoreCompensation)
            ->first();
        $this->assertNotNull($movement);
        $this->assertSame(3, (int) $movement->quantity);

        // Run command a second time - must be idempotent (no duplicate restorations)
        Artisan::call('orders:expire-stale-cash');

        $movementsCount = StockMovement::where('tenant_id', $this->tenantA->id)
            ->where('order_id', $order->id)
            ->where('type', StockMovementType::RestoreCompensation)
            ->count();
        $this->assertSame(1, $movementsCount);
    }

    public function test_return_refund_service_disposition_restock_replenishes_inventory(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $this->productA->track_inventory = true;
        $this->productA->stock = 20;
        $this->productA->save();

        // Create completed paid order
        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'order_code' => 'ORD-RETURN-RESTOCK-01',
            'idempotency_key' => 'idemp-restock-01',
            'order_date' => now()->toDateString(),
            'order_status' => OrderStatus::Paid,
            'payment_method' => 'cash',
            'subtotal_amount' => 50000,
            'order_amount' => 50000,
            'created_at' => now()->subHour(),
        ]);

        $orderItem = OrderItem::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'product_id' => $this->productA->id,
            'qty' => 2,
            'order_price' => 25000,
            'order_subtotal' => 50000,
        ]);

        $payment = Payment::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'shift_id' => $this->shiftA->id,
            'device_id' => $this->deviceA->id,
            'payment_method' => 'cash',
            'amount' => 50000,
            'status' => 'captured',
        ]);

        // Process return with RESTOCK disposition
        $orderReturn = $this->returnService->createReturnAndRefund(
            order: $order,
            returnItemsWithDisposition: [
                [
                    'order_item_id' => $orderItem->id,
                    'quantity' => 2,
                    'disposition' => ReturnDispositionEnum::Restock,
                    'disposition_reason' => 'Customer changed mind, sealed package intact',
                ],
            ],
            refundAllocations: [
                [
                    'payment_id' => $payment->id,
                    'amount' => 50000,
                ],
            ],
            shiftId: $this->shiftA->id,
            deviceId: $this->deviceA->id,
            actorUserId: $this->userAOwner->id,
            supervisorPin: '123456',
            reason: 'Retur barang customer',
            idempotencyKey: 'ret-key-restock-01'
        );

        $this->assertNotNull($orderReturn);

        // Stock was 20, returned 2 with restock -> now 22
        $this->productA->refresh();
        $this->assertSame(22, (int) $this->productA->stock);

        // Verify movement
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $this->tenantA->id,
            'order_id' => $order->id,
            'type' => 'restore_compensation',
            'quantity' => 2,
        ]);
    }

    public function test_return_refund_service_disposition_discard_does_not_replenish_stock(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $this->productA->track_inventory = true;
        $this->productA->stock = 30;
        $this->productA->save();

        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'order_code' => 'ORD-RETURN-DISCARD-01',
            'idempotency_key' => 'idemp-discard-01',
            'order_date' => now()->toDateString(),
            'order_status' => OrderStatus::Paid,
            'payment_method' => 'cash',
            'subtotal_amount' => 25000,
            'order_amount' => 25000,
            'created_at' => now()->subHour(),
        ]);

        $orderItem = OrderItem::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'product_id' => $this->productA->id,
            'qty' => 1,
            'order_price' => 25000,
            'order_subtotal' => 25000,
        ]);

        $payment = Payment::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'shift_id' => $this->shiftA->id,
            'device_id' => $this->deviceA->id,
            'payment_method' => 'cash',
            'amount' => 25000,
            'status' => 'captured',
        ]);

        // Process return with DISCARD disposition (damaged item)
        $orderReturn = $this->returnService->createReturnAndRefund(
            order: $order,
            returnItemsWithDisposition: [
                [
                    'order_item_id' => $orderItem->id,
                    'quantity' => 1,
                    'disposition' => ReturnDispositionEnum::WriteOffWaste,
                    'disposition_reason' => 'Makanan terkontaminasi / rusak saat disajikan',
                ],
            ],
            refundAllocations: [
                [
                    'payment_id' => $payment->id,
                    'amount' => 25000,
                ],
            ],
            shiftId: $this->shiftA->id,
            deviceId: $this->deviceA->id,
            actorUserId: $this->userAOwner->id,
            supervisorPin: '123456',
            reason: 'Komplain makanan rusak',
            idempotencyKey: 'ret-key-discard-01'
        );

        $this->assertNotNull($orderReturn);

        // Stock was 30, disposition was discard -> stock remains 30 (NOT increased)
        $this->productA->refresh();
        $this->assertSame(30, (int) $this->productA->stock);

        // Verify NO restore_compensation stock movement was created for this order
        $this->assertDatabaseMissing('stock_movements', [
            'tenant_id' => $this->tenantA->id,
            'order_id' => $order->id,
            'type' => 'restore_compensation',
        ]);
    }

    public function test_return_refund_service_cross_tenant_isolation_rejected(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Create order under Tenant B
        $orderB = app(TenantContext::class)->runWithoutTenant(function () {
            return Order::forceCreate([
                'tenant_id' => $this->tenantB->id,
                'store_id' => $this->storeB->id,
                'user_id' => $this->userBBarista->id,
                'order_code' => 'ORD-TENANT-B-01',
                'idempotency_key' => 'idemp-cross-tenant-01',
                'order_date' => now()->toDateString(),
                'order_status' => OrderStatus::Paid,
                'payment_method' => 'cash',
                'subtotal_amount' => 20000,
                'order_amount' => 20000,
                'created_at' => now(),
            ]);
        });

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('lintas tenant');

        $this->returnService->createReturnAndRefund(
            order: $orderB,
            returnItemsWithDisposition: [],
            refundAllocations: [],
            shiftId: $this->shiftA->id,
            deviceId: $this->deviceA->id,
            actorUserId: $this->userAOwner->id,
            supervisorPin: '123456',
            reason: 'Cross tenant attack',
            idempotencyKey: 'cross-tenant-key'
        );
    }
}
