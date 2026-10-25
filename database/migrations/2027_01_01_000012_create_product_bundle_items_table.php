<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inkremen 5 — Modul 3
 * Tabel product_bundle_items untuk mendukung Sales BOM produk bertipe 'bundle' (ADR-04).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_bundle_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('bundle_product_id');
            $table->unsignedBigInteger('component_product_id');
            $table->unsignedBigInteger('component_variant_id')->nullable();
            $table->decimal('quantity', 15, 4)->default(1.0000);
            $table->unsignedBigInteger('uom_id')->nullable();
            $table->decimal('cost_allocation_ratio', 8, 4)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'pbi_tenant_id_id_unique');
            $table->unique(
                ['tenant_id', 'bundle_product_id', 'component_product_id', 'component_variant_id'],
                'pbi_bundle_component_unique'
            );

            // Composite Foreign Keys
            $table->foreign(['tenant_id', 'bundle_product_id'], 'pbi_bundle_product_composite_fk')
                ->references(['tenant_id', 'id'])->on('products')
                ->onDelete('CASCADE');

            $table->foreign(['tenant_id', 'component_product_id'], 'pbi_comp_product_composite_fk')
                ->references(['tenant_id', 'id'])->on('products')
                ->onDelete('RESTRICT');

            $table->foreign(['tenant_id', 'component_variant_id'], 'pbi_comp_variant_composite_fk')
                ->references(['tenant_id', 'id'])->on('product_variants')
                ->onDelete('RESTRICT');

            $table->foreign(['tenant_id', 'uom_id'], 'pbi_uom_composite_fk')
                ->references(['tenant_id', 'id'])->on('units_of_measure')
                ->onDelete('RESTRICT');

            $table->index(['tenant_id', 'bundle_product_id', 'sort_order'], 'pbi_bundle_sort_idx');
        });

        DB::statement('ALTER TABLE `product_bundle_items` ADD CONSTRAINT `pbi_quantity_check` CHECK (`quantity` > 0)');
        DB::statement('ALTER TABLE `product_bundle_items` ADD CONSTRAINT `pbi_not_self_bundle_check` CHECK (`bundle_product_id` != `component_product_id`)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_bundle_items');
    }
};
