<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngredientRestockForecast extends Model
{
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

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
