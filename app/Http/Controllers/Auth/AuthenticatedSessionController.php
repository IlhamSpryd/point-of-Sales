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

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
