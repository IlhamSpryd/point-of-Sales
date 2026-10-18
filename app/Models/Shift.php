<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $store_id
 * @property int $tenant_id
 * @property string $status
 */
class Shift extends Model
{
    use AssignsStore, AssignsTenant;
    use HasFactory, SoftDeletes;
    use ScopedToStore;
    use ScopedToTenant;

    protected $fillable = [
        'user_id', 'active_cashier_id', 'device_id', 'opening_balance', 'closing_balance', 'expected_cash',
        'cash_difference', 'status', 'opened_at', 'closed_at', 'closed_by_user_id',
        'close_type', 'close_reason', 'notes', 'tenant_id', 'store_id',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_balance' => 'integer',
            'closing_balance' => 'integer',
            'expected_cash' => 'integer',
            'cash_difference' => 'integer',
        ];
    }

    /** Relasi (BelongsTo): Shift ini dibuka oleh satu Kasir spesifik. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** Relasi (BelongsTo): Kasir aktif yang saat ini memegang tanggung jawab laci kas. */
    public function activeCashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'active_cashier_id')->withTrashed();
    }

    /** Relasi (BelongsTo): Pengguna/Supervisor yang menutup shift. */
    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id')->withTrashed();
    }

    /** Relasi (BelongsTo): Perangkat terminal kasir fisik yang diikat ke shift. */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** Relasi (HasMany): Riwayat serah terima kasir selama shift berlangsung. */
    public function handovers(): HasMany
    {
        return $this->hasMany(ShiftHandover::class);
    }

    /** Relasi (HasMany): Satu shift menaungi banyak transaksi selama sesi berjalan. */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Relasi (HasMany): Mutasi kas masuk/keluar laci selama shift. */
    public function cashDrawerMovements(): HasMany
    {
        return $this->hasMany(CashDrawerMovement::class);
    }

    /** Alias untuk cashDrawerMovements. */
    public function cashMovements(): HasMany
    {
        return $this->cashDrawerMovements();
    }
}
