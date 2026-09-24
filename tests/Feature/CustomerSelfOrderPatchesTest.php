<?php

namespace Tests\Feature;

use App\Events\WaiterCalled;
use App\Models\Category;
use App\Models\Product;
use App\Models\Table;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CustomerSelfOrderPatchesTest extends TestCase
{
    use RefreshDatabase;

    private function createTable(): Table
    {
        return Table::create([
            'table_name' => 'Meja Test',
            'secure_token' => 'test-token',
            'is_active' => true,
        ]);
    }

    private function createProduct(): Product
    {
        $category = Category::create(['category_name' => 'Kategori Test '.uniqid()]);

        return Product::create([
            'category_id' => $category->id,
            'product_name' => 'Produk Test '.uniqid(),
            'product_price' => 10000,
            'stock' => 100,
            'is_active' => true,
        ]);
    }

    public function test_waiter_call_dispatches_event_and_is_rate_limited(): void
    {
        Event::fake();
        $table = $this->createTable();

        // 1. Call waiter first time
        $response1 = $this->withSession([
            'current_table_id' => $table->id,
            'current_table_name' => $table->table_name,
        ])->postJson(route('customer.waiter.call'));

        $response1->assertStatus(200);
        $response1->assertJson(['message' => "Panggilan terkirim. Staf sedang menuju {$table->table_name}."]);

        Event::assertDispatched(WaiterCalled::class, function ($event) use ($table) {
            return $event->tableId === $table->id && $event->tableName === $table->table_name;
        });

        // 2. Call waiter second time immediately (should be rate limited by cache lock)
        $response2 = $this->withSession([
            'current_table_id' => $table->id,
            'current_table_name' => $table->table_name,
        ])->postJson(route('customer.waiter.call'));

        $response2->assertStatus(200);
        $response2->assertJson(['message' => "Panggilan untuk {$table->table_name} sudah dikirim, staf sedang menuju ke sana."]);

        // Verify only dispatched once
        Event::assertDispatchedTimes(WaiterCalled::class, 1);
    }

    public function test_cart_caps_limit_total_qty(): void
    {
        $product1 = $this->createProduct();
        $product2 = $this->createProduct();
        $product3 = $this->createProduct();
        $table = $this->createTable();

        // Adding 20 items is fine
        $response1 = $this->withSession([
            'current_table_id' => $table->id,
            'current_table_name' => $table->table_name,
        ])->postJson(route('customer.cart.store'), [
            'product_id' => $product1->id,
            'qty' => 20,
            'modifier_ids' => [],
        ]);

        $response1->assertStatus(200);

        // We must preserve the cart session from response1
        $cartSession = session('customer_cart');

        // Adding another 20 items of DIFFERENT product
        $response2 = $this->withSession([
            'current_table_id' => $table->id,
            'current_table_name' => $table->table_name,
            'customer_cart' => $cartSession,
        ])->postJson(route('customer.cart.store'), [
            'product_id' => $product2->id,
            'qty' => 20,
            'modifier_ids' => [],
        ]);

        $response2->assertStatus(200);

        $cartSession2 = session('customer_cart');

        // Adding 1 more item of DIFFERENT product should fail because max total is 40
        $response3 = $this->withSession([
            'current_table_id' => $table->id,
            'current_table_name' => $table->table_name,
            'customer_cart' => $cartSession2,
        ])->postJson(route('customer.cart.store'), [
            'product_id' => $product3->id,
            'qty' => 1,
            'modifier_ids' => [],
        ]);

        $response3->assertStatus(422);
        $response3->assertJsonValidationErrors(['items']);
    }

    public function test_checkout_reorder_restores_snapshot(): void
    {
        $product = $this->createProduct();
        $table = $this->createTable();

        // Setup snapshot manually via CartService
        $cartService = app(CartService::class);
        $cartService->addItem($product, [], 2);
        $cartService->snapshot();
        $cartService->clear();

        // Cart should be empty now
        $this->assertEquals(0, $cartService->getTotalQty());

        // Call reorder endpoint
        $response = $this->withSession([
            'current_table_id' => $table->id,
            'current_table_name' => $table->table_name,
        ])->post(route('customer.checkout.reorder'));

        $response->assertStatus(302);
        $response->assertRedirect(route('customer.cart.index'));
        $response->assertSessionHas('success', 'Keranjang berhasil dipulihkan.');

        // Cart should have the items back
        $this->assertEquals(2, $cartService->getTotalQty());
    }
}
