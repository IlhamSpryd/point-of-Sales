<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $quantity
 * @property numeric-string|null $unit_cost
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $ingredient_id
 * @property int|null $order_id
 * @property int|null $order_item_id
 * @property int|null $created_by
 */
class IngredientStockMovement extends Model
{
    use AssignsTenant, ScopedToTenant;

    protected $table = 'ingredient_stock_movements';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'ingredient_id',
        'order_id',
        'order_item_id',
        'type',
        'quantity',
        'unit_cost',
        'reason',
        'idempotency_key',
        'created_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'created_at' => 'datetime',
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
