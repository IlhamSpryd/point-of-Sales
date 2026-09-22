<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// [OMEGA-NODE5] Predictive Restocking -- cache materialized | 2026-09-23
return new class extends Migration
{
    /**
     * Dihitung ulang oleh job terjadwal (bukan live saat dashboard dibuka)
     * -- konsisten pola cache-aside MenuCacheService/DashboardService.
     */
    public function up(): void
    {
        Schema::create('ingredient_restock_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->unique()->constrained('ingredients')->cascadeOnDelete();
            $table->decimal('avg_daily_consumption', 14, 4)->default(0);
            $table->decimal('projected_days_remaining', 8, 2)->nullable();
            $table->timestamp('projected_stockout_at')->nullable();
            $table->decimal('suggested_reorder_qty', 14, 4)->default(0);
            $table->string('trend', 10)->default('stable'); // rising | falling | stable
            $table->timestamp('computed_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_restock_forecasts');
    }
};
