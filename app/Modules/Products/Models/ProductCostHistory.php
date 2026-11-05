<?php

declare(strict_types=1);

namespace App\Modules\Products\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProductCostHistory: Catatan riwayat biaya standar append-only per produk.
 *
 * @property numeric-string $old_cost
 * @property numeric-string $new_cost
 * @property int $id
 * @property int $tenant_id
 * @property int|null $store_id
 * @property int $product_id
 * @property int|null $changed_by
 */
class ProductCostHistory extends Model
{
    use AssignsTenant;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use ScopedToTenant;

    public const UPDATED_AT = null;

    protected $table = 'product_cost_histories';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'product_id',
        'costing_method',
        'old_cost',
        'new_cost',
        'cost_price',
        'reason',
        'changed_by',
        'created_by_user_id',
        'effective_at',
        'effective_date',
    ];

    public function setCostPriceAttribute(mixed $value): void
    {
        $this->attributes['new_cost'] = $value;
    }

    public function getCostPriceAttribute(): mixed
    {
        return $this->attributes['new_cost'] ?? 0;
    }

    public function setEffectiveDateAttribute(mixed $value): void
    {
        $this->attributes['effective_at'] = $value;
    }

    public function getEffectiveDateAttribute(): mixed
    {
        return $this->attributes['effective_at'] ?? null;
    }

    public function setCreatedByUserIdAttribute(mixed $value): void
    {
        $this->attributes['changed_by'] = $value;
    }

    public function getCreatedByUserIdAttribute(): mixed
    {
        return $this->attributes['changed_by'] ?? null;
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException('ProductCostHistory is append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new \RuntimeException('ProductCostHistory is append-only and cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'old_cost' => 'decimal:2',
            'new_cost' => 'decimal:2',
            'effective_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
