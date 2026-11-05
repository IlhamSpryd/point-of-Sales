<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Ingredient;
use App\Models\Product;
use App\Modules\Products\Services\MenuCacheService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;

/**
 * [D2-BE-005] Pivot resep produk (BOM): product_ingredients.
 *
 * Tabel memiliki kolom tenant_id (FK komposit ke products & ingredients),
 * sehingga pivot ini WAJIB men-stamp tenant_id pada setiap attach/sync —
 * kalau tidak, composite FK product_ingredients_product_id_composite_foreign
 * akan menolak baris tersebut.
 *
 * @property int|null $tenant_id
 * @property int $product_id
 * @property int $ingredient_id
 * @property numeric-string $quantity_required
 * @property int $id
 */
class ProductIngredient extends Pivot
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'product_ingredients';

    protected $guarded = ['*'];

    protected $fillable = [
        'tenant_id',
        'product_id',
        'ingredient_id',
        'quantity_required',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $pivot) {
            $tenantId = $pivot->tenant_id;
            if ($tenantId) {
                DB::afterCommit(function () use ($tenantId) {
                    try {
                        app(MenuCacheService::class)->flush((int) $tenantId);
                    } catch (\Throwable) {
                    }
                });
            }
        });

        static::deleted(function (self $pivot) {
            $tenantId = $pivot->tenant_id;
            if ($tenantId) {
                DB::afterCommit(function () use ($tenantId) {
                    try {
                        app(MenuCacheService::class)->flush((int) $tenantId);
                    } catch (\Throwable) {
                    }
                });
            }
        });
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
