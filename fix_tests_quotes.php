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
        $content = str_replace("['\idempotency_key'", "['idempotency_key'", $content);
        $content = str_replace("[''\idempotency_key''", "['idempotency_key'", $content);
        $content = str_replace("['\\idempotency_key'", "['idempotency_key'", $content);
        $content = str_replace("['\\\\idempotency_key'", "['idempotency_key'", $content);
        file_put_contents($file, $content);
        echo 'Fixed '.$file.PHP_EOL;
    }
}
