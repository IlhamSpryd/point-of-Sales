<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * [D2-BE-005] Pivot resep produk (BOM): product_ingredients.
 *
 * Tabel memiliki kolom tenant_id (FK komposit ke products & ingredients),
 * sehingga pivot ini WAJIB men-stamp tenant_id pada setiap attach/sync —
 * kalau tidak, composite FK product_ingredients_product_id_composite_foreign
 * akan menolak baris tersebut.
 */
class ProductIngredient extends Pivot
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'product_ingredients';

    protected $guarded = [];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
