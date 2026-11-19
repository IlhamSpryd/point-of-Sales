<?php

declare(strict_types=1);

use App\Livewire\Customers\CustomerCreditManager;
use App\Livewire\Customers\CustomerGroupManager;
use App\Livewire\Customers\CustomerManager;
use App\Livewire\Customers\LoyaltyAndValueInstrumentManager;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Customer & CRM Routes (Modul 13 CRM — Customer & Loyalty)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'tenant.context', 'enforce.email.verification', 'ensure.onboarding.completed'])->group(function () {
    // Pelanggan & B2B CRM (Modul 13) — Owner, Manager, Sales (OQ-19)
    Route::middleware('role:Owner,Manager,Sales')->group(function () {
        Route::get('/customers', CustomerManager::class)->name('customers.index');
    });

    Route::middleware('role:Owner,Manager')->group(function () {
        Route::get('/customers/groups', CustomerGroupManager::class)->name('customers.groups.index');
        Route::get('/customers/instruments', LoyaltyAndValueInstrumentManager::class)->name('customers.instruments.index');
    });

    Route::middleware('role:Owner,Manager,Finance,Sales')->group(function () {
        Route::get('/customers/credit', CustomerCreditManager::class)->name('customers.credit.index');
    });
});
