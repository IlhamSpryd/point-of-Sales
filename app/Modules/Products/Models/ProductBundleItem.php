<?php

declare(strict_types=1);

namespace App\Modules\Products\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model ProductBundleItem mewakili komponen penyusun dari suatu produk paket/bundel (Sales BOM - ADR-04).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $bundle_product_id
 * @property int $component_product_id
 * @property int|null $component_variant_id
 * @property float $quantity
 * @property int|null $uom_id
 * @property float|null $cost_allocation_ratio
 * @property int $sort_order
 */
class ProductBundleItem extends Model
{
    use AssignsTenant;
    use HasFactory;
    use ScopedToTenant;

    protected $table = 'product_bundle_items';

    protected $fillable = [
        'tenant_id',
        'bundle_product_id',
        'component_product_id',
        'component_variant_id',
        'quantity',
        'uom_id',
        'cost_allocation_ratio',
        'sort_order',
    ];

    protected $casts = [
        'bundle_product_id' => 'integer',
        'component_product_id' => 'integer',
        'component_variant_id' => 'integer',
        'quantity' => 'float',
        'uom_id' => 'integer',
        'cost_allocation_ratio' => 'float',
        'sort_order' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function bundleProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'bundle_product_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function componentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }

    public function componentVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'component_variant_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }
}
