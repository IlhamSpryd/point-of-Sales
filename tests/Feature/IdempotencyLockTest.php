<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IdempotencyLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => 'Kasir', 'role_code' => 'KSR']);
        $this->user = User::factory()->create(['role_id' => $role->id]);

        $this->shift = Shift::create([
            'user_id' => $this->user->id,
            'opened_at' => now(),
            'opening_balance' => 500000,
            'status' => 'open',
        ]);

        $cat = Category::create(['category_name' => 'Food']);
        $this->product = Product::create([
            'product_name' => 'Nasi Goreng',
            'product_price' => 20000,
            'stock' => 10,
            'category_id' => $cat->id,
            'is_active' => true,
        ]);
    }

    public function test_rejects_missing_idempotency_key()
    {
        $response = $this->actingAs($this->user)->postJson(route('transaction.store'), [
            'order_type' => 'dine_in',
            'customer_name' => 'Test',
            'payment_method' => 'cash',
            'cash_received' => 100000,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key']);
    }

    public function test_handles_idempotency_properly_on_repeat()
    {
        $key = (string) Str::uuid();

        $payload = [
            'idempotency_key' => $key,
            'order_type' => 'dine_in',
            'customer_name' => 'Test',
            'payment_method' => 'cash',
            'cash_received' => 100000,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                ],
            ],
        ];

        // Request 1: Success
        $res1 = $this->actingAs($this->user)->postJson(route('transaction.store'), $payload);

        $res1->assertStatus(200)
            ->assertJsonFragment(['success' => true, 'message' => 'Pesanan berhasil dibuat']);

        $orderNumber = $res1->json('order_number');

        // Request 2 (Exact same idempotency key): Should return idempotent message
        $res2 = $this->actingAs($this->user)->postJson(route('transaction.store'), $payload);
        $res2->assertStatus(200)
            ->assertJsonFragment([
                'success' => true,
                'message' => 'Pesanan sudah diproses sebelumnya (Idempotent)',
                'order_number' => $orderNumber,
            ]);

        // Ensure only 1 order in DB
        $this->assertDatabaseCount('orders', 1);

        // Ensure stock only deducted once (10 - 1 = 9)
        $this->assertDatabaseHas('products', [
            'id' => $this->product->id,
            'stock' => 9,
        ]);
    }
}
