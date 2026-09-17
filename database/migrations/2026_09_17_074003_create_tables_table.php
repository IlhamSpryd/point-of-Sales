<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            // Nomor/nama meja yang tampil ke Kasir/Barista, contoh: "12", "VIP-1".
            $table->string('table_number', 10)->unique();
            // Token rahasia UNIK yang dikodekan ke dalam QR Code fisik di atas meja.
            // Sengaja BUKAN memakai 'id' auto-increment biasa, karena id bisa ditebak
            // secara berurutan (1, 2, 3, ...) — memakai UUID mencegah orang menebak
            // URL meja lain hanya dengan mengganti angka di address bar.
            $table->uuid('secure_token')->unique();
            // 'active'   = meja boleh dipakai, QR bisa di-scan untuk pesan.
            // 'inactive' = meja sedang tidak dipakai (di re-layout ulang, rusak, dsb),
            //              QR akan menolak akses jika di-scan.
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tables');
    }
};
