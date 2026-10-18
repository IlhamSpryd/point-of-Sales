<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\EmailVerificationOtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Mengirim ulang kode verifikasi OTP ke alamat email pengguna.
     * Mengikuti batas throttling ketat (cooldown 60 detik, max 5/jam, max 10/hari).
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user === null) {
            return redirect()->route('login');
        }

        if ($user->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        // Jika terhambat rate limit, OtpRateLimitedException otomatis menghasilkan HTTP 429
        app(EmailVerificationOtpService::class)->issueOtp(
            $user,
            'registration',
            $request->ip(),
            $request->userAgent()
        );

        return back()->with('status', 'verification-code-sent');
    }
}
