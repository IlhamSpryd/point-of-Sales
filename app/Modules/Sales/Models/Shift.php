<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Traits\AssignsStore;
use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToStore;
use App\Core\Traits\ScopedToTenant;
use App\Modules\Organization\Models\Device;
use App\Modules\Organization\Models\User;
use App\Modules\Sales\Contracts\ShiftReaderInterface;
/** @use HasFactory<Factory<static>> */
use Illuminate\Database\Eloquent\Factories\Factory;
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
 * @property int|null $active_cashier_id
 * @property int $opening_balance
 * @property int|null $closing_balance
 * @property int|null $expected_cash
 * @property int|null $cash_difference
 * @property int|null $open_shift_lock_key
 * @property int|null $closed_by_user_id
 * @property int|null $variance_approved_by
 * @property string|null $variance_reason
 * @property int|null $device_id
 * @property int|null $open_device_guard
 */
class Shift extends Model implements ShiftReaderInterface
{
    public function getId(): int
    {
        return (int) $this->id;
    }

    public function getTenantId(): int
    {
        return (int) $this->tenant_id;
    }

    public function getStoreId(): ?int
    {
        return $this->store_id !== null ? (int) $this->store_id : null;
    }

    public function getUserId(): int
    {
        return (int) $this->user_id;
    }

    public function getShiftNumber(): ?string
    {
        return (string) ($this->shift_number ?? ('SHIFT-'.$this->id));
    }

    public function getCashDifference(): float|string
    {
        return (string) ($this->cash_difference ?? '0.00');
    }

    public function getExpectedCash(): float|string
    {
        return (string) ($this->expected_cash ?? '0.00');
    }

    public function getClosingBalance(): float|string
    {
        return (string) ($this->closing_balance ?? '0.00');
    }

    public function getClosedAt(): mixed
    {
        return $this->closed_at;
    }

    use AssignsStore, AssignsTenant;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use ScopedToStore;
    use ScopedToTenant;
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'active_cashier_id', 'device_id', 'opening_balance', 'closing_balance', 'expected_cash',
        'cash_difference', 'variance_approved_by', 'variance_reason', 'status', 'opened_at', 'closed_at', 'closed_by_user_id',
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

    /** Relasi (BelongsTo): Shift ini dibuka oleh satu Kasir spesifik.
     * @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** Relasi (BelongsTo): Kasir aktif yang saat ini memegang tanggung jawab laci kas.
     * @return BelongsTo<User, $this> */
    public function activeCashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'active_cashier_id')->withTrashed();
    }

    /** Relasi (BelongsTo): Pengguna/Supervisor yang menutup shift.
     * @return BelongsTo<User, $this> */
    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id')->withTrashed();
    }

    /** Relasi (BelongsTo): Supervisor yang mengotorisasi selisih kas fisik.
     * @return BelongsTo<User, $this> */
    public function varianceApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'variance_approved_by')->withTrashed();
    }

    /** Relasi (BelongsTo): Perangkat terminal kasir fisik yang diikat ke shift.
     * @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** Relasi (HasMany): Riwayat serah terima kasir selama shift berlangsung.
     * @return HasMany<ShiftHandover, $this> */
    public function handovers(): HasMany
    {
        return $this->hasMany(ShiftHandover::class);
    }

    /** Relasi (HasMany): Satu shift menaungi banyak transaksi selama sesi berjalan.
     * @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Relasi (HasMany): Mutasi kas masuk/keluar laci selama shift.
     * @return HasMany<CashDrawerMovement, $this> */
    public function cashDrawerMovements(): HasMany
    {
        return $this->hasMany(CashDrawerMovement::class);
    }

    /**
     * Alias untuk cashDrawerMovements.
     *
     * @return HasMany<CashDrawerMovement, $this>
     */
    public function cashMovements(): HasMany
    {
        return $this->cashDrawerMovements();
    }
}
