<?php

declare(strict_types=1);

use App\Http\Controllers\DiscountController;
use App\Http\Controllers\TableController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Customer & Venue Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'tenant.context', 'enforce.email.verification', 'ensure.onboarding.completed'])->group(function () {
    Route::middleware('role:Owner,Manager')->group(function () {
        // Diskon & Promo
        Route::get('/discounts', [DiscountController::class, 'index'])->name('discounts.index');

        // Meja Resto & QR Token
        Route::post('tables/{table}/regenerate-token', [TableController::class, 'regenerateToken'])->name('tables.regenerate-token');
        Route::resource('tables', TableController::class);
    });
});
