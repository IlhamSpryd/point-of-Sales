<?php

declare(strict_types=1);

namespace App\Modules\Products\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model PriceListItem mewakili aturan harga atau diskon per produk dalam daftar harga (ADR-02).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $price_list_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int|null $uom_id
 * @property float|numeric-string $min_quantity
 * @property float|numeric-string $price
 * @property float|numeric-string|null $discount_percentage
 * @property float|numeric-string|null $max_quantity
 */
class PriceListItem extends Model
{
    use AssignsTenant;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use ScopedToTenant;

    protected $table = 'price_list_items';

    protected $fillable = [
        'tenant_id',
        'price_list_id',
        'product_id',
        'product_variant_id',
        'uom_id',
        'min_quantity',
        'max_quantity',
        'price',
        'discount_percentage',
    ];

    protected $casts = [
        'price_list_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'uom_id' => 'integer',
        'min_quantity' => 'decimal:4',
        'max_quantity' => 'decimal:4',
        'price' => 'decimal:2',
        'discount_percentage' => 'decimal:2',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<PriceList, $this>
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->productVariant();
    }

    /**
     * @return BelongsTo<UnitOfMeasure, $this>
     */
    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }
}
