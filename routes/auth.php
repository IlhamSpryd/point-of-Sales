<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    // [SEC-003 - CRITICAL FIX - AUDIT KEAMANAN]
    // Rute `register` (GET & POST) yang sebelumnya ada di sini adalah sisa
    // scaffolding default Laravel Breeze, mengarah ke RegisteredUserController.
    // Sistem POS ini adalah aplikasi INTERNAL untuk staf kafe (Owner, Manager,
    // Kasir, Barista, Waiter, Inventory) -- TIDAK PERNAH dirancang untuk
    // pendaftaran mandiri oleh publik. Rute lama membiarkan SIAPA PUN yang
    // mengunjungi /register membuat akun baru tanpa role_id lalu langsung
    // ter-autentikasi ke dalam sistem (lihat RegisteredUserController::store()).
    //
    // Controller App\Http\Controllers\Auth\RegisteredUserController.php SENGAJA
    // dibiarkan tetap ada di disk (tidak dihapus dari filesystem agar tidak
    // memutus autoload/tooling lain yang mungkin merujuknya), namun kini
    // sepenuhnya tidak dapat dijangkau (unroutable) karena tidak lagi
    // di-import maupun didaftarkan di bawah ini.
    //
    // Akun staf HANYA boleh dibuat lewat alur terkontrol:
    // App\Http\Controllers\UserController (dijaga middleware role:Owner).

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
