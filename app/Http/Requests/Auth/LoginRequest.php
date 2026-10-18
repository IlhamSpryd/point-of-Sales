<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\AuditTrailService;
use App\Support\EmailNormalizer;
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

        $rawEmail = $this->string('email');
        $normalizedEmail = EmailNormalizer::normalize($rawEmail);
        $userCandidate = User::withoutGlobalScopes()->where('email_normalized', $normalizedEmail)->first();

        // 1. Cek apakah user terkunci di level database (15 menit)
        if ($userCandidate && $userCandidate->isLockedOut()) {
            event(new Lockout($this));
            $seconds = now()->diffInSeconds($userCandidate->locked_until, false);
            throw ValidationException::withMessages([
                'email' => 'Your account is temporarily locked due to 5 consecutive failed sign-in attempts. Please try again in '.ceil($seconds / 60).' minute(s).',
            ]);
        }

        $attemptEmail = $userCandidate ? $userCandidate->email : $rawEmail;
        if (! Auth::attempt(['email' => $attemptEmail, 'password' => $this->string('password')], $this->boolean('remember'))) {
            // Hit rate limiter dengan decay 15 menit (900 detik) sesuai PRD OD-09
            RateLimiter::hit($this->throttleKey(), 900);

            if ($userCandidate) {
                $isLocked = $userCandidate->recordFailedLogin(5, 15);
                if ($isLocked) {
                    app(AuditTrailService::class)->log(
                        tenantId: (int) $userCandidate->tenant_id,
                        action: 'user.auth_locked_out',
                        userId: (int) $userCandidate->id,
                        subjectType: User::class,
                        subjectId: (int) $userCandidate->id,
                        description: "Account {$userCandidate->email} locked for 15 minutes after 5 failed sign-in attempts."
                    );

                    event(new Lockout($this));
                    throw ValidationException::withMessages([
                        'email' => 'Your account has been locked for 15 minutes due to 5 consecutive failed sign-in attempts.',
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
                'email' => 'This account has been deactivated. Please contact your store owner or manager.',
            ]);
        }

        // Cek tenant ditangguhkan
        if ($user instanceof User && $user->tenant?->isSuspended()) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'Your business workspace is currently suspended. Please contact customer support.',
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
        $rawEmail = $this->string('email');
        $normalizedEmail = EmailNormalizer::normalize($rawEmail);
        $userCandidate = User::withoutGlobalScopes()->where('email_normalized', $normalizedEmail)->first();
        if ($userCandidate && $userCandidate->isLockedOut()) {
            event(new Lockout($this));
            $seconds = now()->diffInSeconds($userCandidate->locked_until, false);
            throw ValidationException::withMessages([
                'email' => 'Your account is temporarily locked due to 5 consecutive failed sign-in attempts. Please try again in '.ceil($seconds / 60).' minute(s).',
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
        $normalizedEmail = EmailNormalizer::normalize($this->string('email'));

        return Str::transliterate($normalizedEmail.'|'.$this->ip());
    }
}
