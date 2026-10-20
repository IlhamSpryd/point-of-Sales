<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inkremen 5 — Modul 3
 * Menambahkan product_variant_id pada order_items, channel_product_mappings, dan store_product_assortments.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. order_items
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_variant_id')->nullable()->after('product_id');

            $table->foreign(['tenant_id', 'product_variant_id'], 'order_items_variant_composite_fk')
                ->references(['tenant_id', 'id'])->on('product_variants')
                ->onUpdate('CASCADE')
                ->onDelete('RESTRICT');

            $table->index(['tenant_id', 'product_variant_id'], 'order_items_variant_idx');
        });

        // 2. channel_product_mappings
        Schema::table('channel_product_mappings', function (Blueprint $table) {
            $table->unsignedBigInteger('product_variant_id')->nullable()->after('product_id');

            $table->foreign(['tenant_id', 'product_variant_id'], 'cpm_variant_composite_fk')
                ->references(['tenant_id', 'id'])->on('product_variants')
                ->onUpdate('CASCADE')
                ->onDelete('CASCADE');

            $table->index(['tenant_id', 'product_variant_id'], 'cpm_variant_idx');
        });

        // 3. store_product_assortments
        Schema::table('store_product_assortments', function (Blueprint $table) {
            $table->unsignedBigInteger('product_variant_id')->nullable()->after('product_id');

            $table->foreign(['tenant_id', 'product_variant_id'], 'spa_variant_composite_fk')
                ->references(['tenant_id', 'id'])->on('product_variants')
                ->onUpdate('CASCADE')
                ->onDelete('CASCADE');

            $table->index(['tenant_id', 'store_id', 'product_variant_id'], 'spa_variant_store_idx');
        });
    }

    public function down(): void
    {
        Schema::table('store_product_assortments', function (Blueprint $table) {
            $table->dropForeign('spa_variant_composite_fk');
            $table->dropIndex('spa_variant_store_idx');
            $table->dropColumn('product_variant_id');
        });

        Schema::table('channel_product_mappings', function (Blueprint $table) {
            $table->dropForeign('cpm_variant_composite_fk');
            $table->dropIndex('cpm_variant_idx');
            $table->dropColumn('product_variant_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign('order_items_variant_composite_fk');
            $table->dropIndex('order_items_variant_idx');
            $table->dropColumn('product_variant_id');
        });
    }
};
