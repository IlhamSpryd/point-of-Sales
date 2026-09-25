<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->default(1)->after('id');
            $table->unsignedBigInteger('store_id')->default(1)->after('tenant_id');
            
            // Make order_id and order_item_id nullable for non-order movements
            $table->unsignedBigInteger('order_id')->nullable()->change();
            $table->unsignedBigInteger('order_item_id')->nullable()->change();
        });

        Schema::table('ingredient_stock_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->default(1)->after('id');
            $table->unsignedBigInteger('store_id')->default(1)->after('tenant_id');
        });

        if (DB::getDriverName() !== 'sqlite') {
            // Update check constraint to allow 'initial_sync'
            DB::statement('ALTER TABLE ingredient_stock_movements DROP CONSTRAINT chk_ism_type_enum');
            DB::statement("ALTER TABLE ingredient_stock_movements ADD CONSTRAINT chk_ism_type_enum CHECK (type IN ('sale_deduction','purchase_receipt','waste','adjustment','restore_compensation','initial_sync'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Add down logic if necessary
    }
};
