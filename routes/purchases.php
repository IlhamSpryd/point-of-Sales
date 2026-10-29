<?php

declare(strict_types=1);

use App\Livewire\Purchasing\GoodsReceivedNoteManager;
use App\Livewire\Purchasing\PurchaseOrderManager;
use App\Livewire\Purchasing\PurchaseReturnManager;
use App\Livewire\Purchasing\SupplierInvoiceManager;
use App\Livewire\Purchasing\SupplierManager;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Purchases & Supplier Routes (Module 5 P2P Cycle)
|--------------------------------------------------------------------------
|
| Routing for suppliers, purchase orders, goods received notes,
| supplier invoices (3-way match), and purchase returns.
|
*/

Route::middleware(['auth', 'tenant.context', 'enforce.email.verification', 'ensure.onboarding.completed'])->group(function () {
    Route::middleware('role:Owner,Manager,Inventory')->group(function () {
        Route::get('/purchasing/suppliers', SupplierManager::class)->name('purchasing.suppliers.index');
        Route::get('/purchasing/orders', PurchaseOrderManager::class)->name('purchasing.orders.index');
        Route::get('/purchasing/grns', GoodsReceivedNoteManager::class)->name('purchasing.grns.index');
        Route::get('/purchasing/invoices', SupplierInvoiceManager::class)->name('purchasing.invoices.index');
        Route::get('/purchasing/returns', PurchaseReturnManager::class)->name('purchasing.returns.index');
    });
});
