<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Models\Shift;
use App\Models\User;
use App\Services\AuditTrailService;
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
     * Mengaitkan context shift yang tervalidasi pada pencatatan audit lockout.
     *
     * @param  User  $user  Pengguna kasir yang diverifikasi
     * @param  string  $pin  PIN numerik yang dimasukkan
     * @param  int|Shift|null  $shiftContext  ID Shift atau instance Shift (opsional)
     *
     * @throws ValidationException jika akun terkunci akibat terlalu banyak percobaan salah.
     */
    public function verifyPin(User $user, string $pin, int|Shift|null $shiftContext = null): bool
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
                $shiftAudit = $this->resolveShiftContextForAudit($user, $shiftContext);

                $this->auditTrail->log(
                    tenantId: (int) $user->tenant_id,
                    action: 'cashier.pin_locked_out',
                    userId: (int) $user->id,
                    storeId: $shiftAudit['store_id'] ?? null,
                    subjectType: User::class,
                    subjectId: (int) $user->id,
                    description: "PIN Kasir untuk user #{$user->id} ({$user->name}) terkunci selama 15 menit setelah 5 percobaan salah.".(isset($shiftAudit['shift_id']) ? " (Shift #{$shiftAudit['shift_id']})" : ''),
                    metadata: $shiftAudit['metadata'] ?? null
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
     * Memvalidasi dan menyelesaikan context shift untuk audit lockout tanpa
     * mempercayai input mentah. Hanya dieksekusi saat ambang batas lockout tercapai.
     *
     * @return array{shift_id?: int, store_id?: int, metadata: array<string, mixed>}
     */
    private function resolveShiftContextForAudit(User $user, int|Shift|null $shiftContext): array
    {
        $result = [
            'metadata' => [
                'lockout_threshold' => self::MAX_ATTEMPTS,
                'decay_seconds' => self::DECAY_SECONDS,
            ],
        ];

        if ($shiftContext instanceof Shift) {
            $matchesTenant = (int) $shiftContext->tenant_id === (int) $user->tenant_id;
            $matchesUser = (int) $shiftContext->user_id === (int) $user->id;

            if ($matchesTenant && $matchesUser) {
                $result['shift_id'] = (int) $shiftContext->id;
                $result['store_id'] = (int) $shiftContext->store_id;
                $result['metadata']['shift_id'] = (int) $shiftContext->id;
                $result['metadata']['shift_status'] = $shiftContext->status;
                $result['metadata']['shift_store_id'] = (int) $shiftContext->store_id;
            } else {
                $result['metadata']['unverified_shift_id'] = (int) $shiftContext->id;
                $result['metadata']['shift_validation'] = 'mismatch_tenant_or_user';
            }

            return $result;
        }

        if (is_int($shiftContext)) {
            $shift = Shift::withoutGlobalScopes()
                ->where('tenant_id', $user->tenant_id)
                ->where('user_id', $user->id)
                ->where('id', $shiftContext)
                ->first();

            if ($shift) {
                $result['shift_id'] = (int) $shift->id;
                $result['store_id'] = (int) $shift->store_id;
                $result['metadata']['shift_id'] = (int) $shift->id;
                $result['metadata']['shift_status'] = $shift->status;
                $result['metadata']['shift_store_id'] = (int) $shift->store_id;
            } else {
                $result['metadata']['unverified_shift_id'] = $shiftContext;
                $result['metadata']['shift_validation'] = 'shift_not_found_or_unauthorized';
            }

            return $result;
        }

        // Jika null, cek apakah user memiliki shift aktif terbuka saat ini
        $activeShift = Shift::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('status', 'open')
            ->first();

        if ($activeShift) {
            $result['shift_id'] = (int) $activeShift->id;
            $result['store_id'] = (int) $activeShift->store_id;
            $result['metadata']['shift_id'] = (int) $activeShift->id;
            $result['metadata']['shift_status'] = 'open';
            $result['metadata']['shift_store_id'] = (int) $activeShift->store_id;
            $result['metadata']['shift_resolved_from'] = 'active_open_shift';
        }

        return $result;
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

    /**
     * Memverifikasi PIN supervisor atau manager untuk otorisasi aksi sensitif (EOD, reprint lanjutan).
     *
     * @throws ValidationException
     */
    public function verifySupervisorPin(int $userId, string $pin, int $tenantId): bool
    {
        $user = User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $userId)
            ->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'pin' => 'Pengguna supervisor tidak ditemukan atau tidak terafiliasi dengan tenant ini.',
            ]);
        }

        if (! $this->hasPin($user)) {
            throw ValidationException::withMessages([
                'pin' => 'Supervisor belum memiliki PIN aktif.',
            ]);
        }

        if (! $this->verifyPin($user, $pin)) {
            throw ValidationException::withMessages([
                'pin' => 'PIN supervisor tidak valid.',
            ]);
        }

        return true;
    }
}
