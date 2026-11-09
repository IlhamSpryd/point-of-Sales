<?php

declare(strict_types=1);

namespace App\Modules\Retail\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $old_price
 * @property numeric-string $new_price
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int|null $printed_by_user_id
 */
class ShelfLabel extends Model
{
    use AssignsTenant, ScopedToTenant;

    public const STATUS_PENDING = 'SHELF_LABEL_PENDING_PRINT';

    public const STATUS_PRINTED = 'PRINTED';

    protected $table = 'shelf_labels';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'product_id',
        'product_variant_id',
        'old_price',
        'new_price',
        'status',
        'batch_number',
        'printed_at',
        'printed_by_user_id',
    ];

    protected $casts = [
        'old_price' => 'decimal:2',
        'new_price' => 'decimal:2',
        'printed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
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
     * @return BelongsTo<User, $this>
     */
    public function printedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by_user_id');
    }
}
