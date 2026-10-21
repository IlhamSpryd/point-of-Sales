<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Ingredient;
use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saldo stok bahan baku per (tenant_id, store_id, ingredient_id):
 * ingredient_stock_balances. Primary key komposit.
 */
class IngredientStockBalance extends Model
{
    use AssignsStore;
    use AssignsTenant;
    use ScopedToStore;
    use ScopedToTenant;

    public $incrementing = false;

    protected $table = 'ingredient_stock_balances';

    protected $primaryKey = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'baseline_quantity' => 'decimal:4',
            'baseline_movement_id' => 'integer',
            'min_stock_level' => 'decimal:4',
            'reorder_point' => 'decimal:4',
            'max_stock_level' => 'decimal:4',
            'average_unit_cost' => 'decimal:4',
            'last_unit_cost' => 'decimal:4',
            'last_movement_id' => 'integer',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
