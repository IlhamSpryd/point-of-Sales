<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\StockMovementType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class StockCompensationHardeningTest extends TestCase
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

    public function test_snap_token_and_sensitive_fields_not_mass_assignable(): void
    {
        $fillable = (new Order)->getFillable();

        $this->assertNotContains('snap_token', $fillable);
        $this->assertNotContains('order_status', $fillable);
        $this->assertNotContains('payment_method', $fillable);
    }

    public function test_restores_stock_exactly_once_even_if_expire_webhook_sent_twice(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        DB::table('product_stock_balances')
            ->where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('product_id', $this->productA->id)
            ->update(['quantity' => 7]);
        $this->productA->update(['stock' => 7]);

        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'order_code' => 'POS-TEST-EXP-001',
            'idempotency_key' => Str::uuid()->toString(),
            'order_date' => now()->toDateString(),
            'subtotal_amount' => 60000,
            'tax_amount' => 6600,
            'order_amount' => 66600,
            'order_status' => OrderStatus::Pending,
            'payment_method' => 'qris',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->productA->id,
            'qty' => 3,
            'order_price' => 20000,
            'order_subtotal' => 60000,
        ]);

        DB::table('stock_movements')->insert([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'product_id' => $this->productA->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'type' => 'sale_deduction',
            'quantity' => -3,
            'reason' => 'sale',
            'idempotency_key' => "sale:{$order->id}:{$item->id}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(TransactionService::class);

        // First webhook
        $service->updateStatusFromMidtransNotification(
            orderCode: $order->order_code,
            transactionStatus: 'expire',
            fraudStatus: null,
        );

        $this->assertEquals(
            1,
            StockMovement::where('order_id', $order->id)
                ->where('type', StockMovementType::RestoreCompensation->value)
                ->count()
        );

        // Second webhook (duplicate)
        $service->updateStatusFromMidtransNotification(
            orderCode: $order->order_code,
            transactionStatus: 'expire',
            fraudStatus: null,
        );

        $this->assertEquals(10, $this->productA->fresh()->stock);
        $this->assertEquals(
            1,
            StockMovement::where('order_id', $order->id)
                ->where('type', StockMovementType::RestoreCompensation->value)
                ->count()
        );
        $this->assertEquals(OrderStatus::Expired, $order->fresh()->order_status);
    }

    public function test_does_not_restore_stock_when_order_becomes_paid(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        DB::table('product_stock_balances')
            ->where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('product_id', $this->productA->id)
            ->update(['quantity' => 8]);
        $this->productA->update(['stock' => 8]);

        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'order_code' => 'POS-TEST-PAID-001',
            'idempotency_key' => Str::uuid()->toString(),
            'order_date' => now()->toDateString(),
            'subtotal_amount' => 40000,
            'tax_amount' => 4400,
            'order_amount' => 44400,
            'order_status' => OrderStatus::Pending,
            'payment_method' => 'qris',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->productA->id,
            'qty' => 2,
            'order_price' => 20000,
            'order_subtotal' => 40000,
        ]);

        app(TransactionService::class)->updateStatusFromMidtransNotification(
            orderCode: $order->order_code,
            transactionStatus: 'settlement',
            fraudStatus: null,
        );

        $this->assertEquals(8, $this->productA->fresh()->stock);
        $this->assertEquals(0, StockMovement::count());
        $this->assertEquals(OrderStatus::Paid, $order->fresh()->order_status);
    }

    public function test_rejects_new_cashier_transaction_if_no_open_shift(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $roleKasir = Role::withoutTenantScope()->firstOrCreate(
            ['name' => 'Kasir', 'tenant_id' => $this->tenantA->id],
            ['is_active' => true]
        );

        $kasir = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role_id' => $roleKasir->id,
        ]);
        $kasir->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

        DB::table('product_stock_balances')
            ->where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('product_id', $this->productA->id)
            ->update(['quantity' => 10]);
        $this->productA->update(['stock' => 10]);

        $initialOrderCount = Order::count();

        $response = $this->actingAs($kasir)
            ->withSession(['active_store_id' => $this->storeA->id])
            ->postJson(route('transaction.store'), [
                'idempotency_key' => Str::uuid()->toString(),
                'items' => [['product_id' => $this->productA->id, 'quantity' => 1]],
                'payment_method' => 'cash',
                'cash_received' => 25000,
            ]);

        $response->assertStatus(400);
        $this->assertEquals($initialOrderCount, Order::count());
    }

    public function test_auto_assigns_shift_id_when_cashier_has_open_shift(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $roleKasir = Role::withoutTenantScope()->firstOrCreate(
            ['name' => 'Kasir', 'tenant_id' => $this->tenantA->id],
            ['is_active' => true]
        );

        $kasir = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role_id' => $roleKasir->id,
        ]);
        $kasir->stores()->attach($this->storeA->id, ['tenant_id' => $this->tenantA->id]);

        $shift = Shift::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $kasir->id,
            'opening_balance' => 100000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        DB::table('product_stock_balances')
            ->where('tenant_id', $this->tenantA->id)
            ->where('store_id', $this->storeA->id)
            ->where('product_id', $this->productA->id)
            ->update(['quantity' => 10]);
        $this->productA->update(['stock' => 10]);

        $response = $this->actingAs($kasir)
            ->withSession(['active_store_id' => $this->storeA->id])
            ->postJson(route('transaction.store'), [
                'idempotency_key' => Str::uuid()->toString(),
                'items' => [['product_id' => $this->productA->id, 'quantity' => 1]],
                'payment_method' => 'cash',
                'cash_received' => 25000,
            ]);

        $response->assertOk();
        $this->assertEquals($shift->id, Order::latest('id')->first()->shift_id);
    }
}
