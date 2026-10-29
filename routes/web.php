<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorAuthenticationController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvestorDemoController;
use App\Http\Controllers\OwnerOnboardingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfileEmailChangeController;
use App\Http\Controllers\UserInvitationController;
use App\Services\OAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes (Orchestrator)
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return redirect()->route('login');
});

// Domain 1 — Penukaran magic link onboarding Owner (unauthenticated, rate limited)
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

// Self-service resend token aktivasi
Route::post('/onboarding/resend-token', [OwnerOnboardingController::class, 'resend'])
    ->middleware('throttle:3,15')
    ->name('onboarding.resend');

// Email Verification Notices
Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');
Route::get('/verify-email', EmailVerificationPromptController::class)
    ->middleware(['auth', 'tenant.context'])
    ->name('verification.notice');
Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
    ->middleware(['auth', 'tenant.context', 'throttle:6,1'])
    ->name('verification.send');

// Guest Authentication Routes
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])->name('password.store');
});

// Tantangan Autentikasi Dua Faktor (2FA / TOTP)
Route::get('/two-factor-challenge', [TwoFactorAuthenticationController::class, 'challenge'])->name('two-factor.challenge');
Route::post('/two-factor-challenge', [TwoFactorAuthenticationController::class, 'verifyChallenge'])
    ->middleware('throttle:6,1')
    ->name('two-factor.verify');

// Base Authenticated Routes (Profile, Home, Password Confirmation)
Route::middleware(['auth', 'tenant.context', 'enforce.email.verification'])->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/home', [HomeController::class, 'index'])->name('home');
    Route::get('/investor-demo', [InvestorDemoController::class, 'index'])
        ->middleware(['ensure.onboarding.completed', 'role:Owner,Manager'])
        ->name('investor.demo');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);
    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])
        ->middleware('require.two_factor')
        ->name('profile.destroy');

    Route::post('/profile/two-factor/enable', [TwoFactorAuthenticationController::class, 'enable'])
        ->middleware('throttle:6,1')
        ->name('two-factor.enable');
    Route::post('/profile/two-factor/confirm', [TwoFactorAuthenticationController::class, 'confirm'])
        ->middleware('throttle:6,1')
        ->name('two-factor.confirm');
    Route::delete('/profile/two-factor', [TwoFactorAuthenticationController::class, 'disable'])
        ->name('two-factor.disable');

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
});

/*
|--------------------------------------------------------------------------
| Modular Feature Route Registrations
|--------------------------------------------------------------------------
*/
require __DIR__.'/customer.php';
require __DIR__.'/sales.php';
require __DIR__.'/products.php';
require __DIR__.'/inventory.php';
require __DIR__.'/customers.php';
require __DIR__.'/purchases.php';
require __DIR__.'/reports.php';
require __DIR__.'/settings.php';
require __DIR__.'/finance.php';
