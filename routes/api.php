<?php

use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

// [OMEGA-NODE5] throttle:120,1 sebelum verify.webhook -- pola identik
// midtrans.notification, lapis pertahanan kedua terhadap flood, TIDAK
// menggantikan verifikasi HMAC. | 2026-09-23
Route::post('/webhooks/{provider}/orders', [WebhookController::class, 'handle'])
    ->middleware(['throttle:120,1', 'verify.webhook']);
