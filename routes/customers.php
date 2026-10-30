<?php

declare(strict_types=1);

use App\Livewire\Customers\CustomerCreditManager;
use App\Livewire\Customers\CustomerGroupManager;
use App\Livewire\Customers\CustomerManager;
use App\Livewire\Customers\LoyaltyAndValueInstrumentManager;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Customer & CRM Routes (Modul 6 — Customer & Loyalty)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'tenant.context', 'enforce.email.verification', 'ensure.onboarding.completed'])->group(function () {
    Route::middleware('role:Owner,Manager')->group(function () {
        // Pelanggan & B2B CRM (Modul 6)
        Route::get('/customers', CustomerManager::class)->name('customers.index');
        Route::get('/customers/groups', CustomerGroupManager::class)->name('customers.groups.index');
        Route::get('/customers/credit', CustomerCreditManager::class)->name('customers.credit.index');
        Route::get('/customers/instruments', LoyaltyAndValueInstrumentManager::class)->name('customers.instruments.index');
    });
});
