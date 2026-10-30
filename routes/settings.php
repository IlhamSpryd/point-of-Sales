<?php

declare(strict_types=1);

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\OnboardingWizardController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\StoreSwitchController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserInvitationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Settings, Tenancy & Store Administration Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'tenant.context', 'enforce.email.verification'])->group(function () {
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

    Route::middleware('ensure.onboarding.completed')->group(function () {
        // Toko, Pengguna, Peran — Khusus Owner
        Route::middleware('role:Owner')->group(function () {
            Route::resource('stores', StoreController::class);
            Route::resource('users', UserController::class);
            Route::resource('roles', RoleController::class);

            // Undangan Karyawan
            Route::get('/users-invitations', [UserInvitationController::class, 'index'])->name('users.invitations.index');
            Route::post('/users-invitations', [UserInvitationController::class, 'store'])->name('users.invitations.store');
            Route::post('/users-invitations/{id}/resend', [UserInvitationController::class, 'resend'])->name('users.invitations.resend');
            Route::delete('/users-invitations/{id}', [UserInvitationController::class, 'revoke'])->name('users.invitations.revoke');
            Route::delete('/users-invitations/{id}/destroy', [UserInvitationController::class, 'revoke'])->name('users.invitations.destroy');

            Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
            Route::post('/settings', [SettingController::class, 'update'])->name('settings.update');
        });

        // Meja & Perangkat Kasir (Devices) & Audit Logs
        Route::middleware('role:Owner,Manager')->group(function () {
            Route::post('devices/{device}/revoke', [DeviceController::class, 'revoke'])->name('devices.revoke');
            Route::post('devices/{device}/regenerate-token', [DeviceController::class, 'regenerateToken'])->name('devices.regenerate-token');
            Route::resource('devices', DeviceController::class);

            Route::get('/activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index');
        });
    });
});
