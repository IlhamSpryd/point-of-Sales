<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WebhookController;

Route::post('/webhooks/{provider}/orders', [WebhookController::class, 'handle'])
    ->middleware('verify.webhook');
