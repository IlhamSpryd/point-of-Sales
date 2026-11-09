<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use App\Modules\HR\Enums\CommissionBasisTypeEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $store_id
 * @property string $rule_name
 * @property int|null $target_role_id
 * @property CommissionBasisTypeEnum $basis_type
 * @property numeric-string $rate_percent
 * @property numeric-string $rate_amount
 * @property numeric-string $min_sales_threshold
 * @property int|null $product_id
 * @property bool $is_active
 * @property CarbonInterface $valid_from
 * @property CarbonInterface|null $valid_to
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Tenant|null $tenant
 * @property-read Store|null $store
 * @property-read Product|null $product
 */
class CommissionRule extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'commission_rules';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'rule_name',
        'target_role_id',
        'basis_type',
        'rate_percent',
        'rate_amount',
        'min_sales_threshold',
        'product_id',
        'is_active',
        'valid_from',
        'valid_to',
    ];

    protected function casts(): array
    {
        return [
            'basis_type' => CommissionBasisTypeEnum::class,
            'rate_percent' => 'decimal:4',
            'rate_amount' => 'decimal:2',
            'min_sales_threshold' => 'decimal:2',
            'is_active' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return HasMany<CommissionRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(CommissionRecord::class, 'commission_rule_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActiveOn(Builder $query, CarbonInterface|string $date): Builder
    {
        $dateStr = is_string($date) ? $date : $date->toDateString();

        return $query->where('is_active', true)
            ->where('valid_from', '<=', $dateStr)
            ->where(function (Builder $q) use ($dateStr) {
                $q->whereNull('valid_to')
                    ->orWhere('valid_to', '>=', $dateStr);
            });
    }
}
