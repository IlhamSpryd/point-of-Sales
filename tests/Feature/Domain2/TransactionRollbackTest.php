<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Enums\PaymentMethodEnum;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TransactionRollbackTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected User $cashier;
    protected Shift $shift;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::create([
            'name' => 'Rollback Tenant',
            'slug' => 'rollback-tenant',
            'status' => 'active',
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Rollback Store',
            'is_active' => true,
        ]);

        $context->setStoreId($this->store->id);

        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cashier',
            'permissions' => [],
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

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Beverage',
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Iced Tea',
            'product_price' => 10000,
            'stock' => 50,
            'is_active' => true,
        ]);
    }

    public function test_mid_transaction_payment_mismatch_rolls_back_entire_transaction(): void
    {
        $initialStock = $this->product->stock;
        $service = app(TransactionService::class);

        // Subtotal = 20,000, Tax (11%) = 2,200, Total = 22,200.
        // We submit split payments that sum to 20,000 (mismatch by 2,200).
        $data = [
            'shift_id' => $this->shift->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                ],
            ],
            'payments' => [
                [
                    'method' => PaymentMethodEnum::Cash->value,
                    'amount' => 20000,
                ],
            ],
            'idempotency_key' => 'rollback-key-001',
        ];

        try {
            $service->createTransaction($data, $this->cashier->id);
            $this->fail('Expected ValidationException was not thrown on payment sum mismatch.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payments', $e->errors());
        }

        // Assert atomicity: 0 orders, 0 order items, 0 payments, 0 stock movements created
        $this->assertDatabaseMissing('orders', ['idempotency_key' => 'rollback-key-001']);
        $this->assertEquals(0, Order::count());
        $this->assertEquals(0, OrderItem::count());
        $this->assertEquals(0, Payment::count());
        $this->assertEquals(0, StockMovement::count());

        // Assert product stock was restored / never committed
        $this->assertEquals($initialStock, $this->product->fresh()->stock);
    }

    public function test_insufficient_stock_fails_fast_and_creates_no_records(): void
    {
        $service = app(TransactionService::class);

        // Attempt to order 100 items when stock is 50
        $data = [
            'shift_id' => $this->shift->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 100,
                ],
            ],
            'payments' => [
                [
                    'method' => PaymentMethodEnum::Cash->value,
                    'amount' => 1110000,
                ],
            ],
            'idempotency_key' => 'rollback-key-002',
        ];

        try {
            $service->createTransaction($data, $this->cashier->id);
            $this->fail('Expected ValidationException was not thrown on insufficient stock.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }

        $this->assertDatabaseMissing('orders', ['idempotency_key' => 'rollback-key-002']);
        $this->assertEquals(0, Order::count());
        $this->assertEquals(50, $this->product->fresh()->stock);
    }
}
