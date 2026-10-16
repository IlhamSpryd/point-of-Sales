<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sentral otentikasi request: Penengah dari proses Rate-Limiting dan verifikasi kredensial login.
 */
class LoginRequest extends FormRequest
{
    /**
     * Tentukan apakah guest (tamu anonim) diperkenankan mengirimkan formulasi request Login ini.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Daftar aturan validasi dari kerangka isian pendaftaran Login (Email terformat string dan ada Sandi).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Percobaan pembandingan kredensial yang masuk dalam form dengan nilai terenkripsi di Database (Attempt).
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $email = Str::lower($this->string('email'));
        $userCandidate = User::withoutGlobalScopes()->where('email', $email)->first();

        // 1. Cek apakah user terkunci di level database (15 menit)
        if ($userCandidate && $userCandidate->isLockedOut()) {
            event(new Lockout($this));
            $seconds = now()->diffInSeconds($userCandidate->locked_until, false);
            throw ValidationException::withMessages([
                'email' => 'Akun Anda terkunci sementara karena 5 kali percobaan login gagal. Silakan coba lagi dalam '.ceil($seconds / 60).' menit.',
            ]);
        }

        if (! Auth::attempt(['email' => $email, 'password' => $this->string('password')], $this->boolean('remember'))) {
            // Hit rate limiter dengan decay 15 menit (900 detik) sesuai PRD OD-09
            RateLimiter::hit($this->throttleKey(), 900);

            if ($userCandidate) {
                $isLocked = $userCandidate->recordFailedLogin(5, 15);
                if ($isLocked) {
                    try {
                        \Illuminate\Support\Facades\DB::table('activity_logs')->insert([
                            'tenant_id' => $userCandidate->tenant_id,
                            'store_id' => \App\Models\Store::withoutTenantScope()->where('tenant_id', $userCandidate->tenant_id)->orderBy('id')->value('id') ?? 1,
                            'user_id' => $userCandidate->id,
                            'action' => 'user.auth_locked_out',
                            'subject_type' => User::class,
                            'subject_id' => $userCandidate->id,
                            'description' => "Akun {$userCandidate->email} dikunci selama 15 menit setelah 5 kali gagal login.",
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('Audit user.auth_locked_out failed: '.$e->getMessage());
                    }

                    event(new Lockout($this));
                    throw ValidationException::withMessages([
                        'email' => 'Akun Anda telah dikunci selama 15 menit karena 5 kali percobaan login gagal berturut-turut.',
                    ]);
                }
            }

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        $user = Auth::user();

        // [SEC-004] Cek akun nonaktif
        if ($user instanceof User && ! $user->is_active) {
            Auth::logout();
            RateLimiter::hit($this->throttleKey(), 900);

            throw ValidationException::withMessages([
                'email' => 'Akun ini telah dinonaktifkan. Silakan hubungi Owner atau Manager.',
            ]);
        }

        // Cek tenant ditangguhkan
        if ($user instanceof User && $user->tenant?->isSuspended()) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'Ruang kerja bisnis Anda sedang ditangguhkan. Silakan hubungi dukungan pelanggan.',
            ]);
        }

        // Login sukses: reset penghitung kegagalan
        if ($user instanceof User) {
            $user->resetLoginLockout();
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Memastikan request pengajuan masuknya sandi tidak melanggar batas cegah percobaan peretasan Brute-Force (Rate Limiting).
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $email = Str::lower($this->string('email'));
        $userCandidate = User::withoutGlobalScopes()->where('email', $email)->first();
        if ($userCandidate && $userCandidate->isLockedOut()) {
            event(new Lockout($this));
            $seconds = now()->diffInSeconds($userCandidate->locked_until, false);
            throw ValidationException::withMessages([
                'email' => 'Akun Anda terkunci sementara karena 5 kali percobaan login gagal. Silakan coba lagi dalam '.ceil($seconds / 60).' menit.',
            ]);
        }

        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Menghasilkan pelacak unik dari IP Klien dan Email untuk keperluan pembekuan sementara (Throttle Key).
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
