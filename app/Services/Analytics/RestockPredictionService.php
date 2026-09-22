<?php

declare(strict_types=1);

// [OMEGA-NODE5] Forecasting statistik murni (moving average + tren
// dua-mingguan), TANPA API AI eksternal berbayar. | 2026-09-23

namespace App\Services\Analytics;

use App\Models\Ingredient;
use App\Models\IngredientRestockForecast;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RestockPredictionService
{
    private const TRAILING_WINDOW_DAYS = 14;

    private const FORECAST_HORIZON_DAYS = 14;

    private const TREND_THRESHOLD = 0.10; // ±10% = signifikan, di bawahnya "stable"

    /** Dipanggil dari Artisan command terjadwal, TIDAK PERNAH dari request HTTP. */
    public function recomputeAll(): int
    {
        $windowStart = Carbon::now()->subDays(self::TRAILING_WINDOW_DAYS)->startOfDay();
        $halfWindowDays = max(1, (int) (self::TRAILING_WINDOW_DAYS / 2));
        $midpoint = Carbon::now()->subDays($halfWindowDays)->startOfDay();

        // SATU query agregat untuk SEMUA ingredient (bukan N+1) -- pure
        // SELECT read-only di atas ledger append-only, aman dari kontensi
        // lock dengan penulis (KDS/checkout) di isolation level default.
        $consumption = DB::table('ingredient_stock_movements')
            ->select('ingredient_id')
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN -quantity ELSE 0 END) as recent_half', [$midpoint])
            ->selectRaw('SUM(CASE WHEN created_at < ? THEN -quantity ELSE 0 END) as older_half', [$midpoint])
            ->selectRaw('SUM(-quantity) as total_consumed')
            ->where('type', 'sale_deduction')
            ->where('created_at', '>=', $windowStart)
            ->groupBy('ingredient_id')
            ->get()
            ->keyBy('ingredient_id');

        $updated = 0;
        $now = Carbon::now();

        Ingredient::where('is_active', true)
            ->chunkById(200, function ($ingredients) use ($consumption, $halfWindowDays, $now, &$updated) {
                foreach ($ingredients as $ingredient) {
                    $this->upsertForecast($ingredient, $consumption->get($ingredient->id), $halfWindowDays, $now);
                    $updated++;
                }
            });

        return $updated;
    }

    private function upsertForecast(Ingredient $ingredient, $row, int $halfWindowDays, Carbon $now): void
    {
        $totalConsumed = (float) ($row->total_consumed ?? 0);
        $recentAvg = (float) ($row->recent_half ?? 0) / $halfWindowDays;
        $olderAvg = (float) ($row->older_half ?? 0) / $halfWindowDays;
        $avgDaily = $totalConsumed > 0 ? $totalConsumed / self::TRAILING_WINDOW_DAYS : 0.0;

        $projectedDays = $avgDaily > 0 ? round((float) $ingredient->current_stock / $avgDaily, 2) : null;
        $stockoutAt = $projectedDays !== null ? $now->copy()->addSeconds((int) round($projectedDays * 86400)) : null;

        $forwardNeed = $avgDaily * self::FORECAST_HORIZON_DAYS;
        $buffer = (float) $ingredient->current_stock - (float) $ingredient->reorder_level;

        IngredientRestockForecast::updateOrCreate(
            ['ingredient_id' => $ingredient->id],
            [
                'avg_daily_consumption' => round($avgDaily, 4),
                'projected_days_remaining' => $projectedDays,
                'projected_stockout_at' => $stockoutAt,
                'suggested_reorder_qty' => round(max(0.0, $forwardNeed - $buffer), 4),
                'trend' => $this->resolveTrend($recentAvg, $olderAvg),
                'computed_at' => $now,
            ]
        );
    }

    private function resolveTrend(float $recentAvg, float $olderAvg): string
    {
        if ($olderAvg <= 0) {
            return $recentAvg > 0 ? 'rising' : 'stable';
        }

        $delta = ($recentAvg - $olderAvg) / $olderAvg;

        return match (true) {
            $delta >= self::TREND_THRESHOLD => 'rising',
            $delta <= -self::TREND_THRESHOLD => 'falling',
            default => 'stable',
        };
    }
}
