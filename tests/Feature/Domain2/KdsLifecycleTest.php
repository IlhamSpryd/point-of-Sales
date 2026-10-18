<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Enums\OrderStatus;
use App\Enums\PreparationStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\KdsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class KdsLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Store $store;

    protected User $staffA;

    protected User $staffB;

    protected Product $product;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::create([
            'name' => 'KDS Tenant',
            'readiness_status' => 'active',
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kitchen Branch',
        ]);

        $context->setStoreId($this->store->id);

        $role = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Barista',
            'permissions' => [],
        ]);

        $this->staffA = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
        ]);

        $this->staffB = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Coffee',
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Americano',
            'product_price' => 25000,
            'stock' => 50,
        ]);
    }

    public function test_kds_claim_persists_processed_by_and_allows_mark_ready(): void
    {
        $order = Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->staffA->id,
            'order_code' => 'KDS-001',
            'order_date' => now()->toDateString(),
            'order_status' => OrderStatus::Paid,
            'payment_method' => 'cash',
            'subtotal_amount' => 25000,
            'tax_amount' => 2750,
            'order_amount' => 27750,
            'idempotency_key' => 'kds-test-key-1',
        ]);

        $orderItem = OrderItem::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'qty' => 1,
            'order_price' => 25000,
            'order_subtotal' => 25000,
            'preparation_status' => PreparationStatus::Pending,
        ]);

        $service = app(KdsService::class);

        // 1. Staff A claims the item
        $service->claim($orderItem->id, $this->staffA->id);

        $fresh = $orderItem->fresh();
        $this->assertEquals(PreparationStatus::Brewing, $fresh->preparation_status);
        $this->assertEquals($this->staffA->id, $fresh->processed_by, 'processed_by must be persisted upon claim');

        // 2. Staff B attempts to mark ready -> must fail with validation error
        try {
            $service->markReady($orderItem->id, $this->staffB->id);
            $this->fail('Staff B should not be allowed to mark ready an item claimed by Staff A');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('diambil staf lain', $e->getMessage());
        }

        // 3. Staff A marks ready -> must succeed
        $service->markReady($orderItem->id, $this->staffA->id);
        $this->assertEquals(PreparationStatus::Ready, $orderItem->fresh()->preparation_status);
    }
}
