<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modifier_groups', function (Blueprint $table) {
            $table->id();
            // Nama grup varian, contoh: "Pilihan Suhu", "Tingkat Gula", "Jenis Susu"
            $table->string('name');
            // 'single'   = pelanggan cuma boleh pilih SATU opsi (radio button), misal Suhu.
            // 'multiple' = pelanggan boleh pilih LEBIH DARI SATU opsi (checkbox), misal Topping.
            $table->enum('selection_type', ['single', 'multiple'])->default('single');
            // Jika true, form varian ini WAJIB diisi sebelum produk bisa masuk keranjang.
            $table->boolean('is_required')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifier_groups');
    }
};
