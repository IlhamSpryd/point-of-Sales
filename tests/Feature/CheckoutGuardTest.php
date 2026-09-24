<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Tests\TestCase;

class CheckoutGuardTest extends TestCase
{
    use RefreshDatabase;

    protected $systemUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed the system role and user for self order
        $role = Role::firstOrCreate(
            ['role_code' => 'ROL-001'],
            [
                'name' => 'Admin',
                'permissions' => [],
                'is_active' => true,
            ]
        );

        $this->systemUser = User::factory()->create([
            'email' => config('pos.self_order_system_email', 'system@yovel.com'),
            'role_id' => $role->id,
        ]);
    }

    public function test_blocks_checkout_if_cart_exceeds_40_items()
    {
        $table = Table::create([
            'table_name' => 'A1',
            'capacity' => 4,
            'area' => 'Indoor',
            'is_active' => true,
            'operational_status' => 'available',
            'secure_token' => Str::uuid()->toString(),
        ]);

        Session::put('current_table_id', $table->id);
        Session::put('checkout_idempotency_key', 'test_key');

        // Mock CartService to return 41 items
        $this->mock(CartService::class, function ($mock) {
            $mock->shouldReceive('getItems')->andReturn([
                ['product_id' => 1, 'qty' => 41, 'unit_price' => 10000, 'options' => []],
            ]);
            $mock->shouldReceive('getTotalQty')->andReturn(41);
        });

        $response = $this->post(route('customer.checkout.store'), [
            '_idempotency_key' => 'test_key',
            'payment_method' => 'cash',
        ]);

        $response->assertRedirect(route('customer.cart.index'));
        $response->assertSessionHas('error', 'Maksimal 40 item per pesanan. Silakan panggil staf untuk pesanan besar.');
    }

    public function test_blocks_checkout_if_session_has_2_pending_orders()
    {
        $table = Table::create([
            'table_name' => 'A2',
            'capacity' => 4,
            'area' => 'Indoor',
            'is_active' => true,
            'operational_status' => 'available',
            'secure_token' => Str::uuid()->toString(),
        ]);

        Session::put('current_table_id', $table->id);
        Session::put('checkout_idempotency_key', 'test_key');
        Session::put('customer_orders', ['ORD-001', 'ORD-002']);

        // Create 2 pending orders
        Order::forceCreate(['idempotency_key' => Str::uuid()->toString(), 'user_id' => $this->systemUser->id, 'order_type' => 'dine_in', 'order_date' => now(), 'subtotal_amount' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'service_charge_amount' => 0, 'order_amount' => 0, 'order_change' => 0, 'payment_method' => 'cash', 'order_code' => 'ORD-001', 'order_status' => OrderStatus::Pending, 'table_id' => $table->id]);
        Order::forceCreate(['idempotency_key' => Str::uuid()->toString(), 'user_id' => $this->systemUser->id, 'order_type' => 'dine_in', 'order_date' => now(), 'subtotal_amount' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'service_charge_amount' => 0, 'order_amount' => 0, 'order_change' => 0, 'payment_method' => 'cash', 'order_code' => 'ORD-002', 'order_status' => OrderStatus::Pending, 'table_id' => $table->id]);

        $this->mock(CartService::class, function ($mock) {
            $mock->shouldReceive('getItems')->andReturn([
                ['product_id' => 1, 'qty' => 1, 'unit_price' => 10000, 'options' => []],
            ]);
            $mock->shouldReceive('getTotalQty')->andReturn(1);
        });

        $response = $this->post(route('customer.checkout.store'), [
            '_idempotency_key' => 'test_key',
            'payment_method' => 'cash',
        ]);

        $response->assertSessionHas('error', 'Masih ada pesanan yang belum dibayar. Selesaikan atau tunggu kedaluwarsa sebelum memesan lagi.');
    }

    public function test_blocks_checkout_if_table_has_5_pending_orders()
    {
        $table = Table::create([
            'table_name' => 'A3',
            'capacity' => 4,
            'area' => 'Indoor',
            'is_active' => true,
            'operational_status' => 'available',
            'secure_token' => Str::uuid()->toString(),
        ]);

        Session::put('current_table_id', $table->id);
        Session::put('checkout_idempotency_key', 'test_key');

        // Create 5 pending orders for the table, but NOT in this session
        for ($i = 0; $i < 5; $i++) {
            Order::forceCreate([
                'idempotency_key' => Str::uuid()->toString(),
                'user_id' => $this->systemUser->id,
                'order_code' => 'ORD-TB-'.$i,
                'order_type' => 'dine_in', 'order_date' => now(), 'subtotal_amount' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'service_charge_amount' => 0, 'order_amount' => 0, 'order_change' => 0, 'payment_method' => 'cash',
                'order_status' => OrderStatus::Pending,
                'table_id' => $table->id,
                'created_at' => now(),
            ]);
        }

        $this->mock(CartService::class, function ($mock) {
            $mock->shouldReceive('getItems')->andReturn([
                ['product_id' => 1, 'qty' => 1, 'unit_price' => 10000, 'options' => []],
            ]);
            $mock->shouldReceive('getTotalQty')->andReturn(1);
        });

        $response = $this->post(route('customer.checkout.store'), [
            '_idempotency_key' => 'test_key',
            'payment_method' => 'cash',
        ]);

        $response->assertSessionHas('error', 'Masih ada pesanan yang belum dibayar. Selesaikan atau tunggu kedaluwarsa sebelum memesan lagi.');
    }

    public function test_blocks_checkout_if_cash_exceeds_500k()
    {
        $table = Table::create([
            'table_name' => 'A4',
            'capacity' => 4,
            'area' => 'Indoor',
            'is_active' => true,
            'operational_status' => 'available',
            'secure_token' => Str::uuid()->toString(),
        ]);

        Session::put('current_table_id', $table->id);
        Session::put('checkout_idempotency_key', 'test_key');

        // Create product so liveSubtotal matches
        $category = Category::create(['category_name' => 'Food', 'is_active' => true]);
        $product = Product::create([
            'id' => 1,
            'category_id' => $category->id,
            'product_name' => 'Luxury Meal',
            'product_price' => 550000,
            'stock' => 10,
            'is_active' => true,
        ]);

        $this->mock(CartService::class, function ($mock) {
            $mock->shouldReceive('getItems')->andReturn([
                ['product_id' => 1, 'qty' => 1, 'unit_price' => 550000, 'options' => []],
            ]);
            $mock->shouldReceive('getTotalQty')->andReturn(1);
            $mock->shouldReceive('getSubtotal')->andReturn(550000);
        });

        $response = $this->post(route('customer.checkout.store'), [
            '_idempotency_key' => 'test_key',
            'payment_method' => 'cash',
        ]);

        $response->assertSessionHas('error', 'Pesanan tunai maksimal Rp 500.000. Untuk pesanan lebih besar, gunakan pembayaran digital atau pesan di Kasir.');
    }

    public function test_blocks_checkout_if_idempotency_key_is_missing_or_invalid()
    {
        $table = Table::create([
            'table_name' => 'A5',
            'capacity' => 4,
            'area' => 'Indoor',
            'is_active' => true,
            'operational_status' => 'available',
            'secure_token' => Str::uuid()->toString(),
        ]);

        Session::put('current_table_id', $table->id);
        Session::put('checkout_idempotency_key', 'valid_key');

        $response = $this->post(route('customer.checkout.store'), [
            '_idempotency_key' => 'invalid_key',
            'payment_method' => 'cash',
        ]);

        $response->assertRedirect(route('customer.cart.index'));
        $response->assertSessionHas('error', 'Pesanan sudah diproses. Jangan klik tombol bayar lebih dari sekali.');
    }

    public function test_blocks_checkout_if_concurrent_request_is_detected()
    {
        $table = Table::create([
            'table_name' => 'A6',
            'capacity' => 4,
            'area' => 'Indoor',
            'is_active' => true,
            'operational_status' => 'available',
            'secure_token' => Str::uuid()->toString(),
        ]);

        Session::put('current_table_id', $table->id);
        Session::put('checkout_idempotency_key', 'concurrent_key');

        // Simulasikan race condition dengan mengambil lock duluan
        $lock = Cache::lock('checkout_idempotency_concurrent_key', 15);
        $lock->get();

        $this->mock(CartService::class, function ($mock) {
            $mock->shouldReceive('getItems')->andReturn([
                ['product_id' => 1, 'qty' => 1, 'unit_price' => 10000, 'options' => []],
            ]);
            $mock->shouldReceive('getTotalQty')->andReturn(1);
        });

        $response = $this->post(route('customer.checkout.store'), [
            '_idempotency_key' => 'concurrent_key', // This matches the session key
            'payment_method' => 'cash',
        ]);

        $response->assertRedirect(route('customer.checkout.create'));
        $response->assertSessionHas('error', 'Pembayaran sedang diproses, mohon tunggu sebentar.');

        $lock->release();
    }

    public function test_blocks_checkout_if_product_is_inactive()
    {
        $table = Table::create([
            'table_name' => 'A7',
            'capacity' => 4,
            'area' => 'Indoor',
            'is_active' => true,
            'operational_status' => 'available',
            'secure_token' => Str::uuid()->toString(),
        ]);

        Session::put('current_table_id', $table->id);
        Session::put('checkout_idempotency_key', 'test_key_inactive');

        $category = Category::create(['category_name' => 'Food', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Soon to be inactive',
            'product_price' => 10000,
            'stock' => 10,
            'is_active' => true,
        ]);

        $this->mock(CartService::class, function ($mock) use ($product) {
            $mock->shouldReceive('refreshCartPrices');
            $mock->shouldReceive('getItems')->andReturn([
                ['product_id' => $product->id, 'qty' => 1, 'unit_price' => 10000, 'options' => []],
            ]);
            $mock->shouldReceive('getTotalQty')->andReturn(1);
            $mock->shouldReceive('getSubtotal')->andReturn(10000);
        });

        // Simulasikan produk dinonaktifkan oleh admin sebelum checkout
        $product->update(['is_active' => false]);

        $response = $this->post(route('customer.checkout.store'), [
            '_idempotency_key' => 'test_key_inactive',
            'payment_method' => 'cash',
        ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('dinonaktifkan', Session::get('error'));
    }
}
