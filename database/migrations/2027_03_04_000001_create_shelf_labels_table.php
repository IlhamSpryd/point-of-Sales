<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shelf_labels')) {
            Schema::create('shelf_labels', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('store_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('product_variant_id')->nullable();
                $table->decimal('old_price', 15, 2)->default(0.00);
                $table->decimal('new_price', 15, 2)->default(0.00);
                $table->string('status', 32)->default('SHELF_LABEL_PENDING_PRINT');
                $table->string('batch_number', 64)->nullable();
                $table->timestamp('printed_at')->nullable();
                $table->unsignedBigInteger('printed_by_user_id')->nullable();
                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign(['tenant_id', 'store_id'])->references(['tenant_id', 'id'])->on('stores')->cascadeOnDelete();
                $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();

                $table->index(['tenant_id', 'store_id', 'status'], 'idx_shelf_labels_store_status');
                $table->index(['tenant_id', 'batch_number'], 'idx_shelf_labels_batch');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shelf_labels');
    }
};
