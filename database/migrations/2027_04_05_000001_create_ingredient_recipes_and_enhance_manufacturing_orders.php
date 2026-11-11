<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for Multi-Level BOM (Kitchen Prep Items) and Manufacturing Order destination.
     */
    public function up(): void
    {
        // 1. Resep Bahan Setengah Jadi / Kitchen Prep Items (Bahan olahan yang dibuat dari bahan mentah)
        if (! Schema::hasTable('ingredient_recipes')) {
            Schema::create('ingredient_recipes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('company_id')->nullable();
                $table->unsignedBigInteger('store_id')->nullable();
                $table->unsignedBigInteger('parent_ingredient_id'); // Bahan setengah jadi hasil produksi (e.g. Sambal Matah)
                $table->unsignedBigInteger('component_ingredient_id'); // Bahan baku yang dikonsumsi (e.g. Bawang Merah, Cabai)
                $table->decimal('quantity_required', 18, 4); // Kuantitas bahan baku yang dibutuhkan per unit output
                $table->string('unit', 32)->nullable();
                $table->string('notes')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'id'], 'uq_ing_recipes_tenant_id');
                $table->index(['tenant_id', 'parent_ingredient_id'], 'idx_ing_recipes_parent');
                $table->index(['tenant_id', 'component_ingredient_id'], 'idx_ing_recipes_comp');

                $table->foreign(['tenant_id', 'parent_ingredient_id'])
                    ->references(['tenant_id', 'id'])->on('ingredients')
                    ->onDelete('cascade')->onUpdate('cascade');

                $table->foreign(['tenant_id', 'component_ingredient_id'])
                    ->references(['tenant_id', 'id'])->on('ingredients')
                    ->onDelete('restrict')->onUpdate('cascade');
            });
        }

        // 2. Tambahkan kapabilitas produksi bahan olahan (Prep Items) pada manufacturing_orders
        if (Schema::hasTable('manufacturing_orders')) {
            Schema::table('manufacturing_orders', function (Blueprint $table) {
                if (! Schema::hasColumn('manufacturing_orders', 'destination_type')) {
                    $table->string('destination_type', 32)->default('product')->after('order_number'); // 'product' atau 'ingredient'
                }
                if (! Schema::hasColumn('manufacturing_orders', 'destination_ingredient_id')) {
                    $table->unsignedBigInteger('destination_ingredient_id')->nullable()->after('product_id');
                    $table->index(['tenant_id', 'destination_ingredient_id'], 'idx_mfg_orders_dest_ingredient');
                }
            });

            // Modify product_id to be nullable so MO can produce either Product or Ingredient
            Schema::table('manufacturing_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('product_id')->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('manufacturing_orders')) {
            Schema::table('manufacturing_orders', function (Blueprint $table) {
                if (Schema::hasColumn('manufacturing_orders', 'destination_ingredient_id')) {
                    $table->dropIndex('idx_mfg_orders_dest_ingredient');
                    $table->dropColumn('destination_ingredient_id');
                }
                if (Schema::hasColumn('manufacturing_orders', 'destination_type')) {
                    $table->dropColumn('destination_type');
                }
            });
        }

        Schema::dropIfExists('ingredient_recipes');
    }
};
