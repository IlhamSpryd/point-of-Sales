<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredient_stock_movements', function (Blueprint $table) {
            $table->index(['type', 'created_at', 'ingredient_id'], 'ism_forecast_type_date_ingredient_index');
        });
    }

    public function down(): void
    {
        Schema::table('ingredient_stock_movements', function (Blueprint $table) {
            $table->dropIndex('ism_forecast_type_date_ingredient_index');
        });
    }
};
