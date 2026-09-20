<?php

// [OMEGA-NODE1] Emergency Backend Hardening | 2026-09-21
// PRASYARAT: pastikan pestphp/pest & pestphp/pest-plugin-laravel terpasang
// (`composer require pestphp/pest pestphp/pest-plugin-laravel --dev`) --
// composer.json yang diberikan hanya mengizinkan plugin-nya, belum tentu
// package intinya sudah ter-install. PERLU VERIFIKASI di environment nyata.

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function omegaMakeProduct(int $stock = 10): Product
{
    $category = Category::create(['category_name' => 'Kategori-'.uniqid()]);

    return Product::create([
        'category_id' => $category->id,
        'product_name' => 'Kopi Susu Test',
        'product_price' => 20000,
        'stock' => $stock,
        'is_active' => true,
    ]);
}

function omegaMakeKasir(): User
{
    $role = Role::firstOrCreate(['name' => 'Kasir']);

    return User::create([
        'name' => 'Kasir Test',
        'email' => 'kasir-'.uniqid().'@test.local',
        'password' => bcrypt('password'),
        'role_id' => $role->id,
        'is_active' => true,
    ]);
}

it('mengembalikan stok tepat satu kali walau webhook expire dikirim dua kali', function () {
    $product = omegaMakeProduct(stock: 10);

    $kasir = omegaMakeKasir();

    $order = Order::forceCreate([
        'user_id' => $kasir->id,
        'order_code' => 'POS-TEST-EXP-001',
        'order_date' => now()->toDateString(),
        'subtotal_amount' => 60000,
        'tax_amount' => 6600,
        'order_amount' => 66600,
        'order_status' => OrderStatus::Pending,
        'payment_method' => 'qris',
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'qty' => 3,
        'order_price' => 20000,
        'order_subtotal' => 60000,
    ]);

    // Simulasikan stok yang SUDAH direservasi saat order dibuat.
    $product->decrement('stock', 3);
    expect($product->fresh()->stock)->toBe(7);

    $service = app(TransactionService::class);

    // Webhook PERTAMA.
    $service->updateStatusFromMidtransNotification(
        orderCode: $order->order_code,
        transactionStatus: 'expire',
        fraudStatus: null,
    );

    expect($product->fresh()->stock)->toBe(10)
        ->and(StockMovement::where('order_id', $order->id)->count())->toBe(1);

    // Webhook KEDUA (duplikat -- skenario paling umum di Midtrans).
    $service->updateStatusFromMidtransNotification(
        orderCode: $order->order_code,
        transactionStatus: 'expire',
        fraudStatus: null,
    );

    // Stok TIDAK boleh bertambah lagi -- exactly-once.
    expect($product->fresh()->stock)->toBe(10)
        ->and(StockMovement::where('order_id', $order->id)->count())->toBe(1)
        ->and($order->fresh()->order_status)->toBe(OrderStatus::Expired);
});

it('TIDAK mengembalikan stok saat order menjadi paid', function () {
    $product = omegaMakeProduct(stock: 10);

    $kasir = omegaMakeKasir();

    $order = Order::forceCreate([
        'user_id' => $kasir->id,
        'order_code' => 'POS-TEST-PAID-001',
        'order_date' => now()->toDateString(),
        'subtotal_amount' => 40000,
        'tax_amount' => 4400,
        'order_amount' => 44400,
        'order_status' => OrderStatus::Pending,
        'payment_method' => 'qris',
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'qty' => 2,
        'order_price' => 20000,
        'order_subtotal' => 40000,
    ]);

    $product->decrement('stock', 2);

    app(TransactionService::class)->updateStatusFromMidtransNotification(
        orderCode: $order->order_code,
        transactionStatus: 'settlement',
        fraudStatus: null,
    );

    expect($product->fresh()->stock)->toBe(8)
        ->and(StockMovement::count())->toBe(0)
        ->and($order->fresh()->order_status)->toBe(OrderStatus::Paid);
});

it('menolak transaksi kasir baru jika belum membuka shift', function () {
    $kasir = omegaMakeKasir();
    $product = omegaMakeProduct(stock: 5);

    $response = test()->actingAs($kasir)->postJson(route('transaction.store'), [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'payment_method' => 'cash',
        'cash_received' => 25000,
    ]);

    $response->assertStatus(400);
    expect(Order::count())->toBe(0)
        ->and($product->fresh()->stock)->toBe(5);
});

it('mengisi shift_id otomatis saat kasir memiliki shift terbuka', function () {
    $kasir = omegaMakeKasir();
    $product = omegaMakeProduct(stock: 5);

    $shift = Shift::create([
        'user_id' => $kasir->id,
        'opening_balance' => 100000,
        'status' => 'open',
        'opened_at' => now(),
    ]);

    $response = test()->actingAs($kasir)->postJson(route('transaction.store'), [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'payment_method' => 'cash',
        'cash_received' => 25000,
    ]);

    $response->assertOk();
    expect(Order::latest()->first()->shift_id)->toBe($shift->id);
});

it('snap_token dan field status sensitif tidak mass-assignable', function () {
    $fillable = (new Order)->getFillable();

    expect($fillable)->not->toContain('snap_token')
        ->and($fillable)->not->toContain('order_status')
        ->and($fillable)->not->toContain('payment_method');
});
