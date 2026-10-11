<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Domain 1 — token magic link onboarding Owner pertama.
 *
 * Plaintext token TIDAK disimpan; hanya SHA-256 hash. Dua kebijakan yang
 * ditegakkan di sini, bukan di controller (supaya semua jalur konsumsi —
 * termasuk job outbox — melewati aturan yang sama):
 *
 *  1. ROTASI / REISSUE ({@see reissueFor()}). Job outbox mungkin perlu
 *     mengirim ulang tautan (crash setelah commit, percobaan ulang, dsb).
 *     Alih-alih menyimpan plaintext di database, job memanggil `reissueFor()`
 *     dengan hash LAMA yang tertulis di body email: token baru dibuat dan
 *     hash lama DICABUT, sehingga plaintext yang mungkin sudah bocor tidak
 *     dapat dipakai lagi.
 *
 *  2. TIDAK BISA DIPERBANYAK. Hanya ada SATU baris token aktif per
 *     (tenant, user): unique (tenant_id, user_id) pada tabel onboarding
 *     tokens menahan baris kedua, dan reissue memakai baris yang sudah ada.
 *
 * Konsumsi (menukar token dengan password) WAJIB atomik — lihat
 * {@see consume()} — karena token yang dipakai dua request konkuren tidak
 * boleh menghasilkan dua perubahan password.
 */
class OwnerOnboardingToken extends Model
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'token_hash',
        'expires_at',
        'consumed_at',
        'send_attempts',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'send_attempts' => 'integer',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    /** Token masih dapat ditukar dengan password. */
    public function isRedeemable(): bool
    {
        return ! $this->isConsumed() && ! $this->isExpired();
    }

    public static function hash(string $plaintextToken): string
    {
        return hash('sha256', $plaintextToken);
    }

    /**
     * Cari token berdasarkan plaintext yang diberikan pengguna. Pencarian
     * lewat hash (bukan plaintext) sehingga nilai yang bocor dari DB tidak
     * berguna.
     */
    public static function findRedeemable(string $plaintextToken): ?self
    {
        return static::query()
            ->where('token_hash', self::hash($plaintextToken))
            ->first();
    }

    /**
     * Rotasi token untuk (tenant, user) berdasarkan hash lama, dan
     * mengembalikan plaintext BARU.
     *
     * Dipakai job outbox saat mengirim ulang email onboarding: hash lama
     * dicabut, plaintext baru hanya hidup di memori pengirim.
     */
    public static function reissueFor(string $oldHash, int $tenantId, int $userId): ?string
    {
        $plaintext = Str::random(48);
        $newHash = self::hash($plaintext);
        $affected = static::query()
            ->where('token_hash', $oldHash)
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->update([
                'token_hash' => $newHash,
                'expires_at' => now()->addHours((int) config('pos.provisioning.onboarding_token_ttl_hours', 48)),
                'send_attempts' => DB::raw('send_attempts + 1'),
                'updated_at' => now(),
            ]);

        return $affected === 1 ? $plaintext : null;
    }

    /**
     * Konsumsi token secara ATOMIK. Mengembalikan true hanya untuk SATU
     * pemanggil — yang berhasil mengubah `consumed_at` dari NULL.
     *
     * Mengapa bukan `if (! $token->isConsumed()) { $token->consumed_at = ... }`:
     * pola itu read-then-write tanpa penguncian, sehingga dua request konkuren
     * dapat sama-sama lolos dan menetapkan password dua kali.
     */
    public function consume(): bool
    {
        if ($this->consumed_at !== null) {
            return false;
        }

        $affected = static::query()
            ->whereKey($this->getKey())
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        if ($affected !== 1) {
            return false;
        }

        $this->consumed_at = now();

        return true;
    }
}
