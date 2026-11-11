<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Core\Traits\AssignsStore;
use App\Core\Traits\AssignsTenant;
use App\Core\Traits\BelongsToCompany;
use App\Models\Ingredient;
use App\Models\Scopes\TenantScope;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model Resep Bahan Olahan Dapur / Kitchen Prep Item (Multi-Level BOM).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $company_id
 * @property int|null $store_id
 * @property int $parent_ingredient_id
 * @property int $component_ingredient_id
 * @property numeric-string $quantity_required
 * @property string|null $unit
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Ingredient $parentIngredient
 * @property-read Ingredient $componentIngredient
 * @property-read Store|null $store
 * @property-read Tenant $tenant
 */
#[ScopedBy(TenantScope::class)]
class IngredientRecipe extends Model
{
    use AssignsStore;
    use AssignsTenant;
    use BelongsToCompany;
    use HasFactory;

    protected $table = 'ingredient_recipes';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'store_id',
        'parent_ingredient_id',
        'component_ingredient_id',
        'quantity_required',
        'unit',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_required' => 'decimal:4',
        ];
    }

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
     * Bahan olahan / setengah jadi yang dihasilkan (e.g. Sambal Matah).
     *
     * @return BelongsTo<Ingredient, $this>
     */
    public function parentIngredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'parent_ingredient_id');
    }

    /**
     * Bahan mentah yang dikonsumsi (e.g. Bawang Merah, Cabai, Minyak).
     *
     * @return BelongsTo<Ingredient, $this>
     */
    public function componentIngredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'component_ingredient_id');
    }
}
