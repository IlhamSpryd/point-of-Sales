<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    
    // Core Entity Resources (Administrator only for Create/Edit/Delete)
    Route::middleware(['role:Administrator'])->group(function () {
        Route::resource('roles', RoleController::class)->except(['show']);
        Route::resource('users', UserController::class)->except(['show']);
        
        // Category and Product management (create, store, edit, update, destroy)
        Route::resource('categories', CategoryController::class)->except(['index', 'show']);
        Route::resource('products', ProductController::class)->except(['index', 'show']);
    });

    // Katalog (Read-only) / products.index bisa diakses oleh Admin, Kasir, Pimpinan
    Route::middleware(['role:Administrator,Kasir,Pimpinan'])->group(function () {
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    });

    // Transaksi (Kasir)
    Route::middleware(['role:Kasir'])->group(function () {
        Route::get('/transaction/order', [TransactionController::class, 'create'])->name('transaction.create');
        Route::post('/transaction/order', [TransactionController::class, 'store'])->name('transaction.store');
        Route::get('/payment/success', [TransactionController::class, 'paymentSuccess'])->name('payment.success');
        Route::get('/transaction/{order_number}/receipt', [TransactionController::class, 'receipt'])->name('transaction.receipt');
        Route::post('/api/orders/{order_number}/sync-status', [TransactionController::class, 'syncMidtrans'])->name('api.order.sync-status');
    });

    // Laporan Penjualan (Pimpinan)
    Route::middleware(['role:Pimpinan'])->group(function () {
        Route::get('/reports/sales', [ReportController::class, 'index'])->name('reports.sales');
    });

    // Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
