<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inkremen 1 — Modul 3
 * Perluasan master products: product_type, status, has_variants, track_inventory,
 * brand_id, base_uom_id, barcode, costing_method, standard_cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'product_type')) {
                $table->string('product_type', 32)->default('standard')->after('product_code');
            }
            if (! Schema::hasColumn('products', 'status')) {
                $table->string('status', 32)->default('active')->after('product_type');
            }
            if (! Schema::hasColumn('products', 'has_variants')) {
                $table->boolean('has_variants')->default(false)->after('status');
            }
            if (! Schema::hasColumn('products', 'track_inventory')) {
                $table->boolean('track_inventory')->default(true)->after('has_variants');
            }
            if (! Schema::hasColumn('products', 'brand_id')) {
                $table->unsignedBigInteger('brand_id')->nullable()->after('category_id');
            }
            if (! Schema::hasColumn('products', 'base_uom_id')) {
                $table->unsignedBigInteger('base_uom_id')->nullable()->after('brand_id');
            }
            if (! Schema::hasColumn('products', 'barcode')) {
                $table->string('barcode', 100)->nullable()->after('product_code');
            }
            if (! Schema::hasColumn('products', 'costing_method')) {
                $table->string('costing_method', 32)->default('standard')->after('product_price');
            }
            if (! Schema::hasColumn('products', 'standard_cost')) {
                $table->decimal('standard_cost', 15, 2)->default(0.00)->after('costing_method');
            }
        });

        // Composite FKs
        $fkList = array_map(fn ($r) => $r->CONSTRAINT_NAME, DB::select("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'"));
        Schema::table('products', function (Blueprint $table) use ($fkList) {
            if (! in_array('products_tenant_brand_composite_foreign', $fkList, true)) {
                $table->foreign(['tenant_id', 'brand_id'], 'products_tenant_brand_composite_foreign')
                    ->references(['tenant_id', 'id'])->on('brands')
                    ->onDelete('RESTRICT');
            }
            if (! in_array('products_tenant_uom_composite_foreign', $fkList, true)) {
                $table->foreign(['tenant_id', 'base_uom_id'], 'products_tenant_uom_composite_foreign')
                    ->references(['tenant_id', 'id'])->on('units_of_measure')
                    ->onDelete('RESTRICT');
            }
        });

        // Indexes
        $idxList = array_map(fn ($r) => $r->Key_name, DB::select('SHOW INDEX FROM products'));
        Schema::table('products', function (Blueprint $table) use ($idxList) {
            if (! in_array('products_tenant_status_category_idx', $idxList, true)) {
                $table->index(['tenant_id', 'status', 'category_id'], 'products_tenant_status_category_idx');
            }
            if (! in_array('products_tenant_barcode_unique', $idxList, true)) {
                $table->unique(['tenant_id', 'barcode'], 'products_tenant_barcode_unique');
            }
        });

        // Sinkronisasi status dari is_active eksisting
        DB::statement("UPDATE `products` SET `status` = 'inactive' WHERE `is_active` = 0 AND `deleted_at` IS NULL");
        DB::statement("UPDATE `products` SET `status` = 'active' WHERE `is_active` = 1 AND `deleted_at` IS NULL");

        // DB check constraints
        try {
            DB::statement("ALTER TABLE `products` ADD CONSTRAINT `products_product_type_check` CHECK (`product_type` IN ('standard','service','non_inventory','bundle'))");
        } catch (Exception $e) {
        }
        try {
            DB::statement("ALTER TABLE `products` ADD CONSTRAINT `products_status_check` CHECK (`status` IN ('draft','active','inactive','discontinued'))");
        } catch (Exception $e) {
        }
        try {
            DB::statement("ALTER TABLE `products` ADD CONSTRAINT `products_costing_method_check` CHECK (`costing_method` IN ('standard','fifo','moving_average'))");
        } catch (Exception $e) {
        }
        try {
            DB::statement('ALTER TABLE `products` ADD CONSTRAINT `products_standard_cost_check` CHECK (`standard_cost` >= 0)');
        } catch (Exception $e) {
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `products` DROP CHECK `products_product_type_check`');
        DB::statement('ALTER TABLE `products` DROP CHECK `products_status_check`');
        DB::statement('ALTER TABLE `products` DROP CHECK `products_costing_method_check`');
        DB::statement('ALTER TABLE `products` DROP CHECK `products_standard_cost_check`');

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign('products_tenant_brand_composite_foreign');
            $table->dropForeign('products_tenant_uom_composite_foreign');
            $table->dropIndex('products_tenant_status_category_idx');
            $table->dropUnique('products_tenant_barcode_unique');
            $table->dropColumn([
                'product_type', 'status', 'has_variants', 'track_inventory',
                'brand_id', 'base_uom_id', 'barcode', 'costing_method', 'standard_cost',
            ]);
        });
    }
};
