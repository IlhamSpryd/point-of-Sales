<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inkremen 1 — Modul 3
 * Satuan Ukur (UoM) dan Konversi Satuan per Produk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units_of_measure', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('code', 32);
            $table->string('name', 100);
            $table->unsignedTinyInteger('precision_scale')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id', 'uom_tenant_id_foreign')
                ->references('id')->on('tenants')
                ->onDelete('CASCADE');

            $table->unique(['tenant_id', 'id'], 'uom_tenant_id_id_unique');
            $table->unique(['tenant_id', 'code'], 'uom_tenant_code_unique');
        });

        DB::statement('ALTER TABLE `units_of_measure` ADD CONSTRAINT `uom_precision_scale_check` CHECK (`precision_scale` BETWEEN 0 AND 4)');

        Schema::create('product_unit_conversions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('from_uom_id');
            $table->unsignedBigInteger('to_uom_id');
            $table->decimal('conversion_factor', 18, 6);
            $table->string('barcode', 100)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id', 'puc_tenant_id_foreign')
                ->references('id')->on('tenants')
                ->onDelete('CASCADE');

            // Composite FK ke products
            $table->foreign(['tenant_id', 'product_id'], 'puc_product_composite_foreign')
                ->references(['tenant_id', 'id'])->on('products')
                ->onDelete('CASCADE');

            // Composite FK ke units_of_measure
            $table->foreign(['tenant_id', 'from_uom_id'], 'puc_from_uom_composite_foreign')
                ->references(['tenant_id', 'id'])->on('units_of_measure')
                ->onDelete('RESTRICT');

            $table->foreign(['tenant_id', 'to_uom_id'], 'puc_to_uom_composite_foreign')
                ->references(['tenant_id', 'id'])->on('units_of_measure')
                ->onDelete('RESTRICT');

            $table->unique(['tenant_id', 'product_id', 'from_uom_id', 'to_uom_id'], 'puc_tenant_product_uom_unique');
        });

        DB::statement('ALTER TABLE `product_unit_conversions` ADD CONSTRAINT `puc_conversion_factor_check` CHECK (`conversion_factor` > 0)');
        DB::statement('ALTER TABLE `product_unit_conversions` ADD CONSTRAINT `puc_different_uoms_check` CHECK (`from_uom_id` <> `to_uom_id`)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_unit_conversions');
        Schema::dropIfExists('units_of_measure');
    }
};
