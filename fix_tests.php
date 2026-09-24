<?php

$files = [
    'tests/Feature/StockCompensationHardeningTest.php',
    'tests/Feature/MidtransWebhookTest.php',
    'tests/Feature/ReconcilePendingMidtransOrdersTest.php',
    'tests/Feature/CheckoutGuardTest.php',
    'tests/Feature/TransactionConcurrencyTest.php',
];
foreach ($files as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        $content = str_replace('Order::forceCreate([', "Order::forceCreate(['\idempotency_key' => \Illuminate\Support\Str::uuid(),", $content);
        $content = str_replace('Order::create([', "Order::create(['\idempotency_key' => \Illuminate\Support\Str::uuid(),", $content);
        file_put_contents($file, $content);
        echo 'Updated '.$file.PHP_EOL;
    }
}
