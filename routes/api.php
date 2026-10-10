<?php

use App\Http\Controllers\Api\ProvisioningController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

// throttle:120,1 sebelum verify.webhook -- pola identik
// midtrans.notification, lapis pertahanan kedua terhadap flood, TIDAK
// menggantikan verifikasi HMAC. | 2026-09-23
Route::post('/webhooks/{provider}/orders', [WebhookController::class, 'handle'])
    ->middleware(['throttle:120,1', 'verify.webhook']);

/*
| Domain 1 — S2S provisioning dari website marketing.
| HMAC + timestamp + nonce anti-replay + rate limit ketat.
| TANPA session auth; throttle:10,1 karena operasi ini mahal dan
| berasal dari SATU pemanggil server yang diketahui.
*/
Route::post('/v1/provisioning/registrations', [ProvisioningController::class, 'store'])
    ->middleware(['throttle:10,1', 'verify.provisioning']);
