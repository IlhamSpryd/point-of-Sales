<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property numeric-string $current_stock
 * @property numeric-string $cost_per_unit
 * @property numeric-string $reorder_level
 * @property int $id
 * @property int $tenant_id
 */
class Ingredient extends Model
{
    use AssignsTenant, ScopedToTenant, SoftDeletes;

    protected $table = 'ingredients';

    protected $fillable = [
        'tenant_id',
        'ingredient_code',
        'name',
        'unit',
        'current_stock',
        'cost_per_unit',
        'reorder_level',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'current_stock' => 'decimal:4',
            'cost_per_unit' => 'decimal:4',
            'reorder_level' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<IngredientStockBalance, $this>
     */
    public function balances(): HasMany
    {
        return $this->hasMany(IngredientStockBalance::class, 'ingredient_id');
    }

    /**
     * @return HasMany<IngredientStockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(IngredientStockMovement::class, 'ingredient_id');
    }
}
