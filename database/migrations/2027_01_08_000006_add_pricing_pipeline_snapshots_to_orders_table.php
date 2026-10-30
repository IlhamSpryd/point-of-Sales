<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'dpp_amount')) {
                $table->decimal('dpp_amount', 15, 2)->default(0.00);
            }
            if (! Schema::hasColumn('orders', 'rounding_adjustment')) {
                $table->decimal('rounding_adjustment', 15, 2)->default(0.00);
            }
            if (! Schema::hasColumn('orders', 'applied_promotions_summary')) {
                $table->json('applied_promotions_summary')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'dpp_amount')) {
                $table->dropColumn('dpp_amount');
            }
            if (Schema::hasColumn('orders', 'rounding_adjustment')) {
                $table->dropColumn('rounding_adjustment');
            }
            if (Schema::hasColumn('orders', 'applied_promotions_summary')) {
                $table->dropColumn('applied_promotions_summary');
            }
        });
    }
};
