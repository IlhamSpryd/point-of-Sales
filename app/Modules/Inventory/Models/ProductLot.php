<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Traits\AssignsStore;
use App\Core\Traits\AssignsTenant;
use App\Core\Traits\BelongsToCompany;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Scopes\TenantScope;
use App\Models\Store;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model Batch / Lot Persediaan dengan Penelusuran FEFO (First Expired, First Out).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $company_id
 * @property int|null $store_id
 * @property int|null $warehouse_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property string $lot_number
 * @property CarbonInterface $expiry_date
 * @property CarbonInterface|null $received_date
 * @property numeric-string $initial_quantity
 * @property numeric-string $current_quantity
 * @property numeric-string|null $unit_cost
 * @property string $status
 * @property string|null $notes
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 * @property-read Store|null $store
 * @property-read Company|null $company
 * @property-read Tenant $tenant
 */
#[ScopedBy(TenantScope::class)]
class ProductLot extends Model
{
    use AssignsStore;
    use AssignsTenant;
    use BelongsToCompany;
    use HasFactory;

    protected $table = 'product_lots';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'store_id',
        'warehouse_id',
        'product_id',
        'product_variant_id',
        'lot_number',
        'expiry_date',
        'received_date',
        'initial_quantity',
        'current_quantity',
        'unit_cost',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'received_date' => 'date',
            'initial_quantity' => 'decimal:4',
            'current_quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expiry_date->isPast();
    }

    public function isExpiringWithin(int $days): bool
    {
        return $this->expiry_date->isBefore(now()->addDays($days));
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
