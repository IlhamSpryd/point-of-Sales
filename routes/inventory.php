<?php

declare(strict_types=1);

use App\Http\Controllers\RestockForecastController;
use App\Livewire\Inventory\IngredientLedger;
use App\Livewire\Inventory\IngredientManager;
use App\Livewire\Inventory\ModifierManager;
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
        });
    });

    // BI restock forecast (JSON read-only, dikonsumsi widget Node 2)
    Route::middleware(['role:Owner,Manager,Inventory', 'enforce.entitlement:restock_forecast'])->group(function () {
        Route::get('/api/analytics/restock-forecasts', [RestockForecastController::class, 'index'])
            ->name('analytics.restock-forecasts');
    });
});
