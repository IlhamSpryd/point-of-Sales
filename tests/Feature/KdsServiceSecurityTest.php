<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PreparationStatus;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Organization\Models\Role;
use App\Modules\Organization\Models\User;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use App\Modules\Sales\Services\KdsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class KdsServiceSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_claim_unpaid_order_item_throws_exception(): void
    {
        $role = Role::firstOrCreate(['name' => 'Barista']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $category = Category::create(['category_name' => 'Drink']);
        $product = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Kopi',
            'product_price' => 10000,
            'stock' => 10,
            'is_active' => true,
        ]);

        // Order is unpaid
        $order = Order::forceCreate([
            'order_status' => 'pending',
            'user_id' => $user->id,
            'order_code' => 'ORD-1234',
            'order_date' => now()->toDateString(),
            'idempotency_key' => uniqid('order_'),
            'subtotal_amount' => 10000,
            'order_type' => 'dine_in',
        ]);

        $orderItem = OrderItem::forceCreate([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'preparation_status' => PreparationStatus::Pending,
            'qty' => 1,
            'order_price' => 10000,
            'order_subtotal' => 10000,
        ]);

        $service = app(KdsService::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Only PAID orders can be processed in the kitchen.');

        $service->claim($orderItem->id, $user->id);

        $this->assertEquals(PreparationStatus::Pending, $orderItem->fresh()->preparation_status);
    }

    public function test_recall_ready_item_success(): void
    {
        $role = Role::firstOrCreate(['name' => 'Barista']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $category = Category::create(['category_name' => 'Drink']);
        $product = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Kopi',
            'product_price' => 10000,
            'stock' => 10,
            'is_active' => true,
        ]);

        // Order is paid
        $order = Order::forceCreate([
            'order_status' => 'paid',
            'user_id' => $user->id,
            'order_code' => 'ORD-5678',
            'order_date' => now()->toDateString(),
            'idempotency_key' => uniqid('order_'),
            'subtotal_amount' => 10000,
            'order_type' => 'dine_in',
        ]);

        $orderItem = OrderItem::forceCreate([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'preparation_status' => PreparationStatus::Ready,
            'processed_by' => $user->id,
            'qty' => 1,
            'order_price' => 10000,
            'order_subtotal' => 10000,
        ]);

        $service = app(KdsService::class);
        $service->recall($orderItem->id, $user->id, false);

        $this->assertEquals(PreparationStatus::Brewing, $orderItem->fresh()->preparation_status);
    }
}
