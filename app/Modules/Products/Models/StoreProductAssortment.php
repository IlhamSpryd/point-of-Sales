<?php

declare(strict_types=1);

namespace App\Modules\Products\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property bool $is_available
 * @property string|null $store_price_override
 * @property array<string>|null $allowed_channels
 */
class StoreProductAssortment extends Model
{
    use AssignsTenant, HasFactory, ScopedToTenant;

    protected $table = 'store_product_assortments';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'product_id',
        'product_variant_id',
        'is_available',
        'store_price_override',
        'allowed_channels',
    ];

    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
            'store_price_override' => 'decimal:2',
            'allowed_channels' => 'array',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
