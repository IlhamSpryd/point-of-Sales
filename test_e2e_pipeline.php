<?php

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Table;
use App\Models\User;
use App\Services\TransactionService;

$service = app(TransactionService::class);
$systemUserId = User::where('email', config('pos.self_order_system_email'))->value('id');

if (! $systemUserId) {
    echo "ERROR: System user not found.\n";
    exit;
}

// 1. Setup
$table = Table::firstOrCreate(['table_number' => 99], ['secure_token' => 'test-token-99']);
$category = Category::firstOrCreate(['category_name' => 'Test Category']);
$product = Product::create([
    'category_id' => $category->id,
    'product_name' => 'E2E Test Product',
    'product_price' => 15000,
    'stock' => 10,
    'status' => 'available',
]);

// 2. Create unpaid order via TransactionService (Simulating CheckoutController)
echo "[*] Creating Order via TransactionService...\n";
$order = $service->createTransaction([
    'items' => [
        [
            'product_id' => $product->id,
            'quantity' => 1,
            'extra_price' => 0.0,
            'options' => [],
        ],
    ],
    'payment_method' => 'qris',
    'cash_received' => null,
    'table_id' => $table->id,
], $systemUserId);

echo "[SUCCESS] Order Created: {$order->order_code} (Status: {$order->order_status})\n";

// 3. Simulate Webhook
echo "[*] Simulating Midtrans Webhook (Settlement)...\n";
$service->updateStatusFromMidtransNotification($order->order_code, 'settlement', 'accept');

$order->refresh();
echo "[SUCCESS] Webhook Processed. New Status: {$order->order_status}\n";

// 4. Verify KDS Visibility
echo "[*] Querying KDS (checking if order_status = 'paid')...\n";
$kdsOrders = Order::with(['orderItems.product', 'table'])
    ->where('order_status', 'paid')
    ->whereDate('created_at', today())
    ->orderBy('created_at', 'asc')
    ->get();

$foundInKds = $kdsOrders->contains('id', $order->id);
if ($foundInKds) {
    echo "[SUCCESS] KDS Visibility: YES (Order successfully surfaced in KDS Pipeline)\n";
} else {
    echo "[FAILED] KDS Visibility: NO (Failed to surface in KDS)\n";
}

// Cleanup
$order->orderItems()->delete();
$order->delete();
$product->delete();
$category->delete();
$table->delete();
echo "[*] Cleanup done.\n";
