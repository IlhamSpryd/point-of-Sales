<?php

declare(strict_types=1);

namespace Tests\Feature\Domain3;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentMethodEnum;
use App\Enums\PaymentStatusEnum;
use App\Enums\PreparationStatus;
use App\Livewire\DiscountManager;
use App\Livewire\Kasir\CreateOrder;
use App\Livewire\Kasir\OrderHistory;
use App\Livewire\Kds\Board;
use App\Models\Category;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class FrontendArchitectureAuditTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected Role $ownerRole;
    protected Role $kasirRole;
    protected Role $waiterRole;
    protected Role $cookRole;
    protected User $cashierUser;
    protected User $waiterUser;
    protected User $cookUser;
    protected User $ownerUser;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::create([
            'name' => 'Domain 3 Tenant',
            'slug' => 'domain-3-tenant',
            'status' => 'active',
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Domain 3 Store',
            'is_active' => true,
        ]);

        $context->setStoreId($this->store->id);

        $this->ownerRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'permissions' => ['*'],
        ]);

        $this->kasirRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kasir',
            'permissions' => ['orders.create', 'orders.view', 'can_void_order'],
        ]);

        $this->waiterRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Waiter',
            'permissions' => ['orders.view'],
        ]);

        $this->cookRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cook',
            'permissions' => ['kds.view', 'kds.update'],
        ]);

        $this->cashierUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->kasirRole->id,
        ]);

        $this->waiterUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->waiterRole->id,
        ]);

        $this->cookUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->cookRole->id,
        ]);

        $this->ownerUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->ownerRole->id,
        ]);

        foreach ([$this->cashierUser, $this->waiterUser, $this->cookUser, $this->ownerUser] as $user) {
            DB::table('user_stores')->insert([
                'tenant_id' => $this->tenant->id,
                'user_id' => $user->id,
                'store_id' => $this->store->id,
            ]);
        }
    }

    private function openShiftFor(User $user): Shift
    {
        return Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $user->id,
            'status' => 'open',
            'opening_balance' => 100000,
            'opened_at' => now(),
        ]);
    }

    public function test_non_cashier_role_cannot_access_create_order_component(): void
    {
        Livewire::actingAs($this->waiterUser)
            ->test(CreateOrder::class)
            ->assertStatus(403);
    }

    public function test_cashier_can_mount_create_order_component(): void
    {
        Livewire::actingAs($this->cashierUser)
            ->test(CreateOrder::class)
            ->assertStatus(200);
    }

    public function test_load_pending_order_does_not_crash_when_order_not_found(): void
    {
        Livewire::actingAs($this->cashierUser)
            ->test(CreateOrder::class)
            ->call('loadPendingOrder', 'POS-NONEXISTENT')
            ->assertDispatched('toast', message: 'Pesanan tidak ditemukan atau sudah dibayar.', type: 'error')
            ->assertSet('pendingOrderId', null);
    }

    public function test_cannot_add_product_directly_when_required_modifiers_exist(): void
    {
        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Kopi',
        ]);

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Latte Custom',
            'product_price' => 30000,
            'stock' => 50,
            'is_active' => true,
        ]);

        $group = ModifierGroup::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pilihan Suhu',
            'selection_type' => 'single',
            'is_required' => true,
        ]);

        $product->modifierGroups()->attach($group->id, ['tenant_id' => $this->tenant->id]);

        Livewire::actingAs($this->cashierUser)
            ->test(CreateOrder::class)
            ->call('addToCartDirectly', $product->id)
            ->assertSet('selectingProductId', $product->id)
            ->assertSet('cart', []);
    }

    public function test_confirm_add_to_cart_fails_validation_when_required_modifier_missing(): void
    {
        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Minuman',
        ]);

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Americano',
            'product_price' => 25000,
            'stock' => 50,
            'is_active' => true,
        ]);

        $group = ModifierGroup::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Pilihan Suhu',
            'selection_type' => 'single',
            'is_required' => true,
        ]);

        Modifier::create([
            'tenant_id' => $this->tenant->id,
            'modifier_group_id' => $group->id,
            'name' => 'Hot',
            'extra_price' => 0,
            'is_default' => false,
            'is_active' => true,
        ]);

        $product->modifierGroups()->attach($group->id, ['tenant_id' => $this->tenant->id]);

        Livewire::actingAs($this->cashierUser)
            ->test(CreateOrder::class)
            ->call('openModifierPicker', $product->id)
            ->set('pendingModifierIds', []) // Missing required modifier
            ->call('confirmAddToCart')
            ->assertHasErrors(['cart'])
            ->assertSet('cart', []);
    }

    public function test_successful_order_resets_cart_and_state(): void
    {
        $this->openShiftFor($this->cashierUser);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Makanan',
        ]);

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Croissant',
            'product_price' => 20000,
            'stock' => 50,
            'is_active' => true,
        ]);

        $table = Table::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'table_name' => 'Meja 01',
            'is_active' => true,
        ]);

        $component = Livewire::actingAs($this->cashierUser)
            ->test(CreateOrder::class)
            ->set('orderType', 'dine_in')
            ->set('tableId', $table->id)
            ->call('addToCartDirectly', $product->id);

        $totalAmount = $component->get('totalAmount');

        $component->call('submitOrder', [
            ['method' => 'cash', 'amount' => $totalAmount],
        ])
            ->assertHasNoErrors()
            ->assertDispatched('transaction-success')
            ->assertSet('cart', [])
            ->assertSet('orderType', null)
            ->assertSet('tableId', null)
            ->assertSet('customerId', null);
    }

    public function test_failed_order_preserves_cart(): void
    {
        // Don't open shift, so TransactionService fails
        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Snack',
        ]);

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Muffin',
            'product_price' => 15000,
            'stock' => 50,
            'is_active' => true,
        ]);

        $table = Table::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'table_name' => 'Meja 02',
            'is_active' => true,
        ]);

        $component = Livewire::actingAs($this->cashierUser)
            ->test(CreateOrder::class)
            ->set('orderType', 'dine_in')
            ->set('tableId', $table->id)
            ->call('addToCartDirectly', $product->id);

        $totalAmount = $component->get('totalAmount');

        $component->call('submitOrder', [
            ['method' => 'cash', 'amount' => $totalAmount],
        ])
            ->assertHasErrors(['cart'])
            ->assertCount('cart', 1); // Cart remains intact
    }

    public function test_pending_order_checkout_with_cash_succeeds_when_cash_sufficient(): void
    {
        $shift = $this->openShiftFor($this->cashierUser);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Minuman',
        ]);

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Kopi Tubruk',
            'product_price' => 10000,
            'stock' => 50,
            'is_active' => true,
        ]);

        $table = Table::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'table_name' => 'Meja 05',
            'is_active' => true,
        ]);

        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashierUser->id,
            'shift_id' => $shift->id,
            'order_code' => 'POS-RETRIEVE-01',
            'order_status' => OrderStatus::Pending,
            'payment_method' => PaymentMethod::Cash,
            'order_date' => now()->toDateString(),
            'subtotal_amount' => 10000,
            'discount_amount' => 0,
            'tax_amount' => 1100,
            'service_charge_amount' => 0,
            'order_amount' => 11100,
            'table_id' => $table->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        OrderItem::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'qty' => 1,
            'order_price' => 10000,
            'order_subtotal' => 10000,
            'options' => [],
            'notes' => null,
            'preparation_status' => PreparationStatus::Pending,
        ]);

        Livewire::actingAs($this->cashierUser)
            ->test(CreateOrder::class)
            ->call('loadPendingOrder', 'POS-RETRIEVE-01')
            ->assertSet('pendingOrderId', $order->id)
            ->set('cashReceived', 11100)
            ->call('submitOrder') // Called with NO arguments, as rendered by the UI button
            ->assertHasNoErrors()
            ->assertDispatched('transaction-success')
            ->assertSet('pendingOrderId', null)
            ->assertSet('cart', []);

        $fresh = $order->fresh();
        $this->assertEquals(OrderStatus::Paid, $fresh->order_status);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::Cash->value,
            'amount' => 11100,
            'status' => PaymentStatusEnum::Captured->value,
        ]);
    }

    public function test_pending_order_checkout_fails_when_cash_insufficient(): void
    {
        $this->openShiftFor($this->cashierUser);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Minuman',
        ]);

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Es Teh Manis',
            'product_price' => 8000,
            'stock' => 50,
            'is_active' => true,
        ]);

        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashierUser->id,
            'order_code' => 'POS-SHORT-01',
            'order_status' => OrderStatus::Pending,
            'payment_method' => PaymentMethod::Cash,
            'order_date' => now()->toDateString(),
            'subtotal_amount' => 8000,
            'discount_amount' => 0,
            'tax_amount' => 880,
            'service_charge_amount' => 0,
            'order_amount' => 8880,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        OrderItem::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'qty' => 1,
            'order_price' => 8000,
            'order_subtotal' => 8000,
            'options' => [],
            'notes' => null,
            'preparation_status' => PreparationStatus::Pending,
        ]);

        Livewire::actingAs($this->cashierUser)
            ->test(CreateOrder::class)
            ->call('loadPendingOrder', 'POS-SHORT-01')
            ->set('cashReceived', 5000) // Less than totalAmount (~8900)
            ->call('submitOrder')
            ->assertHasErrors(['cashReceived']);
    }

    public function test_void_order_from_modal_without_arguments_succeeds(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashierUser->id,
            'order_code' => 'POS-VOID-TEST-01',
            'order_status' => OrderStatus::Paid,
            'payment_method' => PaymentMethod::Cash,
            'order_date' => now()->toDateString(),
            'subtotal_amount' => 20000,
            'discount_amount' => 0,
            'tax_amount' => 2200,
            'service_charge_amount' => 0,
            'order_amount' => 22200,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        Payment::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::Cash,
            'amount' => 22200,
            'status' => PaymentStatusEnum::Captured,
            'captured_at' => now(),
            'processed_by' => $this->cashierUser->id,
            'idempotency_key' => 'pay-void-dom3',
        ]);

        // Simulating the UI interaction: cashier clicks void button which sets voidingOrderId,
        // then fills voidReason, then submits the form without arguments.
        Livewire::actingAs($this->cashierUser)
            ->test(OrderHistory::class)
            ->set('voidingOrderId', $order->id)
            ->set('voidReason', 'Pelanggan membatalkan pesanan secara langsung')
            ->call('voidOrder') // No arguments!
            ->assertHasNoErrors()
            ->assertSet('voidingOrderId', null)
            ->assertSet('voidReason', '');

        $fresh = $order->fresh();
        $this->assertEquals(OrderStatus::Void, $fresh->order_status);
        $this->assertEquals('Pelanggan membatalkan pesanan secara langsung', $fresh->void_reason);
    }

    public function test_void_order_without_permission_fails_with_403(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->waiterUser->id,
            'order_code' => 'POS-VOID-DENIED',
            'order_status' => OrderStatus::Paid,
            'payment_method' => PaymentMethod::Cash,
            'order_date' => now()->toDateString(),
            'subtotal_amount' => 20000,
            'discount_amount' => 0,
            'tax_amount' => 2200,
            'service_charge_amount' => 0,
            'order_amount' => 22200,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        Livewire::actingAs($this->waiterUser)
            ->test(OrderHistory::class)
            ->set('voidingOrderId', $order->id)
            ->set('voidReason', 'Coba batalkan tanpa izin')
            ->call('voidOrder')
            ->assertStatus(403);
    }

    public function test_discount_manager_rejects_non_manager_roles(): void
    {
        Livewire::actingAs($this->cashierUser)
            ->test(DiscountManager::class)
            ->assertStatus(403);

        Livewire::actingAs($this->ownerUser)
            ->test(DiscountManager::class)
            ->assertStatus(200);
    }

    public function test_kds_board_requires_kitchen_roles_for_claim(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashierUser->id,
            'order_code' => 'POS-KDS-01',
            'order_status' => OrderStatus::Paid,
            'payment_method' => PaymentMethod::Cash,
            'order_date' => now()->toDateString(),
            'subtotal_amount' => 15000,
            'discount_amount' => 0,
            'tax_amount' => 1650,
            'service_charge_amount' => 0,
            'order_amount' => 16650,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Kopi',
        ]);

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Cappuccino',
            'product_price' => 15000,
            'stock' => 50,
            'is_active' => true,
        ]);

        $item = OrderItem::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'qty' => 1,
            'order_price' => 15000,
            'order_subtotal' => 15000,
            'options' => [],
            'notes' => null,
            'preparation_status' => PreparationStatus::Pending,
        ]);

        // Waiter role is rejected with 403
        Livewire::actingAs($this->waiterUser)
            ->test(Board::class)
            ->call('claim', $item->id)
            ->assertStatus(403);

        // Cook role can claim successfully
        Livewire::actingAs($this->cookUser)
            ->test(Board::class)
            ->call('claim', $item->id)
            ->assertHasNoErrors();

        $fresh = $item->fresh();
        $this->assertEquals(PreparationStatus::Brewing, $fresh->preparation_status);
        $this->assertEquals($this->cookUser->id, $fresh->processed_by);
    }

    public function test_void_order_cross_tenant_manipulation_fails(): void
    {
        $context = app(TenantContext::class);

        $tenantB = Tenant::create([
            'name' => 'Tenant Rival',
            'slug' => 'tenant-rival',
            'status' => 'active',
        ]);

        $context->setTenantId($tenantB->id);

        $storeB = Store::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Store Rival',
            'is_active' => true,
        ]);

        $context->setStoreId($storeB->id);

        $rivalOrder = Order::forceCreate([
            'tenant_id' => $tenantB->id,
            'store_id' => $storeB->id,
            'user_id' => $this->cashierUser->id,
            'order_code' => 'RIVAL-ORDER-99',
            'order_status' => OrderStatus::Paid,
            'payment_method' => PaymentMethod::Cash,
            'order_date' => now()->toDateString(),
            'subtotal_amount' => 50000,
            'order_amount' => 55000,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        Payment::forceCreate([
            'tenant_id' => $tenantB->id,
            'store_id' => $storeB->id,
            'order_id' => $rivalOrder->id,
            'payment_method' => PaymentMethodEnum::Cash,
            'amount' => 55000,
            'status' => PaymentStatusEnum::Captured,
            'captured_at' => now(),
            'idempotency_key' => 'rival-pay-01',
        ]);

        // Restore context to test cashier's tenant and store
        $context->setTenantId($this->tenant->id);
        $context->setStoreId($this->store->id);

        try {
            Livewire::actingAs($this->cashierUser)
                ->test(OrderHistory::class)
                ->call('voidOrder', $rivalOrder->id, 'Mencoba sabotase pesanan tenant lain');

            $this->fail('Expected ModelNotFoundException was not thrown.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $fresh = Order::withoutGlobalScopes()->find($rivalOrder->id);
        $this->assertEquals(OrderStatus::Paid, $fresh->order_status);
        $this->assertNull($fresh->void_reason);
    }

    public function test_void_order_cross_store_manipulation_fails(): void
    {
        $context = app(TenantContext::class);

        $store2 = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store Cabang Lain',
            'is_active' => true,
        ]);

        $context->setStoreId($store2->id);

        $otherStoreOrder = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $store2->id,
            'user_id' => $this->cashierUser->id,
            'order_code' => 'STORE2-ORDER-01',
            'order_status' => OrderStatus::Paid,
            'payment_method' => PaymentMethod::Cash,
            'order_date' => now()->toDateString(),
            'subtotal_amount' => 30000,
            'order_amount' => 33000,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        Payment::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $store2->id,
            'order_id' => $otherStoreOrder->id,
            'payment_method' => PaymentMethodEnum::Cash,
            'amount' => 33000,
            'status' => PaymentStatusEnum::Captured,
            'captured_at' => now(),
            'idempotency_key' => 'store2-pay-01',
        ]);

        // Restore context to test cashier's active store
        $context->setStoreId($this->store->id);

        try {
            Livewire::actingAs($this->cashierUser)
                ->test(OrderHistory::class)
                ->call('voidOrder', $otherStoreOrder->id, 'Mencoba void pesanan cabang lain');

            $this->fail('Expected ModelNotFoundException was not thrown.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $fresh = Order::withoutGlobalScopes()->find($otherStoreOrder->id);
        $this->assertEquals(OrderStatus::Paid, $fresh->order_status);
        $this->assertNull($fresh->void_reason);
    }

    public function test_void_order_direct_call_with_arguments_succeeds_without_opening_modal(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashierUser->id,
            'order_code' => 'POS-VOID-DIRECT-01',
            'order_status' => OrderStatus::Paid,
            'payment_method' => PaymentMethod::Cash,
            'order_date' => now()->toDateString(),
            'subtotal_amount' => 20000,
            'order_amount' => 22000,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        Payment::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::Cash,
            'amount' => 22000,
            'status' => PaymentStatusEnum::Captured,
            'captured_at' => now(),
            'processed_by' => $this->cashierUser->id,
            'idempotency_key' => 'pay-direct-01',
        ]);

        Livewire::actingAs($this->cashierUser)
            ->test(OrderHistory::class)
            ->call('voidOrder', $order->id, 'Pembatalan langsung via Livewire action call')
            ->assertHasNoErrors()
            ->assertDispatched('toast', message: 'Pesanan berhasil di-void dan stok dikembalikan.', type: 'success');

        $fresh = $order->fresh();
        $this->assertEquals(OrderStatus::Void, $fresh->order_status);
        $this->assertEquals('Pembatalan langsung via Livewire action call', $fresh->void_reason);
    }

    public function test_void_order_rejects_non_paid_order(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashierUser->id,
            'order_code' => 'POS-PENDING-VOID-TEST',
            'order_status' => OrderStatus::Pending,
            'payment_method' => PaymentMethod::Cash,
            'order_date' => now()->toDateString(),
            'subtotal_amount' => 15000,
            'order_amount' => 16500,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        Livewire::actingAs($this->cashierUser)
            ->test(OrderHistory::class)
            ->call('voidOrder', $order->id, 'Mencoba batalkan order pending')
            ->assertDispatched('toast', message: 'Pesanan tidak ditemukan atau tidak dalam status Lunas.', type: 'error');

        $fresh = $order->fresh();
        $this->assertEquals(OrderStatus::Pending, $fresh->order_status);
    }

    public function test_void_order_validates_minimum_reason_length(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashierUser->id,
            'order_code' => 'POS-SHORT-REASON-TEST',
            'order_status' => OrderStatus::Paid,
            'payment_method' => PaymentMethod::Cash,
            'order_date' => now()->toDateString(),
            'subtotal_amount' => 15000,
            'order_amount' => 16500,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        Livewire::actingAs($this->cashierUser)
            ->test(OrderHistory::class)
            ->call('voidOrder', $order->id, 'bad')
            ->assertHasErrors(['voidReason']);

        $fresh = $order->fresh();
        $this->assertEquals(OrderStatus::Paid, $fresh->order_status);
    }

    public function test_regular_checkout_without_payment_legs_is_rejected(): void
    {
        $this->openShiftFor($this->cashierUser);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Snack',
        ]);
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Chips',
            'product_price' => 12000,
            'stock' => 50,
            'is_active' => true,
        ]);

        Livewire::actingAs($this->cashierUser)
            ->test(CreateOrder::class)
            ->set('orderType', 'takeaway')
            ->call('addToCartDirectly', $product->id)
            ->call('submitOrder', [])
            ->assertHasErrors(['cart'])
            ->assertCount('cart', 1);

        $this->assertEquals(0, Order::count());
    }

    public function test_checkout_idempotency_key_redirects_and_prevents_duplicate_processing(): void
    {
        $this->openShiftFor($this->cashierUser);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Minuman',
        ]);
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Teh Botol',
            'product_price' => 5000,
            'stock' => 50,
            'is_active' => true,
        ]);

        $idempotencyKey = (string) Str::uuid();

        $component = Livewire::actingAs($this->cashierUser)
            ->test(CreateOrder::class)
            ->set('orderType', 'takeaway')
            ->call('addToCartDirectly', $product->id);

        $totalAmount = $component->get('totalAmount');

        $component->call('submitOrder', [
            ['method' => 'cash', 'amount' => $totalAmount],
        ], $idempotencyKey)
            ->assertHasNoErrors()
            ->assertDispatched('transaction-success');

        $this->assertEquals(1, Order::where('idempotency_key', $idempotencyKey)->count());
        $order = Order::where('idempotency_key', $idempotencyKey)->first();

        Livewire::actingAs($this->cashierUser)
            ->test(CreateOrder::class)
            ->set('orderType', 'takeaway')
            ->call('addToCartDirectly', $product->id)
            ->call('submitOrder', [
                ['method' => 'cash', 'amount' => $totalAmount],
            ], $idempotencyKey)
            ->assertRedirect(route('transaction.receipt', $order->order_code));

        $this->assertEquals(1, Order::where('idempotency_key', $idempotencyKey)->count());
        $this->assertEquals(1, Payment::where('order_id', $order->id)->count());
    }

    public function test_order_history_lifecycle_rejects_unauthorized_role(): void
    {
        $inventoryRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Inventory',
            'permissions' => ['inventory.*'],
        ]);
        $inventoryUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $inventoryRole->id,
        ]);
        DB::table('user_stores')->insert([
            'tenant_id' => $this->tenant->id,
            'user_id' => $inventoryUser->id,
            'store_id' => $this->store->id,
        ]);

        Livewire::actingAs($inventoryUser)
            ->test(OrderHistory::class)
            ->assertStatus(403);
    }

    public function test_kds_board_lifecycle_rejects_unauthorized_role(): void
    {
        $inventoryRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Inventory',
            'permissions' => ['inventory.*'],
        ]);
        $inventoryUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $inventoryRole->id,
        ]);
        DB::table('user_stores')->insert([
            'tenant_id' => $this->tenant->id,
            'user_id' => $inventoryUser->id,
            'store_id' => $this->store->id,
        ]);

        Livewire::actingAs($inventoryUser)
            ->test(Board::class)
            ->assertStatus(403);
    }
}
