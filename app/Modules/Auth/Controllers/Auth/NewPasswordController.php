<?php

namespace App\Modules\Auth\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    /**
     * Merender halaman pembaruan sandi bagi yang lupa kata sandi lama.
     */
    public function create(Request $request): View
    {
        return view('auth.reset-password', ['request' => $request]);
    }

    /**
     * Menangani reset kata sandi masuk dan memperbaruinya di tabel User.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::min(10)->letters()->mixedCase()->numbers()->uncompromised()],
        ]);

        // Proses krusial pembaharuan token autentikasi sandi masuk ke DB.
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request) {
                $hash = Hash::make($request->password);
                $user->forceFill([
                    'password' => $hash,
                    'password_hash' => $hash,
                    'password_changed_at' => now(),
                    'remember_token' => Str::random(60),
                    'failed_login_attempts' => 0,
                    'locked_until' => null,
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();

                // Purge active sessions pada database driver
                try {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                } catch (\Throwable) {
                    // Session table might not exist in array/memory driver
                }

                event(new PasswordReset($user));
            }
        );

        // Evaluasi pengembalian respons aman jika perombakan Password usai.
        return $status == Password::PASSWORD_RESET
                    ? redirect()->route('login')->with('status', __($status))
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => __($status)]);
    }
}
