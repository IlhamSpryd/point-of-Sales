<?php

declare(strict_types=1);

use App\Livewire\Purchasing\SupplierManager;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Purchases & Supplier Routes
|--------------------------------------------------------------------------
|
| Placeholder for future purchase order management, supplier agreements,
| and procurement workflows.
|
*/

Route::middleware(['auth', 'tenant.context', 'enforce.email.verification', 'ensure.onboarding.completed'])->group(function () {
    Route::middleware('role:Owner,Manager,Inventory')->group(function () {
        Route::get('/purchasing/suppliers', SupplierManager::class)->name('purchasing.suppliers.index');
    });
});
