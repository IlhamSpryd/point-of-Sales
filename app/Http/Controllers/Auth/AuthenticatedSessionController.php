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

        // Arahkan sesuai role — Kasir TIDAK PERNAH menyentuh dashboard finansial,
        // Pimpinan langsung ke laporan, Administrator ke dashboard.
        $user = $request->user();

        return match ($user->role?->name) {
            'Kasir' => redirect()->route('transaction.create'),
            'Pimpinan' => redirect()->route('reports.sales'),
            default => redirect()->intended(route('dashboard', absolute: false)),
        };
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
