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
 * @property float $min_quantity
 * @property float $price
 * @property float|null $discount_percentage
 */
class PriceListItem extends Model
{
    use AssignsTenant;
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
        'price',
        'discount_percentage',
    ];

    protected $casts = [
        'price_list_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'uom_id' => 'integer',
        'min_quantity' => 'float',
        'price' => 'float',
        'discount_percentage' => 'float',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function variant(): BelongsTo
    {
        return $this->productVariant();
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }
}
