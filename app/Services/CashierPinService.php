<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * CashierPinService: Layanan sentral untuk manajemen dan verifikasi PIN Operasional Kasir.
 *
 * Mengamankan:
 * 1. Format PIN (wajib 4-6 digit numerik).
 * 2. Penyimpanan PIN ter-hash (menggunakan bcrypt).
 * 3. Proteksi brute-force verifikasi PIN dengan Rate Limiting terisolasi per user-tenant.
 */
class CashierPinService
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 900; // 15 menit

    public function __construct(
        protected AuditTrailService $auditTrail
    ) {}

    /**
     * Memeriksa apakah pengguna sudah memiliki PIN kasir yang disetel.
     */
    public function hasPin(User $user): bool
    {
        return $user->pin_hash !== null && $user->pin_hash !== '';
    }

    /**
     * Memvalidasi format PIN kasir (wajib 4-6 digit angka).
     */
    public function validateFormat(string $pin): bool
    {
        return (bool) preg_match('/^\d{4,6}$/', $pin);
    }

    /**
     * Menyetel atau mengubah PIN kasir untuk pengguna.
     *
     * @throws InvalidArgumentException jika format PIN tidak valid.
     */
    public function setPin(User $user, string $pin): void
    {
        if (! $this->validateFormat($pin)) {
            throw new InvalidArgumentException('PIN Kasir harus berupa 4-6 digit angka numerik.');
        }

        // Catatan: Model User memiliki cast 'pin_hash' => 'hashed',
        // sehingga Laravel otomatis melakukan Hash::make().
        $user->update([
            'pin_hash' => $pin,
        ]);

        $this->resetPinLockout($user);

        $this->auditTrail->log(
            tenantId: (int) $user->tenant_id,
            action: 'cashier.pin_updated',
            userId: (int) $user->id,
            subjectType: User::class,
            subjectId: (int) $user->id,
            description: "PIN Kasir untuk user #{$user->id} ({$user->name}) telah disetel/diperbarui."
        );
    }

    /**
     * Memverifikasi PIN kasir dengan perlindungan rate limiting brute-force.
     *
     * @throws ValidationException jika akun terkunci akibat terlalu banyak percobaan salah.
     */
    public function verifyPin(User $user, string $pin): bool
    {
        $throttleKey = $this->throttleKey($user);

        if ($this->isLockedOut($user)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            $minutes = (int) ceil($seconds / 60);

            throw ValidationException::withMessages([
                'pin' => "PIN Kasir terkunci karena terlalu banyak percobaan salah. Silakan coba lagi dalam {$minutes} menit.",
            ]);
        }

        if (! $this->hasPin($user)) {
            return false;
        }

        if (! Hash::check($pin, (string) $user->pin_hash)) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            if ($this->isLockedOut($user)) {
                $this->auditTrail->log(
                    tenantId: (int) $user->tenant_id,
                    action: 'cashier.pin_locked_out',
                    userId: (int) $user->id,
                    subjectType: User::class,
                    subjectId: (int) $user->id,
                    description: "PIN Kasir untuk user #{$user->id} ({$user->name}) terkunci selama 15 menit setelah 5 percobaan salah."
                );

                $seconds = RateLimiter::availableIn($throttleKey);
                $minutes = (int) ceil($seconds / 60);

                throw ValidationException::withMessages([
                    'pin' => "PIN Kasir terkunci karena terlalu banyak percobaan salah. Silakan coba lagi dalam {$minutes} menit.",
                ]);
            }

            return false;
        }

        // PIN benar: reset counter lockout
        RateLimiter::clear($throttleKey);

        return true;
    }

    /**
     * Cek apakah user saat ini sedang terkunci verifikasi PIN-nya.
     */
    public function isLockedOut(User $user): bool
    {
        return RateLimiter::tooManyAttempts($this->throttleKey($user), self::MAX_ATTEMPTS);
    }

    /**
     * Sisa waktu kunci dalam detik.
     */
    public function availableInSeconds(User $user): int
    {
        return RateLimiter::availableIn($this->throttleKey($user));
    }

    /**
     * Reset status lockout PIN kasir (misal di-unlock oleh Manager/Owner).
     */
    public function resetPinLockout(User $user): void
    {
        RateLimiter::clear($this->throttleKey($user));
    }

    /**
     * Kunci throttle terisolasi per tenant dan user.
     */
    public function throttleKey(User $user): string
    {
        return "pos:cashier_pin_throttle:{$user->tenant_id}:{$user->id}";
    }
}
