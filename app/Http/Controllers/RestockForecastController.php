<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\IngredientStockMovementTypeEnum;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RestockForecastController extends Controller
{
    public function index(): JsonResponse
    {
        $days = 30;
        $startDate = Carbon::now()->subDays($days)->startOfDay();

        $consumptionSub = DB::table('ingredient_stock_movements')
            ->select(
                'ingredient_id',
                DB::raw('ABS(SUM(quantity)) as total_consumption')
            )
            ->where('type', IngredientStockMovementTypeEnum::SaleDeduction->value)
            ->where('created_at', '>=', $startDate)
            ->groupBy('ingredient_id');

        $forecasts = DB::table('ingredients as i')
            ->leftJoinSub($consumptionSub, 'c', 'i.id', '=', 'c.ingredient_id')
            ->select(
                'i.id as ingredient_id',
                'i.name',
                'i.unit',
                'i.current_stock',
                'i.reorder_level',
                'c.total_consumption'
            )
            ->where('i.is_active', true)
            ->get()
            ->map(function ($row) use ($days) {
                $avgDailyConsumption = ($row->total_consumption ?? 0) / $days;
                
                $projectedDaysRemaining = null;
                $projectedStockoutAt = null;
                
                if ($avgDailyConsumption > 0) {
                    $projectedDaysRemaining = $row->current_stock / $avgDailyConsumption;
                    $projectedStockoutAt = Carbon::now()->addDays((int) round($projectedDaysRemaining))->toDateString();
                }

                $suggestedReorderQty = max(0, $row->reorder_level - $row->current_stock);
                if ($projectedDaysRemaining !== null && $projectedDaysRemaining <= 7) {
                    $suggestedReorderQty = max($suggestedReorderQty, ($avgDailyConsumption * 14) - $row->current_stock);
                }

                $trend = $avgDailyConsumption > 0 ? 'stable' : 'none';
                
                return [
                    'ingredient_id' => $row->ingredient_id,
                    'name' => $row->name,
                    'unit' => $row->unit,
                    'current_stock' => (float) $row->current_stock,
                    'avg_daily_consumption' => (float) round($avgDailyConsumption, 4),
                    'projected_days_remaining' => $projectedDaysRemaining !== null ? (float) round($projectedDaysRemaining, 1) : null,
                    'projected_stockout_at' => $projectedStockoutAt,
                    'suggested_reorder_qty' => (float) round($suggestedReorderQty, 2),
                    'trend' => $trend,
                    'computed_at' => Carbon::now()->toIso8601String(),
                ];
            })
            ->sortBy('projected_days_remaining')
            ->values();

        return response()->json(['data' => $forecasts]);
    }
}
