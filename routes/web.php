<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DiscountController;
use App\Http\Controllers\ExportTaskController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MidtransNotificationController;
use App\Http\Controllers\OnboardingWizardController;
use App\Http\Controllers\OwnerOnboardingController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfileEmailChangeController;
use App\Http\Controllers\QzTraySigningController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RestockForecastController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\StoreSwitchController;
use App\Http\Controllers\TableController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserInvitationController;
use App\Livewire\ChannelMappingManager;
use App\Livewire\Inventory\IngredientLedger;
use App\Livewire\Inventory\IngredientManager;
use App\Livewire\Inventory\ModifierManager;
use App\Livewire\Kasir\CreateOrder;
use App\Livewire\Kds\Board;
use App\Services\OAuthService;
use Illuminate\Http\Request;
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

// Owner Self-Registration & OTP Lifecycle
Route::get('/register', [RegisteredUserController::class, 'create'])
    ->name('register');
Route::post('/register', [RegisteredUserController::class, 'store'])
    ->middleware('throttle:6,1');

// 6-digit OTP verification
Route::post('/verify-email/otp', [VerifyEmailController::class, 'verifyOtp'])
    ->middleware(['auth', 'tenant.context', 'throttle:10,1'])
    ->name('verification.otp');

// Google & Apple OAuth Routes
Route::get('/auth/oauth/{provider}/redirect', [OAuthController::class, 'redirect'])
    ->name('oauth.redirect');
Route::get('/auth/oauth/google/callback', [OAuthController::class, 'googleCallback'])
    ->name('oauth.callback.google');
Route::post('/auth/oauth/apple/callback', [OAuthController::class, 'appleCallback'])
    ->name('oauth.callback.apple');

// Employee Invitation Acceptance Lifecycle
Route::get('/invitations/{token}/accept', [UserInvitationController::class, 'showAccept'])
    ->middleware('throttle:10,1')
    ->name('invitations.accept');
Route::post('/invitations/{token}/accept', [UserInvitationController::class, 'processAccept'])
    ->middleware('throttle:6,1')
    ->name('invitations.process-accept');

