<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property float|numeric-string $quantity
 * @property numeric-string $reserved_quantity
 * @property numeric-string $baseline_quantity
 * @property numeric-string $min_stock_level
 * @property numeric-string $reorder_point
 * @property numeric-string|null $max_stock_level
 * @property numeric-string $average_unit_cost
 * @property numeric-string $last_unit_cost
 * @property int $tenant_id
 * @property int $store_id
 * @property int $ingredient_id
 * @property int $baseline_movement_id
 * @property int|null $last_movement_id
 */
class IngredientStockBalance extends Model
{
    use AssignsTenant, ScopedToTenant;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $table = 'ingredient_stock_balances';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'ingredient_id',
        'quantity',
        'reserved_quantity',
        'baseline_quantity',
        'baseline_movement_id',
        'min_stock_level',
        'reorder_point',
        'max_stock_level',
        'average_unit_cost',
        'last_unit_cost',
        'last_movement_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'reserved_quantity' => 'decimal:4',
            'baseline_quantity' => 'decimal:4',
            'average_unit_cost' => 'decimal:4',
            'last_unit_cost' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'ingredient_id');
    }
}
