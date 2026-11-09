<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Implements TASK-203 (ACT-08): Kitchen Display System Station Filtering Standardization.
     */
    public function up(): void
    {
        if (Schema::hasTable('categories')) {
            Schema::table('categories', function (Blueprint $table) {
                if (! Schema::hasColumn('categories', 'kitchen_station')) {
                    $table->string('kitchen_station', 20)->default('kitchen');
                    $table->index(['tenant_id', 'kitchen_station'], 'idx_categories_tenant_station');
                }
            });

            // Backfill existing categories based on known patterns
            $barKeywords = ['beverage', 'minuman', 'bar', 'coffee', 'kopi', 'drink', 'tea', 'juice', 'mocktail', 'boba', 'latte', 'smoothie'];
            $dessertKeywords = ['dessert', 'pastry', 'bakery', 'snack', 'kue'];

            foreach ($barKeywords as $keyword) {
                DB::table('categories')
                    ->whereRaw('LOWER(category_name) LIKE ?', ["%{$keyword}%"])
                    ->update(['kitchen_station' => 'bar']);
            }

            foreach ($dessertKeywords as $keyword) {
                DB::table('categories')
                    ->whereRaw('LOWER(category_name) LIKE ?', ["%{$keyword}%"])
                    ->update(['kitchen_station' => 'dessert']);
            }
        }

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (! Schema::hasColumn('products', 'kitchen_station')) {
                    $table->string('kitchen_station', 20)->nullable();
                    $table->index(['tenant_id', 'kitchen_station'], 'idx_products_tenant_station');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'kitchen_station')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex('idx_products_tenant_station');
                $table->dropColumn('kitchen_station');
            });
        }

        if (Schema::hasTable('categories') && Schema::hasColumn('categories', 'kitchen_station')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropIndex('idx_categories_tenant_station');
                $table->dropColumn('kitchen_station');
            });
        }
    }
};
