<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\KdsController;
use App\Http\Controllers\MidtransNotificationController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\TableController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

// Rute publik untuk pelanggan self-order (TANPA login).
// Diletakkan di luar grup 'auth' & 'verified' secara sengaja.
require __DIR__.'/customer.php';

// Webhook Midtrans (server-to-server, TANPA CSRF & TANPA auth -- lihat
// pengecualian CSRF di bootstrap/app.php). Endpoint ini SATU-SATUNYA
// sumber kebenaran otomatis untuk transisi status pembayaran non-tunai,
// dipakai bersama oleh Self-Order pelanggan maupun Kasir POS.
Route::post('/midtrans/notification', [MidtransNotificationController::class, 'handle'])
    ->middleware('throttle:120,1')
    ->name('midtrans.notification');

Route::middleware(['auth', 'verified'])->group(function () {

    // Core Entity Resources (Administrator only for Create/Edit/Delete)
    Route::middleware(['role:Administrator'])->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::resource('roles', RoleController::class)->except(['show']);
        Route::resource('users', UserController::class)->except(['show']);

        // Category and Product management (create, store, edit, update, destroy)
        Route::resource('categories', CategoryController::class)->except(['index', 'show']);
        Route::resource('products', ProductController::class)->except(['index', 'show']);
        Route::resource('tables', TableController::class)->except(['show']);
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
        Route::get('/transaction/{order_number}/receipt', [TransactionController::class, 'receipt'])->name('transaction.receipt');
        Route::post('/api/orders/{order_number}/sync-status', [TransactionController::class, 'syncMidtrans'])->name('api.order.sync-status');
    });

    // Laporan Penjualan (Pimpinan)
    Route::middleware(['role:Pimpinan'])->group(function () {
        Route::get('/reports/sales', [ReportController::class, 'index'])->name('reports.sales');
    });

    // Kitchen Display System (Administrator + Kasir -- toko kecil, kasir sering merangkap barista)
    Route::middleware(['role:Administrator,Kasir'])->group(function () {
        Route::get('/kds', [KdsController::class, 'index'])->name('kds.index');
    });

    // Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
