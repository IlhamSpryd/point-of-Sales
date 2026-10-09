<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_stock_balances', function (Blueprint $table) {
            $table->bigInteger('tenant_id')->unsigned();
            $table->bigInteger('store_id')->unsigned();
            $table->bigInteger('product_id')->unsigned();
            $table->decimal('quantity', 18, 6)->default(0);
            $table->decimal('baseline_quantity', 18, 6)->default(0);
            $table->bigInteger('baseline_movement_id')->unsigned()->default(0);
            $table->timestamps();

            $table->primary(['tenant_id', 'store_id', 'product_id'], 'psb_primary');

            $table->foreign(['tenant_id', 'store_id'], 'psb_store_fk')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'product_id'], 'psb_product_fk')
                ->references(['tenant_id', 'id'])->on('products')
                ->onDelete('restrict');
        });

        DB::statement('ALTER TABLE product_stock_balances ADD CONSTRAINT psb_quantity_check CHECK (quantity >= 0)');

        Schema::create('ingredient_stock_balances', function (Blueprint $table) {
            $table->bigInteger('tenant_id')->unsigned();
            $table->bigInteger('store_id')->unsigned();
            $table->bigInteger('ingredient_id')->unsigned();
            $table->decimal('quantity', 18, 6)->default(0);
            $table->decimal('baseline_quantity', 18, 6)->default(0);
            $table->bigInteger('baseline_movement_id')->unsigned()->default(0);
            $table->timestamps();

            $table->primary(['tenant_id', 'store_id', 'ingredient_id'], 'isb_primary');

            $table->foreign(['tenant_id', 'store_id'], 'isb_store_fk')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'ingredient_id'], 'isb_ingredient_fk')
                ->references(['tenant_id', 'id'])->on('ingredients')
                ->onDelete('restrict');
        });

        DB::statement('ALTER TABLE ingredient_stock_balances ADD CONSTRAINT isb_quantity_check CHECK (quantity >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_stock_balances');
        Schema::dropIfExists('product_stock_balances');
    }
};
