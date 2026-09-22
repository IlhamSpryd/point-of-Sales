<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// [OMEGA-NODE5] Omnichannel Gateway -- pemetaan SKU eksternal -> produk internal | 2026-09-23
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_product_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30); // grabfood, gofood
            $table->string('external_product_id');
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['provider', 'external_product_id'], 'channel_product_mappings_provider_sku_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_product_mappings');
    }
};
