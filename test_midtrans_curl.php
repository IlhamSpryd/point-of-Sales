<?php

use Illuminate\Contracts\Console\Kernel;

$orderCode = 'POS-O1F174-976';
$grossAmount = '16700.00';
$statusCode = '200';

// Boot Laravel to get config
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$serverKey = config('services.midtrans.server_key');
$signature = hash('sha512', $orderCode.$statusCode.$grossAmount.$serverKey);

$payload = json_encode([
    'order_id' => $orderCode,
    'status_code' => $statusCode,
    'gross_amount' => $grossAmount,
    'signature_key' => $signature,
    'transaction_status' => 'settlement',
    'fraud_status' => 'accept',
]);

$ch = curl_init('http://127.0.0.1:8000/midtrans/notification');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: '.strlen($payload),
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

$response = curl_exec($ch);
curl_close($ch);

echo $response;
