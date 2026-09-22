<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// [OMEGA-NODE7] Enterprise Data Architecture Expansion — Modul Bill of Materials | 2026-09-22
return new class extends Migration
{
    /**
     * Master bahan baku (raw material). Terpisah total dari `products` --
     * `products` adalah barang yang DIJUAL ke pelanggan (harga jual, stok
     * jual), sedangkan `ingredients` adalah bahan MENTAH yang DIKONSUMSI
     * saat produk diracik (kopi bubuk, susu, sirup, gelas kertas). Tanpa
     * pemisahan ini, mustahil menghitung COGS (Cost of Goods Sold) per
     * produk atau memotong stok bahan baku otomatis saat kasir menjual
     * "Caffe Latte" (yang secara diam-diam menghabiskan susu bersama
     * "Cappuccino").
     */
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->string('ingredient_code')->unique()->nullable();
            $table->string('name');
            // Satuan dasar BAKU untuk bahan ini (g, ml, pcs, kg, l). Semua
            // quantity_required di product_ingredients WAJIB dalam satuan
            // yang sama persis -- konversi (mis. kg <-> g) dilakukan di
            // Service layer sebelum insert, BUKAN disimpan dua kali di DB
            // (mencegah drift antara dua representasi unit yang sama).
            $table->string('unit', 20);
            // Presisi 4 desimal: racikan espresso/rempah butuh granularitas
            // sekecil 0.0001 kg (0.1 gram) -- DECIMAL, BUKAN FLOAT/DOUBLE,
            // agar tidak ada rounding error kumulatif pada laporan
            // food-cost bulanan (klasik: FLOAT 0.1 + 0.2 != 0.3).
            $table->decimal('current_stock', 14, 4)->unsigned()->default(0);
            $table->decimal('cost_per_unit', 14, 2)->unsigned()->default(0);
            $table->decimal('reorder_level', 14, 4)->unsigned()->default(0);
            $table->boolean('is_active')->default(true);
            $table->softDeletes(); // resep lama (product_ingredients) tidak boleh kehilangan rujukan
            $table->timestamps();

            // Pola sama seperti products_is_active_stock_index: query
            // "bahan baku mana yang hampir habis" adalah query real-time
            // yang dijalankan di dashboard Inventory setiap kali halaman dimuat.
            $table->index(['is_active', 'current_stock'], 'ingredients_active_stock_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
