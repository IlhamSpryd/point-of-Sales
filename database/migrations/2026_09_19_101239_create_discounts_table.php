<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            // Nullable: diskon manual kasir (misal "diskon karyawan 10%")
            // tidak butuh kode kupon, hanya dipilih dari daftar oleh Kasir.
            $table->string('code', 50)->unique()->nullable();
            $table->string('name');
            $table->enum('type', ['percentage', 'fixed'])->default('percentage');
            // Untuk type=percentage: nilai 0-100. Untuk type=fixed: nominal Rupiah.
            // Validasi batas 0-100 untuk percentage dilakukan di Form Request
            // (Phase 5), bukan di level kolom database.
            $table->unsignedBigInteger('value');
            // Batas maksimal potongan Rupiah untuk type=percentage, mencegah
            // diskon 90% pada item mahal jadi potongan yang tidak masuk akal.
            $table->unsignedBigInteger('max_discount_amount')->nullable();
            $table->unsignedBigInteger('min_purchase_amount')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['is_active', 'valid_from', 'valid_until'], 'discounts_active_validity_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
