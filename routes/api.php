<?php

use App\Http\Controllers\Api\ProvisioningController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\HealthCheckController;
use App\Modules\Integration\Controllers\PaymentNotificationController;
use App\Modules\Integration\Services\SanctumScopeGuardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// throttle:120,1 sebelum verify.webhook -- pola identik
// midtrans.notification, lapis pertahanan kedua terhadap flood, TIDAK
// menggantikan verifikasi HMAC. | 2026-09-23
Route::post('/webhooks/{provider}/orders', [WebhookController::class, 'handle'])
    ->middleware(['throttle:120,1', 'verify.webhook']);

Route::post('/tenants/{tenant}/webhooks/{provider}/orders', [WebhookController::class, 'handle'])
    ->middleware(['throttle:120,1', 'verify.webhook']);

/*
| Domain 1 — S2S provisioning dari website marketing.
| HMAC + timestamp + nonce anti-replay + rate limit ketat.
| TANPA session auth; throttle:10,1 karena operasi ini mahal dan
| berasal dari SATU pemanggil server yang diketahui.
*/
Route::post('/v1/provisioning/registrations', [ProvisioningController::class, 'store'])
    ->middleware(['throttle:10,1', 'verify.provisioning']);

// Terminal kasir fisik pairing / aktivasi endpoint
Route::post('/devices/pair', [DeviceController::class, 'pair'])
    ->middleware('throttle:10,1')
    ->name('api.devices.pair');

// Health check probes
Route::get('/health/live', [HealthCheckController::class, 'live'])->name('api.health.live');
Route::get('/health/ready', [HealthCheckController::class, 'ready'])->name('api.health.ready');

// Midtrans Payment Webhook Notification
Route::post('/payment/notification', [PaymentNotificationController::class, 'handle'])
    ->name('api.payment.notification');

// Scoped Sanctum REST API for external integrations (e.g. n8n)
Route::middleware(['auth:sanctum', 'tenant.context'])->group(function () {
    Route::get('/v1/orders', function (Request $request, SanctumScopeGuardService $guard) {
        $guard->authorize($request, 'orders:read');

        return response()->json([
            'status' => 'OK',
            'orders' => [],
        ]);
    })->name('api.v1.orders');
});
