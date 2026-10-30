<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'is_open_price')) {
                $table->boolean('is_open_price')->default(false)->after('product_price');
            }
        });

        Schema::table('product_variants', function (Blueprint $table) {
            if (! Schema::hasColumn('product_variants', 'is_open_price')) {
                $table->boolean('is_open_price')->default(false)->after('price_override');
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'product_name_snapshot')) {
                $table->string('product_name_snapshot', 255)->nullable()->after('product_variant_id');
            }
            if (! Schema::hasColumn('order_items', 'sku_snapshot')) {
                $table->string('sku_snapshot', 100)->nullable()->after('product_name_snapshot');
            }
            if (! Schema::hasColumn('order_items', 'hpp_snapshot')) {
                $table->decimal('hpp_snapshot', 15, 2)->default(0)->after('order_subtotal');
            }
            if (! Schema::hasColumn('order_items', 'price_source')) {
                $table->string('price_source', 50)->nullable()->after('hpp_snapshot');
            }
            if (! Schema::hasColumn('order_items', 'applied_promotions_snapshot')) {
                $table->json('applied_promotions_snapshot')->nullable()->after('net_line_total');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn([
                'product_name_snapshot',
                'sku_snapshot',
                'hpp_snapshot',
                'price_source',
                'applied_promotions_snapshot',
            ]);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('is_open_price');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_open_price');
        });
    }
};
