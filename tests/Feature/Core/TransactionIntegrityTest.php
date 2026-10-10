<?php

namespace Tests\Feature\Core;

use App\Livewire\ShiftManager;
use App\Models\CashDrawerMovement;
use App\Models\Category;
use App\Models\Discount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class TransactionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private $tenant;

    private $store;

    private $product;

    private $transactionService;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->tenant = Tenant::forceCreate(['name' => 'Tenant Core']);
            $this->store = Store::forceCreate(['tenant_id' => $this->tenant->id, 'name' => 'Store Core']);
            $category = Category::forceCreate(['tenant_id' => $this->tenant->id, 'category_name' => 'Cat']);

            $this->product = Product::forceCreate([
                'tenant_id' => $this->tenant->id,
                'category_id' => $category->id,
                'product_name' => 'Item Core',
                'product_price' => 50000,
                'stock' => 10,
                'is_active' => true,
            ]);
        });

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store->id);

        $this->transactionService = app(TransactionService::class);
    }

    public function test_negative_quantity_is_rejected_in_transaction()
    {
        $this->expectException(ValidationException::class);

        $user = User::forceCreate([
            'tenant_id' => $this->tenant->id,
            'name' => 'User',
            'email' => 'u@test.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $this->transactionService->createTransaction([
            'order_type' => 'dine_in',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => -1,
                    'notes' => '',
                ],
            ],
            'payment_method' => 'cash',
            'cash_received' => 50000,
        ], $user->id);
    }

    public function test_expired_discount_is_ignored()
    {
        $discount = Discount::forceCreate([
            'tenant_id' => $this->tenant->id,
            'name' => 'Expired Discount',
            'type' => 'percentage',
            'value' => 50,
            'min_purchase_amount' => 10000,
            'is_active' => true,
            'valid_from' => now()->subDays(10),
            'valid_until' => now()->subDays(1),
        ]);

        $user = User::forceCreate([
            'tenant_id' => $this->tenant->id,
            'name' => 'User',
            'email' => 'u2@test.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $order = $this->transactionService->createTransaction([
            'order_type' => 'dine_in',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'notes' => '',
                ],
            ],
            'discount_id' => $discount->id,
            'payment_method' => 'cash',
            'cash_received' => 100000,
        ], $user->id);

        // Expect 50000 + 11% tax = 55500 (assuming tax is 11%). Let's just check the discount was ignored
        $this->assertNull($order->discount_id);
    }

    public function test_shift_manager_expected_cash_includes_split_payments_and_cash_movements()
    {
        $user = User::forceCreate([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cashier',
            'email' => 'cashier@test.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $shift = Shift::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $user->id,
            'opening_balance' => 100000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        // Order 1: Split payment (Total 50000 -> 20000 Cash, 30000 QRIS)
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'order_code' => 'ORD-SPLIT-1',
            'idempotency_key' => 'IDEMP-SPLIT-1',
            'order_date' => now()->toDateString(),
            'subtotal_amount' => 50000,
            'order_amount' => 50000,
            'payment_method' => 'qris', // Dominant method is QRIS! Old logic would count 0 cash!
            'order_status' => 'paid',
        ]);

        Payment::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'payment_method' => 'cash',
            'amount' => 20000,
            'status' => 'captured',
            'reference_number' => 'PAY-CASH-1',
        ]);

        Payment::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'payment_method' => 'qris',
            'amount' => 30000,
            'status' => 'captured',
            'reference_number' => 'PAY-QRIS-1',
        ]);

        // Cash Drawer Movement: Cash In 50000, Cash Out 10000
        CashDrawerMovement::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'shift_id' => $shift->id,
            'created_by' => $user->id,
            'type' => 'cash_in',
            'category' => 'restock_change',
            'amount' => 50000,
            'reason' => 'Petty cash top up',
        ]);

        CashDrawerMovement::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'shift_id' => $shift->id,
            'created_by' => $user->id,
            'type' => 'cash_out',
            'category' => 'petty_expense',
            'amount' => 10000,
            'reason' => 'Ice purchase',
        ]);

        // Expected cash = 100000 (opening) + 20000 (cash payment) + 50000 (cash in) - 10000 (cash out) = 160000
        $component = Livewire::actingAs($user)->test(ShiftManager::class);
        $component->assertViewHas('expectedCash', 160000);
        $component->assertViewHas('cashSales', 20000);

        // Now close shift with actual cash counted 160000
        $component->set('closing_balance', 160000);
        $component->call('closeShift');

        $shift->refresh();
        $this->assertEquals('closed', $shift->status);
        $this->assertEquals(160000, $shift->expected_cash);
        $this->assertEquals(160000, $shift->closing_balance);
        $this->assertEquals(0, $shift->cash_difference);
    }

    public function test_failed_and_pending_payments_do_not_count_into_expected_cash()
    {
        $user = User::forceCreate([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cashier2',
            'email' => 'cashier2@test.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $shift = Shift::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $user->id,
            'opening_balance' => 100000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'order_code' => 'ORD-FAIL-1',
            'idempotency_key' => 'IDEMP-FAIL-1',
            'order_date' => now()->toDateString(),
            'subtotal_amount' => 50000,
            'order_amount' => 50000,
            'payment_method' => 'cash',
            'order_status' => 'paid',
        ]);

        // A captured leg of 30000 + a failed leg of 20000.
        Payment::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'payment_method' => 'cash',
            'amount' => 30000,
            'status' => 'captured',
            'reference_number' => 'PAY-CAP-1',
        ]);

        Payment::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'payment_method' => 'cash',
            'amount' => 20000,
            'status' => 'failed',
            'reference_number' => 'PAY-FAIL-1',
        ]);

        // Expected cash must be 100000 + 30000 = 130000 (failed leg excluded).
        $component = Livewire::actingAs($user)->test(ShiftManager::class);
        $component->assertViewHas('cashSales', 30000);
        $component->assertViewHas('expectedCash', 130000);
    }

    public function test_voided_cash_payment_reverses_previous_capture_not_double_count()
    {
        $user = User::forceCreate([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cashier3',
            'email' => 'cashier3@test.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $shift = Shift::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $user->id,
            'opening_balance' => 50000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'order_code' => 'ORD-VOID-1',
            'idempotency_key' => 'IDEMP-VOID-1',
            'order_date' => now()->toDateString(),
            'subtotal_amount' => 25000,
            'order_amount' => 25000,
            'payment_method' => 'cash',
            'order_status' => 'void',
        ]);

        // Original captured leg (cash entered drawer).
        Payment::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'payment_method' => 'cash',
            'amount' => 25000,
            'status' => 'captured',
            'reference_number' => 'PAY-VOID-CAP',
        ]);

        // Immutable void counter-entry (audit trail), net effect = 0.
        Payment::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'payment_method' => 'cash',
            'amount' => 25000,
            'status' => 'voided',
            'reference_number' => 'PAY-VOID-CTR',
        ]);

        // Expected cash = 50000 (opening) + 25000 (captured) - 25000 (void counter) = 50000.
        $component = Livewire::actingAs($user)->test(ShiftManager::class);
        $component->assertViewHas('cashSales', 0);
        $component->assertViewHas('expectedCash', 50000);
    }
}
