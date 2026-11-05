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
 *
 * @property numeric-string $quantity_required
 * @property int $id
 * @property int $modifier_id
 * @property int $ingredient_id
 * @property int $tenant_id
 */
class ModifierIngredient extends Pivot
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'modifier_ingredients';

    protected $guarded = ['*'];

    protected $fillable = [
        'tenant_id',
        'modifier_id',
        'ingredient_id',
        'quantity_required',
    ];

    /**
     * @return BelongsTo<Modifier, $this>
     */
    public function modifier(): BelongsTo
    {
        return $this->belongsTo(Modifier::class);
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
