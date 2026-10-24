<?php

declare(strict_types=1);

use App\Http\Controllers\RestockForecastController;
use App\Livewire\Inventory\GoodsReceiptManager;
use App\Livewire\Inventory\IngredientLedger;
use App\Livewire\Inventory\IngredientManager;
use App\Livewire\Inventory\ModifierManager;
use App\Livewire\Inventory\StockAdjustmentManager;
use App\Livewire\Inventory\StockOpnameManager;
use App\Livewire\Inventory\StockTransferManager;
use App\Livewire\Inventory\SupplierReturnManager;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inventory & BOM Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'tenant.context', 'enforce.email.verification', 'ensure.onboarding.completed'])->group(function () {
    Route::middleware('role:Owner,Manager,Inventory')->group(function () {
        Route::prefix('inventory')->name('inventory.')->group(function () {
            Route::get('/ingredients', IngredientManager::class)->name('ingredients');
            Route::get('/ledger', IngredientLedger::class)->name('ledger');
            Route::get('/modifiers', ModifierManager::class)->name('modifiers');
            Route::get('/goods-receipts', GoodsReceiptManager::class)->name('goods-receipts');
            Route::get('/adjustments', StockAdjustmentManager::class)->name('adjustments');
            Route::get('/opnames', StockOpnameManager::class)->name('opnames');
            Route::get('/transfers', StockTransferManager::class)->name('transfers');
            Route::get('/supplier-returns', SupplierReturnManager::class)->name('supplier-returns');
        });
    });

    // BI restock forecast (JSON read-only, dikonsumsi widget Node 2)
    Route::middleware(['role:Owner,Manager,Inventory', 'enforce.entitlement:restock_forecast'])->group(function () {
        Route::get('/api/analytics/restock-forecasts', [RestockForecastController::class, 'index'])
            ->name('analytics.restock-forecasts');
    });
});
