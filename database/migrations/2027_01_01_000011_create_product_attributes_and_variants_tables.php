<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inkremen 5 — Modul 3
 * Tabel product_attributes, product_attribute_values, product_variants,
 * dan product_variant_attribute_values untuk mendukung model produk bervarian (ADR-01).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. product_attributes
        Schema::create('product_attributes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 255);
            $table->string('code', 50)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'id'], 'product_attributes_tenant_id_id_unique');
            $table->unique(['tenant_id', 'name'], 'product_attributes_tenant_name_unique');

            $table->foreign('tenant_id', 'product_attributes_tenant_fk')
                ->references('id')->on('tenants')
                ->onDelete('NO ACTION');

            $table->index(['tenant_id', 'is_active'], 'product_attributes_tenant_active_idx');
        });

        // 2. product_attribute_values
        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('attribute_id');
            $table->string('value', 255);
            $table->string('code', 50)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'product_attr_values_tenant_id_id_unique');
            $table->unique(['tenant_id', 'attribute_id', 'value'], 'product_attr_values_tenant_attr_value_unique');

            $table->foreign(['tenant_id', 'attribute_id'], 'pav_tenant_attribute_composite_fk')
                ->references(['tenant_id', 'id'])->on('product_attributes')
                ->onDelete('CASCADE');

            $table->index(['tenant_id', 'attribute_id', 'sort_order'], 'pav_tenant_attr_sort_idx');
        });

        // 3. product_variants
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('product_id');
            $table->string('variant_name', 255);
            $table->string('sku', 255)->nullable();
            $table->string('barcode', 100)->nullable();
            $table->decimal('price_override', 15, 2)->nullable();
            $table->string('costing_method', 32)->default('standard');
            $table->decimal('standard_cost', 15, 2)->default(0.00);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'id'], 'product_variants_tenant_id_id_unique');
            $table->unique(['tenant_id', 'sku'], 'product_variants_tenant_sku_unique');
            $table->unique(['tenant_id', 'barcode'], 'product_variants_tenant_barcode_unique');

            $table->foreign(['tenant_id', 'product_id'], 'pv_tenant_product_composite_fk')
                ->references(['tenant_id', 'id'])->on('products')
                ->onDelete('CASCADE');

            $table->index(['tenant_id', 'product_id', 'is_active'], 'pv_tenant_product_active_idx');
        });

        // Check constraints pada product_variants
        DB::statement("ALTER TABLE `product_variants` ADD CONSTRAINT `pv_costing_method_check` CHECK (`costing_method` in ('standard','fifo','moving_average'))");
        DB::statement('ALTER TABLE `product_variants` ADD CONSTRAINT `pv_standard_cost_check` CHECK (`standard_cost` >= 0)');
        DB::statement('ALTER TABLE `product_variants` ADD CONSTRAINT `pv_price_override_check` CHECK (`price_override` IS NULL OR `price_override` >= 0)');

        // 4. product_variant_attribute_values
        Schema::create('product_variant_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('variant_id');
            $table->unsignedBigInteger('attribute_id');
            $table->unsignedBigInteger('attribute_value_id');
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'pvav_tenant_id_id_unique');
            $table->unique(['tenant_id', 'variant_id', 'attribute_id'], 'pvav_tenant_variant_attribute_unique');

            $table->foreign(['tenant_id', 'variant_id'], 'pvav_tenant_variant_composite_fk')
                ->references(['tenant_id', 'id'])->on('product_variants')
                ->onDelete('CASCADE');

            $table->foreign(['tenant_id', 'attribute_id'], 'pvav_tenant_attribute_composite_fk')
                ->references(['tenant_id', 'id'])->on('product_attributes')
                ->onDelete('RESTRICT');

            $table->foreign(['tenant_id', 'attribute_value_id'], 'pvav_tenant_attr_value_composite_fk')
                ->references(['tenant_id', 'id'])->on('product_attribute_values')
                ->onDelete('RESTRICT');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_attribute_values');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_attribute_values');
        Schema::dropIfExists('product_attributes');
    }
};
