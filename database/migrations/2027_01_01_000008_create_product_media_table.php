<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inkremen 2 — Modul 3
 * Multi-Media Galeri Produk (product_media).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_media', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('product_id');
            $table->string('disk', 32)->default('public');
            $table->string('file_path', 255);
            $table->string('mime_type', 64);
            $table->unsignedInteger('file_size_bytes');
            $table->string('alt_text', 255)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id', 'pm_tenant_id_foreign')
                ->references('id')->on('tenants')
                ->onDelete('CASCADE');

            // Composite FK ke products
            $table->foreign(['tenant_id', 'product_id'], 'pm_product_composite_foreign')
                ->references(['tenant_id', 'id'])->on('products')
                ->onDelete('CASCADE');

            $table->index(['tenant_id', 'product_id', 'is_primary', 'sort_order'], 'pm_tenant_product_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_media');
    }
};
