<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Merender antarmuka halaman Login.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Menangani permintaan masuk dan mencocokkan sesi kredensial pengguna (Login).
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $user = Auth::user();

        // [M1-MVC-003] Kebijakan MFA: Pengguna dengan 2FA aktif wajib menyelesaikan challenge
        // sebelum memperoleh sesi aplikasi terautentikasi penuh.
        if ($user instanceof \App\Models\User && $user->hasTwoFactorEnabled()) {
            $userId = (int) $user->id;
            $remember = $request->boolean('remember');

            // Log out dari web guard agar sesi saat ini BUKAN full authentication
            Auth::guard('web')->logout();

            // Simpan pending MFA state dalam session terisolasi
            $request->session()->regenerate();
            $request->session()->put('login.mfa_user_id', $userId);
            $request->session()->put('login.mfa_remember', $remember);
            $request->session()->put('login.mfa_started_at', now()->timestamp);

            return redirect()->route('two-factor.challenge');
        }

        $request->session()->regenerate();

        // Satu sumber kebenaran untuk redirect per-role: HomeController.
        // Tidak ada lagi duplikasi mapping di sini. Semua role (termasuk
        // Supervisor, Cook, dan role custom) aman — tidak pernah 403.
        // @see §2.1 audit navigasi, App\Http\Controllers\HomeController
        return redirect()->intended(route('home', absolute: false));
    }

    /**
     * Mengakhiri sesi terautentikasi dan mereset token (Proses Logout).
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->forget(['login.mfa_user_id', 'login.mfa_remember', 'login.mfa_started_at']);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
