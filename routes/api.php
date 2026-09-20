<?php

use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/{provider}/orders', [WebhookController::class, 'handle'])
    ->middleware('verify.webhook');
