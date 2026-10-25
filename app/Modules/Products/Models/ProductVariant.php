<?php

declare(strict_types=1);

namespace App\Modules\Products\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariantAttributeValue;
use App\Models\StoreProductAssortment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Model ProductVariant mewakili varian fisik/spesifik dari suatu produk induk (ADR-01).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_id
 * @property string $variant_name
 * @property string|null $sku
 * @property string|null $barcode
 * @property float|null $price_override
 * @property string $costing_method
 * @property float $standard_cost
 * @property bool $is_active
 * @property int $sort_order
 */
class ProductVariant extends Model
{
    use AssignsTenant;
    use HasFactory;
    use ScopedToTenant;
    use SoftDeletes;

    protected $table = 'product_variants';

    protected $fillable = [
        'tenant_id',
        'product_id',
        'variant_name',
        'sku',
        'barcode',
        'price_override',
        'costing_method',
        'standard_cost',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'price_override' => 'float',
        'standard_cost' => 'float',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductAttributeValue::class,
            'product_variant_attribute_values',
            'variant_id',
            'attribute_value_id'
        )->withPivot('attribute_id')->withTimestamps();
    }

    public function variantAttributeValues(): HasMany
    {
        return $this->hasMany(ProductVariantAttributeValue::class, 'variant_id');
    }

    public function storeAssortments(): HasMany
    {
        return $this->hasMany(StoreProductAssortment::class, 'product_variant_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'product_variant_id');
    }
}