// Self-service resend token aktivasi (unauthenticated & rate limited throttle:3,15)
Route::post('/onboarding/resend-token', [OwnerOnboardingController::class, 'resend'])
    ->middleware('throttle:3,15')
    ->name('onboarding.resend');

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

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])->name('password.store');
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

    // Verify-Before-Swap Email Change Workflow
    Route::post('/profile/email/request', [ProfileEmailChangeController::class, 'requestChange'])
        ->middleware('throttle:6,1')
        ->name('profile.email.request');
    Route::post('/profile/email/verify', [ProfileEmailChangeController::class, 'verifyChange'])
        ->middleware('throttle:10,1')
        ->name('profile.email.verify');
    Route::post('/profile/email/cancel', [ProfileEmailChangeController::class, 'cancelChange'])
        ->name('profile.email.cancel');

    Route::post('/profile/oauth/{provider}/link', function (string $provider, Request $request) {
        $request->query->set('intent', OAuthService::INTENT_LINK_ACCOUNT);

        return app(OAuthController::class)->redirect($request, $provider);
    })->name('profile.oauth.link');
    Route::delete('/profile/oauth/{provider}', [OAuthController::class, 'unlink'])
        ->name('profile.oauth.unlink');

    // Penggantian Cabang (Store Switcher)
    Route::post('/switch-store', StoreSwitchController::class)->name('store.switch');

    // Wizard Onboarding (Step 1 & Step 2) — Hanya Owner
    Route::middleware('role:Owner')->group(function () {
        Route::get('/onboarding/business-profile', [OnboardingWizardController::class, 'businessProfile'])
            ->name('onboarding.business-profile');
        Route::post('/onboarding/business-profile', [OnboardingWizardController::class, 'storeBusinessProfile'])
            ->name('onboarding.business-profile.store');
        Route::get('/onboarding/initial-branch', [OnboardingWizardController::class, 'initialBranch'])
            ->name('onboarding.initial-branch');
        Route::post('/onboarding/initial-branch', [OnboardingWizardController::class, 'storeInitialBranch'])
            ->name('onboarding.initial-branch.store');
    });

    // Rute Operasional POS — Dilindungi EnsureOnboardingCompleted
    Route::middleware('ensure.onboarding.completed')->group(function () {
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

        // Sistem (Stores, Users, Roles)
        Route::middleware('role:Owner')->group(function () {
            Route::resource('stores', StoreController::class);
            Route::resource('users', UserController::class);
            Route::resource('roles', RoleController::class);

            // Undangan Karyawan
            Route::get('/users-invitations', [UserInvitationController::class, 'index'])->name('users.invitations.index');
            Route::post('/users-invitations', [UserInvitationController::class, 'store'])->name('users.invitations.store');
            Route::post('/users-invitations/{id}/resend', [UserInvitationController::class, 'resend'])->name('users.invitations.resend');
            Route::delete('/users-invitations/{id}', [UserInvitationController::class, 'revoke'])->name('users.invitations.revoke');
        });

        // Meja & Perangkat Kasir (Devices)
        Route::middleware('role:Owner,Manager')->group(function () {
            Route::post('tables/{table}/regenerate-token', [TableController::class, 'regenerateToken'])->name('tables.regenerate-token');
            Route::resource('tables', TableController::class);

            // Perangkat Kasir & Terminal POS
            Route::post('devices/{device}/revoke', [DeviceController::class, 'revoke'])->name('devices.revoke');
            Route::post('devices/{device}/regenerate-token', [DeviceController::class, 'regenerateToken'])->name('devices.regenerate-token');
            Route::resource('devices', DeviceController::class);
        });

        // --- MODUL ENTERPRISE BARU ---

        // Shift Kasir — Supervisor ditambahkan (§7.3 audit: Supervisor memiliki izin shifts.*)
        Route::middleware('role:Owner,Manager,Kasir,Supervisor')->group(function () {
            Route::get('/shifts', [ShiftController::class, 'index'])->name('shifts.index');
            Route::post('/shifts/open', [ShiftController::class, 'open'])->name('shifts.open');
            Route::post('/shifts/{shift}/close', [ShiftController::class, 'close'])->name('shifts.close');
            Route::post('/shifts/{shift}/movement', [ShiftController::class, 'recordCashMovement'])->name('shifts.movement');
            Route::get('/shifts/{shift}', [ShiftController::class, 'show'])->name('shifts.show');
        });

        // Riwayat Pesanan
        Route::middleware('role:Owner,Manager,Kasir,Waiter')->group(function () {
            Route::get('/orders', [TransactionController::class, 'index'])->name('orders.index');
            Route::get('/orders/{orderNumber}', [TransactionController::class, 'show'])->name('orders.show');
            Route::post('/orders/{orderNumber}/void', [TransactionController::class, 'void'])->name('orders.void');
        });

        // Diskon & Promo
        Route::middleware('role:Owner,Manager')->group(function () {
            Route::get('/discounts', [DiscountController::class, 'index'])->name('discounts.index');
        });

        // Pengaturan & Audit Log
        Route::middleware('role:Owner,Manager')->group(function () {
            Route::get('/activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index');
        });

        // Ekspor laporan asinkron (polling status + download) — Dilindungi hak akses fitur async_export
        Route::middleware(['role:Owner,Manager', 'enforce.entitlement:async_export'])->group(function () {
            Route::get('/exports/{exportTask}/status', [ExportTaskController::class, 'status'])->name('exports.status');
            Route::get('/exports/{exportTask}/download', [ExportTaskController::class, 'download'])->name('exports.download');
        });

        // BI restock forecast (JSON read-only, dikonsumsi widget Node 2) — Dilindungi hak akses fitur restock_forecast
        Route::middleware(['role:Owner,Manager,Inventory', 'enforce.entitlement:restock_forecast'])->group(function () {
            Route::get('/api/analytics/restock-forecasts', [RestockForecastController::class, 'index'])
                ->name('analytics.restock-forecasts');
        });

        // Channel Mapping Manager — Dilindungi hak akses fitur omnichannel
        Route::middleware(['role:Owner,Manager', 'enforce.entitlement:omnichannel'])->group(function () {
            Route::get('/integrations/channel-mapping', ChannelMappingManager::class)->name('integrations.channel-mapping');
        });

        Route::middleware('role:Owner')->group(function () {
            Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
            Route::post('/settings', [SettingController::class, 'update'])->name('settings.update');
        });
    }); // Akhir dari grup ensure.onboarding.completed
});
