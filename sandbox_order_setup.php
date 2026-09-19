<?php

use App\Models\Product;
use App\Models\Table;
use App\Models\User;
use App\Services\TransactionService;

$service = app(TransactionService::class);
$product = Product::where('is_active', true)->where('stock', '>', 0)->first();
$table = Table::where('operational_status', 'available')->first();
$systemUserId = User::where('email', config('pos.self_order_system_email'))->value('id');

$order = $service->createTransaction([
    'items' => [['product_id' => $product->id, 'quantity' => 1, 'extra_price' => 0, 'options' => null]],
    'payment_method' => 'qris',
    'cash_received' => null,
    'table_id' => $table->id,
], $systemUserId);

echo "ORDER_CODE={$order->order_code}\n";
echo "SNAP_TOKEN={$order->snap_token}\n";
echo "GROSS_AMOUNT={$order->order_amount}\n";
echo "INITIAL_STATUS={$order->order_status}\n";
