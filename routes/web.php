<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiscountController;
use App\Http\Controllers\ExportTaskController;
use App\Http\Controllers\MidtransNotificationController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QzTraySigningController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RestockForecastController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\TableController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use App\Livewire\ChannelMappingManager;
use App\Livewire\Kasir\CreateOrder;
use App\Livewire\Kds\Board;
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

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);
    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Dashboard & Laporan
    Route::middleware('role:Owner,Manager')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/reports/sales', [ReportController::class, 'index'])->name('reports.sales');
    });

    // Kasir POS
    Route::middleware('role:Kasir')->group(function () {
        Route::get('/transaction', CreateOrder::class)->name('transaction.create');
        Route::post('/transaction', [TransactionController::class, 'store'])->name('transaction.store');
        Route::get('/transaction/receipt/{orderCode}', [TransactionController::class, 'receipt'])->name('transaction.receipt');
        Route::post('/api/orders/{order_number}/sync-status', [TransactionController::class, 'syncMidtrans'])->name('api.order.sync-status');
        Route::get('/api/orders/{order_number}/print-payload', [TransactionController::class, 'printPayload'])->name('api.order.print-payload');

        Route::get('/qz/certificate', [QzTraySigningController::class, 'certificate'])->name('qz.certificate');
        Route::post('/qz/sign', [QzTraySigningController::class, 'sign'])->name('qz.sign');
    });

    // Dapur (KDS)
    Route::middleware('role:Owner,Manager,Kasir,Barista,Waiter')->group(function () {
        Route::get('/kds', Board::class)->name('kds.index');
    });

    // Katalog (Produk & Kategori)
    Route::middleware('role:Owner,Manager,Inventory')->group(function () {
        Route::resource('products', ProductController::class);
        Route::resource('categories', CategoryController::class);
    });

    // Sistem (Users, Roles, Tables)
    Route::middleware('role:Owner')->group(function () {
        Route::resource('users', UserController::class);
        Route::resource('roles', RoleController::class);
    });

    // Meja
    Route::middleware('role:Owner,Manager')->group(function () {
        Route::resource('tables', TableController::class);
    });

    // --- MODUL ENTERPRISE BARU ---

    // Shift Kasir
    Route::middleware('role:Owner,Manager,Kasir')->group(function () {
        Route::get('/shifts', [ShiftController::class, 'index'])->name('shifts.index');
    });

    // Riwayat Pesanan
    Route::middleware('role:Owner,Manager,Kasir,Waiter')->group(function () {
        // Fallback sementara agar sidebar aktif. Nanti diganti Controller beneran.
        Route::get('/orders', fn () => view('orders.index'))->name('orders.index');
    });

    // Diskon & Promo
    Route::middleware('role:Owner,Manager')->group(function () {
        Route::get('/discounts', [DiscountController::class, 'index'])->name('discounts.index');
    });

    // Pengaturan & Audit Log
    Route::middleware('role:Owner,Manager')->group(function () {
        Route::get('/activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index');
    });

    // [OMEGA-NODE5] Ekspor laporan asinkron (polling status + download)
    Route::middleware('role:Owner,Manager')->group(function () {
        Route::get('/exports/{exportTask}/status', [ExportTaskController::class, 'status'])->name('exports.status');
        Route::get('/exports/{exportTask}/download', [ExportTaskController::class, 'download'])->name('exports.download');
    });

    // [OMEGA-NODE5] BI restock forecast (JSON read-only, dikonsumsi widget Node 2)
    Route::middleware('role:Owner,Manager,Inventory')->group(function () {
        Route::get('/api/analytics/restock-forecasts', [RestockForecastController::class, 'index'])
            ->name('analytics.restock-forecasts');
    });

    // [OMEGA-NODE5] Channel Mapping Manager
    Route::middleware('role:Owner,Manager')->group(function () {
        Route::get('/integrations/channel-mapping', ChannelMappingManager::class)->name('integrations.channel-mapping');
    });

    Route::middleware('role:Owner')->group(function () {
        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
    });
});
