<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for Discrete Manufacturing Orders (Modul 09 MFG).
     */
    public function up(): void
    {
        if (! Schema::hasTable('manufacturing_orders')) {
            Schema::create('manufacturing_orders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('store_id');
                $table->string('order_number', 64);
                $table->unsignedBigInteger('product_id');
                $table->decimal('planned_quantity', 18, 4);
                $table->decimal('produced_quantity', 18, 4)->default(0.0000);
                $table->string('status', 32)->default('DRAFT'); // DRAFT, PLANNED, IN_PROGRESS, COMPLETED, CANCELLED
                $table->date('planned_start_date')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'id'], 'uq_mfg_orders_tenant_id');
                $table->unique(['tenant_id', 'order_number'], 'uq_mfg_orders_number');
                $table->index(['tenant_id', 'store_id', 'status'], 'idx_mfg_orders_store_status');

                $table->foreign(['tenant_id', 'store_id'])
                    ->references(['tenant_id', 'id'])->on('stores')
                    ->onDelete('restrict')->onUpdate('cascade');

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
        Schema::dropIfExists('manufacturing_orders');
    }
};

