<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerLoyaltyAccount extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $guarded = ['id'];

    /**
     * [D2-BE-009] Kontrak trigger `trg_loyalty_ledger_sync_account`:
     * satu-satunya penulis kolom saldo (current_points / lifetime_points_earned)
     * adalah trigger DB yang bereaksi pada INSERT `loyalty_ledger`.
     * Mutasi manual dari PHP akan menyebabkan double-counting, jadi ditolak.
     */
    protected static function booted(): void
    {
        static::updating(function (self $account) {
            if ($account->isDirty('current_points') || $account->isDirty('lifetime_points_earned')) {
                throw new \RuntimeException(
                    'Saldo poin loyalitas hanya boleh diubah oleh trigger trg_loyalty_ledger_sync_account '
                    .'melalui penulisan LoyaltyLedger. Mutasi manual ditolak (anti double-counting).'
                );
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function currentTier(): BelongsTo
    {
        return $this->belongsTo(LoyaltyTier::class, 'current_tier_id');
    }

    public static function lockAndGetAccount(int $customerId): ?self
    {
        return self::where('customer_id', $customerId)->lockForUpdate()->first();
    }
}
