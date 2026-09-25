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
            // Exclude forecasts left behind for ingredients that were
            // deactivated/soft-deleted after their last recompute -- these
            // never get cleaned up by RestockPredictionService::recomputeAll().
            ->whereHas('ingredient', fn ($q) => $q->where('is_active', true))
            ->with('ingredient:id,name,unit,current_stock,reorder_level')
            // CRITICAL FIX: MySQL puts NULL first on ASC sort, so
            // ingredients with NO sales history (projected_days_remaining
            // = null) were ranking ABOVE ingredients that are genuinely
            // about to run out. Push NULLs to the end explicitly.
            ->orderByRaw('projected_days_remaining IS NULL, projected_days_remaining ASC')
            ->get()
            ->map(fn (IngredientRestockForecast $f) => [
                'ingredient_id' => $f->ingredient_id,
                'name' => $f->ingredient?->name ?? '-',
                'unit' => $f->ingredient?->unit ?? '',
                'current_stock' => (float) ($f->ingredient?->current_stock ?? 0),
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
