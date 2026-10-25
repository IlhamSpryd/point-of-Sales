<?php

namespace App\Modules\Auth\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Memperbarui kata sandi pengguna terautentikasi dan mencabut sesi di perangkat lain.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ], [
            'current_password.required' => 'Kata sandi saat ini wajib diisi.',
            'current_password.current_password' => 'Kata sandi saat ini tidak cocok.',
            'password.required' => 'Kata sandi baru wajib diisi.',
            'password.confirmed' => 'Konfirmasi kata sandi baru tidak cocok.',
        ]);

        $user = $request->user();
        $user->forceFill([
            'password_hash' => Hash::make($validated['password']),
            'password_changed_at' => now(),
            'remember_token' => Str::random(60),
        ])->save();

        // Cabut seluruh sesi di perangkat lain pada tabel sessions
        try {
            DB::table('sessions')
                ->where('user_id', $user->id)
                ->where('id', '!=', session()->getId())
                ->delete();
        } catch (\Throwable) {
        }

        return back()->with('status', 'password-updated');
    }
}
