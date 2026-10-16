<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $ingredient_id
 * @property float|null $avg_daily_consumption
 * @property float|null $projected_days_remaining
 * @property Carbon|null $projected_stockout_at
 * @property float|null $suggested_reorder_qty
 * @property string|null $trend
 * @property Carbon|null $computed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Ingredient|null $ingredient
 */
class IngredientRestockForecast extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $fillable = [
        'ingredient_id', 'avg_daily_consumption', 'projected_days_remaining',
        'projected_stockout_at', 'suggested_reorder_qty', 'trend', 'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'avg_daily_consumption' => 'decimal:4',
            'projected_days_remaining' => 'decimal:2',
            'suggested_reorder_qty' => 'decimal:4',
            'projected_stockout_at' => 'datetime',
            'computed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
