<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabel pivot murni: menghubungkan produk mana saja yang memakai grup varian tertentu.
        // Kenapa perlu pivot (bukan foreign key langsung)? Karena satu grup varian
        // (misal "Pilihan Suhu") dipakai ULANG oleh banyak produk kopi berbeda,
        // dan satu produk juga bisa punya banyak grup varian sekaligus.
        Schema::create('modifier_group_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('modifier_group_id')->constrained('modifier_groups')->cascadeOnDelete();
            $table->timestamps();

            // Mencegah data duplikat: satu produk tidak boleh terhubung ke grup varian
            // yang sama dua kali.
            $table->unique(['product_id', 'modifier_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifier_group_product');
    }
};
