<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Ingredient;
use App\Models\Modifier;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * [D2-BE-005] Pivot resep modifier (BOM): modifier_ingredients.
 * WAJIB men-stamp tenant_id agar FK komposit modifier_ingredients_* tidak gagal.
 */
class ModifierIngredient extends Pivot
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'modifier_ingredients';

    protected $guarded = [];

    public function modifier(): BelongsTo
    {
        return $this->belongsTo(Modifier::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
