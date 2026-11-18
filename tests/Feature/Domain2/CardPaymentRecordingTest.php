<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Core\Context\TenantContext;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Organization\Models\User;
use App\Modules\Sales\Models\Shift;
use App\Modules\Sales\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CardPaymentRecordingTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Store $store;

    protected User $cashier;

    protected Product $product;

    protected Shift $shift;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::create([
            'name' => 'Card Tenant',
            'readiness_status' => 'active',
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Card Branch',
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
            'category_name' => 'Retail',
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Tumbler',
            'product_price' => 100000,
            'stock' => 10,
        ]);
    }

    public function test_card_payment_records_card_method_on_order(): void
    {
        $service = app(TransactionService::class);

        $order = $service->createTransaction([
            'shift_id' => $this->shift->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                ],
            ],
            'payments' => [
                [
                    'method' => PaymentMethod::Card,
                    'amount' => 111000, // 100,000 + 11% PPN (11,000)
                    'reference_number' => 'EDC-BCA-9876',
                ],
            ],
            'idempotency_key' => 'card-test-key-1',
        ], $this->cashier->id);

        $fresh = $order->fresh();
        $this->assertEquals(OrderStatus::Paid, $fresh->order_status);
        $this->assertEquals('card', $fresh->payment_method->value ?? $fresh->payment_method, 'Order payment_method must be card, not qris');

        $this->assertDatabaseHas('payments', [
            'order_id' => $fresh->id,
            'payment_method' => PaymentMethod::Card->value,
            'amount' => 111000,
            'reference_number' => 'EDC-BCA-9876',
        ]);
    }
}
