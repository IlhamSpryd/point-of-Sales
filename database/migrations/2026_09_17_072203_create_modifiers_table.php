<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modifiers', function (Blueprint $table) {
            $table->id();
            // Setiap opsi varian pasti milik satu grup (contoh: opsi "Ice" milik grup "Pilihan Suhu").
            // cascadeOnDelete: kalau grupnya dihapus, semua opsi di dalamnya ikut terhapus otomatis.
            $table->foreignId('modifier_group_id')->constrained('modifier_groups')->cascadeOnDelete();
            // Nama opsi, contoh: "Ice", "Hot", "Less Sugar", "Oat Milk"
            $table->string('name');
            // Biaya tambahan (dalam Rupiah, TANPA desimal) jika opsi ini dipilih.
            // Konsisten dengan product_price yang juga bigInteger di tabel products.
            $table->bigInteger('extra_price')->default(0);
            // Opsi yang otomatis ter-pilih duluan saat modal varian dibuka (UX shortcut).
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifiers');
    }
};
