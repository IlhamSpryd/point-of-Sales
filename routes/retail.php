<?php

declare(strict_types=1);

use App\Livewire\Retail\BarcodeManager;
use App\Livewire\Retail\RetailExchangeManager;
use App\Livewire\Retail\ShelfLabelManager;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Retail & Merchandising Routes (Modul 11 RTL)
|--------------------------------------------------------------------------
|
| Barcode generation, shelf price ticketing, and item exchanges.
|
*/

Route::middleware(['auth', 'tenant.context', 'enforce.email.verification', 'ensure.onboarding.completed'])->prefix('retail')->name('retail.')->group(function () {
    Route::middleware('role:Owner,Manager,Inventory,Kasir,Sales')->group(function () {
        Route::get('/shelf-labels', ShelfLabelManager::class)->name('shelf-labels.index');
        Route::get('/barcodes', BarcodeManager::class)->name('barcodes.index');
        Route::get('/exchanges', RetailExchangeManager::class)->name('exchanges.index');
    });
});

