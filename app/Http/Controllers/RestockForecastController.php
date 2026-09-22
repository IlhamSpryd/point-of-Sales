<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\IngredientRestockForecast;
use Illuminate\Http\JsonResponse;

class RestockForecastController extends Controller
{
    public function index(): JsonResponse
    {
        $forecasts = IngredientRestockForecast::query()
            ->with('ingredient:id,name,unit,current_stock,reorder_level')
            ->orderBy('projected_days_remaining')
            ->get()
            ->map(fn (IngredientRestockForecast $f) => [
                'ingredient_id' => $f->ingredient_id,
                'name' => $f->ingredient->name ?? '-',
                'unit' => $f->ingredient->unit ?? '',
                'current_stock' => (float) ($f->ingredient->current_stock ?? 0),
                'avg_daily_consumption' => (float) $f->avg_daily_consumption,
                'projected_days_remaining' => $f->projected_days_remaining !== null ? (float) $f->projected_days_remaining : null,
                'projected_stockout_at' => $f->projected_stockout_at?->toDateString(),
                'suggested_reorder_qty' => (float) $f->suggested_reorder_qty,
                'trend' => $f->trend,
                'computed_at' => $f->computed_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $forecasts]);
    }
}
