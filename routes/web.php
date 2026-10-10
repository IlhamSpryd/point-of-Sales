<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiscountController;
use App\Http\Controllers\ExportTaskController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MidtransNotificationController;
use App\Http\Controllers\OwnerOnboardingController;
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
use App\Livewire\Inventory\IngredientLedger;
use App\Livewire\Inventory\IngredientManager;
use App\Livewire\Inventory\ModifierManager;
use App\Livewire\Kasir\CreateOrder;
use App\Livewire\Kasir\OrderHistory;
use App\Livewire\Kds\Board;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

/*
| Domain 1 — Penukaran magic link onboarding Owner (D5).
| Route ini TIDAK butuh auth (token = bukti otorisasi sekali-pakai),
| rate limited ketat. Hanya tersedia via HTTPS di deployment (force HTTPS
| ditegakkan di level web server/CDN — dokumentasi deployment).
*/
// Rute tidak menggunakan guest middleware: user yang sedang login dapat
// menyelesaikan onboarding token yang valid tanpa membuat token bisa dipakai
// sebagai jalur bypass tenant; controller mengikat token ke user_id/tenant_id.
Route::get('/onboarding/set-password/{token}', [OwnerOnboardingController::class, 'show'])
    ->middleware('throttle:6,1')
    ->name('onboarding.set-password');
Route::post('/onboarding/set-password/{token}', [OwnerOnboardingController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('onboarding.set-password.store');

Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');
Route::get('/verify-email', EmailVerificationPromptController::class)
    ->middleware(['auth', 'tenant.context'])
    ->name('verification.notice');
Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
    ->middleware(['auth', 'tenant.context', 'throttle:6,1'])
    ->name('verification.send');

// Route:method/path yang sudah terverifikasi tidak bergantung pada kode verifikasi email.

// Catatan: password.confirm route middleware di atas sengaja tidak diterapkan
// (token sekali-pakai adalah bukti otorisasi); CSRF tetap aktif.

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

Route::middleware(['auth', 'tenant.context', 'enforce.email.verification'])->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // Pendaratan netral-role — TIDAK pernah 403 untuk siapa pun yang login.
    // Menggantikan redirect langsung ke 'dashboard' yang 403 untuk non-Owner/Manager.
    // @see §2.1, §2.3 audit navigasi
    Route::get('/home', [HomeController::class, 'index'])->name('home');

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
    Route::middleware('role:Owner,Manager,Kasir')->group(function () {
        Route::get('/transaction', CreateOrder::class)->name('transaction.create');
        Route::post('/transaction', [TransactionController::class, 'store'])->name('transaction.store');
        Route::get('/transaction/receipt/{orderCode}', [TransactionController::class, 'receipt'])->name('transaction.receipt');
        Route::post('/api/orders/{order_number}/sync-status', [TransactionController::class, 'syncMidtrans'])->name('api.order.sync-status');
        Route::get('/api/orders/{order_number}/print-payload', [TransactionController::class, 'printPayload'])->name('api.order.print-payload');
        Route::get('/api/orders/{order_number}/print-kitchen-payload', [TransactionController::class, 'printKitchenPayload'])->name('api.order.print-kitchen-payload');

        Route::get('/qz/certificate', [QzTraySigningController::class, 'certificate'])->name('qz.certificate');
        Route::post('/qz/sign', [QzTraySigningController::class, 'sign'])->name('qz.sign');
    });

    // Dapur (KDS) — Cook ditambahkan (§2.2 audit: role Cook di-seed dengan izin kds.* tapi tidak bisa akses KDS)
    Route::middleware('role:Owner,Manager,Kasir,Barista,Waiter,Cook')->group(function () {
        Route::get('/kds', Board::class)->name('kds.index');
    });

    // Katalog (Produk & Kategori)
    Route::middleware('role:Owner,Manager,Inventory')->group(function () {
        // Inventory (BOM & Ingredients)
        Route::prefix('inventory')->name('inventory.')->group(function () {
            Route::get('/ingredients', IngredientManager::class)->name('ingredients');
            Route::get('/ledger', IngredientLedger::class)->name('ledger');
            Route::get('/modifiers', ModifierManager::class)->name('modifiers');
        });

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

    // Shift Kasir — Supervisor ditambahkan (§7.3 audit: Supervisor memiliki izin shifts.*)
    Route::middleware('role:Owner,Manager,Kasir,Supervisor')->group(function () {
        Route::get('/shifts', [ShiftController::class, 'index'])->name('shifts.index');
    });

    // Riwayat Pesanan
    Route::middleware('role:Owner,Manager,Kasir,Waiter')->group(function () {
        Route::get('/orders', OrderHistory::class)->name('orders.index');
    });

    // Diskon & Promo
    Route::middleware('role:Owner,Manager')->group(function () {
        Route::get('/discounts', [DiscountController::class, 'index'])->name('discounts.index');
    });

    // Pengaturan & Audit Log
    Route::middleware('role:Owner,Manager')->group(function () {
        Route::get('/activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index');
    });

    // Ekspor laporan asinkron (polling status + download)
    Route::middleware('role:Owner,Manager')->group(function () {
        Route::get('/exports/{exportTask}/status', [ExportTaskController::class, 'status'])->name('exports.status');
        Route::get('/exports/{exportTask}/download', [ExportTaskController::class, 'download'])->name('exports.download');
    });

    // BI restock forecast (JSON read-only, dikonsumsi widget Node 2)
    Route::middleware('role:Owner,Manager,Inventory')->group(function () {
        Route::get('/api/analytics/restock-forecasts', [RestockForecastController::class, 'index'])
            ->name('analytics.restock-forecasts');
    });

    // Channel Mapping Manager
    Route::middleware('role:Owner,Manager')->group(function () {
        Route::get('/integrations/channel-mapping', ChannelMappingManager::class)->name('integrations.channel-mapping');
    });

    Route::middleware('role:Owner')->group(function () {
        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
    });
});
