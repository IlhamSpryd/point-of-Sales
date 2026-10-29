<?php

declare(strict_types=1);

use App\Http\Controllers\DiscountController;
use App\Http\Controllers\TableController;
use App\Livewire\Customers\CustomerCreditManager;
use App\Livewire\Customers\CustomerGroupManager;
use App\Livewire\Customers\CustomerManager;
use App\Livewire\Customers\LoyaltyAndValueInstrumentManager;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Customer & Venue Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'tenant.context', 'enforce.email.verification', 'ensure.onboarding.completed'])->group(function () {
    Route::middleware('role:Owner,Manager')->group(function () {
        // Pelanggan & B2B CRM (Modul 6)
        Route::get('/customers', CustomerManager::class)->name('customers.index');
        Route::get('/customers/groups', CustomerGroupManager::class)->name('customers.groups.index');
        Route::get('/customers/credit', CustomerCreditManager::class)->name('customers.credit.index');
        Route::get('/customers/instruments', LoyaltyAndValueInstrumentManager::class)->name('customers.instruments.index');

        // Diskon & Promo
        Route::get('/discounts', [DiscountController::class, 'index'])->name('discounts.index');

        // Meja Resto & QR Token
        Route::post('tables/{table}/rotate-qr', [TableController::class, 'rotateQr'])->name('tables.rotate-qr');
        Route::post('tables/{table}/release', [TableController::class, 'release'])->name('tables.release');
        Route::post('tables/{table}/regenerate-token', [TableController::class, 'regenerateToken'])->name('tables.regenerate-token');
        Route::resource('tables', TableController::class);
    });
});
