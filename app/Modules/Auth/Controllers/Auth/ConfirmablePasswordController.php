<?php

namespace App\Modules\Auth\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ConfirmablePasswordController extends Controller
{
    /**
     * Merender antarmuka konfirmasi sandi demi keamanan transaksi kritis.
     */
    public function show(): View
    {
        return view('auth.confirm-password');
    }

    /**
     * Mencocokkan konfirmasi sandi dengan akun sebelum izin akses sementara diberikan.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! Auth::guard('web')->validate([
            'email' => $user->email,
            'password' => $request->password,
        ])) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
