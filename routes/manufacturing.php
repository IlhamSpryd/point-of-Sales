<?php

declare(strict_types=1);

use App\Livewire\Manufacturing\ManufacturingOrderManager;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Discrete Manufacturing & Work Orders Routes (Modul 09 MFG)
|--------------------------------------------------------------------------
|
| Planning, executing, and completing production runs.
|
*/

Route::middleware(['auth', 'tenant.context', 'enforce.email.verification', 'ensure.onboarding.completed'])->prefix('manufacturing')->name('manufacturing.')->group(function () {
    Route::middleware('role:Owner,Manager,Inventory')->group(function () {
        Route::get('/orders', ManufacturingOrderManager::class)->name('orders.index');
    });
});

