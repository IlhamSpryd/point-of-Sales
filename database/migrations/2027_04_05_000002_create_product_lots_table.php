<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for Product Lots & Batches with FEFO tracking (TASK-P2-03).
     */
    public function up(): void
    {
        if (! Schema::hasTable('product_lots')) {
            Schema::create('product_lots', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('company_id')->nullable();
                $table->unsignedBigInteger('store_id')->nullable();
                $table->unsignedBigInteger('warehouse_id')->nullable();
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('product_variant_id')->nullable();
                $table->string('lot_number', 64);
                $table->date('expiry_date'); // Tanggal kadaluwarsa batch untuk algoritma FEFO
                $table->date('received_date')->nullable();
                $table->decimal('initial_quantity', 18, 4);
                $table->decimal('current_quantity', 18, 4);
                $table->decimal('unit_cost', 18, 4)->nullable();
                $table->string('status', 32)->default('active'); // active, expired, quarantined, depleted
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'id'], 'uq_product_lots_tenant_id');
                $table->index(['tenant_id', 'product_id', 'status', 'expiry_date'], 'idx_product_lots_fefo');
                $table->index(['tenant_id', 'store_id'], 'idx_product_lots_store');
                $table->index(['tenant_id', 'lot_number'], 'idx_product_lots_lot_number');

                $table->foreign(['tenant_id', 'product_id'])
                    ->references(['tenant_id', 'id'])->on('products')
                    ->onDelete('restrict')->onUpdate('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_lots');
    }
};
