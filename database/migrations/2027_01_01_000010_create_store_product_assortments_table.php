<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inkremen 3 — Modul 3
 * Tabel store_product_assortments untuk ketersediaan cabang dan override harga cabang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_product_assortments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('product_id');
            $table->boolean('is_available')->default(true);
            $table->decimal('store_price_override', 15, 2)->nullable();
            $table->json('allowed_channels')->nullable();
            $table->timestamps();

            // Composite Foreign Keys
            $table->foreign(['tenant_id', 'store_id'], 'spa_tenant_store_composite_fk')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('CASCADE');

            $table->foreign(['tenant_id', 'product_id'], 'spa_tenant_product_composite_fk')
                ->references(['tenant_id', 'id'])->on('products')
                ->onDelete('CASCADE');

            // Unique constraint per tenant, store, and product
            $table->unique(['tenant_id', 'store_id', 'product_id'], 'spa_tenant_store_product_unique');

            // Index untuk pencarian cepat ketersediaan di cabang
            $table->index(['tenant_id', 'store_id', 'is_available'], 'spa_store_availability_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_product_assortments');
    }
};
