<?php

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\TransactionService;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

if (! function_exists('selfOrderMakeProduct')) {
    function selfOrderMakeProduct(int $stock = 10): Product
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
}

if (! function_exists('selfOrderMakeKasir')) {
    function selfOrderMakeKasir(): User
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
}

it('self-order cash menghasilkan order Pending, bukan error validasi', function () {
    $product = selfOrderMakeProduct(stock: 10);
    $systemUser = selfOrderMakeKasir();

    $order = app(TransactionService::class)->createTransaction([
        'items' => [['product_id' => $product->id, 'quantity' => 1, 'extra_price' => 0, 'options' => null]],
        'payment_method' => 'cash',
        'cash_received' => null,
        'is_self_order_cash' => true,
        'table_id' => null,
    ], $systemUser->id);

    expect($order->order_status)->toBe(OrderStatus::Pending)
        ->and($order->cash_received)->toBeNull()
        ->and($order->snap_token)->toBeNull();
});
