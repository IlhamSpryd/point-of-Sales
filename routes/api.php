<?php

use App\Http\Controllers\Api\ProvisioningController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\V1\AiAssistantApiController;
use App\Http\Controllers\Api\V1\InventoryApiController;
use App\Http\Controllers\Api\V1\OrderApiController;
use App\Http\Controllers\Api\V1\ProductApiController;
use App\Http\Controllers\Api\V1\WebhookSubscriptionApiController;
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

// Scoped Sanctum REST API for external integrations (e.g. n8n & external ERP)
Route::middleware(['auth:sanctum', 'tenant.context'])->prefix('v1')->group(function () {
    // Orders API
    Route::get('/orders', [OrderApiController::class, 'index'])->name('api.v1.orders.index');
    Route::get('/orders/{id}', [OrderApiController::class, 'show'])->name('api.v1.orders.show');

    // Products Catalog API
    Route::get('/products', [ProductApiController::class, 'index'])->name('api.v1.products.index');

    // Inventory Balances API
    Route::get('/inventory/balances', [InventoryApiController::class, 'balances'])->name('api.v1.inventory.balances');

    // Webhook Subscriptions API
    Route::get('/webhooks/subscriptions', [WebhookSubscriptionApiController::class, 'index'])->name('api.v1.webhooks.subscriptions.index');
    Route::post('/webhooks/subscriptions', [WebhookSubscriptionApiController::class, 'store'])->name('api.v1.webhooks.subscriptions.store');
    Route::delete('/webhooks/subscriptions/{id}', [WebhookSubscriptionApiController::class, 'destroy'])->name('api.v1.webhooks.subscriptions.destroy');

    // AI Business Assistant API (Human-in-the-Loop & Read-Only Insights)
    Route::get('/ai/insights/sales-trends', [AiAssistantApiController::class, 'salesTrends'])->name('api.v1.ai.sales-trends');
    Route::get('/ai/insights/inventory-anomalies', [AiAssistantApiController::class, 'inventoryAnomalies'])->name('api.v1.ai.inventory-anomalies');
    Route::get('/ai/recommendations', [AiAssistantApiController::class, 'recommendations'])->name('api.v1.ai.recommendations');
    Route::post('/ai/query', [AiAssistantApiController::class, 'query'])->name('api.v1.ai.query');
});
