<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\EmailVerificationOtpService;
use App\Services\OtpVerificationResult;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VerifyEmailController extends Controller
{
    /**
     * Menandai sah (verified_at) alamat email pada pendaftar jika token URL cocok (kompatibilitas link lama).
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
        }

        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
    }

    /**
     * Memverifikasi kode OTP 6 digit untuk verifikasi email pendaftaran akun baru.
     */
    public function verifyOtp(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ]);

        $user = $request->user();
        if ($user === null) {
            return redirect()->route('login');
        }

        if ($user->hasVerifiedEmail()) {
            $tenant = $user->tenant;
            if ($tenant && ! $tenant->isOnboardingCompleted()) {
                return redirect()->route('onboarding.business-profile');
            }

            return redirect()->intended(route('dashboard', absolute: false));
        }

        $otpService = app(EmailVerificationOtpService::class);
        $result = $otpService->verifyOtp($user, $request->string('code'), 'registration');

        if (! $result->isSuccessful()) {
            $message = match ($result->getStatus()) {
                OtpVerificationResult::STATUS_LOCKED => 'Kode verifikasi telah dikunci karena melebihi batas percobaan. Silakan minta kode verifikasi baru.',
                OtpVerificationResult::STATUS_EXPIRED => 'Kode verifikasi telah kedaluwarsa. Silakan minta kode verifikasi baru.',
                OtpVerificationResult::STATUS_NOT_FOUND => 'Kode verifikasi tidak ditemukan atau sudah tidak berlaku.',
                default => "Kode verifikasi tidak valid. Sisa percobaan: {$result->getRemainingAttempts()}.",
            };

            return back()->withErrors(['code' => $message]);
        }

        event(new Verified($user));

        // Rotasi sesi untuk mencegah session fixation
        $request->session()->regenerate();

        $freshUser = $user->fresh();
        $tenant = $freshUser->tenant;

        if ($tenant && ! $tenant->isOnboardingCompleted()) {
            if (in_array($tenant->onboarding_status, [Tenant::ONBOARDING_PENDING_VERIFICATION, Tenant::ONBOARDING_PENDING, null, ''], true)) {
                $tenant->update(['onboarding_status' => Tenant::ONBOARDING_BUSINESS_PROFILE]);
            }

            return redirect()->route('onboarding.business-profile')
                ->with('success', 'Email berhasil diverifikasi! Silakan lanjutkan konfigurasi profil bisnis Anda.');
        }

        return redirect()->intended(route('dashboard', absolute: false))
            ->with('success', 'Email berhasil diverifikasi!');
    }
}
