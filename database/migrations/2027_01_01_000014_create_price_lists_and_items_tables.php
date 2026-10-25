<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inkremen 6 — Modul 3
 * Tabel price_lists dan price_list_items untuk multi-price lists, harga grosir bertingkat,
 * dan kontrak harga khusus pelanggan/cabang/kanal (ADR-02).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. price_lists
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 255);
            $table->string('code', 50)->nullable();
            $table->string('type', 32)->default('standard');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('loyalty_tier_id')->nullable();
            $table->string('channel', 32)->nullable();
            $table->string('currency', 3)->default('IDR');
            $table->integer('priority')->default(10);
            $table->dateTime('valid_from')->nullable();
            $table->dateTime('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'id'], 'price_lists_tenant_id_id_unique');
            $table->unique(['tenant_id', 'code'], 'price_lists_tenant_code_unique');

            $table->foreign('tenant_id', 'price_lists_tenant_fk')
                ->references('id')->on('tenants')
                ->onDelete('NO ACTION');

            $table->foreign(['tenant_id', 'store_id'], 'pl_tenant_store_composite_fk')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('CASCADE');

            $table->foreign(['tenant_id', 'customer_id'], 'pl_tenant_customer_composite_fk')
                ->references(['tenant_id', 'id'])->on('customers')
                ->onDelete('CASCADE');

            $table->foreign(['tenant_id', 'loyalty_tier_id'], 'pl_tenant_loyalty_composite_fk')
                ->references(['tenant_id', 'id'])->on('loyalty_tiers')
                ->onDelete('RESTRICT');

            $table->index(['tenant_id', 'type', 'is_active'], 'pl_tenant_type_active_idx');
            $table->index(['tenant_id', 'priority', 'is_active'], 'pl_tenant_priority_idx');
        });

        DB::statement("ALTER TABLE `price_lists` ADD CONSTRAINT `pl_type_check` CHECK (`type` in ('standard','branch','wholesale','customer_tier','customer_specific','channel'))");

        // 2. price_list_items
        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('price_list_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->unsignedBigInteger('uom_id')->nullable();
            $table->decimal('min_quantity', 15, 4)->default(1.0000);
            $table->decimal('price', 15, 2);
            $table->decimal('discount_percentage', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'price_list_items_tenant_id_id_unique');
            $table->unique(
                ['tenant_id', 'price_list_id', 'product_id', 'product_variant_id', 'uom_id', 'min_quantity'],
                'pli_composite_unique'
            );

            $table->foreign(['tenant_id', 'price_list_id'], 'pli_tenant_pl_composite_fk')
                ->references(['tenant_id', 'id'])->on('price_lists')
                ->onDelete('CASCADE');

            $table->foreign(['tenant_id', 'product_id'], 'pli_tenant_prod_composite_fk')
                ->references(['tenant_id', 'id'])->on('products')
                ->onDelete('CASCADE');

            $table->foreign(['tenant_id', 'product_variant_id'], 'pli_tenant_variant_composite_fk')
                ->references(['tenant_id', 'id'])->on('product_variants')
                ->onDelete('CASCADE');

            $table->foreign(['tenant_id', 'uom_id'], 'pli_tenant_uom_composite_fk')
                ->references(['tenant_id', 'id'])->on('units_of_measure')
                ->onDelete('RESTRICT');

            $table->index(['tenant_id', 'product_id', 'min_quantity'], 'pli_prod_qty_idx');
        });

        DB::statement('ALTER TABLE `price_list_items` ADD CONSTRAINT `pli_price_check` CHECK (`price` >= 0)');
        DB::statement('ALTER TABLE `price_list_items` ADD CONSTRAINT `pli_min_quantity_check` CHECK (`min_quantity` >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
    }
};
