<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// [OMEGA-NODE7] Enterprise Data Architecture Expansion — Modul Bill of Materials | 2026-09-22
return new class extends Migration
{
    /**
     * Resep (BOM): berapa banyak SETIAP bahan baku dibutuhkan untuk membuat
     * SATU unit produk jual. Contoh: "Caffe Latte" butuh 18g Kopi Arabica +
     * 150ml Susu Full Cream. Tabel pivot ini adalah jembatan antara katalog
     * jual (products) dan gudang bahan baku (ingredients) -- TANPA tabel
     * ini, TransactionService tidak punya cara mengetahui bahan apa saja
     * yang harus dipotong saat satu unit produk terjual.
     */
    public function up(): void
    {
        Schema::create('product_ingredients', function (Blueprint $table) {
            $table->id();
            // cascadeOnDelete: hanya berlaku pada HARD delete produk (yang
            // sangat jarang terjadi -- ProductService memblokir delete
            // selama masih ada order_items yang merujuknya, lihat
            // ProductService::delete()). Baris resep yang sudah usang
            // otomatis ikut bersih tanpa menyisakan orphan row.
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            // restrictOnDelete: TIDAK BOLEH menghapus master bahan baku
            // selama masih dipakai di resep produk manapun -- paksa
            // Inventory menonaktifkan (is_active=false) dulu, bukan hapus,
            // pola identik dengan products_category_id_foreign.
            $table->foreignId('ingredient_id')->constrained('ingredients')->restrictOnDelete();
            $table->decimal('quantity_required', 14, 4)->unsigned();
            $table->timestamps();

            // Satu produk tidak boleh punya dua baris resep untuk bahan
            // baku yang sama (harus UPDATE quantity_required-nya, bukan
            // insert baris kedua yang ambigu saat dijumlahkan).
            $table->unique(['product_id', 'ingredient_id'], 'product_ingredients_unique');
        });

        // quantity_required = 0 lolos dari UNSIGNED (unsigned mengizinkan
        // nol), padahal baris resep dengan kebutuhan NOL bahan baku tidak
        // masuk akal secara bisnis -- seharusnya baris itu dihapus, bukan
        // di-nol-kan.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE product_ingredients ADD CONSTRAINT chk_pi_qty_positive CHECK (quantity_required > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_ingredients');
    }
};
