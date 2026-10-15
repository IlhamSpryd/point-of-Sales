<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatusEnum;
use App\Livewire\Kasir\OrderHistory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrderVoidIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected User $cashier;
    protected Role $adminRole;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::create([
            'name' => 'Void Tenant',
            'readiness_status' => 'active',
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Main Branch',
        ]);

        $context->setStoreId($this->store->id);

        $this->adminRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin',
            'permissions' => ['can_void_order'],
        ]);

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->adminRole->id,
        ]);
    }

    public function test_void_order_persists_void_status_and_audit_fields(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'order_code' => 'POS-VOID-001',
            'order_date' => now()->toDateString(),
            'order_status' => OrderStatus::Paid,
            'payment_method' => 'cash',
            'subtotal_amount' => 50000,
            'tax_amount' => 5500,
            'order_amount' => 55500,
            'idempotency_key' => 'void-test-uuid-1',
        ]);

        Payment::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'payment_method' => PaymentMethod::Cash,
            'amount' => 55500,
            'status' => PaymentStatusEnum::Captured,
            'captured_at' => now(),
            'processed_by' => $this->cashier->id,
            'idempotency_key' => 'pay-void-1',
        ]);

        $service = app(TransactionService::class);
        $service->voidOrder($order, 'Pelanggan membatalkan pesanan', $this->cashier->id);

        $fresh = $order->fresh();
        $this->assertEquals(OrderStatus::Void, $fresh->order_status, 'Order status must be updated to Void in database');
        $this->assertEquals($this->cashier->id, $fresh->voided_by, 'voided_by must record the user ID');
        $this->assertEquals('Pelanggan membatalkan pesanan', $fresh->void_reason, 'void_reason must be recorded');
        $this->assertNotNull($fresh->voided_at, 'voided_at timestamp must be set');

        // Assert counter-payment row was created with voided status
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'status' => PaymentStatusEnum::Voided->value,
        ]);

        // Assert re-voiding throws exception because order is no longer Paid
        $this->expectException(\Exception::class);
        $service->voidOrder($fresh, 'Percobaan void kedua', $this->cashier->id);
    }

    public function test_livewire_order_history_propagates_void_reason_parameter(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'order_code' => 'POS-VOID-002',
            'order_date' => now()->toDateString(),
            'order_status' => OrderStatus::Paid,
            'payment_method' => 'cash',
            'subtotal_amount' => 20000,
            'tax_amount' => 2200,
            'order_amount' => 22200,
            'idempotency_key' => 'void-test-uuid-2',
        ]);

        Payment::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'payment_method' => PaymentMethod::Cash,
            'amount' => 22200,
            'status' => PaymentStatusEnum::Captured,
            'captured_at' => now(),
            'processed_by' => $this->cashier->id,
            'idempotency_key' => 'pay-void-2',
        ]);

        Livewire::actingAs($this->cashier)
            ->test(OrderHistory::class)
            ->call('voidOrder', $order->id, 'Salah input meja oleh kasir')
            ->assertHasNoErrors();

        $fresh = $order->fresh();
        $this->assertEquals(OrderStatus::Void, $fresh->order_status);
        $this->assertEquals('Salah input meja oleh kasir', $fresh->void_reason);
    }
}
