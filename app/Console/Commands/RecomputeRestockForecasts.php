<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\RestockPredictionService;
use Illuminate\Console\Command;

class RecomputeRestockForecasts extends Command
{
    protected $signature = 'analytics:recompute-restock-forecasts';

    protected $description = 'Hitung ulang prediksi restock bahan baku (moving average 14 hari), statistik murni tanpa API AI eksternal.';

    public function handle(RestockPredictionService $service): int
    {
        $count = $service->recomputeAll();
        $this->info("Forecast restock diperbarui untuk {$count} bahan baku aktif.");

        return self::SUCCESS;
    }
}
