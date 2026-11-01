<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * CashierAuthService — Layanan otentikasi kasir cepat via 6-digit PIN terenkripsi.
 *
 * Menerapkan:
 * - Format PIN 6-digit angka numerik.
 * - Hash PIN terenkripsi (bcrypt) pada users.cashier_pin / users.pin_hash.
 * - Rate-limiting: 5 kali gagal berturut-turut memicu penguncian selama 15 menit (900 detik).
 * - Pencatatan insiden keamanan & otentikasi ke ActivityLogService.
 */
class CashierAuthService
{
    public const MAX_ATTEMPTS = 5;

    public const LOCKOUT_SECONDS = 900; // 15 menit

    public function __construct(
        private readonly ActivityLogService $activityLogService
    ) {}

    /**
     * Memvalidasi format 6-digit PIN numerik.
     */
    public function validateFormat(string $pin): bool
    {
        return (bool) preg_match('/^\d{6}$/', $pin);
    }

    /**
     * Setel atau perbarui 6-digit PIN untuk kasir.
     *
     * @throws InvalidArgumentException
     */
    public function setPin(User $user, string $pin): void
    {
        if (! $this->validateFormat($pin)) {
            throw new InvalidArgumentException('PIN Kasir wajib berupa 6 digit angka numerik.');
        }

        $user->cashier_pin = $pin;
        $user->save();

        $this->resetLockout($user);

        $this->activityLogService->log(
            tenantId: (int) $user->tenant_id,
            action: 'cashier.pin_set',
            userId: (int) $user->id,
            subjectType: User::class,
            subjectId: (int) $user->id,
            description: "PIN Kasir 6-digit disetel/diperbarui untuk kasir #{$user->id} ({$user->name})."
        );
    }

    /**
     * Otentikasi kasir menggunakan 6-digit PIN dengan perlindungan rate-limiting.
     *
     * @throws ValidationException
     */
    public function authenticate(int|User $userCandidate, string $pin, ?int $storeId = null): User
    {
        $user = is_int($userCandidate)
            ? User::withoutGlobalScopes()->findOrFail($userCandidate)
            : $userCandidate;

        if (! $this->validateFormat($pin)) {
            throw ValidationException::withMessages([
                'pin' => 'Format PIN tidak valid. Masukkan 6 digit angka numerik.',
            ]);
        }

        $throttleKey = $this->throttleKey($user);

        if ($this->isLockedOut($user)) {
            $seconds = $this->availableInSeconds($user);
            $minutes = (int) ceil($seconds / 60);

            throw ValidationException::withMessages([
                'pin' => "PIN kasir terkunci karena 5 kali percobaan salah berturut-turut. Silakan coba lagi dalam {$minutes} menit.",
            ]);
        }

        $storedHash = $user->cashier_pin ?? $user->pin_hash;
        if (empty($storedHash) || ! Hash::check($pin, (string) $storedHash)) {
            RateLimiter::hit($throttleKey, self::LOCKOUT_SECONDS);

            if ($this->isLockedOut($user)) {
                $this->activityLogService->log(
                    tenantId: (int) $user->tenant_id,
                    action: 'cashier.pin_lockout',
                    userId: (int) $user->id,
                    storeId: $storeId,
                    subjectType: User::class,
                    subjectId: (int) $user->id,
                    description: "Akun kasir #{$user->id} ({$user->name}) terkunci selama 15 menit setelah 5 kali salah PIN.",
                    metadata: [
                        'lockout_duration_seconds' => self::LOCKOUT_SECONDS,
                        'max_attempts' => self::MAX_ATTEMPTS,
                    ]
                );

                $minutes = (int) ceil(self::LOCKOUT_SECONDS / 60);

                throw ValidationException::withMessages([
                    'pin' => "PIN kasir terkunci karena 5 kali percobaan salah berturut-turut. Silakan coba lagi dalam {$minutes} menit.",
                ]);
            }

            throw ValidationException::withMessages([
                'pin' => 'PIN kasir yang Anda masukkan salah.',
            ]);
        }

        // Login / otentikasi berhasil: bersihkan rate limiter
        RateLimiter::clear($throttleKey);

        $this->activityLogService->log(
            tenantId: (int) $user->tenant_id,
            action: 'cashier.authenticated',
            userId: (int) $user->id,
            storeId: $storeId,
            subjectType: User::class,
            subjectId: (int) $user->id,
            description: "Kasir #{$user->id} ({$user->name}) berhasil terotentikasi via PIN 6-digit."
        );

        return $user;
    }

    /**
     * Periksa apakah akun kasir sedang dalam masa penguncian lockout.
     *
     * @phpstan-impure
     */
    public function isLockedOut(User $user): bool
    {
        return RateLimiter::tooManyAttempts($this->throttleKey($user), self::MAX_ATTEMPTS);
    }

    /**
     * Hitung sisa waktu penguncian lockout dalam detik.
     */
    public function availableInSeconds(User $user): int
    {
        return RateLimiter::availableIn($this->throttleKey($user));
    }

    /**
     * Reset kunci lockout kasir (misal di-unlock oleh Owner / Manager).
     */
    public function resetLockout(User $user): void
    {
        RateLimiter::clear($this->throttleKey($user));
    }

    /**
     * Kunci throttle rate limiting per tenant dan user.
     */
    public function throttleKey(User $user): string
    {
        return "pos:cashier_pin_throttle:{$user->tenant_id}:{$user->id}";
    }
}
