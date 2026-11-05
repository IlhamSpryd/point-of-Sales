<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStoreForDiscount;
use App\Models\Concerns\ScopedToTenant;
use App\Models\DiscountRedemption;
use App\Models\Order;
/** @use HasFactory<Factory<static>> */
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $value
 * @property int|null $max_discount_amount
 * @property int $min_purchase_amount
 * @property int|null $usage_limit
 * @property int $tenant_id
 * @property int|null $store_id
 */
class Discount extends Model
{
    use AssignsTenant;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use ScopedToStoreForDiscount;
    use ScopedToTenant;
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'type', 'value', 'max_discount_amount',
        'min_purchase_amount', 'usage_limit', 'is_stackable', 'scope',
        'is_active', 'valid_from', 'valid_until', 'tenant_id', 'store_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_stackable' => 'boolean',
            'usage_limit' => 'integer',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
        ];
    }

    /** Relasi (HasMany): Satu master diskon bisa dipakai oleh banyak transaksi.
     * @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Relasi (HasMany): Catatan riwayat penggunaan kuota voucher.
     * @return HasMany<DiscountRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(DiscountRedemption::class);
    }

    /**
     * Menghitung pemakaian kuota yang aktif (applied).
     */
    public function getAppliedRedemptionsCount(): int
    {
        return $this->redemptions()
            ->where('status', DiscountRedemption::STATUS_APPLIED)
            ->count();
    }

    /**
     * Memeriksa apakah kuota voucher masih tersedia.
     */
    public function hasAvailableQuota(): bool
    {
        if ($this->usage_limit === null) {
            return true;
        }

        return $this->getAppliedRedemptionsCount() < $this->usage_limit;
    }
}
